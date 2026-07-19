/**
 * Workflow Minimap
 * Navigation aid for large workflows
 */

class Minimap {
    constructor(builder) {
        this.builder = builder;
        this.minimap = null;
        this.minimapCanvas = null;
        this.viewport = null;
        this.scale = 0.1;
    }
    
    init() {
        this.createMinimap();
        this.update();
        
        // Update minimap on workflow changes
        setInterval(() => {
            if (this.builder.nodes.length > 0) {
                this.update();
            }
        }, 1000);
    }
    
    createMinimap() {
        const container = document.createElement('div');
        container.id = 'workflow-minimap';
        container.style.cssText = `
            position: absolute;
            bottom: 20px;
            right: 20px;
            width: 200px;
            height: 150px;
            background: white;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 100;
            overflow: hidden;
            cursor: pointer;
        `;
        
        const canvas = document.createElement('canvas');
        canvas.width = 200;
        canvas.height = 150;
        canvas.style.cssText = 'width: 100%; height: 100%; display: block;';
        container.appendChild(canvas);
        
        // Viewport indicator
        const viewport = document.createElement('div');
        viewport.id = 'minimap-viewport';
        viewport.style.cssText = `
            position: absolute;
            border: 2px solid #0066cc;
            background: rgba(0, 102, 204, 0.1);
            pointer-events: none;
        `;
        container.appendChild(viewport);
        
        // Click to navigate
        container.addEventListener('click', (e) => {
            const rect = container.getBoundingClientRect();
            const x = (e.clientX - rect.left) / this.scale;
            const y = (e.clientY - rect.top) / this.scale;
            this.navigateTo(x, y);
        });
        
        this.builder.canvas.appendChild(container);
        this.minimap = container;
        this.minimapCanvas = canvas;
        this.viewport = viewport;
    }
    
    update() {
        if (!this.minimapCanvas || this.builder.nodes.length === 0) return;
        
        const ctx = this.minimapCanvas.getContext('2d');
        ctx.clearRect(0, 0, this.minimapCanvas.width, this.minimapCanvas.height);
        
        // Calculate bounds
        let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        this.builder.nodes.forEach(node => {
            minX = Math.min(minX, node.x);
            minY = Math.min(minY, node.y);
            maxX = Math.max(maxX, node.x + 200);
            maxY = Math.max(maxY, node.y + 100);
        });
        
        const width = maxX - minX;
        const height = maxY - minY;
        this.scale = Math.min(
            this.minimapCanvas.width / width,
            this.minimapCanvas.height / height
        );
        
        // Draw nodes
        this.builder.nodes.forEach(node => {
            const x = (node.x - minX) * this.scale;
            const y = (node.y - minY) * this.scale;
            const w = 200 * this.scale;
            const h = 100 * this.scale;
            
            ctx.fillStyle = node.color;
            ctx.fillRect(x, y, w, h);
            ctx.strokeStyle = '#333';
            ctx.lineWidth = 1;
            ctx.strokeRect(x, y, w, h);
        });
        
        // Draw connections
        ctx.strokeStyle = '#999';
        ctx.lineWidth = 1;
        this.builder.connectionManager.connections.forEach(conn => {
            const fromNode = this.builder.nodes.find(n => n.id === conn.fromNodeId);
            const toNode = this.builder.nodes.find(n => n.id === conn.toNodeId);
            if (fromNode && toNode) {
                const x1 = (fromNode.x - minX + 100) * this.scale;
                const y1 = (fromNode.y - minY + 50) * this.scale;
                const x2 = (toNode.x - minX + 100) * this.scale;
                const y2 = (toNode.y - minY + 50) * this.scale;
                
                ctx.beginPath();
                ctx.moveTo(x1, y1);
                ctx.lineTo(x2, y2);
                ctx.stroke();
            }
        });
        
        // Update viewport indicator
        const canvasRect = this.builder.canvas.getBoundingClientRect();
        const scrollX = this.builder.canvas.scrollLeft || 0;
        const scrollY = this.builder.canvas.scrollTop || 0;
        
        const viewportX = (scrollX - minX) * this.scale;
        const viewportY = (scrollY - minY) * this.scale;
        const viewportW = canvasRect.width * this.scale;
        const viewportH = canvasRect.height * this.scale;
        
        this.viewport.style.left = `${viewportX}px`;
        this.viewport.style.top = `${viewportY}px`;
        this.viewport.style.width = `${viewportW}px`;
        this.viewport.style.height = `${viewportH}px`;
    }
    
    navigateTo(x, y) {
        // Scroll canvas to position
        // This would require implementing canvas scrolling
        console.log('Navigate to:', x, y);
    }
}

window.Minimap = Minimap;
