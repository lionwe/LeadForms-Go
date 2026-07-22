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
		const groupValue = field.type === 'radio' ? this.form.elements.namedItem(field.name)?.value : '';
		if (field.required && ((field.type === 'checkbox' && !field.checked) || (field.type === 'radio' && !groupValue) || (!['checkbox', 'radio'].includes(field.type) && value === ''))) return this.messages.required;
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
	constructor(input, config = {}, locale = 'uk_UA') {
		this.input = input;
		this.config = config && typeof config === 'object' ? config : {};
		this.locale = String(locale || 'uk_UA').replace('_', '-');
		this.countries = this.config.countries && typeof this.config.countries === 'object' ? this.config.countries : {};
		this.country = this.countries[this.config.default] ? this.config.default : Object.keys(this.countries)[0];
		this.pattern = this.country ? this.countries[this.country].mask : (input.dataset.leadformsGoMask || '');
		this.prefixDigits = this.country ? String(this.countries[this.country].dial) : (this.pattern.split('0', 1)[0].match(/\d/g) || []).join('');
		if (!this.pattern) return;
		if (this.config.enabled && Object.keys(this.countries).length > 1) this.addCountrySelector();
		input.addEventListener('input', () => this.apply());
		input.addEventListener('blur', () => {
			if (this.input.value.replace(/\D/g, '') === this.prefixDigits) this.input.value = '';
		});
		this.apply();
	}

	addCountrySelector() {
		const wrapper = document.createElement('span');
		const display = ['name_code', 'code', 'flag_code', 'flag'].includes(this.config.display) ? this.config.display : 'code';
		wrapper.className = `leadforms-go-phone leadforms-go-phone--${display}`;
		const select = document.createElement('select');
		select.className = 'leadforms-go-phone__country';
		const language = this.locale.split('-', 1)[0].toLowerCase();
		select.lang = this.locale;
		select.setAttribute('aria-label', this.config.countryLabels?.[language] || 'Country code');
		const dialCodes = new Set();
		Object.entries(this.countries).forEach(([code, country]) => {
			const dial = String(country.dial);
			if (display === 'code' && dialCodes.has(dial)) return;
			dialCodes.add(dial);
			const option = document.createElement('option');
			option.value = code;
			option.textContent = this.countryOption(display, code, country);
			option.selected = display === 'code' ? dial === String(this.countries[this.country].dial) : code === this.country;
			select.append(option);
		});
		this.input.before(wrapper);
		wrapper.append(select, this.input);
		this.select = select;
		select.addEventListener('change', () => {
			const national = this.nationalDigits();
			this.country = select.value;
			this.updateCountry();
			this.input.value = national;
			this.apply();
			this.input.focus();
		});
	}

	countryOption(display, code, country) {
		const flag = String.fromCodePoint(...code.toUpperCase().split('').map((character) => 127397 + character.charCodeAt(0)));
		if (display === 'name_code') return `${this.countryName(code, country.name)} (+${country.dial})`;
		if (display === 'flag_code') return `${flag} +${country.dial}`;
		if (display === 'flag') return flag;
		return `+${country.dial}`;
	}

	countryName(code, fallback) {
		try {
			return new Intl.DisplayNames([this.locale], { type: 'region' }).of(code) || fallback;
		} catch { return fallback; }
	}

	nationalDigits() {
		let digits = this.input.value.replace(/\D/g, '');
		if (this.prefixDigits && digits.startsWith(this.prefixDigits)) digits = digits.slice(this.prefixDigits.length);
		return digits;
	}

	updateCountry() {
		const country = this.countries[this.country];
		if (!country) return;
		this.pattern = country.mask;
		this.prefixDigits = String(country.dial);
		const prefix = `+${this.prefixDigits}`;
		this.nationalPattern = this.pattern.startsWith(prefix) ? this.pattern.slice(prefix.length).trimStart() : this.pattern;
		this.input.dataset.minLength = String(Number(country.min || 4) + (this.select ? 0 : this.prefixDigits.length));
		this.input.dataset.maxPhoneLength = String(Number(country.max || 15) + (this.select ? 0 : this.prefixDigits.length));
	}

	fullValue() {
		const value = this.input.value.trim();
		return this.select && value ? `+${this.prefixDigits} ${value}` : value;
	}

	reset() {
		if (this.select) this.country = this.select.value;
		this.updateCountry();
		this.apply();
	}

	apply() {
		let digits = this.input.value.replace(/\D/g, '');
		if (this.select && this.input.value.trim().startsWith('+')) {
			const detected = Object.entries(this.countries)
				.filter(([, country]) => digits.startsWith(String(country.dial)))
				.sort(([, first], [, second]) => String(second.dial).length - String(first.dial).length)[0];
			if (detected && detected[0] !== this.country) {
				this.country = detected[0];
				this.select.value = this.country;
				this.updateCountry();
			}
		}
		if (this.prefixDigits && digits.startsWith(this.prefixDigits)) digits = digits.slice(this.prefixDigits.length);
		if (this.country) {
			this.updateCountry();
			digits = digits.slice(0, Number(this.countries[this.country].max || 15));
		}
		let index = 0;
		let output = this.country && digits.length && !this.select ? `+${this.prefixDigits}` : '';
		const pattern = this.country ? this.nationalPattern : this.pattern;
		for (let position = 0; position < pattern.length; position += 1) {
			const character = pattern[position];
			if (character === '{') {
				const end = pattern.indexOf('}', position);
				if (end !== -1) {
					if (index < digits.length) output += pattern.slice(position + 1, end);
					position = end;
					continue;
				}
			}
			if (character === '0') {
				if (index >= digits.length) break;
				output += digits[index++];
			} else if (index < digits.length) output += character;
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
		this.startedAt = Math.floor(Date.now() / 1000);
		this.visitedAt = Number(this.storageGet('_lfg_visited_at')) || this.startedAt;
		this.landingPage = this.storageGet('_lfg_landing_page') || window.location.href;
		if (!this.storageGet('_lfg_landing_page')) this.storageSet('_lfg_landing_page', this.landingPage);
		if (!this.storageGet('_lfg_visited_at')) this.storageSet('_lfg_visited_at', String(this.visitedAt));
		this.requestId = this.createRequestId();
		this.addHoneypot();
		this.validator = new FormValidator(this.form, this.config.messages);
		this.phoneMasks = [...this.form.querySelectorAll('input[type="tel"], input[data-leadforms-go-mask]')].map((input) => new PhoneMask(input, this.config.phone, this.root.dataset.leadformsGoLocale));
		this.initConditions();
		this.initTurnstile();
		this.form.noValidate = true;
		this.form.addEventListener('submit', (event) => this.submit(event), true);
		this.trackView();
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
		const requestStartedAt = performance.now();
		let responseStatus = 0;
		try {
			const response = await fetch(this.config.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: this.requestBody(), signal: controller.signal });
			responseStatus = response.status;
			const result = await response.json();
			if (!response.ok || !result.success) {
				this.validator.applyServerErrors(result?.data?.errors);
				throw new Error(result?.data?.message || this.config.messages.error);
			}
			this.validator.clear();
			this.form.reset();
			this.phoneMasks.forEach((phone) => phone.reset());
			this.updateConditions();
			this.requestId = this.createRequestId();
			console.info('[LeadForms Go] Submission accepted', {
				success: true,
				durationMs: Math.round(performance.now() - requestStartedAt),
				serverProcessingMs: Number(result.data.processing_ms) || null,
				submissionId: Number(result.data.submission_id) || null,
				deliveriesQueued: Number(result.data.deliveries) || 0,
				deliveryStatus: Number(result.data.deliveries) > 0 ? 'queued' : 'not_required',
			});
			this.dispatchDelivery(result.data);
			if (result.data.success_action === 'redirect' && result.data.redirect_url) {
				window.location.assign(result.data.redirect_url);
				return;
			}
			this.showSuccess(result.data.message || this.config.messages.success, result.data.success_action === 'hide', result.data.success_duration);
			document.dispatchEvent(new CustomEvent('leadFormsGoSubmitted', { detail: { form: this.form, data: result } }));
			document.dispatchEvent(new CustomEvent('reintegrationFormSubmitted', { detail: { form: this.form, data: result } }));
		} catch (error) {
			console.error('[LeadForms Go] Submission failed', {
				success: false,
				durationMs: Math.round(performance.now() - requestStartedAt),
				httpStatus: responseStatus || null,
				error: error instanceof Error ? error.message : String(error),
			});
			if (window.turnstile && this.form.querySelector('.cf-turnstile')) window.turnstile.reset(this.form.querySelector('.cf-turnstile'));
			this.showStatus(error instanceof Error && error.name !== 'AbortError' ? error.message : this.config.messages.error, 'is-error');
		} finally {
			window.clearTimeout(timeout);
			this.setSubmitting(button, buttonText, false, original);
		}
	}

	requestBody() {
		const payload = {};
		new FormData(this.form).forEach((value, key) => { if (typeof value === 'string') payload[key] = value; });
		this.phoneMasks.forEach((phone) => {
			if (phone.input.name) payload[phone.input.name] = phone.fullValue();
		});
		const query = new URLSearchParams(window.location.search);
		['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'ttclid'].forEach((key) => {
			const current = query.get(key);
			if (current) this.storageSet(key, current);
			const value = current || this.storageGet(key);
			if (value) payload[key] = value;
		});
		payload.landing_page = this.landingPage;
		payload.document_referrer = document.referrer || '';
		payload.visited_at = String(this.visitedAt);
		payload._lfg_started_at = String(this.startedAt);
		const formId = this.root.dataset.leadformsGoForm || this.root.id.replace('leadforms-go-form-', '');
		return new URLSearchParams({ action: 'leadforms_go_submit', nonce: this.root.dataset.leadformsGoNonce || '', form_token: this.root.dataset.leadformsGoToken || '', request_id: this.requestId, form_id: formId, locale: this.root.dataset.leadformsGoLocale || '', form_data: JSON.stringify(payload) });
	}

	dispatchDelivery(data) {
		const submissionId = Number(data?.submission_id) || 0;
		const token = String(data?.dispatch_token || '');
		if (!submissionId || !token) return;
		const startedAt = performance.now();
		fetch(this.config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams({ action: 'leadforms_go_dispatch', submission_id: String(submissionId), dispatch_token: token }),
		}).then(async (response) => {
			const result = await response.json();
			if (!response.ok || !result.success) throw new Error('Delivery dispatch failed');
			console.info('[LeadForms Go] Delivery completed', {
				success: Boolean(result.data.success),
				durationMs: Math.round(performance.now() - startedAt),
				submissionId,
				delivered: Number(result.data.delivered) || 0,
				pending: Number(result.data.pending) || 0,
				failed: Number(result.data.failed) || 0,
			});
		}).catch((error) => {
			console.warn('[LeadForms Go] Delivery dispatch deferred to WP-Cron', {
				success: false,
				durationMs: Math.round(performance.now() - startedAt),
				submissionId,
				error: error instanceof Error ? error.message : String(error),
			});
		});
	}

	initConditions() {
		this.conditions = [...this.form.querySelectorAll('.leadforms-go-field')].filter((wrapper) => {
			const token = [...wrapper.classList].find((name) => name.startsWith('lfg-condition--'));
			if (!token) return false;
			try {
				const condition = JSON.parse(decodeURIComponent(token.slice('lfg-condition--'.length)));
				wrapper.dataset.lfgConditionField = String(condition.field || '');
				wrapper.dataset.lfgConditionOperator = String(condition.operator || 'equals');
				wrapper.dataset.lfgConditionValue = String(condition.value || '');
				return wrapper.dataset.lfgConditionField !== '';
			} catch { return false; }
		});
		if (!this.conditions.length) return;
		this.form.addEventListener('input', () => this.updateConditions());
		this.form.addEventListener('change', () => this.updateConditions());
		this.updateConditions();
	}

	initTurnstile() {
		const container = this.form.querySelector('.leadforms-go-turnstile');
		if (!container || !this.config.turnstileSiteKey || !window.turnstile?.render) return;
		window.turnstile.render(container, { sitekey: this.config.turnstileSiteKey, action: this.config.turnstileAction || 'leadforms_go_submit' });
	}

	updateConditions() {
		this.conditions.forEach((wrapper) => {
			const controller = this.form.elements.namedItem(wrapper.dataset.lfgConditionField || '');
			let current = '';
			if (controller instanceof RadioNodeList) current = controller.value;
			else if (controller instanceof HTMLInputElement && controller.type === 'checkbox') current = controller.checked ? controller.value : '';
			else if (controller instanceof HTMLElement && 'value' in controller) current = String(controller.value || '');
			const expected = wrapper.dataset.lfgConditionValue || '';
			const visible = ({ not_equals: current !== expected, contains: expected !== '' && current.includes(expected), filled: current !== '', equals: current === expected })[wrapper.dataset.lfgConditionOperator || 'equals'];
			wrapper.hidden = !visible;
			wrapper.querySelectorAll('input, select, textarea').forEach((field) => {
				if (!field.dataset.lfgOriginalRequired) field.dataset.lfgOriginalRequired = field.required ? '1' : '0';
				field.disabled = !visible;
				field.required = visible && field.dataset.lfgOriginalRequired === '1';
			});
		});
	}

	trackView() {
		const formId = this.root.dataset.leadformsGoForm || '';
		LeadForm.trackedViews ||= new Set();
		if (!formId || LeadForm.trackedViews.has(formId)) return;
		LeadForm.trackedViews.add(formId);
		const query = new URLSearchParams(window.location.search);
		fetch(this.config.ajaxUrl, {
			method: 'POST', credentials: 'same-origin', keepalive: true,
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams({ action: 'leadforms_go_view', form_id: formId, nonce: this.root.dataset.leadformsGoNonce || '', utm_source: query.get('utm_source') || this.storageGet('utm_source') || '', utm_campaign: query.get('utm_campaign') || this.storageGet('utm_campaign') || '' }),
		}).catch(() => {});
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

	showSuccess(message, keepHidden = false, duration = 0) {
		const paragraph = document.createElement('p');
		paragraph.textContent = message;
		this.status.replaceChildren(paragraph);
		this.status.className = 'leadforms-go-form__status reintegration-form__success';
		this.form.hidden = true;
		if (window.turnstile && this.form.querySelector('.cf-turnstile')) window.turnstile.reset(this.form.querySelector('.cf-turnstile'));
		if (keepHidden) return;
		window.setTimeout(() => {
			this.clearStatus();
			this.form.hidden = false;
		}, Number(duration) || this.config.successDuration);
	}
}

class LeadFormsGoApp {
	constructor(config) { this.config = config; }
	init() { document.querySelectorAll('.leadforms-go-form').forEach((root) => new LeadForm(root, this.config)); }
}

if (window.leadFormsGo) new LeadFormsGoApp(window.leadFormsGo).init();
