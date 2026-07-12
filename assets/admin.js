class LeadFormsGoAdmin {
	constructor(config) {
		this.config = config;
		this.schema = [];
		this.translations = {};
	}

	init() {
		document.addEventListener('click', (event) => this.handleClick(event));
		document.addEventListener('change', (event) => this.handleChange(event));
		this.initBuilder();
	}

	handleChange(event) {
		const selectAll = event.target.closest('[data-lfg-select-all]');
		if (!selectAll) return;
		selectAll.closest('table')?.querySelectorAll('tbody input[type="checkbox"]').forEach((checkbox) => {
			checkbox.checked = selectAll.checked;
		});
	}

	initBuilder() {
		this.modeInput = document.querySelector('[data-lfg-mode-input]');
		this.schemaInput = document.querySelector('[data-lfg-schema]');
		this.canvas = document.querySelector('[data-lfg-canvas]');
		this.codeInput = document.querySelector('[data-lfg-code]');
		this.submitLabelInput = document.querySelector('[name="submit_label"]');
		this.buttonIconType = document.querySelector('[data-lfg-button-icon-type]');
		this.buttonIconPosition = document.querySelector('[data-lfg-button-icon-position]');
		this.buttonIconFa = document.querySelector('[data-lfg-button-icon-fa]');
		this.buttonIconSvg = document.querySelector('[data-lfg-button-icon-svg]');
		this.faCatalog = document.querySelector('[data-lfg-fa-catalog]');
		this.translationsInput = document.querySelector('[data-lfg-translations]');
		this.defaultLocaleInput = document.querySelector('[data-lfg-default-locale]');
		this.preview = document.querySelector('[data-lfg-preview]');
		this.messageFields = document.querySelector('[data-lfg-message-fields]');
		if (!this.modeInput || !this.schemaInput || !this.canvas) return;
		try { this.schema = JSON.parse(this.schemaInput.value || '[]'); } catch { this.schema = []; }
		try { this.translations = JSON.parse(this.translationsInput?.value || '{}'); } catch { this.translations = {}; }
		if (!Array.isArray(this.schema)) this.schema = [];
		if (!this.translations || Array.isArray(this.translations) || typeof this.translations !== 'object') this.translations = {};
		this.currentLocale = this.defaultLocaleInput?.value || 'uk_UA';
		this.hydrateFieldKeys();
		document.querySelectorAll('[data-lfg-locale]').forEach((button) => this.ensureTranslation(button.dataset.lfgLocale));
		this.ensureTranslation(this.currentLocale);
		this.setMode(this.modeInput.value || 'visual');
		this.renderBuilder();
		this.submitLabelInput?.addEventListener('input', () => {
			this.ensureTranslation(this.currentLocale).submit_label = this.submitLabelInput.value;
			this.syncTranslations(); this.syncCodePreview(); this.renderPreview(); this.updateLocaleProgress();
		});
		[this.buttonIconType, this.buttonIconPosition, this.buttonIconFa, this.buttonIconSvg].forEach((input) => {
			input?.addEventListener('input', () => {
				this.updateButtonIconPanels();
				this.syncCodePreview();
				this.renderPreview();
			});
			input?.addEventListener('change', () => {
				this.updateButtonIconPanels();
				this.syncCodePreview();
				this.renderPreview();
			});
		});
		this.faCatalog?.addEventListener('change', () => {
			if (this.faCatalog.value && this.buttonIconFa) this.buttonIconFa.value = this.faCatalog.value;
			this.syncCodePreview();
			this.renderPreview();
		});
		this.updateButtonIconPanels();
		this.defaultLocaleInput?.addEventListener('change', () => { this.updateLocaleProgress(); this.syncCodePreview(); });
	}

	hydrateFieldKeys() {
		const templates = [...document.querySelectorAll('[data-lfg-template]')].map((tile) => {
			try { return { key: tile.dataset.lfgAdd, field: JSON.parse(tile.dataset.lfgTemplate) }; } catch { return null; }
		}).filter(Boolean);
		this.schema.forEach((field) => {
			if (field.key) return;
			const match = templates.find((item) => item.field.type === field.type && item.field.name === field.name);
			field.key = match?.key || field.type || 'field';
		});
	}

	async handleClick(event) {
		const confirmLink = event.target.closest('[data-lfg-confirm]');
		if (confirmLink && !window.confirm(this.config.confirmDelete)) event.preventDefault();
		const modeButton = event.target.closest('[data-lfg-mode]');
		if (modeButton) this.setMode(modeButton.dataset.lfgMode);
		const tile = event.target.closest('[data-lfg-add]');
		if (tile) this.addField(tile);
		const fieldAction = event.target.closest('[data-lfg-field-action]');
		if (fieldAction) this.updateFieldOrder(fieldAction);
		const fieldToggle = event.target.closest('[data-lfg-field-toggle]');
		if (fieldToggle) this.toggleField(fieldToggle);
		const copyButton = event.target.closest('[data-lfg-copy]');
		if (copyButton) await this.copyShortcode(copyButton);
		const localeButton = event.target.closest('[data-lfg-locale]');
		if (localeButton) this.setLocale(localeButton.dataset.lfgLocale);
		const copyLanguage = event.target.closest('[data-lfg-copy-language]');
		if (copyLanguage) this.copyDefaultLanguage();
		const button = event.target.closest('[data-lfg-test]');
		if (button) await this.testConnector(button);
		const googleUpload = event.target.closest('[data-lfg-google-upload]');
		if (googleUpload) await this.uploadGoogleCredentials(googleUpload);
		const googleRemove = event.target.closest('[data-lfg-google-remove]');
		if (googleRemove) await this.removeGoogleCredentials(googleRemove);
	}

	setMode(mode) {
		if (!this.modeInput) return;
		this.modeInput.value = mode === 'code' ? 'code' : 'visual';
		if (this.modeInput.value === 'code') this.syncCodePreview();
		document.querySelectorAll('[data-lfg-panel]').forEach((panel) => { panel.hidden = panel.dataset.lfgPanel !== this.modeInput.value; });
		document.querySelectorAll('[data-lfg-mode]').forEach((button) => {
			button.classList.toggle('is-active', button.dataset.lfgMode === this.modeInput.value);
			button.setAttribute('aria-selected', String(button.dataset.lfgMode === this.modeInput.value));
		});
	}

	addField(tile) {
		if (this.schema.length >= this.config.builder.maxFields) {
			window.alert(this.config.builder.maxFieldsMessage);
			return;
		}
		let template;
		try { template = JSON.parse(tile.dataset.lfgTemplate); } catch { return; }
		const base = String(tile.dataset.lfgAdd || template.type || 'field').replaceAll('-', '_');
		let key = base; let suffix = 2;
		while (this.schema.some((field) => field.key === key)) key = `${base}_${suffix++}`;
		this.schema.push({ ...template, id: key.replaceAll('_', '-'), key, name: key });
		document.querySelectorAll('[data-lfg-locale]').forEach((button) => {
			const locale = button.dataset.lfgLocale;
			const defaults = this.config.builder.localeDefaults?.[locale]?.fields?.[base] || {};
			const translation = this.ensureTranslation(locale);
			translation.fields[key] = {
				label: defaults.label || (locale === this.currentLocale ? template.label : '') || key,
				placeholder: defaults.placeholder ?? (locale === this.currentLocale ? template.placeholder || '' : ''),
				options: [],
			};
		});
		this.renderBuilder();
	}

	updateFieldOrder(button) {
		const card = button.closest('[data-lfg-field-index]');
		if (!card) return;
		const index = Number.parseInt(card.dataset.lfgFieldIndex, 10);
		const action = button.dataset.lfgFieldAction;
		if (action === 'remove') this.schema.splice(index, 1);
		if (action === 'up' && index > 0) [this.schema[index - 1], this.schema[index]] = [this.schema[index], this.schema[index - 1]];
		if (action === 'down' && index < this.schema.length - 1) [this.schema[index + 1], this.schema[index]] = [this.schema[index], this.schema[index + 1]];
		this.renderBuilder();
	}

	renderBuilder() {
		if (!this.canvas) return;
		this.canvas.replaceChildren();
		if (!this.schema.length) {
			const empty = document.createElement('p');
			empty.className = 'lfg-builder__empty';
			empty.textContent = this.config.builder.empty;
			this.canvas.append(empty);
		}
		this.schema.forEach((field, index) => this.canvas.append(this.createFieldCard(field, index)));
		this.syncSchema();
		this.syncTranslations();
		this.renderMessageFields();
		this.renderPreview();
		this.updateLocaleProgress();
		this.syncCodePreview();
	}

	ensureTranslation(locale) {
		const defaults = this.config.builder.localeDefaults?.[locale] || {};
		if (!this.translations[locale]) this.translations[locale] = { submit_label: defaults.submit_label || '', messages: {}, fields: {} };
		const translation = this.translations[locale];
		translation.submit_label ||= defaults.submit_label || '';
		translation.messages ||= {};
		Object.entries(defaults.messages || {}).forEach(([key, value]) => { translation.messages[key] ||= value; });
		translation.fields ||= {};
		return translation;
	}

	fieldTranslation(field, locale = this.currentLocale) {
		const translation = this.ensureTranslation(locale);
		translation.fields[field.key] ||= { label: '', placeholder: '', options: [] };
		return translation.fields[field.key];
	}

	resolvedFieldTranslation(field, locale = this.currentLocale) {
		const current = this.fieldTranslation(field, locale);
		const fallback = this.ensureTranslation(this.defaultLocaleInput?.value || 'uk_UA').fields[field.key] || {};
		return { label: current.label || fallback.label || field.label || field.key, placeholder: current.placeholder || fallback.placeholder || field.placeholder || '' };
	}

	setLocale(locale) {
		if (!locale || locale === this.currentLocale) return;
		this.currentLocale = locale;
		const translation = this.ensureTranslation(locale);
		this.submitLabelInput.value = translation.submit_label || this.ensureTranslation(this.defaultLocaleInput?.value || 'uk_UA').submit_label || '';
		this.renderBuilder();
	}

	copyDefaultLanguage() {
		const sourceLocale = this.defaultLocaleInput?.value || 'uk_UA';
		if (this.currentLocale === sourceLocale || !window.confirm(this.config.builder.copyLanguageConfirm)) return;
		this.translations[this.currentLocale] = JSON.parse(JSON.stringify(this.ensureTranslation(sourceLocale)));
		this.submitLabelInput.value = this.translations[this.currentLocale].submit_label || '';
		this.renderBuilder();
	}

	renderMessageFields() {
		if (!this.messageFields) return;
		this.messageFields.replaceChildren();
		const messages = this.ensureTranslation(this.currentLocale).messages;
		Object.entries(this.config.builder.messageLabels || {}).forEach(([key, labelText]) => {
			const label = document.createElement('label');
			const span = document.createElement('span'); span.textContent = labelText;
			const input = document.createElement('input'); input.type = 'text'; input.value = messages[key] || '';
			input.addEventListener('input', () => { messages[key] = input.value; this.syncTranslations(); this.updateLocaleProgress(); });
			label.append(span, input); this.messageFields.append(label);
		});
	}

	buttonIcon() {
		const type = this.buttonIconType?.value || 'none';
		const position = this.buttonIconPosition?.value === 'before' ? 'before' : 'after';
		const faClass = (this.buttonIconFa?.value || '').trim().split(/\s+/).filter((token) => /^(fa|fas|far|fab|fal|fa-[a-z0-9-]+)$/i.test(token)).slice(0, 6).join(' ');
		const svg = (this.buttonIconSvg?.value || '').trim();
		const hasFaIcon = faClass.split(/\s+/).some((token) => token.startsWith('fa-') && !['fa-solid', 'fa-regular', 'fa-brands'].includes(token));
		if (type === 'fontawesome' && faClass && hasFaIcon) return { type, position, faClass, svg: '' };
		if (type === 'svg' && svg) return { type, position, faClass: '', svg };
		return { type: 'none', position, faClass: '', svg: '' };
	}

	updateButtonIconPanels() {
		const type = this.buttonIconType?.value || 'none';
		document.querySelectorAll('[data-lfg-button-icon-panel]').forEach((panel) => {
			panel.hidden = panel.dataset.lfgButtonIconPanel !== type;
		});
	}

	createButtonIconElement() {
		const icon = this.buttonIcon();
		if (icon.type === 'fontawesome' && icon.faClass) {
			const wrapper = document.createElement('span');
			wrapper.className = 'btn__icon leadforms-go-button__icon leadforms-go-button__icon--fontawesome';
			wrapper.setAttribute('aria-hidden', 'true');
			const element = document.createElement('i');
			element.className = icon.faClass;
			wrapper.append(element);
			return wrapper;
		}
		if (icon.type === 'svg' && icon.svg) {
			const svg = this.safeSvgElement(icon.svg);
			if (!svg) return null;
			const wrapper = document.createElement('span');
			wrapper.className = 'btn__icon leadforms-go-button__icon leadforms-go-button__icon--svg';
			wrapper.setAttribute('aria-hidden', 'true');
			wrapper.append(svg);
			return wrapper;
		}
		return null;
	}

	safeSvgElement(value) {
		const doc = new DOMParser().parseFromString(value, 'image/svg+xml');
		const svg = doc.querySelector('svg');
		if (!svg || doc.querySelector('parsererror, script, foreignObject')) return null;
		const allowedTags = new Set(['svg', 'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon']);
		const allowedAttrs = new Set(['class', 'aria-hidden', 'focusable', 'height', 'role', 'viewBox', 'viewbox', 'width', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'xmlns', 'transform', 'd', 'cx', 'cy', 'r', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'rx', 'ry', 'points']);
		[...svg.querySelectorAll('*'), svg].forEach((node) => {
			if (!allowedTags.has(node.tagName)) {
				node.remove();
				return;
			}
			[...node.attributes].forEach((attribute) => {
				if (!allowedAttrs.has(attribute.name)) node.removeAttribute(attribute.name);
			});
		});
		return document.importNode(svg, true);
	}

	renderPreview() {
		if (!this.preview) return;
		this.preview.replaceChildren();
		this.schema.forEach((field) => {
			const text = this.resolvedFieldTranslation(field);
			const label = document.createElement('label');
			const span = document.createElement('span'); span.textContent = `${text.label || field.key}${field.required ? '*' : ''}`;
			const input = document.createElement(field.type === 'textarea' ? 'textarea' : 'input');
			if (field.type !== 'textarea') input.type = field.type || 'text';
			input.placeholder = text.placeholder || ''; input.disabled = true;
			label.append(span, input); this.preview.append(label);
		});
		const button = document.createElement('button'); button.type = 'button'; button.className = 'button button-primary'; button.disabled = true;
		const icon = this.createButtonIconElement();
		const text = document.createElement('span');
		text.className = 'btn__text';
		text.textContent = this.submitLabelInput?.value || 'Надіслати';
		if (icon && this.buttonIcon().position === 'before') button.append(icon);
		button.append(text);
		if (icon && this.buttonIcon().position === 'after') button.append(icon);
		this.preview.append(button);
	}

	updateLocaleProgress() {
		document.querySelectorAll('[data-lfg-locale]').forEach((button) => {
			const locale = button.dataset.lfgLocale;
			const translation = this.ensureTranslation(locale);
			const total = this.schema.length * 2 + 1;
			let completed = translation.submit_label ? 1 : 0;
			this.schema.forEach((field) => { const value = translation.fields[field.key] || {}; if (value.label) completed += 1; if (value.placeholder || field.type === 'checkbox') completed += 1; });
			button.classList.toggle('is-active', locale === this.currentLocale);
			button.setAttribute('aria-selected', String(locale === this.currentLocale));
			const progress = button.querySelector('[data-lfg-locale-progress]');
			if (progress) progress.textContent = `${completed}/${total}`;
		});
	}

	createFieldCard(field, index) {
		const card = document.createElement('article');
		card.className = 'lfg-builder-field';
		card.dataset.lfgFieldIndex = String(index);
		const header = document.createElement('header');
		const toggle = document.createElement('button');
		toggle.type = 'button'; toggle.className = 'lfg-builder-field__toggle'; toggle.dataset.lfgFieldToggle = '';
		toggle.setAttribute('aria-expanded', 'false');
		const fieldText = this.fieldTranslation(field);
		const title = document.createElement('strong'); title.textContent = fieldText.label || this.resolvedFieldTranslation(field).label || field.key;
		const chevron = document.createElement('span'); chevron.className = 'dashicons dashicons-arrow-down-alt2'; chevron.setAttribute('aria-hidden', 'true');
		toggle.append(title, chevron);
		const actions = document.createElement('div');
		[['up', '↑', this.config.builder.moveUp], ['down', '↓', this.config.builder.moveDown], ['remove', '×', this.config.builder.remove]].forEach(([action, text, label]) => {
			const button = document.createElement('button');
			button.type = 'button'; button.className = 'button button-small'; button.dataset.lfgFieldAction = action; button.textContent = text; button.setAttribute('aria-label', label);
			actions.append(button);
		});
		header.append(toggle, actions);
		const body = document.createElement('div');
		body.className = 'lfg-builder-field__body'; body.hidden = true; body.id = `lfg-field-settings-${index}`;
		toggle.setAttribute('aria-controls', body.id);
		const fields = document.createElement('div');
		fields.className = 'lfg-builder-field__settings';
		[
			['label', this.config.builder.fieldLabel, this.config.builder.fieldLabelHelp],
			['placeholder', this.config.builder.placeholder, this.config.builder.placeholderHelp],
		].forEach(([property, labelText, helpText]) => {
			const label = document.createElement('label');
			const span = document.createElement('strong'); span.textContent = labelText;
			const help = document.createElement('small'); help.textContent = helpText;
			const input = document.createElement('input'); input.type = 'text'; input.value = fieldText[property] || '';
			input.addEventListener('input', () => { this.fieldTranslation(field)[property] = input.value; if (property === 'label') title.textContent = input.value || field.key; this.syncTranslations(); this.syncCodePreview(); this.renderPreview(); this.updateLocaleProgress(); });
			label.append(span, help, input); fields.append(label);
		});
		const keyLabel = document.createElement('label');
		const keyTitle = document.createElement('strong'); keyTitle.textContent = this.config.builder.fieldName;
		const keyHelp = document.createElement('small'); keyHelp.textContent = this.config.builder.fieldNameHelp;
		const keyInput = document.createElement('input'); keyInput.type = 'text'; keyInput.value = field.key || ''; keyInput.readOnly = true;
		keyLabel.append(keyTitle, keyHelp, keyInput); fields.append(keyLabel);
		const requiredLabel = document.createElement('label');
		requiredLabel.className = 'lfg-builder-field__required';
		const required = document.createElement('input'); required.type = 'checkbox'; required.checked = Boolean(field.required);
		required.addEventListener('change', () => { field.required = required.checked; this.syncSchema(); this.syncCodePreview(); });
		requiredLabel.append(required, document.createTextNode(` ${this.config.builder.required}`));
		body.append(fields, requiredLabel);
		card.append(header, body);
		return card;
	}

	toggleField(button) {
		const body = document.getElementById(button.getAttribute('aria-controls'));
		if (!body) return;
		const expanded = button.getAttribute('aria-expanded') === 'true';
		button.setAttribute('aria-expanded', String(!expanded));
		body.hidden = expanded;
		button.closest('.lfg-builder-field')?.classList.toggle('is-expanded', !expanded);
	}

	syncSchema() {
		this.schema.forEach((field) => { field.name = field.key; field.id = String(field.key || '').replaceAll('_', '-'); });
		if (this.schemaInput) this.schemaInput.value = JSON.stringify(this.schema);
	}

	syncTranslations() { if (this.translationsInput) this.translationsInput.value = JSON.stringify(this.translations); }

	syncCodePreview() {
		if (this.codeInput && this.schema.length) this.codeInput.value = this.generateCode();
	}

	buttonIconMarkupForCode() {
		const escape = (value) => String(value || '').replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
		const icon = this.buttonIcon();
		if (icon.type === 'fontawesome' && icon.faClass) {
			return `    <span class="btn__icon leadforms-go-button__icon leadforms-go-button__icon--fontawesome" aria-hidden="true"><i class="${escape(icon.faClass)}"></i></span>`;
		}
		if (icon.type === 'svg' && icon.svg) {
			return `    <span class="btn__icon leadforms-go-button__icon leadforms-go-button__icon--svg" aria-hidden="true">${icon.svg}</span>`;
		}
		return '';
	}

	generateCode() {
		const escape = (value) => String(value || '').replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
		const counts = {};
		const lines = ['<form method="post" action="">'];
		this.schema.forEach((field) => {
			const text = this.resolvedFieldTranslation(field, this.defaultLocaleInput?.value || this.currentLocale);
			const base = String(field.key || field.type || 'field').replaceAll('_', '-').replace(/[^a-z0-9-]/gi, '') || 'field';
			counts[base] = (counts[base] || 0) + 1;
			const id = `lfg-${base}${counts[base] > 1 ? `-${counts[base]}` : ''}`;
			const required = field.required ? ' required' : '';
			const mark = field.required ? '*' : '';
			if (field.type === 'checkbox') {
				lines.push(`  <label class="leadforms-go-checkbox" for="${id}">`);
				lines.push(`    <input id="${id}" type="checkbox" name="${escape(field.key)}" value="1"${required}>`);
				lines.push(`    <span class="leadforms-go-checkbox__label">${escape(text.label)}${mark}</span>`);
				lines.push('  </label>');
				return;
			}
			lines.push(`  <label for="${id}">`);
			lines.push(`    <span>${escape(text.label)}${mark}</span>`);
			if (field.type === 'textarea') lines.push(`    <textarea id="${id}" name="${escape(field.key)}" placeholder="${escape(text.placeholder)}"${required}></textarea>`);
			else {
				const mask = field.type === 'tel' && field.mask ? ` data-mask="${escape(field.mask)}" data-min-length="12"` : '';
				lines.push(`    <input id="${id}" type="${escape(field.type)}" name="${escape(field.key)}" placeholder="${escape(text.placeholder)}"${mask}${required}>`);
			}
			lines.push('  </label>');
		});
		lines.push('  <button class="btn btn--primary" type="submit">');
		const primary = this.ensureTranslation(this.defaultLocaleInput?.value || this.currentLocale);
		const buttonIcon = this.buttonIcon();
		const buttonIconMarkup = this.buttonIconMarkupForCode();
		if (buttonIcon.position === 'before' && buttonIconMarkup) lines.push(buttonIconMarkup);
		lines.push(`    <span class="btn__text">${escape(primary.submit_label || this.submitLabelInput?.value || 'Надіслати')}</span>`);
		if (buttonIcon.position === 'after' && buttonIconMarkup) lines.push(buttonIconMarkup);
		lines.push('  </button>');
		lines.push('</form>');
		return lines.join('\n');
	}

	async copyShortcode(button) {
		const shortcode = button.dataset.lfgCopy || '';
		const originalTitle = button.title;
		const feedback = button.querySelector('[data-lfg-copy-feedback]');
		const originalFeedback = feedback?.textContent || '';
		let copied = this.copyWithSelection(shortcode);
		if (!copied && navigator.clipboard?.writeText) {
			try {
				await navigator.clipboard.writeText(shortcode);
				copied = true;
			} catch { copied = false; }
		}

		const message = copied ? this.config.copied : this.config.copyFailed;
		button.title = message;
		button.setAttribute('aria-label', message);
		if (feedback) feedback.textContent = message;
		button.classList.toggle('is-copied', copied);
		button.classList.toggle('is-copy-error', !copied);
		window.setTimeout(() => {
			button.title = originalTitle;
			button.setAttribute('aria-label', originalTitle);
			if (feedback) feedback.textContent = originalFeedback;
			button.classList.remove('is-copied', 'is-copy-error');
		}, 1500);
	}

	copyWithSelection(value) {
		if (!value || typeof document.execCommand !== 'function') return false;
		const textarea = document.createElement('textarea');
		textarea.value = value;
		textarea.readOnly = true;
		textarea.style.position = 'fixed';
		textarea.style.opacity = '0';
		textarea.style.pointerEvents = 'none';
		document.body.append(textarea);
		textarea.select();
		textarea.setSelectionRange(0, textarea.value.length);
		let copied = false;
		try { copied = document.execCommand('copy'); } catch { copied = false; }
		textarea.remove();
		return copied;
	}

	async testConnector(button) {
		const output = button.parentElement.querySelector('.lfg-test-result');
		if (!output) return;
		button.disabled = true;
		output.textContent = this.config.testing;
		const controller = new AbortController();
		const timeout = window.setTimeout(() => controller.abort(), 15000);
		try {
			const body = new URLSearchParams({ action: 'leadforms_go_test_connector', nonce: this.config.nonce, connector: button.dataset.lfgTest });
			if (button.dataset.lfgTest === 'sheets') {
				const section = button.closest('.lfg-settings');
				['spreadsheet_id', 'sheet_name', 'fields_order'].forEach((key) => {
					const input = section?.querySelector(`[name="leadforms_go_settings[sheets][${key}]"]`);
					if (input) body.append(key, input.value);
				});
				const enabled = section?.querySelector('[name="leadforms_go_settings[sheets][enabled]"]');
				body.append('enabled', enabled?.checked ? '1' : '');
			}
			const response = await fetch(this.config.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body, signal: controller.signal });
			const result = await response.json();
			output.textContent = result?.data?.message || this.config.requestFailed;
			output.className = `lfg-test-result ${response.ok && result.success ? 'is-success' : 'is-error'}`;
		} catch {
			output.textContent = this.config.requestFailed;
			output.className = 'lfg-test-result is-error';
		} finally {
			window.clearTimeout(timeout);
			button.disabled = false;
		}
	}

	async uploadGoogleCredentials(button) {
		const container = button.closest('.lfg-google-connect__step');
		const input = container?.querySelector('[data-lfg-google-file]');
		const output = container?.querySelector('[data-lfg-google-result]');
		const file = input?.files?.[0];
		if (!file || !output) {
			if (output) { output.textContent = this.config.selectGoogleJson; output.className = 'lfg-google-action-result is-error'; }
			return;
		}
		if (file.size < 1 || file.size > 131072 || !file.name.toLowerCase().endsWith('.json')) {
			output.textContent = this.config.invalidGoogleJson;
			output.className = 'lfg-google-action-result is-error';
			return;
		}
		button.disabled = true;
		output.textContent = this.config.testing;
		output.className = 'lfg-google-action-result';
		const body = new FormData();
		body.append('action', 'leadforms_go_upload_google_credentials');
		body.append('nonce', this.config.nonce);
		body.append('credentials', file);
		try {
			const response = await fetch(this.config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
			const result = await response.json();
			if (!response.ok || !result.success) throw new Error(result?.data?.message || this.config.requestFailed);
			output.textContent = result.data.message;
			output.className = 'lfg-google-action-result is-success';
			window.setTimeout(() => window.location.reload(), 700);
		} catch (error) {
			output.textContent = error instanceof Error ? error.message : this.config.requestFailed;
			output.className = 'lfg-google-action-result is-error';
			button.disabled = false;
		}
	}

	async removeGoogleCredentials(button) {
		if (!window.confirm(this.config.confirmGoogleRemove)) return;
		const container = button.closest('.lfg-google-connect__step');
		const output = container?.querySelector('[data-lfg-google-result]');
		if (!output) return;
		button.disabled = true;
		const body = new URLSearchParams({ action: 'leadforms_go_remove_google_credentials', nonce: this.config.nonce });
		try {
			const response = await fetch(this.config.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body });
			const result = await response.json();
			if (!response.ok || !result.success) throw new Error(result?.data?.message || this.config.requestFailed);
			output.textContent = result.data.message;
			output.className = 'lfg-google-action-result is-success';
			window.setTimeout(() => window.location.reload(), 500);
		} catch (error) {
			output.textContent = error instanceof Error ? error.message : this.config.requestFailed;
			output.className = 'lfg-google-action-result is-error';
			button.disabled = false;
		}
	}
}

if (window.leadFormsGoAdmin) new LeadFormsGoAdmin(window.leadFormsGoAdmin).init();
