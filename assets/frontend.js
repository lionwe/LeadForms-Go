const EMOJI_PATTERN = /[\p{Extended_Pictographic}\p{Regional_Indicator}\u{FE0F}\u{20E3}]/u;

class FormValidator {
	static errorId = 0;

	constructor(form, messages) {
		this.form = form;
		this.messages = messages;
		this.fields = [...form.querySelectorAll('input, textarea, select')];
		this.fields.forEach((field) => {
			field.addEventListener('blur', () => this.validateField(field));
			field.addEventListener('input', () => {
				if (field.getAttribute('aria-invalid') === 'true') this.validateField(field);
			});
		});
	}

	validate() {
		let valid = true;
		let firstInvalid = null;
		this.fields.forEach((field) => {
			if (!this.validateField(field)) {
				valid = false;
				firstInvalid ||= field;
			}
		});
		firstInvalid?.focus();
		return valid;
	}

	validateField(field) {
		const message = this.getError(field);
		if (message) {
			this.showError(field, message);
			return false;
		}
		this.clearError(field);
		return true;
	}

	getError(field) {
		if (field.disabled || field.type === 'hidden' || ['submit', 'button'].includes(field.type)) return '';
		const value = String(field.value || '').trim();
		if (field.required && ((['checkbox', 'radio'].includes(field.type) && !field.checked) || (!['checkbox', 'radio'].includes(field.type) && value === ''))) return this.messages.required;
		if (!value) return '';
		if (EMOJI_PATTERN.test(value)) return this.messages.emoji;
		const configured = Number.parseInt(field.dataset.maxLength || field.getAttribute('maxlength') || '', 10);
		const maximum = Number.isFinite(configured) && configured > 0 ? configured : (field.type === 'tel' ? 32 : (field.tagName === 'TEXTAREA' ? 1000 : 255));
		if (value.length > maximum) return this.messages.tooLong.replace('%d', String(maximum));
		if (field.type === 'tel') {
			const minimum = Number.parseInt(field.dataset.minLength || '12', 10);
			if (value.replace(/\D/g, '').length < minimum) return this.messages.phone.replace('%d', String(minimum));
		}
		if (field.validity?.typeMismatch || field.validity?.patternMismatch) return field.dataset.errorMessage || this.messages.invalid;
		return '';
	}

	showError(field, message) {
		let error = this.errorElement(field);
		if (!error) {
			error = document.createElement('span');
			error.className = 'leadforms-go-form__field-error reintegration-form__field-error';
			error.id = `leadforms-go-field-error-${++FormValidator.errorId}`;
			error.setAttribute('role', 'alert');
			const checkboxLabel = field.type === 'checkbox' ? field.closest('.leadforms-go-checkbox') : null;
			(checkboxLabel || field).insertAdjacentElement('afterend', error);
		}
		error.textContent = message;
		field.setAttribute('aria-invalid', 'true');
		field.setAttribute('aria-describedby', error.id);
	}

	clearError(field) {
		this.errorElement(field)?.remove();
		field.removeAttribute('aria-invalid');
		if (field.getAttribute('aria-describedby')?.startsWith('leadforms-go-field-error-')) field.removeAttribute('aria-describedby');
	}

	applyServerErrors(errors = {}) {
		Object.entries(errors).forEach(([name, message]) => {
			const field = this.fields.find((item) => item.name === name);
			if (field) this.showError(field, String(message));
		});
	}

	clear() { this.fields.forEach((field) => this.clearError(field)); }
	errorElement(field) {
		const anchor = field.type === 'checkbox' ? field.closest('.leadforms-go-checkbox') : field;
		return anchor?.nextElementSibling?.classList.contains('leadforms-go-form__field-error') ? anchor.nextElementSibling : null;
	}
}

class PhoneMask {
	constructor(input) {
		this.input = input;
		this.pattern = input.dataset.mask || '';
		this.prefixDigits = (this.pattern.split('0', 1)[0].match(/\d/g) || []).join('');
		if (!this.pattern) return;
		input.addEventListener('input', () => this.apply());
		this.apply();
	}

	apply() {
		let digits = this.input.value.replace(/\D/g, '');
		if (this.prefixDigits && digits.startsWith(this.prefixDigits)) digits = digits.slice(this.prefixDigits.length);
		let index = 0;
		let output = '';
		for (let position = 0; position < this.pattern.length; position += 1) {
			const character = this.pattern[position];
			if (character === '{') {
				const end = this.pattern.indexOf('}', position);
				if (end !== -1) { output += this.pattern.slice(position + 1, end); position = end; continue; }
			}
			if (character === '0') {
				if (index >= digits.length) break;
				output += digits[index++];
			} else if (index < digits.length || output) output += character;
		}
		this.input.value = output;
	}
}

class LeadForm {
	constructor(root, config) {
		this.root = root;
		this.form = root.querySelector('form');
		this.status = root.querySelector('.leadforms-go-form__status');
		this.config = { ...config, messages: { ...config.messages, ...this.parseMessages() } };
		if (!this.form) return;
		this.requestId = this.createRequestId();
		this.addHoneypot();
		this.validator = new FormValidator(this.form, this.config.messages);
		this.form.querySelectorAll('input[data-mask]').forEach((input) => new PhoneMask(input));
		this.form.noValidate = true;
		this.form.addEventListener('submit', (event) => this.submit(event), true);
	}

	createRequestId() {
		if (window.crypto?.randomUUID) return window.crypto.randomUUID();
		const bytes = new Uint8Array(24);
		window.crypto?.getRandomValues?.(bytes);
		return [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('') || `${Date.now()}_${Math.random().toString(36).slice(2)}`;
	}

	addHoneypot() {
		if (this.form.elements.namedItem('_lfg_website')) return;
		const input = document.createElement('input');
		input.type = 'text'; input.name = '_lfg_website'; input.className = 'leadforms-go-form__honeypot';
		input.tabIndex = -1; input.autocomplete = 'off'; input.setAttribute('aria-hidden', 'true');
		this.form.append(input);
	}

	parseMessages() {
		try {
			const messages = JSON.parse(this.root.dataset.leadformsGoMessages || '{}');
			return messages && typeof messages === 'object' ? messages : {};
		} catch { return {}; }
	}

	async submit(event) {
		event.preventDefault();
		event.stopImmediatePropagation();
		if (this.form.dataset.submitting === 'true' || !this.validator.validate()) return;
		const button = this.form.querySelector('[type="submit"]');
		const buttonText = button?.querySelector('.btn__text') || button;
		const original = buttonText?.textContent || '';
		this.setSubmitting(button, buttonText, true);
		this.clearStatus();
		const controller = new AbortController();
		const timeout = window.setTimeout(() => controller.abort(), Number(this.config.requestTimeout) || 20000);
		try {
			const response = await fetch(this.config.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: this.requestBody(), signal: controller.signal });
			const result = await response.json();
			if (!response.ok || !result.success) {
				this.validator.applyServerErrors(result?.data?.errors);
				throw new Error(result?.data?.message || this.config.messages.error);
			}
			this.validator.clear();
			this.form.reset();
			this.requestId = this.createRequestId();
			this.showSuccess(result.data.message || this.config.messages.success);
			document.dispatchEvent(new CustomEvent('leadFormsGoSubmitted', { detail: { form: this.form, data: result } }));
			document.dispatchEvent(new CustomEvent('reintegrationFormSubmitted', { detail: { form: this.form, data: result } }));
		} catch (error) {
			this.showStatus(error instanceof Error && error.name !== 'AbortError' ? error.message : this.config.messages.error, 'is-error');
		} finally {
			window.clearTimeout(timeout);
			this.setSubmitting(button, buttonText, false, original);
		}
	}

	requestBody() {
		const payload = {};
		new FormData(this.form).forEach((value, key) => { if (typeof value === 'string') payload[key] = value; });
		const query = new URLSearchParams(window.location.search);
		['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach((key) => {
			const current = query.get(key);
			if (current) this.storageSet(key, current);
			const value = current || this.storageGet(key);
			if (value) payload[key] = value;
		});
		const formId = this.root.dataset.leadformsGoForm || this.root.id.replace('leadforms-go-form-', '');
		return new URLSearchParams({ action: 'leadforms_go_submit', nonce: this.root.dataset.leadformsGoNonce || '', form_token: this.root.dataset.leadformsGoToken || '', request_id: this.requestId, form_id: formId, locale: this.root.dataset.leadformsGoLocale || '', form_data: JSON.stringify(payload) });
	}

	storageGet(key) {
		try {
			const raw = window.localStorage.getItem(key);
			if (!raw) return null;
			const stored = JSON.parse(raw);
			if (!stored?.value || Number(stored.expiresAt) <= Date.now()) { window.localStorage.removeItem(key); return null; }
			return String(stored.value);
		} catch { try { window.localStorage.removeItem(key); } catch { /* Storage can be blocked. */ } return null; }
	}

	storageSet(key, value) {
		try {
			const ttl = Math.max(0, Number(this.config.attributionTtl) || 0) * 1000;
			if (ttl > 0) window.localStorage.setItem(key, JSON.stringify({ value, expiresAt: Date.now() + ttl }));
		} catch { /* Storage can be blocked by browser privacy settings. */ }
	}

	setSubmitting(button, text, submitting, original = '') {
		this.form.dataset.submitting = String(submitting);
		if (!button) return;
		button.disabled = submitting;
		button.setAttribute('aria-busy', String(submitting));
		if (text) text.textContent = submitting ? this.config.messages.sending : original;
	}

	showStatus(message, state) {
		this.status.textContent = message;
		this.status.className = `leadforms-go-form__status reintegration-form__form-error ${state}`;
	}

	clearStatus() {
		this.status.replaceChildren();
		this.status.className = 'leadforms-go-form__status';
	}

	showSuccess(message) {
		const paragraph = document.createElement('p');
		paragraph.textContent = message;
		this.status.replaceChildren(paragraph);
		this.status.className = 'leadforms-go-form__status reintegration-form__success';
		this.form.hidden = true;
		window.setTimeout(() => {
			this.clearStatus();
			this.form.hidden = false;
		}, this.config.successDuration);
	}
}

class LeadFormsGoApp {
	constructor(config) { this.config = config; }
	init() { document.querySelectorAll('.leadforms-go-form').forEach((root) => new LeadForm(root, this.config)); }
}

if (window.leadFormsGo) new LeadFormsGoApp(window.leadFormsGo).init();
