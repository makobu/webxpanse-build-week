/**
 * Workflow Validator
 * Validates workflow structure and node configurations
 */

class WorkflowValidator {
    constructor(builder) {
        this.builder = builder;
        this.errors = [];
        this.warnings = [];
    }
    
    validate() {
        this.errors = [];
        this.warnings = [];
        
        // Check for trigger
        const triggers = this.builder.nodes.filter(n => n.type === 'trigger');
        if (triggers.length === 0) {
            this.errors.push({
                type: 'missing_trigger',
                message: 'Workflow must have at least one trigger',
                severity: 'error'
            });
        } else if (triggers.length > 1) {
            this.warnings.push({
                type: 'multiple_triggers',
                message: 'Multiple triggers detected. Only one trigger will be used.',
                severity: 'warning'
            });
        }
        
        // Check for actions
        const actions = this.builder.nodes.filter(n => n.type === 'action' || n.type === 'delay');
        if (actions.length === 0) {
            this.errors.push({
                type: 'missing_actions',
                message: 'Workflow must have at least one action',
                severity: 'error'
            });
        }
        
        // Check for orphaned nodes (nodes without connections)
        this.builder.nodes.forEach(node => {
            if (node.type === 'trigger') return; // Triggers don't need inputs

            const incomingConnections = this.builder.connectionManager.connections.filter(
                c => c.toNodeId === node.id
            );

            if (incomingConnections.length === 0) {
                const label = node.key || node.type || node.id;
                this.warnings.push({
                    type: 'orphaned_node',
                    message: `"${label}" is not connected. Connect it to the workflow.`,
                    nodeId: node.id,
                    severity: 'warning'
                });
            }

            if (node.type === 'condition') {
                const outgoing = this.builder.connectionManager.connections.filter(
                    c => c.fromNodeId === node.id
                );
                const hasYes = outgoing.some(c => c.branchType === 'true');
                const hasNo = outgoing.some(c => c.branchType === 'false');
                if (outgoing.length > 0 && (!hasYes || !hasNo)) {
                    this.warnings.push({
                        type: 'incomplete_condition',
                        message: `Condition node should have both "Yes" and "No" branches connected`,
                        nodeId: node.id,
                        severity: 'warning'
                    });
                }
            }
        });
        
        // Check for cycles (basic check)
        const visited = new Set();
        const recStack = new Set();
        
        const hasCycle = (nodeId) => {
            if (recStack.has(nodeId)) return true;
            if (visited.has(nodeId)) return false;
            
            visited.add(nodeId);
            recStack.add(nodeId);
            
            const connections = this.builder.connectionManager.connections.filter(
                c => c.fromNodeId === nodeId
            );
            
            for (const conn of connections) {
                if (hasCycle(conn.toNodeId)) return true;
            }
            
            recStack.delete(nodeId);
            return false;
        };
        
        triggers.forEach(trigger => {
            if (hasCycle(trigger.id)) {
                this.errors.push({
                    type: 'cycle_detected',
                    message: 'Workflow contains a cycle (circular reference)',
                    severity: 'error'
                });
            }
        });
        
        // Validate node configurations
        this.builder.nodes.forEach(node => {
            const nodeDef = window.WorkflowNodeDefinitions ? 
                window.WorkflowNodeDefinitions.getNode(node.key || node.type) : null;
            
            if (nodeDef && nodeDef.config) {
                nodeDef.config.fields.forEach(field => {
                    if (field.required && !node.data[field.name]) {
                        const nodeLabel = node.key || node.type || 'Node';
                        this.errors.push({
                            type: 'missing_field',
                            message: `"${nodeLabel}" requires ${field.label}`,
                            nodeId: node.id,
                            field: field.name,
                            severity: 'error'
                        });
                    }
                });
            }
        });
        
        return {
            valid: this.errors.length === 0,
            errors: this.errors,
            warnings: this.warnings
        };
    }
    
    showValidationResults() {
        const results = this.validate();
        
        // Remove existing validation panel
        const existing = document.getElementById('workflow-validation-panel');
        if (existing) existing.remove();
        
        const panel = document.createElement('div');
        panel.id = 'workflow-validation-panel';
        panel.style.cssText = `
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 300px;
            max-height: 300px;
            background: white;
            border: 2px solid ${results.valid ? '#28a745' : '#dc3545'};
            border-radius: 8px;
            padding: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            overflow-y: auto;
        `;
        
        const header = document.createElement('div');
        header.style.cssText = 'margin-bottom: 12px; font-weight: 600;';
        header.textContent = results.valid ? '✓ Workflow Valid' : '✗ Validation Errors';
        header.style.color = results.valid ? '#28a745' : '#dc3545';
        panel.appendChild(header);
        
        if (results.errors.length > 0) {
            const errorList = document.createElement('div');
            errorList.style.cssText = 'margin-bottom: 12px;';
            results.errors.forEach(error => {
                const item = document.createElement('div');
                item.style.cssText = 'padding: 8px; background: #fee; border-left: 3px solid #dc3545; margin-bottom: 4px; font-size: 12px;';
                item.textContent = error.message;
                if (error.nodeId) {
                    item.addEventListener('click', () => {
                        const node = this.builder.nodes.find(n => n.id === error.nodeId);
                        if (node) {
                            this.builder.selectNode(node);
                        }
                    });
                    item.style.cursor = 'pointer';
                }
                errorList.appendChild(item);
            });
            panel.appendChild(errorList);
        }
        
        if (results.warnings.length > 0) {
            const warningList = document.createElement('div');
            results.warnings.forEach(warning => {
                const item = document.createElement('div');
                item.style.cssText = 'padding: 8px; background: #fff3cd; border-left: 3px solid #ffc107; margin-bottom: 4px; font-size: 12px;';
                item.textContent = warning.message;
                if (warning.nodeId) {
                    item.addEventListener('click', () => {
                        const node = this.builder.nodes.find(n => n.id === warning.nodeId);
                        if (node) {
                            this.builder.selectNode(node);
                        }
                    });
                    item.style.cursor = 'pointer';
                }
                warningList.appendChild(item);
            });
            panel.appendChild(warningList);
        }
        
        document.body.appendChild(panel);
        
        // Auto-hide after 5 seconds if valid
        if (results.valid) {
            setTimeout(() => {
                if (panel.parentNode) {
                    panel.remove();
                }
            }, 5000);
        }
        
        return results;
    }
}

window.WorkflowValidator = WorkflowValidator;
