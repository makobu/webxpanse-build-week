/**
 * Workflow Keyboard Shortcuts and Context Menu
 */

class ShortcutManager {
    constructor(builder) {
        this.builder = builder;
        this.selectedNodes = new Set();
        // Don't init in constructor - will be called after canvas is ready
    }
    
    init() {
        if (!this.builder || !this.builder.canvas) {
            // Builder not ready yet
            return;
        }
        this.setupKeyboardShortcuts();
        this.setupContextMenu();
    }
    
    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Don't interfere with form fields, including palette search.
            if (this.isEditableTarget(e.target)) {
                return;
            }
            
            switch(e.key) {
                case 'Delete':
                case 'Backspace':
                    e.preventDefault();
                    this.deleteSelected();
                    break;
                    
                case 'a':
                    if ((e.ctrlKey || e.metaKey) && !e.shiftKey) {
                        e.preventDefault();
                        this.selectAll();
                    }
                    break;
                    
                case 'c':
                    if ((e.ctrlKey || e.metaKey) && !e.shiftKey) {
                        e.preventDefault();
                        this.copySelected();
                    }
                    break;
                    
                case 'v':
                    if ((e.ctrlKey || e.metaKey) && !e.shiftKey) {
                        e.preventDefault();
                        this.pasteNodes();
                    }
                    break;
                    
                case 'ArrowUp':
                    if (this.selectedNodes.size > 0) {
                        e.preventDefault();
                        this.nudgeSelected(0, -10);
                    }
                    break;
                    
                case 'ArrowDown':
                    if (this.selectedNodes.size > 0) {
                        e.preventDefault();
                        this.nudgeSelected(0, 10);
                    }
                    break;
                    
                case 'ArrowLeft':
                    if (this.selectedNodes.size > 0) {
                        e.preventDefault();
                        this.nudgeSelected(-10, 0);
                    }
                    break;
                    
                case 'ArrowRight':
                    if (this.selectedNodes.size > 0) {
                        e.preventDefault();
                        this.nudgeSelected(10, 0);
                    }
                    break;
                    
                case 'Escape':
                    this.deselectAll();
                    break;
            }
        });
    }

    isEditableTarget(target) {
        if (!target) {
            return false;
        }

        const tagName = String(target.tagName || '').toUpperCase();
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tagName)) {
            return true;
        }

        return Boolean(target.closest && target.closest('[contenteditable="true"]'));
    }
    
    setupContextMenu() {
        this.builder.canvas.addEventListener('contextmenu', (e) => {
            e.preventDefault();
            this.showContextMenu(e.clientX, e.clientY, e.target);
        });
        
        // Close context menu on click
        document.addEventListener('click', () => {
            this.hideContextMenu();
        });
    }
    
    showContextMenu(x, y, target) {
        this.hideContextMenu();
        
        const menu = document.createElement('div');
        menu.id = 'workflow-context-menu';
        menu.style.cssText = `
            position: fixed;
            left: ${x}px;
            top: ${y}px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10000;
            min-width: 180px;
            padding: 4px;
        `;
        
        // Check if clicking on a node
        const nodeEl = target.closest('.workflow-node');
        if (nodeEl) {
            const nodeId = nodeEl.dataset.nodeId;
            const node = this.builder.nodes.find(n => n.id === nodeId);
            
            if (node) {
                menu.appendChild(this.createMenuItem('Configure', () => {
                    this.builder.selectNode(node);
                    this.hideContextMenu();
                }));
                
                menu.appendChild(this.createMenuItem('Duplicate', () => {
                    this.duplicateNode(node);
                    this.hideContextMenu();
                }));
                
                menu.appendChild(this.createMenuItem('Delete', () => {
                    this.builder.deleteNode(node.id);
                    this.hideContextMenu();
                }, true));
            }
        } else {
            // Canvas context menu
            menu.appendChild(this.createMenuItem('Paste', () => {
                this.pasteNodes();
                this.hideContextMenu();
            }));
            
            menu.appendChild(this.createMenuItem('Select All', () => {
                this.selectAll();
                this.hideContextMenu();
            }));
        }
        
        document.body.appendChild(menu);
        
        // Adjust position if menu goes off screen
        const rect = menu.getBoundingClientRect();
        if (rect.right > window.innerWidth) {
            menu.style.left = `${x - rect.width}px`;
        }
        if (rect.bottom > window.innerHeight) {
            menu.style.top = `${y - rect.height}px`;
        }
    }
    
    createMenuItem(label, onClick, isDanger = false) {
        const item = document.createElement('div');
        item.textContent = label;
        item.style.cssText = `
            padding: 8px 12px;
            cursor: pointer;
            color: ${isDanger ? '#dc3545' : '#333'};
            font-size: 14px;
            transition: background 0.2s;
        `;
        
        item.addEventListener('mouseenter', () => {
            item.style.background = '#f8f9fa';
        });
        
        item.addEventListener('mouseleave', () => {
            item.style.background = 'transparent';
        });
        
        item.addEventListener('click', onClick);
        
        return item;
    }
    
    hideContextMenu() {
        const menu = document.getElementById('workflow-context-menu');
        if (menu) {
            menu.remove();
        }
    }
    
    deleteSelected() {
        if (this.selectedNodes.size === 0 && this.builder.selectedNode) {
            this.builder.deleteNode(this.builder.selectedNode.id);
        } else {
            this.selectedNodes.forEach(nodeId => {
                this.builder.deleteNode(nodeId);
            });
            this.selectedNodes.clear();
        }
    }
    
    selectAll() {
        this.selectedNodes.clear();
        this.builder.nodes.forEach(node => {
            this.selectedNodes.add(node.id);
            const nodeEl = document.getElementById(node.id);
            if (nodeEl) {
                nodeEl.classList.add('is-multi-selected');
            }
        });
    }
    
    deselectAll() {
        this.selectedNodes.forEach(nodeId => {
            const nodeEl = document.getElementById(nodeId);
            if (nodeEl) {
                nodeEl.classList.remove('is-multi-selected');
            }
        });
        this.selectedNodes.clear();
        this.builder.closeConfig();
    }
    
    copySelected() {
        const nodesToCopy = [];
        
        if (this.selectedNodes.size > 0) {
            this.selectedNodes.forEach(nodeId => {
                const node = this.builder.nodes.find(n => n.id === nodeId);
                if (node) nodesToCopy.push(node);
            });
        } else if (this.builder.selectedNode) {
            nodesToCopy.push(this.builder.selectedNode);
        }
        
        if (nodesToCopy.length > 0) {
            this.builder.clipboard = JSON.parse(JSON.stringify(nodesToCopy));
        }
    }
    
    pasteNodes() {
        if (!this.builder.clipboard || this.builder.clipboard.length === 0) return;
        
        const offset = 50;
        this.builder.clipboard.forEach((nodeData, index) => {
            const newNode = {
                ...nodeData,
                id: `node_${++this.builder.nodeIdCounter}`,
                x: nodeData.x + offset,
                y: nodeData.y + offset
            };
            
            const command = new AddNodeCommand(this.builder, newNode);
            this.builder.historyManager.execute(command);
        });
    }
    
    duplicateNode(node) {
        const newNode = {
            ...node,
            id: `node_${++this.builder.nodeIdCounter}`,
            x: node.x + 50,
            y: node.y + 50
        };
        
        const command = new AddNodeCommand(this.builder, newNode);
        this.builder.historyManager.execute(command);
    }
    
    nudgeSelected(dx, dy) {
        const nodesToMove = [];
        
        if (this.selectedNodes.size > 0) {
            this.selectedNodes.forEach(nodeId => {
                const node = this.builder.nodes.find(n => n.id === nodeId);
                if (node) nodesToMove.push(node);
            });
        } else if (this.builder.selectedNode) {
            nodesToMove.push(this.builder.selectedNode);
        }
        
        nodesToMove.forEach(node => {
            const oldX = node.x;
            const oldY = node.y;
            node.x += dx;
            node.y += dy;
            
            const nodeEl = document.getElementById(node.id);
            if (nodeEl) {
                nodeEl.style.left = `${node.x}px`;
                nodeEl.style.top = `${node.y}px`;
            }
            
            const command = new MoveNodeCommand(this.builder, node, oldX, oldY, node.x, node.y);
            this.builder.historyManager.execute(command);
        });
        
        this.builder.connectionManager.redrawAll();
    }
}

window.ShortcutManager = ShortcutManager;
