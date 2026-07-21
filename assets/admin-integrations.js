class LeadFormsGoIntegrations {
	constructor(config) {
		this.config = config;
		this.root = document.querySelector('[data-lfg-integrations]');
		this.input = this.root?.querySelector('[data-lfg-routing-config]');
		this.schemaInput = document.querySelector('[data-lfg-schema]');
		this.form = this.root?.closest('form');
		this.currentLocale = document.querySelector('[data-lfg-default-locale]')?.value || 'uk_UA';
	}

	init() {
		if (!this.root || !this.input) return;
		try { this.routes = JSON.parse(this.input.value || '{}'); } catch { this.routes = {}; }
		try { this.locales = JSON.parse(this.root.dataset.locales || '{}'); } catch { this.locales = {}; }
		try { this.variables = JSON.parse(this.root.dataset.variables || '[]'); } catch { this.variables = []; }
		if (!this.routes || Array.isArray(this.routes)) this.routes = {};
		if (!Array.isArray(this.variables)) this.variables = [];
		this.bind();
		this.renderButtons();
		this.renderColumns();
		this.renderCrmMapping();
		this.updateLocale(this.currentLocale);
		this.sync();
	}

	bind() {
		document.addEventListener('click', (event) => this.handleClick(event));
		this.root.addEventListener('input', (event) => this.handleInput(event));
		this.root.addEventListener('change', (event) => this.handleInput(event));
		this.form?.addEventListener('submit', () => this.sync());
		document.querySelectorAll('[data-lfg-locale]').forEach((button) => button.addEventListener('click', () => this.updateLocale(button.dataset.lfgLocale)));
	}

	async handleClick(event) {
		const integrationsTab = event.target.closest('[data-lfg-integrations-tab]');
		if (integrationsTab) { this.showIntegrations(integrationsTab); return; }
		const routeToggle = event.target.closest('[data-lfg-route-toggle]');
		if (routeToggle) { this.toggleRoute(routeToggle); return; }
		if (event.target.closest('[data-lfg-mode]')) this.hideIntegrations();
		const addButton = event.target.closest('[data-lfg-add-telegram-button]');
		if (addButton) this.addTelegramButton(addButton.closest('[data-lfg-telegram-buttons]')?.dataset.lfgTelegramButtons);
		const removeButton = event.target.closest('[data-lfg-remove-telegram-button]');
		if (removeButton) { removeButton.closest('.lfg-telegram-button-row')?.remove(); this.collectButtons(); }
		const addColumn = event.target.closest('[data-lfg-add-column]');
		if (addColumn) { this.routes.sheets.columns ||= []; this.routes.sheets.columns.push(this.defaultColumn()); this.renderColumns(); }
		const columnAction = event.target.closest('[data-lfg-column-action]');
		if (columnAction) this.columnAction(columnAction);
		if (event.target.closest('[data-lfg-add-crm-mapping]')) { this.collectCrmMapping(); this.routes.crm.mapping[`field_${Date.now()}`]=this.variables[0]||''; this.renderCrmMapping(); }
		const removeMapping = event.target.closest('[data-lfg-remove-crm-mapping]');
		if (removeMapping) { removeMapping.closest('.lfg-crm-mapping-row')?.remove(); this.collectCrmMapping(); }
		const test = event.target.closest('[data-lfg-test-route]');
		if (test) await this.testRoute(test);
		if (event.target.closest('[data-lfg-sheets-list]')) await this.listSheets();
		if (event.target.closest('[data-lfg-sheets-create]')) await this.createSheet();
		if (event.target.closest('[data-lfg-sheets-headers]')) await this.writeHeaders();
	}

	handleInput(event) {
		const profile = event.target.closest('[data-lfg-profile]');
		if (profile) {
			const connector = profile.dataset.lfgProfile;
			const ids = [...this.root.querySelectorAll(`[data-lfg-profile="${connector}"]:checked`)].map((input) => input.value);
			this.setPath(`${connector}.profile_ids`, ids);
			this.sync();
			return;
		}
		const input = event.target.closest('[data-lfg-route-input]');
		if (input) {
			let value = input.value;
			if (input.type === 'number') value = Number.parseInt(value || '0', 10) || 0;
			this.setPath(input.dataset.lfgRouteInput, value);
			this.sync();
			this.previewTelegram();
		}
		if (event.target.closest('.lfg-sheet-column-row')) this.collectColumns();
		if (event.target.closest('.lfg-telegram-button-row')) this.collectButtons();
		if (event.target.closest('.lfg-crm-mapping-row')) this.collectCrmMapping();
	}

	showIntegrations(tab) {
		document.querySelectorAll('[data-lfg-panel]').forEach((panel) => { panel.hidden = true; });
		const panel = document.querySelector('[data-lfg-integrations-panel]');
		if (panel) panel.hidden = false;
		document.querySelectorAll('.lfg-editor-tab').forEach((button) => { button.classList.toggle('is-active', button === tab); button.setAttribute('aria-selected', String(button === tab)); });
	}

	hideIntegrations() {
		const panel = document.querySelector('[data-lfg-integrations-panel]');
		if (panel) panel.hidden = true;
	}

	toggleRoute(button) {
		const card = button.closest('[data-lfg-route]');
		const body = card?.querySelector('.lfg-route-card__body');
		if (!body) return;
		const expanded = button.getAttribute('aria-expanded') === 'true';
		button.setAttribute('aria-expanded', String(!expanded));
		body.hidden = expanded;
	}

	updateLocale(locale) {
		if (!locale) return;
		this.currentLocale = locale;
		this.root.querySelectorAll('[data-lfg-route-locale]').forEach((panel) => { panel.hidden = panel.dataset.lfgRouteLocale !== locale; });
		this.previewTelegram();
	}

	renderButtons() {
		this.root.querySelectorAll('[data-lfg-telegram-buttons]').forEach((container) => {
			const locale = container.dataset.lfgTelegramButtons;
			const rows = container.querySelector('[data-lfg-button-rows]');
			rows.replaceChildren();
			(this.routes.telegram?.buttons?.[locale] || []).forEach((button) => rows.append(this.buttonRow(button)));
		});
	}

	buttonRow(button = {}) {
		const row = document.createElement('div'); row.className = 'lfg-telegram-button-row';
		const label = document.createElement('input'); label.type = 'text'; label.placeholder = 'Текст кнопки'; label.value = button.label || '';
		const url = document.createElement('input'); url.type = 'url'; url.placeholder = 'https://example.com/{first_name}'; url.value = button.url || '';
		const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button-link-delete'; remove.dataset.lfgRemoveTelegramButton = ''; remove.textContent = '×'; remove.setAttribute('aria-label', 'Видалити кнопку');
		row.append(label, url, remove); return row;
	}

	addTelegramButton(locale) {
		if (!locale) return;
		const rows = this.root.querySelector(`[data-lfg-telegram-buttons="${CSS.escape(locale)}"] [data-lfg-button-rows]`);
		if (!rows || rows.children.length >= 5) return;
		rows.append(this.buttonRow()); this.collectButtons();
	}

	collectButtons() {
		this.routes.telegram ||= {}; this.routes.telegram.buttons ||= {};
		this.root.querySelectorAll('[data-lfg-telegram-buttons]').forEach((container) => {
			this.routes.telegram.buttons[container.dataset.lfgTelegramButtons] = [...container.querySelectorAll('.lfg-telegram-button-row')].map((row) => ({ label: row.children[0].value.trim(), url: row.children[1].value.trim() })).filter((button) => button.label && button.url).slice(0, 5);
		});
		this.sync();
	}

	renderColumns() {
		this.routes.sheets ||= {}; this.routes.sheets.columns ||= [];
		if (!this.routes.sheets.columns.length) {
			let schema = []; try { schema = JSON.parse(this.schemaInput?.value || '[]'); } catch { schema = []; }
			this.routes.sheets.columns = schema.map((field, index) => ({ id: `column_${index}`, header: field.label || field.key, type: 'field', source: field.key, value: '' }));
		}
		const rows = this.root.querySelector('[data-lfg-column-rows]'); if (!rows) return;
		rows.replaceChildren(); this.routes.sheets.columns.forEach((column, index) => rows.append(this.columnRow(column, index)));
		this.sync();
	}

	columnRow(column, index) {
		const row = document.createElement('div'); row.className = 'lfg-sheet-column-row'; row.draggable = true; row.dataset.index = String(index);
		row.addEventListener('dragstart', () => { this.dragIndex = index; });
		row.addEventListener('dragover', (event) => event.preventDefault());
		row.addEventListener('drop', (event) => { event.preventDefault(); this.moveColumn(this.dragIndex, index); });
		const handle = document.createElement('span'); handle.className = 'dashicons dashicons-move'; handle.title = 'Перетягнути';
		const header = document.createElement('input'); header.type = 'text'; header.value = column.header || ''; header.placeholder = 'Заголовок'; header.dataset.columnField = 'header';
		const type = document.createElement('select'); type.dataset.columnField = 'type'; [['field','Поле форми'],['system','Системна змінна'],['static','Статичне значення']].forEach(([value,label]) => { const option = document.createElement('option'); option.value=value; option.textContent=label; option.selected=column.type===value; type.append(option); });
		const source = document.createElement('select'); source.dataset.columnField = 'source'; this.variables.forEach((variable) => { const option=document.createElement('option'); option.value=variable; option.textContent=variable; option.selected=column.source===variable; source.append(option); });
		const value = document.createElement('input'); value.type='text'; value.value=column.value || ''; value.placeholder='Значення'; value.dataset.columnField='value';
		const actions = document.createElement('div'); actions.className='lfg-column-actions'; ['up','down','remove'].forEach((action) => { const button=document.createElement('button'); button.type='button'; button.className=action==='remove'?'button-link-delete':'button'; button.dataset.lfgColumnAction=action; button.textContent=action==='up'?'↑':action==='down'?'↓':'×'; actions.append(button); });
		row.append(handle, header, type, source, value, actions); return row;
	}

	collectColumns() {
		this.routes.sheets.columns = [...this.root.querySelectorAll('.lfg-sheet-column-row')].map((row, index) => { const get=(name)=>row.querySelector(`[data-column-field="${name}"]`)?.value || ''; const type=get('type'); return {id:`column_${index}`,header:get('header').trim(),type,source:type==='static'?'':get('source'),value:type==='static'?get('value').trim():''}; }).filter((column)=>column.header);
		this.sync();
	}

	columnAction(button) {
		this.collectColumns(); const row=button.closest('.lfg-sheet-column-row'); const index=Number.parseInt(row?.dataset.index || '-1',10); const action=button.dataset.lfgColumnAction;
		if(action==='remove') this.routes.sheets.columns.splice(index,1);
		if(action==='up'&&index>0) this.moveColumn(index,index-1,false);
		if(action==='down'&&index<this.routes.sheets.columns.length-1) this.moveColumn(index,index+1,false);
		this.renderColumns();
	}

	moveColumn(from, to, render=true) { if(!Number.isInteger(from)||from<0||to<0||from===to)return; const [item]=this.routes.sheets.columns.splice(from,1); if(!item)return; this.routes.sheets.columns.splice(to,0,item); if(render)this.renderColumns(); }
	defaultColumn() { return {id:`column_${Date.now()}`,header:'',type:'field',source:this.variables[0]||'',value:''}; }

	renderCrmMapping() {
		this.routes.crm ||= {}; this.routes.crm.mapping ||= {};
		const rows=this.root.querySelector('[data-lfg-crm-mapping-rows]'); if(!rows)return;
		rows.replaceChildren(); Object.entries(this.routes.crm.mapping).forEach(([target,source])=>{
			const row=document.createElement('div'); row.className='lfg-crm-mapping-row';
			const targetInput=document.createElement('input'); targetInput.type='text'; targetInput.value=target; targetInput.placeholder='CRM field'; targetInput.dataset.crmTarget='';
			const sourceSelect=document.createElement('select'); sourceSelect.dataset.crmSource=''; this.variables.forEach((variable)=>{const option=document.createElement('option');option.value=variable;option.textContent=variable;option.selected=variable===source;sourceSelect.append(option);});
			const remove=document.createElement('button');remove.type='button';remove.className='button-link-delete';remove.dataset.lfgRemoveCrmMapping='';remove.textContent='×';remove.setAttribute('aria-label','Видалити mapping');
			row.append(targetInput,sourceSelect,remove);rows.append(row);
		}); this.previewPayloads();
	}

	collectCrmMapping() {
		this.routes.crm ||= {}; const mapping={};
		this.root.querySelectorAll('.lfg-crm-mapping-row').forEach((row)=>{const target=row.querySelector('[data-crm-target]')?.value.trim().toLowerCase().replace(/[^a-z0-9_-]/g,'_')||'';const source=row.querySelector('[data-crm-source]')?.value||'';if(target&&source)mapping[target]=source;});
		this.routes.crm.mapping=mapping;this.sync();
	}

	previewTelegram() {
		const template = this.routes.telegram?.templates?.[this.currentLocale] || this.routes.telegram?.templates?.[document.querySelector('[data-lfg-default-locale]')?.value] || '';
		const values = this.testPayload();
		const text = String(template).replace(/\{([a-zA-Z0-9_]+)\}/g, (_, key) => values[key] || '');
		const preview = this.root.querySelector('[data-lfg-telegram-preview]'); if(preview) preview.textContent=text || 'Нова заявка з форми: …';
	}

	previewPayloads() {
		const values=this.testPayload();
		const sheets={}; (this.routes.sheets?.columns||[]).forEach((column)=>{sheets[column.header||column.source]=column.type==='static'?(column.value||''):(values[column.source]||'');});
		const crm={}; Object.entries(this.routes.crm?.mapping||{}).forEach(([target,source])=>{crm[target]=values[source]||'';});
		const sheetsPreview=this.root.querySelector('[data-lfg-route-payload="sheets"]'); if(sheetsPreview)sheetsPreview.textContent=JSON.stringify(sheets,null,2);
		const crmPreview=this.root.querySelector('[data-lfg-route-payload="crm"]'); if(crmPreview)crmPreview.textContent=JSON.stringify(Object.keys(crm).length?crm:values,null,2);
	}

	testPayload() {
		const payload={};
		this.root.querySelectorAll('[data-lfg-test-value]').forEach((input) => { payload[input.dataset.lfgTestValue] = input.value; });
		return payload;
	}

	async testRoute(button) {
		this.sync(); const result=button.closest('.lfg-route-card')?.querySelector('[data-lfg-route-result]'); button.disabled=true; if(result)result.textContent=this.config.testing;
		try { const data=await this.request('leadforms_go_route_test',{form_id:this.root.dataset.formId,connector:button.dataset.lfgTestRoute,locale:this.currentLocale,routing_config:JSON.stringify(this.routes),payload:JSON.stringify(this.testPayload())}); this.showDeliveryResult(result,data); if(['queued','processing'].includes(data.status))this.poll(data,result); }
		catch(error){if(result)result.textContent=error.message;} finally{button.disabled=false;}
	}

	async poll(data, result) { for(let attempt=0;attempt<10;attempt+=1){ await new Promise((resolve)=>window.setTimeout(resolve,1000)); try{const current=await this.request('leadforms_go_route_status',{delivery_id:data.delivery_id,submission_id:data.submission_id}); this.showDeliveryResult(result,current); if(!['queued','processing'].includes(current.status))return;}catch{return;} } }
	showDeliveryResult(element,data){if(!element)return; element.className=`is-${data.status}`; const reference=data.external_reference?` · ${data.external_reference}`:''; element.textContent=data.status==='sent'?`${this.config.success}${reference}`:`${data.status} · HTTP ${data.http_code||'—'} · ${data.attempts||0} спроб${data.error_message?' · '+data.error_message:''}${reference}`;}

	async listSheets() { const id=this.getPath('sheets.spreadsheet_id')||''; const data=await this.safeRequest('leadforms_go_sheets_list',{spreadsheet_id:id}); if(!data)return; this.populateSheets(data.sheets||[]); }
	async createSheet(){const id=this.getPath('sheets.spreadsheet_id')||'';const name=this.root.querySelector('[data-lfg-new-sheet]')?.value||'';const data=await this.safeRequest('leadforms_go_sheets_create',{spreadsheet_id:id,sheet_name:name});if(data?.sheet){this.populateSheets([data.sheet],data.sheet.title);}}
	async writeHeaders(){this.collectColumns();await this.safeRequest('leadforms_go_sheets_headers',{spreadsheet_id:this.getPath('sheets.spreadsheet_id')||'',sheet_name:this.getPath('sheets.sheet_name')||'',headers:JSON.stringify(this.routes.sheets.columns.map((column)=>column.header))});}
	populateSheets(sheets, selected=''){const select=this.root.querySelector('[data-lfg-sheet-select]');if(!select)return;const current=selected||this.getPath('sheets.sheet_name')||'';select.replaceChildren();sheets.forEach((sheet)=>{const option=document.createElement('option');option.value=sheet.title;option.textContent=sheet.title;option.selected=sheet.title===current;select.append(option);});if(select.value){this.setPath('sheets.sheet_name',select.value);this.sync();}}
	async safeRequest(action,data){try{return await this.request(action,data);}catch(error){window.alert(error.message);return null;}}
	async request(action,data){const response=await fetch(this.config.ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams({action,nonce:this.config.nonce,...data})});const result=await response.json();if(!response.ok||!result.success)throw new Error(result?.data?.message||this.config.requestFailed);return result.data;}

	setPath(path,value){const parts=path.split('.');let target=this.routes;parts.forEach((part,index)=>{if(index===parts.length-1)target[part]=value;else{target[part]||={};target=target[part];}});}
	getPath(path){return path.split('.').reduce((value,key)=>value?.[key],this.routes);}
	sync(){this.collectButtonsSilently();this.input.value=JSON.stringify(this.routes);this.previewPayloads();}
	collectButtonsSilently(){if(!this.routes.telegram)return;this.routes.telegram.buttons||={};this.root.querySelectorAll('[data-lfg-telegram-buttons]').forEach((container)=>{this.routes.telegram.buttons[container.dataset.lfgTelegramButtons]=[...container.querySelectorAll('.lfg-telegram-button-row')].map((row)=>({label:row.children[0].value.trim(),url:row.children[1].value.trim()})).filter((button)=>button.label&&button.url).slice(0,5);});}
}

if (window.leadFormsGoRoutes) new LeadFormsGoIntegrations(window.leadFormsGoRoutes).init();
