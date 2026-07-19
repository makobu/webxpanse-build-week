/**
 * Workflow Connection Manager
 * Handles connections between workflow nodes with Bezier curves
 */

class ConnectionManager {
    constructor(builder) {
        this.builder = builder;
        this.connections = [];
        this.connectingFrom = null;
        this.connectingTo = null;
        this.tempConnection = null;
        this.connectionIdCounter = 0;
    }
    
    /**
     * Add connection handles (ports) to a node
     */
    addConnectionHandles(nodeEl, node) {
        // Remove existing handles
        const existingHandles = nodeEl.querySelectorAll('.connection-handle');
        existingHandles.forEach(h => h.remove());
        
        // Add input handles (top of node)
        if (node.type !== 'trigger') {
            const inputHandle = this.createHandle(nodeEl, node, 'input', 'top');
            nodeEl.appendChild(inputHandle);
        }
        
        // Add output handles (bottom of node)
        if (node.type !== 'delay') {
            if (node.type === 'condition') {
                // Condition nodes have multiple outputs (true/false)
                const trueHandle = this.createHandle(nodeEl, node, 'output', 'bottom', 'true');
                const falseHandle = this.createHandle(nodeEl, node, 'output', 'bottom', 'false');
                nodeEl.appendChild(trueHandle);
                nodeEl.appendChild(falseHandle);
            } else {
                const outputHandle = this.createHandle(nodeEl, node, 'output', 'bottom');
                nodeEl.appendChild(outputHandle);
            }
        }
    }
    
    /**
     * Create a connection handle (port)
     */
    createHandle(nodeEl, node, type, position, branchType = null) {
        const handle = document.createElement('div');
        handle.className = `connection-handle connection-handle-${type}`;
        handle.dataset.nodeId = node.id;
        handle.dataset.handleType = type;
        handle.dataset.position = position;
        if (branchType) {
            handle.dataset.branchType = branchType;
        }
        
        const handleSize = 12;
        const handleOffset = branchType ? (branchType === 'true' ? -15 : 15) : 0;
        
        handle.style.cssText = `
            position: absolute;
            width: ${handleSize}px;
            height: ${handleSize}px;
            background: ${type === 'input' ? '#4ecdc4' : '#45b7d1'};
            border: 2px solid white;
            border-radius: 50%;
            cursor: ${type === 'input' ? 'crosshair' : 'crosshair'};
            z-index: 10;
            ${position === 'top' ? `top: -${handleSize/2}px; left: 50%; transform: translateX(${handleOffset}px);` : ''}
            ${position === 'bottom' ? `bottom: -${handleSize/2}px; left: 50%; transform: translateX(${handleOffset}px);` : ''}
            transition: all 0.2s;
        `;
        
        // Add label for branch types
        if (branchType) {
            const label = document.createElement('div');
            label.textContent = branchType === 'true' ? 'Yes' : 'No';
            label.style.cssText = `
                position: absolute;
                ${position === 'bottom' ? 'bottom: -20px;' : 'top: -20px;'}
                left: 50%;
                transform: translateX(-50%);
                font-size: 10px;
                color: #666;
                white-space: nowrap;
                pointer-events: none;
            `;
            handle.appendChild(label);
        }
        
        // Mouse events
        handle.addEventListener('mouseenter', () => {
            handle.style.transform = `translateX(${handleOffset}px) scale(1.3)`;
            handle.style.boxShadow = '0 0 8px rgba(0,0,0,0.3)';
        });
        
        handle.addEventListener('mouseleave', () => {
            if (!this.connectingFrom || this.connectingFrom !== handle) {
                handle.style.transform = `translateX(${handleOffset}px) scale(1)`;
                handle.style.boxShadow = 'none';
            }
        });
        
        // Connection start
        if (type === 'output') {
            handle.addEventListener('mousedown', (e) => {
                e.stopPropagation();
                this.startConnection(handle, node);
            });
        }
        
        // Connection end
        if (type === 'input') {
            handle.addEventListener('mouseup', (e) => {
                e.stopPropagation();
                if (this.connectingFrom) {
                    this.endConnection(handle, node);
                }
            });
        }
        
        return handle;
    }
    
    /**
     * Start creating a connection
     */
    startConnection(handle, fromNode) {
        this.connectingFrom = handle;
        this.connectingTo = null;
        
        // Create temporary connection line
        this.tempConnection = {
            fromNode: fromNode,
            fromHandle: handle,
            toX: 0,
            toY: 0
        };
        
        // Add mouse move listener
        this.canvasMouseMoveHandler = (e) => {
            const rect = this.builder.canvas.getBoundingClientRect();
            this.tempConnection.toX = e.clientX - rect.left;
            this.tempConnection.toY = e.clientY - rect.top;
            this.updateTempConnection();
        };
        
        this.canvasMouseUpHandler = () => {
            this.cancelConnection();
        };
        
        this.builder.canvas.addEventListener('mousemove', this.canvasMouseMoveHandler);
        this.builder.canvas.addEventListener('mouseup', this.canvasMouseUpHandler);
        
        // Update cursor
        this.builder.canvas.style.cursor = 'crosshair';
    }
    
    /**
     * End creating a connection
     */
    endConnection(handle, toNode) {
        if (!this.connectingFrom) return;
        
        const fromHandle = this.connectingFrom;
        const fromNode = this.builder.nodes.find(n => n.id === fromHandle.dataset.nodeId);
        
        // Validate connection
        if (this.validateConnection(fromNode, toNode, fromHandle, handle)) {
            // Create connection
            const connection = {
                id: `conn_${++this.connectionIdCounter}`,
                fromNodeId: fromNode.id,
                toNodeId: toNode.id,
                fromHandleType: fromHandle.dataset.handleType,
                toHandleType: handle.dataset.handleType,
                branchType: fromHandle.dataset.branchType || null,
                type: 'success'
            };
            
            // Don't add to connections yet - let history manager handle it
            this.builder.onConnectionCreated(connection);
        }
        
        this.cancelConnection();
    }
    
    /**
     * Cancel connection creation
     */
    cancelConnection() {
        this.connectingFrom = null;
        this.connectingTo = null;
        this.tempConnection = null;
        
        if (this.canvasMouseMoveHandler) {
            document.removeEventListener('mousemove', this.canvasMouseMoveHandler);
        }
        if (this.canvasMouseUpHandler) {
            document.removeEventListener('mouseup', this.canvasMouseUpHandler);
        }
        
        this.builder.canvas.style.cursor = 'default';
        this.updateTempConnection(); // Clear temp line
    }
    
    /**
     * Validate a connection
     */
    validateConnection(fromNode, toNode, fromHandle, toHandle) {
        // Can't connect to self
        if (fromNode.id === toNode.id) {
            return false;
        }
        
        // Must connect output to input
        if (fromHandle.dataset.handleType !== 'output' || toHandle.dataset.handleType !== 'input') {
            return false;
        }
        
        // Can't connect trigger to trigger
        if (fromNode.type === 'trigger' && toNode.type === 'trigger') {
            return false;
        }
        
        // Check for duplicate connections
        const duplicate = this.connections.find(c => 
            c.fromNodeId === fromNode.id && 
            c.toNodeId === toNode.id &&
            c.branchType === (fromHandle.dataset.branchType || null)
        );
        
        if (duplicate) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Update temporary connection line while dragging
     */
    updateTempConnection() {
        if (!this.tempConnection) {
            // Clear temp connection
            const tempPath = this.builder.svg.querySelector('.temp-connection');
            if (tempPath) {
                tempPath.remove();
            }
            return;
        }
        
        const fromHandle = this.tempConnection.fromHandle;
        const fromNodeEl = document.getElementById(this.tempConnection.fromNode.id);
        if (!fromNodeEl) return;
        
        const fromRect = fromNodeEl.getBoundingClientRect();
        const canvasRect = this.builder.canvas.getBoundingClientRect();
        
        const fromX = fromRect.left - canvasRect.left + fromRect.width / 2;
        const fromY = fromRect.bottom - canvasRect.top;
        
        const toX = this.tempConnection.toX;
        const toY = this.tempConnection.toY;
        
        // Remove existing temp path
        const existingTemp = this.builder.svg.querySelector('.temp-connection');
        if (existingTemp) {
            existingTemp.remove();
        }
        
        // Create Bezier curve
        const path = this.createBezierPath(fromX, fromY, toX, toY);
        const pathEl = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        pathEl.setAttribute('d', path);
        pathEl.setAttribute('stroke', '#45b7d1');
        pathEl.setAttribute('stroke-width', '2');
        pathEl.setAttribute('fill', 'none');
        pathEl.setAttribute('stroke-dasharray', '5,5');
        pathEl.setAttribute('class', 'temp-connection');
        pathEl.style.pointerEvents = 'none';
        
        this.builder.svg.appendChild(pathEl);
    }
    
    /**
     * Draw a connection between nodes
     */
    drawConnection(connection) {
        if (this.builder && typeof this.builder.initArrowMarkers === 'function') {
            this.builder.initArrowMarkers();
        }

        const fromNodeEl = document.getElementById(connection.fromNodeId);
        const toNodeEl = document.getElementById(connection.toNodeId);
        
        if (!fromNodeEl || !toNodeEl) return;

        const fromNode = this.builder.nodes.find(node => node.id === connection.fromNodeId);
        const isConditionalBranch = fromNode?.type === 'condition'
            && ['true', 'false'].includes(String(connection.branchType || ''));
        
        const fromRect = fromNodeEl.getBoundingClientRect();
        const toRect = toNodeEl.getBoundingClientRect();
        const canvasRect = this.builder.canvas.getBoundingClientRect();
        
        // Calculate handle positions
        const fromHandle = fromNodeEl.querySelector(`[data-handle-type="output"]${isConditionalBranch ? `[data-branch-type="${connection.branchType}"]` : ''}`);
        const toHandle = toNodeEl.querySelector('[data-handle-type="input"]');
        
        let fromX, fromY, toX, toY;
        
        if (fromHandle && toHandle) {
            const fromHandleRect = fromHandle.getBoundingClientRect();
            const toHandleRect = toHandle.getBoundingClientRect();
            
            fromX = fromHandleRect.left - canvasRect.left + fromHandleRect.width / 2;
            fromY = fromHandleRect.top - canvasRect.top + fromHandleRect.height / 2;
            toX = toHandleRect.left - canvasRect.left + toHandleRect.width / 2;
            toY = toHandleRect.top - canvasRect.top + toHandleRect.height / 2;
        } else {
            // Fallback to center calculations
            fromX = fromRect.left - canvasRect.left + fromRect.width / 2;
            fromY = fromRect.bottom - canvasRect.top;
            toX = toRect.left - canvasRect.left + toRect.width / 2;
            toY = toRect.top - canvasRect.top;
        }
        
        // Create Bezier curve path
        const path = this.createBezierPath(fromX, fromY, toX, toY);
        
        // Determine connection color based on type
        const colors = {
            success: '#28a745',
            failure: '#dc3545',
            conditional: '#ffc107',
            default: '#6c757d'
        };
        
        const pathEl = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        pathEl.setAttribute('d', path);
        pathEl.setAttribute('stroke', colors[connection.type] || colors.default);
        pathEl.setAttribute('stroke-width', '2');
        pathEl.setAttribute('fill', 'none');
        pathEl.setAttribute('class', 'workflow-connection');
        pathEl.dataset.connectionId = connection.id;
        pathEl.style.cursor = 'pointer';
        
        // Add arrow marker
        pathEl.setAttribute('marker-end', 'url(#arrowhead)');
        
        // Make connection clickable for deletion
        pathEl.style.pointerEvents = 'stroke';
        pathEl.addEventListener('click', (e) => {
            if (e.shiftKey) {
                this.deleteConnection(connection.id);
            }
        });
        
        this.builder.svg.appendChild(pathEl);
        
        // Add Yes/No labels only for true condition branches.
        if (isConditionalBranch) {
            const midX = (fromX + toX) / 2;
            const midY = (fromY + toY) / 2;
            
            const textEl = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            textEl.setAttribute('x', midX);
            textEl.setAttribute('y', midY - 5);
            textEl.setAttribute('text-anchor', 'middle');
            textEl.setAttribute('font-size', '12');
            textEl.setAttribute('fill', '#666');
            textEl.textContent = connection.branchType === 'true' ? 'Yes' : 'No';
            textEl.style.pointerEvents = 'none';
            textEl.dataset.connectionId = connection.id;
            this.builder.svg.appendChild(textEl);
        }
    }
    
    /**
     * Create Bezier curve path
     */
    createBezierPath(x1, y1, x2, y2) {
        const dx = x2 - x1;
        const dy = y2 - y1;
        const curvature = 0.3;
        
        const cp1x = x1;
        const cp1y = y1 + dy * curvature;
        const cp2x = x2;
        const cp2y = y2 - dy * curvature;
        
        return `M ${x1} ${y1} C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${x2} ${y2}`;
    }
    
    /**
     * Delete a connection
     */
    deleteConnection(connectionId) {
        const connection = this.connections.find(c => c.id === connectionId);
        if (!connection) return;
        
        this.connections = this.connections.filter(c => c.id !== connectionId);
        
        // Remove from SVG
        const pathEl = this.builder.svg.querySelector(`[data-connection-id="${connectionId}"]`);
        if (pathEl) {
            pathEl.remove();
        }
        
        const textEl = this.builder.svg.querySelector(`text[data-connection-id="${connectionId}"]`);
        if (textEl) {
            textEl.remove();
        }
        
        // Notify builder (only if not already in history)
        if (this.builder.historyManager && !this.builder.historyManager.isExecuting) {
            this.builder.onConnectionDeleted(connectionId);
        }
    }
    
    /**
     * Redraw all connections
     */
    redrawAll() {
        if (this.builder && typeof this.builder.initArrowMarkers === 'function') {
            this.builder.initArrowMarkers();
        }

        // Clear existing connections
        const existingConnections = this.builder.svg.querySelectorAll('.workflow-connection, text[data-connection-id]');
        existingConnections.forEach(el => el.remove());
        
        // Redraw all connections
        this.connections.forEach(conn => {
            this.drawConnection(conn);
        });
    }
    
    /**
     * Get connections for a node
     */
    getConnectionsForNode(nodeId) {
        return this.connections.filter(c => 
            c.fromNodeId === nodeId || c.toNodeId === nodeId
        );
    }
    
    /**
     * Export connections
     */
    export() {
        const nodesById = new Map(this.builder.nodes.map(node => [node.id, node]));
        return this.connections.map(c => ({
            id: c.id,
            from: c.fromNodeId,
            to: c.toNodeId,
            branchType: nodesById.get(c.fromNodeId)?.type === 'condition' ? c.branchType : null,
            type: nodesById.get(c.fromNodeId)?.type === 'condition' ? (c.type || 'success') : 'default'
        }));
    }
    
    /**
     * Import connections
     */
    import(connectionsData) {
        this.connections = connectionsData.map(c => ({
            id: c.id || `conn_${++this.connectionIdCounter}`,
            fromNodeId: c.fromNodeId || c.from || c.source || c.source_node_id || '',
            toNodeId: c.toNodeId || c.to || c.target || c.target_node_id || '',
            branchType: c.branchType || c.branch || c.condition_value || null,
            type: c.type || 'success',
            fromHandleType: 'output',
            toHandleType: 'input'
        })).filter(c => c.fromNodeId && c.toNodeId);
        
        this.redrawAll();
    }
}
