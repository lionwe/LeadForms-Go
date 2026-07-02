class LeadFormsGoAdmin {
	constructor(config) {
		this.config = config;
		this.schema = [];
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
		if (!this.modeInput || !this.schemaInput || !this.canvas) return;
		try { this.schema = JSON.parse(this.schemaInput.value || '[]'); } catch { this.schema = []; }
		if (!Array.isArray(this.schema)) this.schema = [];
		this.hydrateFieldKeys();
		this.setMode(this.modeInput.value || 'visual');
		this.renderBuilder();
		this.submitLabelInput?.addEventListener('input', () => this.syncCodePreview());
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
		const button = event.target.closest('[data-lfg-test]');
		if (button) await this.testConnector(button);
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
		this.schema.push({ ...template, key: tile.dataset.lfgAdd });
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
		this.syncCodePreview();
	}

	createFieldCard(field, index) {
		const card = document.createElement('article');
		card.className = 'lfg-builder-field';
		card.dataset.lfgFieldIndex = String(index);
		const header = document.createElement('header');
		const toggle = document.createElement('button');
		toggle.type = 'button'; toggle.className = 'lfg-builder-field__toggle'; toggle.dataset.lfgFieldToggle = '';
		toggle.setAttribute('aria-expanded', 'false');
		const title = document.createElement('strong'); title.textContent = field.label || field.name;
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
			['name', this.config.builder.fieldName, this.config.builder.fieldNameHelp],
			['placeholder', this.config.builder.placeholder, this.config.builder.placeholderHelp],
		].forEach(([property, labelText, helpText]) => {
			const label = document.createElement('label');
			const span = document.createElement('strong'); span.textContent = labelText;
			const help = document.createElement('small'); help.textContent = helpText;
			const input = document.createElement('input'); input.type = 'text'; input.value = field[property] || '';
			input.addEventListener('input', () => { field[property] = input.value; if (property === 'label') title.textContent = input.value; this.syncSchema(); this.syncCodePreview(); });
			label.append(span, help, input); fields.append(label);
		});
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

	syncSchema() { if (this.schemaInput) this.schemaInput.value = JSON.stringify(this.schema); }

	syncCodePreview() {
		if (this.codeInput && this.schema.length) this.codeInput.value = this.generateCode();
	}

	generateCode() {
		const escape = (value) => String(value || '').replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
		const counts = {};
		const lines = ['<form>'];
		this.schema.forEach((field) => {
			const base = String(field.key || field.type || 'field').replaceAll('_', '-').replace(/[^a-z0-9-]/gi, '') || 'field';
			counts[base] = (counts[base] || 0) + 1;
			const id = `lfg-${base}${counts[base] > 1 ? `-${counts[base]}` : ''}`;
			const required = field.required ? ' required' : '';
			const mark = field.required ? '*' : '';
			if (field.type === 'checkbox') {
				lines.push(`  <label class="leadforms-go-checkbox" for="${id}">`);
				lines.push(`    <input id="${id}" type="checkbox" name="${escape(field.name)}" value="Так"${required}>`);
				lines.push(`    <span class="leadforms-go-checkbox__label">${escape(field.label)}${mark}</span>`);
				lines.push('  </label>');
				return;
			}
			lines.push(`  <label for="${id}">`);
			lines.push(`    <span>${escape(field.label)}${mark}</span>`);
			if (field.type === 'textarea') lines.push(`    <textarea id="${id}" name="${escape(field.name)}" placeholder="${escape(field.placeholder)}"${required}></textarea>`);
			else {
				const mask = field.type === 'tel' && field.mask ? ` data-mask="${escape(field.mask)}" data-min-length="12"` : '';
				lines.push(`    <input id="${id}" type="${escape(field.type)}" name="${escape(field.name)}" placeholder="${escape(field.placeholder)}"${mask}${required}>`);
			}
			lines.push('  </label>');
		});
		lines.push('  <button class="btn btn--primary" type="submit">');
		lines.push(`    <span class="btn__text">${escape(this.submitLabelInput?.value || 'Надіслати')}</span>`);
		lines.push('  </button>');
		lines.push('</form>');
		return lines.join('\n');
	}

	async copyShortcode(button) {
		try {
			await navigator.clipboard.writeText(button.dataset.lfgCopy);
			const original = button.title;
			button.title = this.config.copied;
			button.classList.add('is-copied');
			window.setTimeout(() => { button.title = original; button.classList.remove('is-copied'); }, 1500);
		} catch { /* Clipboard access can be blocked by browser policy. */ }
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
}

if (window.leadFormsGoAdmin) new LeadFormsGoAdmin(window.leadFormsGoAdmin).init();
