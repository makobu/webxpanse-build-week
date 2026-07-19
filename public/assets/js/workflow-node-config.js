/**
 * Advanced Node Configuration Panel
 * Rich configuration forms for workflow nodes
 */

class NodeConfigPanel {
    constructor(builder) {
        this.builder = builder;
        this.panel = null;
    }
    
    /**
     * Show configuration panel for a node
     */
    show(node) {
        this.hide();
        
        const panel = document.createElement('div');
        panel.id = 'node-config-panel';
        panel.style.cssText = `
            position: fixed;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            width: 400px;
            max-height: 80vh;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            z-index: 1000;
            overflow-y: auto;
        `;
        
        // Get node definition
        const nodeDef = window.WorkflowNodeDefinitions ? 
            window.WorkflowNodeDefinitions.getNode(node.key || node.type) : null;
        
        // Header
        const header = document.createElement('div');
        header.style.cssText = 'margin-bottom: 20px; padding-bottom: 12px; border-bottom: 1px solid #dee2e6;';
        header.innerHTML = `
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 24px;">${nodeDef?.icon || '⚙️'}</span>
                <div>
                    <h3 style="margin: 0; color: ${node.color}; font-size: 18px;">${nodeDef?.label || node.type}</h3>
                    <p style="margin: 4px 0 0 0; color: #666; font-size: 12px;">${nodeDef?.description || 'Configure node settings'}</p>
                </div>
            </div>
        `;
        panel.appendChild(header);
        
        // Configuration form
        const form = document.createElement('form');
        form.id = 'node-config-form';
        form.style.cssText = 'display: flex; flex-direction: column; gap: 16px;';
        
        // Load form fields asynchronously
        const loadFormFields = async () => {
            // Ensure options are loaded
            if (window.WorkflowNodeDefinitions && !window.WorkflowNodeDefinitions.optionsLoaded) {
                await window.WorkflowNodeDefinitions.loadOptions();
            }
            
            if (nodeDef && nodeDef.config && nodeDef.config.fields) {
                for (const field of nodeDef.config.fields) {
                    const fieldEl = await this.createField(field, node.data[field.name] || field.default || '');
                    form.appendChild(fieldEl);
                }
            } else {
                // Fallback configuration
                form.appendChild(this.createBasicConfig(node));
            }

            if (this.isWorkflowWebhookTrigger(node)) {
                form.appendChild(this.createWorkflowTriggerExamples(node));
            }

            this.bindEmailTemplatePicker(form);
            this.populateSelectedEmailTemplate(form, { onlyEmpty: true });
        };
        
        loadFormFields();
        
        panel.appendChild(form);
        
        // Actions
        const actions = document.createElement('div');
        actions.style.cssText = 'display: flex; gap: 8px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #dee2e6;';
        
        const saveBtn = document.createElement('button');
        saveBtn.textContent = 'Save';
        saveBtn.type = 'button';
        saveBtn.style.cssText = `
            flex: 1;
            padding: 10px;
            background: #0066cc;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
        `;
        saveBtn.addEventListener('click', () => {
            this.saveConfig(node, form);
        });
        
        const cancelBtn = document.createElement('button');
        cancelBtn.textContent = 'Cancel';
        cancelBtn.type = 'button';
        cancelBtn.style.cssText = `
            flex: 1;
            padding: 10px;
            background: #f8f9fa;
            color: #333;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            cursor: pointer;
        `;
        cancelBtn.addEventListener('click', () => {
            this.hide();
        });
        
        actions.appendChild(saveBtn);
        actions.appendChild(cancelBtn);
        panel.appendChild(actions);
        
        document.body.appendChild(panel);
        this.panel = panel;
    }
    
    async createField(field, value) {
        const container = document.createElement('div');
        container.style.cssText = 'display: flex; flex-direction: column; gap: 6px;';
        
        const label = document.createElement('label');
        label.textContent = field.label + (field.required ? ' *' : '');
        label.style.cssText = 'font-weight: 500; font-size: 14px; color: #333;';
        container.appendChild(label);
        
        let input;
        
        switch(field.type) {
            case 'select':
                input = document.createElement('select');
                input.name = field.name;
                input.required = field.required || false;
                input.style.cssText = 'padding: 8px; border: 1px solid #dee2e6; border-radius: 4px; font-size: 14px;';
                
                // Load options if not already loaded
                if (window.WorkflowNodeDefinitions && !window.WorkflowNodeDefinitions.optionsLoaded) {
                    await window.WorkflowNodeDefinitions.loadOptions();
                }

                if (
                    (!field.options || field.options.length === 0) &&
                    window.WorkflowNodeDefinitions &&
                    typeof window.WorkflowNodeDefinitions.getNode === 'function'
                ) {
                    const refreshedNodeDef = window.WorkflowNodeDefinitions.getNode(field.name === 'template_id' ? 'send_email' : (this.builder?.selectedNode?.key || this.builder?.selectedNode?.type || ''));
                    const refreshedField = refreshedNodeDef?.config?.fields?.find(candidate => candidate.name === field.name);
                    if (refreshedField && Array.isArray(refreshedField.options)) {
                        field.options = refreshedField.options;
                    }
                }
                
                if (field.options && Array.isArray(field.options)) {
                    const placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = field.options.length ? 'Select an option' : 'No options available';
                    input.appendChild(placeholder);

                    field.options.forEach(option => {
                        const opt = document.createElement('option');
                        opt.value = option.value ?? option;
                        opt.textContent = option.label || option;
                        if (option && typeof option === 'object') {
                            opt.dataset.subject = option.subject || '';
                            opt.dataset.bodyText = option.body_text || '';
                            opt.dataset.bodyHtml = option.body_html || '';
                        }
                        if (value !== undefined && value !== null && String(value) === String(opt.value)) {
                            opt.selected = true;
                        }
                        input.appendChild(opt);
                    });
                } else {
                    const placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = 'No options available';
                    input.appendChild(placeholder);
                }

                if (field.options && field.options.length === 0 && field.name) {
                    // Try to get options from node definitions
                    const nodeDefs = window.WorkflowNodeDefinitions;
                    if (nodeDefs) {
                        // Options should be populated by loadOptions()
                        console.warn('No options available for field:', field.name);
                    }
                }
                break;
                
            case 'textarea':
                input = document.createElement('textarea');
                input.name = field.name;
                input.value = value || '';
                input.required = field.required || false;
                input.rows = 4;
                input.style.cssText = 'padding: 8px; border: 1px solid #dee2e6; border-radius: 4px; font-size: 14px; resize: vertical;';
                break;
                
            case 'number':
                input = document.createElement('input');
                input.type = 'number';
                input.name = field.name;
                input.value = value || field.default || 0;
                input.required = field.required || false;
                if (field.min !== undefined) input.min = field.min;
                if (field.max !== undefined) input.max = field.max;
                input.style.cssText = 'padding: 8px; border: 1px solid #dee2e6; border-radius: 4px; font-size: 14px;';
                break;
                
            case 'time':
                input = document.createElement('input');
                input.type = 'time';
                input.name = field.name;
                input.value = value || '';
                input.required = field.required || false;
                input.style.cssText = 'padding: 8px; border: 1px solid #dee2e6; border-radius: 4px; font-size: 14px;';
                break;
                
            case 'date':
                input = document.createElement('input');
                input.type = 'date';
                input.name = field.name;
                input.value = value || '';
                input.required = field.required || false;
                input.style.cssText = 'padding: 8px; border: 1px solid #dee2e6; border-radius: 4px; font-size: 14px;';
                break;
                
            default:
                input = document.createElement('input');
                input.type = 'text';
                input.name = field.name;
                input.value = value || '';
                input.required = field.required || false;
                input.placeholder = field.placeholder || '';
                input.style.cssText = 'padding: 8px; border: 1px solid #dee2e6; border-radius: 4px; font-size: 14px;';
        }
        
        container.appendChild(input);
        
        if (field.help) {
            const help = document.createElement('small');
            help.textContent = field.help;
            help.style.cssText = 'color: #666; font-size: 12px;';
            container.appendChild(help);
        }
        
        return container;
    }

    isWorkflowWebhookTrigger(node) {
        const triggerType = (node.data && node.data.type) || node.key || '';
        return node.type === 'trigger' && ['webhook_received', 'api_call'].includes(triggerType);
    }

    createWorkflowTriggerExamples(node) {
        const container = document.createElement('div');
        container.style.cssText = `
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 12px;
            border: 1px solid #dbe3ef;
            border-radius: 6px;
            background: #f8fafc;
        `;

        const title = document.createElement('div');
        title.style.cssText = 'font-weight: 600; font-size: 13px; color: #1f2937;';
        title.textContent = 'Integration examples';
        container.appendChild(title);

        const endpointUrl = new URL('../api/webhooks/workflow_trigger.php', window.location.href).href;
        const workflowId = typeof currentWorkflowId !== 'undefined' && currentWorkflowId ? currentWorkflowId : '<workflow_id>';
        const triggerKey = String((node.data && node.data.trigger_key) || '').trim() || 'pricing-demo-request';

        const triggerKeyPayload = {
            trigger_key: triggerKey,
            email: 'lead@example.com',
            source: 'landing_page',
            event: 'demo_requested',
            data: { budget: '50000' }
        };

        const workflowIdPayload = {
            workflow_id: workflowId,
            contact_id: 456,
            source: 'landing_page',
            event: 'demo_requested',
            data: { budget: '50000' }
        };

        container.appendChild(this.createCopyExample('Trigger URL', endpointUrl));
        container.appendChild(this.createCopyExample('API key header', 'Authorization: Bearer <crm_api_key>'));
        container.appendChild(this.createCopyExample('Alternate header', 'X-API-Key: <crm_api_key>'));
        container.appendChild(this.createCopyExample('Trigger key JSON', JSON.stringify(triggerKeyPayload, null, 2), true));
        container.appendChild(this.createCopyExample('Workflow ID JSON', JSON.stringify(workflowIdPayload, null, 2), true));

        return container;
    }

    createCopyExample(labelText, value, multiline = false) {
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'display: flex; flex-direction: column; gap: 4px;';

        const label = document.createElement('label');
        label.textContent = labelText;
        label.style.cssText = 'font-weight: 500; font-size: 12px; color: #4b5563;';
        wrapper.appendChild(label);

        const row = document.createElement('div');
        row.style.cssText = 'display: flex; gap: 6px; align-items: stretch;';

        const field = multiline ? document.createElement('textarea') : document.createElement('input');
        field.readOnly = true;
        field.value = value;
        if (multiline) {
            field.rows = 6;
        } else {
            field.type = 'text';
        }
        field.style.cssText = `
            flex: 1;
            min-width: 0;
            padding: 7px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            background: white;
            color: #111827;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            resize: vertical;
        `;

        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Copy';
        button.style.cssText = `
            width: 58px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            background: #fff;
            color: #1f2937;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        `;
        button.addEventListener('click', async () => {
            await this.copyText(value);
            const original = button.textContent;
            button.textContent = 'Copied';
            window.setTimeout(() => {
                button.textContent = original;
            }, 1200);
        });

        row.appendChild(field);
        row.appendChild(button);
        wrapper.appendChild(row);
        return wrapper;
    }

    async copyText(value) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(value);
            return;
        }

        const textarea = document.createElement('textarea');
        textarea.value = value;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
    }

    bindEmailTemplatePicker(form) {
        const templateSelect = form.querySelector('select[name="template_id"]');
        if (!templateSelect || templateSelect.dataset.templateBinding === '1') {
            return;
        }

        templateSelect.dataset.templateBinding = '1';
        templateSelect.addEventListener('change', () => {
            this.populateSelectedEmailTemplate(form, { onlyEmpty: false });
        });
    }

    populateSelectedEmailTemplate(form, options = {}) {
        const templateSelect = form.querySelector('select[name="template_id"]');
        const subjectInput = form.querySelector('[name="subject"]');
        const bodyInput = form.querySelector('[name="body"]');

        if (!templateSelect || (!subjectInput && !bodyInput)) {
            return;
        }

        const selected = templateSelect.selectedOptions && templateSelect.selectedOptions[0];
        if (!selected || selected.value === '') {
            return;
        }

        const subject = selected.dataset.subject || '';
        const body = selected.dataset.bodyText || this.htmlToText(selected.dataset.bodyHtml || '');
        const onlyEmpty = Boolean(options.onlyEmpty);

        if (subjectInput && subject !== '' && (!onlyEmpty || subjectInput.value.trim() === '')) {
            subjectInput.value = subject;
        }

        if (bodyInput && body !== '' && (!onlyEmpty || bodyInput.value.trim() === '')) {
            bodyInput.value = body;
        }
    }

    htmlToText(html) {
        if (!html) {
            return '';
        }

        const scratch = document.createElement('div');
        scratch.innerHTML = html
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/<\/p>/gi, '\n\n')
            .replace(/<\/div>/gi, '\n');
        return (scratch.textContent || scratch.innerText || '')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }
    
    createBasicConfig(node) {
        const container = document.createElement('div');
        
        if (node.type === 'trigger') {
            const select = document.createElement('select');
            select.name = 'type';
            select.style.cssText = 'padding: 8px; border: 1px solid #dee2e6; border-radius: 4px; width: 100%;';
            
            if (window.WorkflowNodeDefinitions) {
                Object.keys(window.WorkflowNodeDefinitions.triggers).forEach(key => {
                    const def = window.WorkflowNodeDefinitions.triggers[key];
                    const opt = document.createElement('option');
                    opt.value = key;
                    opt.textContent = def.label;
                    if (node.data.type === key) opt.selected = true;
                    select.appendChild(opt);
                });
            }
            
            container.appendChild(select);
        } else if (node.type === 'action') {
            const select = document.createElement('select');
            select.name = 'type';
            select.style.cssText = 'padding: 8px; border: 1px solid #dee2e6; border-radius: 4px; width: 100%; margin-bottom: 12px;';
            
            if (window.WorkflowNodeDefinitions) {
                Object.keys(window.WorkflowNodeDefinitions.actions).forEach(key => {
                    const def = window.WorkflowNodeDefinitions.actions[key];
                    const opt = document.createElement('option');
                    opt.value = key;
                    opt.textContent = def.label;
                    if (node.data.type === key) opt.selected = true;
                    select.appendChild(opt);
                });
            }
            
            container.appendChild(select);
        }
        
        return container;
    }
    
    saveConfig(node, form) {
        const formData = new FormData(form);
        const updates = {};
        
        for (const [key, value] of formData.entries()) {
            const oldValue = node.data[key];
            updates[key] = { oldValue, newValue: value };
        }
        
        // Apply updates via history
        Object.keys(updates).forEach(field => {
            const { oldValue, newValue } = updates[field];
            const command = new UpdateNodeDataCommand(this.builder, node, field, oldValue, newValue);
            this.builder.historyManager.execute(command);
        });
        
        this.hide();
    }
    
    hide() {
        if (this.panel) {
            this.panel.remove();
            this.panel = null;
        }
    }
}

window.NodeConfigPanel = NodeConfigPanel;
