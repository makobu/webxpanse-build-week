/**
 * Workflow History Manager
 * Implements undo/redo functionality using command pattern
 */

class HistoryManager {
    constructor(builder) {
        this.builder = builder;
        this.history = [];
        this.historyIndex = -1;
        this.maxHistorySize = 50;
    }
    
    /**
     * Execute a command and add to history
     */
    execute(command) {
        // Remove any commands after current index (when undoing then doing new action)
        if (this.historyIndex < this.history.length - 1) {
            this.history = this.history.slice(0, this.historyIndex + 1);
        }
        
        // Execute command
        command.execute();
        
        // Add to history
        this.history.push(command);
        this.historyIndex++;
        
        // Limit history size
        if (this.history.length > this.maxHistorySize) {
            this.history.shift();
            this.historyIndex--;
        }
        
        this.updateUI();
    }
    
    /**
     * Undo last command
     */
    undo() {
        if (this.historyIndex >= 0) {
            const command = this.history[this.historyIndex];
            command.undo();
            this.historyIndex--;
            this.updateUI();
            return true;
        }
        return false;
    }
    
    /**
     * Redo last undone command
     */
    redo() {
        if (this.historyIndex < this.history.length - 1) {
            this.historyIndex++;
            const command = this.history[this.historyIndex];
            command.execute();
            this.updateUI();
            return true;
        }
        return false;
    }
    
    /**
     * Check if undo is available
     */
    canUndo() {
        return this.historyIndex >= 0;
    }
    
    /**
     * Check if redo is available
     */
    canRedo() {
        return this.historyIndex < this.history.length - 1;
    }
    
    /**
     * Clear history
     */
    clear() {
        this.history = [];
        this.historyIndex = -1;
        this.updateUI();
    }
    
    /**
     * Update UI buttons
     */
    updateUI() {
        const undoBtn = document.getElementById('workflow-undo-btn');
        const redoBtn = document.getElementById('workflow-redo-btn');
        
        if (undoBtn) {
            undoBtn.disabled = !this.canUndo();
            undoBtn.style.opacity = this.canUndo() ? '1' : '0.5';
        }
        
        if (redoBtn) {
            redoBtn.disabled = !this.canRedo();
            redoBtn.style.opacity = this.canRedo() ? '1' : '0.5';
        }
    }
}

/**
 * Base Command class
 */
class Command {
    constructor(builder) {
        this.builder = builder;
    }
    
    execute() {
        throw new Error('execute() must be implemented');
    }
    
    undo() {
        throw new Error('undo() must be implemented');
    }
}

/**
 * Add Node Command
 */
class AddNodeCommand extends Command {
    constructor(builder, node) {
        super(builder);
        this.node = node;
    }
    
    execute() {
        this.builder.nodes.push(this.node);
        this.builder.renderNode(this.node);
        this.builder.connectionManager.redrawAll();
        this.builder.updateEmptyState && this.builder.updateEmptyState();
    }
    
    undo() {
        // Delete connections
        const connections = this.builder.connectionManager.getConnectionsForNode(this.node.id);
        connections.forEach(conn => {
            this.builder.connectionManager.deleteConnection(conn.id);
        });
        
        this.builder.nodes = this.builder.nodes.filter(n => n.id !== this.node.id);
        const nodeEl = document.getElementById(this.node.id);
        if (nodeEl) {
            nodeEl.remove();
        }
        this.builder.connectionManager.redrawAll();
        this.builder.updateEmptyState && this.builder.updateEmptyState();
    }
}

/**
 * Delete Node Command
 */
class DeleteNodeCommand extends Command {
    constructor(builder, node, connections) {
        super(builder);
        this.node = node;
        this.connections = connections || [];
    }
    
    execute() {
        // Store connections before deletion
        if (this.connections.length === 0) {
            this.connections = this.builder.connectionManager.getConnectionsForNode(this.node.id);
        }
        
        // Delete connections
        this.connections.forEach(conn => {
            this.builder.connectionManager.deleteConnection(conn.id);
        });
        
        this.builder.nodes = this.builder.nodes.filter(n => n.id !== this.node.id);
        const nodeEl = document.getElementById(this.node.id);
        if (nodeEl) {
            nodeEl.remove();
        }
        this.builder.connectionManager.redrawAll();
        this.builder.updateEmptyState && this.builder.updateEmptyState();
    }
    
    undo() {
        // Restore node
        this.builder.nodes.push(this.node);
        this.builder.renderNode(this.node);
        
        // Restore connections
        this.connections.forEach(conn => {
            this.builder.connectionManager.connections.push(conn);
            this.builder.connectionManager.drawConnection(conn);
        });
        
        this.builder.connectionManager.redrawAll();
        this.builder.updateEmptyState && this.builder.updateEmptyState();
    }
}

/**
 * Move Node Command
 */
class MoveNodeCommand extends Command {
    constructor(builder, node, oldX, oldY, newX, newY) {
        super(builder);
        this.node = node;
        this.oldX = oldX;
        this.oldY = oldY;
        this.newX = newX;
        this.newY = newY;
    }
    
    execute() {
        this.node.x = this.newX;
        this.node.y = this.newY;
        const nodeEl = document.getElementById(this.node.id);
        if (nodeEl) {
            nodeEl.style.left = `${this.newX}px`;
            nodeEl.style.top = `${this.newY}px`;
        }
        this.builder.connectionManager.redrawAll();
    }
    
    undo() {
        this.node.x = this.oldX;
        this.node.y = this.oldY;
        const nodeEl = document.getElementById(this.node.id);
        if (nodeEl) {
            nodeEl.style.left = `${this.oldX}px`;
            nodeEl.style.top = `${this.oldY}px`;
        }
        this.builder.connectionManager.redrawAll();
    }
}

/**
 * Create Connection Command
 */
class CreateConnectionCommand extends Command {
    constructor(builder, connection) {
        super(builder);
        this.connection = connection;
    }
    
    execute() {
        this.builder.connectionManager.connections.push(this.connection);
        this.builder.connectionManager.drawConnection(this.connection);
    }
    
    undo() {
        this.builder.connectionManager.deleteConnection(this.connection.id);
    }
}

/**
 * Delete Connection Command
 */
class DeleteConnectionCommand extends Command {
    constructor(builder, connection) {
        super(builder);
        this.connection = connection;
    }
    
    execute() {
        this.builder.connectionManager.deleteConnection(this.connection.id);
    }
    
    undo() {
        this.builder.connectionManager.connections.push(this.connection);
        this.builder.connectionManager.drawConnection(this.connection);
    }
}

/**
 * Update Node Data Command
 */
class UpdateNodeDataCommand extends Command {
    constructor(builder, node, field, oldValue, newValue) {
        super(builder);
        this.node = node;
        this.field = field;
        this.oldValue = oldValue;
        this.newValue = newValue;
    }
    
    execute() {
        this.node.data[this.field] = this.newValue;
        // Re-render node if structure changed
        const nodeEl = document.getElementById(this.node.id);
        if (nodeEl && (this.field === 'type')) {
            const newHTML = this.builder.getNodeHTML(this.node);
            nodeEl.innerHTML = newHTML;
            this.builder.makeNodeDraggable(nodeEl, this.node);
            this.builder.connectionManager.addConnectionHandles(nodeEl, this.node);
        }
    }
    
    undo() {
        this.node.data[this.field] = this.oldValue;
        const nodeEl = document.getElementById(this.node.id);
        if (nodeEl && (this.field === 'type')) {
            const newHTML = this.builder.getNodeHTML(this.node);
            nodeEl.innerHTML = newHTML;
            this.builder.makeNodeDraggable(nodeEl, this.node);
            this.builder.connectionManager.addConnectionHandles(nodeEl, this.node);
        }
    }
}

// Export classes
window.HistoryManager = HistoryManager;
window.AddNodeCommand = AddNodeCommand;
window.DeleteNodeCommand = DeleteNodeCommand;
window.MoveNodeCommand = MoveNodeCommand;
window.CreateConnectionCommand = CreateConnectionCommand;
window.DeleteConnectionCommand = DeleteConnectionCommand;
window.UpdateNodeDataCommand = UpdateNodeDataCommand;
