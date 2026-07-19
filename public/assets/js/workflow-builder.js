/**
 * Visual Workflow Builder
 * Drag-and-drop workflow creation interface
 */

class WorkflowBuilder {
    notify(type, message, title = 'Workflow builder') {
        if (typeof window !== 'undefined' && window.WorkflowBuilderFeedback && typeof window.WorkflowBuilderFeedback.notify === 'function') {
            window.WorkflowBuilderFeedback.notify(type, message, title);
            return;
        }

        alert(message);
    }

    constructor(containerId) {
        this.container = document.getElementById(containerId);
        this.nodes = [];
        this.nodeIdCounter = 0;
        this.selectedNode = null;
        this.isDragging = false;
        this.dragOffset = { x: 0, y: 0 };
        
        // Initialize connection manager (needed early)
        this.connectionManager = new ConnectionManager(this);
        
        // Clipboard for copy/paste
        this.clipboard = [];
        
        // Managers will be initialized after canvas is set up
        this.historyManager = null;
        this.shortcutManager = null;
        this.minimap = null;
        this.validator = null;
        this.lockVersion = null;
        this.lastSavedAt = null;
        
        this.initialized = false;
        
        // Try to initialize if container exists and is visible
        if (this.container) {
            setTimeout(() => {
                const containerStyle = window.getComputedStyle(this.container);
                if (containerStyle.display !== 'none') {
                    this.init();
                }
            }, 100);
        }
    }
    
    init() {
        if (!this.container) {
            console.error('WorkflowBuilder: Container element not found');
            return;
        }
        
        if (this.initialized) {
            console.log('WorkflowBuilder: Already initialized');
            return;
        }
        
        // Check if container is visible
        const containerStyle = window.getComputedStyle(this.container);
        if (containerStyle.display === 'none') {
            console.log('WorkflowBuilder: Container not visible, deferring initialization');
            return;
        }
        
        console.log('WorkflowBuilder: Starting initialization...');
        console.log('WorkflowBuilder: Container element:', this.container);
        
        try {
            // Clear container first to avoid duplicates
            this.container.innerHTML = '';
            
            // Setup palette first (will be at top)
            this.setupPalette();
            console.log('WorkflowBuilder: Palette setup complete');
            
            // Setup canvas (will be below palette)
            this.setupCanvas();
            console.log('WorkflowBuilder: Canvas setup complete');
            
            // Verify elements were created
            const canvas = document.getElementById('workflow-canvas');
            const palette = document.getElementById('workflow-palette-container');
            if (!canvas) {
                throw new Error('Canvas was not created');
            }
            if (!palette) {
                throw new Error('Palette was not created');
            }
            console.log('WorkflowBuilder: All elements verified in DOM');
            
            this.setupEventListeners();
            this.loadFromForm();
            
            // Initialize managers after canvas is set up
            this.historyManager = new HistoryManager(this);
            this.shortcutManager = new ShortcutManager(this);
            this.shortcutManager.init();
            this.minimap = new Minimap(this);
            this.validator = new WorkflowValidator(this);
            
            this.initialized = true;
            this.updateEmptyState();
            
            console.log('WorkflowBuilder: Initialization complete successfully');
            
            // Initialize minimap after a short delay
            setTimeout(() => {
                if (this.minimap) {
                    this.minimap.init();
                }
            }, 200);
        } catch (e) {
            console.error('WorkflowBuilder: Initialization error:', e);
            console.error('Error stack:', e.stack);
            this.notify('error', 'Error initializing workflow builder: ' + e.message, 'Builder failed to load');
        }
    }
    
    setupCanvas() {
        this.canvas = document.createElement('div');
        this.canvas.id = 'workflow-canvas';
        this.canvas.className = 'workflow-canvas-surface';
        
        this.svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        this.svg.style.cssText = 'position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1;';
        this.canvas.appendChild(this.svg);
        
        // Initialize arrow markers
        this.initArrowMarkers();
        
        this.nodesContainer = document.createElement('div');
        this.nodesContainer.className = 'workflow-nodes-layer';
        this.canvas.appendChild(this.nodesContainer);
        
        // Empty state hint
        this.emptyState = document.createElement('div');
        this.emptyState.id = 'workflow-canvas-empty-state';
        this.emptyState.innerHTML = `
            <div style="text-align: center; padding: 48px 24px; color: #6c757d;">
                <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;">📋</div>
                <p style="font-size: 18px; font-weight: 500; color: #495057; margin-bottom: 8px;">Drag nodes here to get started</p>
                <p style="font-size: 14px; max-width: 320px; margin: 0 auto;">Start with a <strong>trigger</strong> from the palette above, then add <strong>actions</strong> and connect them.</p>
            </div>
        `;
        this.emptyState.innerHTML = `
            <div class="workflow-empty-state-content">
                <div class="workflow-empty-flow-mark" aria-hidden="true">
                    <span class="workflow-empty-flow-dot"></span>
                    <span class="workflow-empty-flow-diamond"></span>
                    <span class="workflow-empty-flow-square"></span>
                </div>
                <h2>Build your workflow</h2>
                <p>Drag a trigger here, then add actions to continue the path.</p>
            </div>
        `;
        this.canvas.appendChild(this.emptyState);

        this.dropOverlay = document.createElement('div');
        this.dropOverlay.id = 'workflow-drop-overlay';
        this.dropOverlay.hidden = true;
        this.dropOverlay.innerHTML = `
            <span class="workflow-drop-overlay-icon" aria-hidden="true">+</span>
            <strong>Drop to add node</strong>
            <span>Release anywhere on the grid. Placement snaps automatically.</span>
        `;
        this.canvas.appendChild(this.dropOverlay);

        this.dropMarker = document.createElement('div');
        this.dropMarker.id = 'workflow-drop-marker';
        this.dropMarker.hidden = true;
        this.canvas.appendChild(this.dropMarker);

        this.liveRegion = document.createElement('div');
        this.liveRegion.className = 'sr-only';
        this.liveRegion.setAttribute('aria-live', 'polite');
        this.canvas.appendChild(this.liveRegion);
        
        this.container.appendChild(this.canvas);
    }
    
    setupPalette() {
        const paletteHost = document.getElementById('workflow-palette-host');
        const useExternalPalette = Boolean(paletteHost);
        const paletteContainer = document.createElement('div');
        paletteContainer.id = 'workflow-palette-container';
        paletteContainer.className = useExternalPalette
            ? 'workflow-palette-container workflow-palette-container--side'
            : 'workflow-palette-container';
        paletteContainer.style.cssText = `
            margin-bottom: ${useExternalPalette ? '0' : '18px'};
            max-height: ${useExternalPalette ? 'calc(100vh - 190px)' : '340px'};
            overflow-y: auto;
            border: ${useExternalPalette ? '0' : '1px solid rgba(226, 232, 240, 0.95)'};
            border-radius: ${useExternalPalette ? '0' : '18px'};
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
            box-shadow: ${useExternalPalette ? 'none' : '0 16px 32px rgba(15, 23, 42, 0.06)'};
            overflow-x: hidden;
        `;
        
        // Search bar
        const searchBar = document.createElement('div');
        searchBar.className = 'workflow-palette-search';
        searchBar.style.cssText = `
            position: sticky;
            top: 0;
            z-index: 2;
            padding: 14px 14px 12px;
            border-bottom: 1px solid rgba(226, 232, 240, 0.92);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(8px);
        `;
        const searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.placeholder = 'Search nodes...';
        searchInput.setAttribute('aria-label', 'Search nodes');
        searchInput.className = 'workflow-palette-search-input';
        searchInput.style.cssText = `
            width: 100%;
            padding: 11px 14px;
            border: 1px solid rgba(203, 213, 225, 0.95);
            border-radius: 12px;
            background: #f8fafc;
            color: #0f172a;
            font-size: 13px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85);
        `;
        searchInput.addEventListener('input', (e) => {
            this.filterPalette(e.target.value);
        });
        searchBar.appendChild(searchInput);
        paletteContainer.appendChild(searchBar);
        
        // Category tabs
        const filtersShell = document.createElement('div');
        filtersShell.className = 'workflow-palette-filters';
        filtersShell.style.cssText = `
            border-bottom: 1px solid rgba(226, 232, 240, 0.92);
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(8px);
        `;

        let filtersCollapsed = false;
        const filtersToggle = document.createElement('button');
        filtersToggle.type = 'button';
        filtersToggle.className = 'workflow-filter-toggle';
        filtersToggle.setAttribute('aria-expanded', String(!filtersCollapsed));
        filtersToggle.style.cssText = `
            display: ${useExternalPalette ? 'flex' : 'none'};
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            width: 100%;
            padding: 9px 12px;
            border: 0;
            background: transparent;
            color: #334155;
            cursor: pointer;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        `;
        filtersToggle.innerHTML = '<span>Filters</span><span data-filter-toggle-icon>Show</span>';

        const tabsContainer = document.createElement('div');
        tabsContainer.className = 'workflow-category-tabs';
        tabsContainer.setAttribute('aria-label', 'Filter nodes');
        tabsContainer.style.cssText = `
            position: ${useExternalPalette ? 'static' : 'sticky'};
            top: ${useExternalPalette ? 'auto' : '63px'};
            z-index: 1;
            display: ${useExternalPalette && filtersCollapsed ? 'none' : (useExternalPalette ? 'grid' : 'flex')};
            grid-template-columns: ${useExternalPalette ? 'repeat(2, minmax(0, 1fr))' : 'none'};
            gap: ${useExternalPalette ? '7px' : '8px'};
            padding: ${useExternalPalette ? '0 10px 10px' : '10px 14px 12px'};
            overflow-x: ${useExternalPalette ? 'visible' : 'auto'};
        `;

        const syncFilterToggle = () => {
            if (!useExternalPalette) {
                return;
            }
            const icon = filtersToggle.querySelector('[data-filter-toggle-icon]');
            tabsContainer.style.display = filtersCollapsed ? 'none' : 'grid';
            filtersToggle.setAttribute('aria-expanded', String(!filtersCollapsed));
            if (icon) {
                icon.textContent = filtersCollapsed ? 'Show' : 'Hide';
            }
        };

        filtersToggle.addEventListener('click', () => {
            filtersCollapsed = !filtersCollapsed;
            syncFilterToggle();
        });
        
        const categories = [
            { value: 'all', label: 'All' },
            { value: 'trigger', label: 'Triggers' },
            { value: 'action', label: 'Actions' },
            { value: 'logic', label: 'Logic' }
        ];
        let activeCategory = 'all';
        
        categories.forEach(categoryOption => {
            const category = categoryOption.value;
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.textContent = categoryOption.label;
            tab.dataset.category = category;
            tab.className = 'workflow-category-tab';
            tab.style.cssText = `
                width: ${useExternalPalette ? '100%' : 'auto'};
                min-width: 0;
                padding: ${useExternalPalette ? '7px 6px' : '8px 14px'};
                border: 1px solid ${category === activeCategory ? 'rgba(96, 165, 250, 0.6)' : 'rgba(226, 232, 240, 0.95)'};
                background: ${category === activeCategory ? 'linear-gradient(135deg, #2563eb, #4f46e5)' : 'rgba(248, 250, 252, 0.95)'};
                color: ${category === activeCategory ? '#ffffff' : '#334155'};
                border-radius: 999px;
                cursor: pointer;
                font-size: ${useExternalPalette ? '10px' : '12px'};
                font-weight: 600;
                white-space: nowrap;
                overflow: visible;
                text-overflow: clip;
                box-shadow: ${category === activeCategory ? '0 10px 22px rgba(37, 99, 235, 0.18)' : 'none'};
                transition: background 0.2s ease, color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
            `;
            tab.addEventListener('click', () => {
                activeCategory = category;
                tabsContainer.querySelectorAll('button').forEach(btn => {
                    const isActive = btn.dataset.category === category;
                    btn.classList.toggle('is-active', isActive);
                    btn.setAttribute('aria-pressed', String(isActive));
                    btn.style.background = isActive ? 'linear-gradient(135deg, #2563eb, #4f46e5)' : 'rgba(248, 250, 252, 0.95)';
                    btn.style.color = isActive ? '#ffffff' : '#334155';
                    btn.style.borderColor = isActive ? 'rgba(96, 165, 250, 0.6)' : 'rgba(226, 232, 240, 0.95)';
                    btn.style.boxShadow = isActive ? '0 10px 22px rgba(37, 99, 235, 0.18)' : 'none';
                });
                this.renderPaletteItems(palette, category);
            });
            tab.classList.toggle('is-active', category === activeCategory);
            tab.setAttribute('aria-pressed', String(category === activeCategory));
            tabsContainer.appendChild(tab);
        });
        filtersShell.appendChild(filtersToggle);
        filtersShell.appendChild(tabsContainer);
        paletteContainer.appendChild(filtersShell);
        syncFilterToggle();

        const quickStarts = document.getElementById('workflow-create-recommendations');
        if (useExternalPalette && quickStarts) {
            paletteContainer.appendChild(quickStarts);
        }
        
        // Palette items container
        const palette = document.createElement('div');
        palette.id = 'workflow-palette';
        palette.className = 'workflow-palette-list';
        palette.style.cssText = `
            display: ${useExternalPalette ? 'grid' : 'flex'};
            grid-template-columns: ${useExternalPalette ? '1fr' : 'none'};
            flex-wrap: wrap;
            gap: ${useExternalPalette ? '9px' : '12px'};
            padding: ${useExternalPalette ? '12px' : '16px'};
        `;
        paletteContainer.appendChild(palette);
        this.paletteContainer = paletteContainer;
        this.palette = palette;
        
        // Render initial items
        this.renderPaletteItems(palette, 'all');
        
        if (useExternalPalette) {
            paletteHost.innerHTML = '';
            paletteHost.appendChild(paletteContainer);
        } else if (this.canvas && this.container.contains(this.canvas)) {
            this.container.insertBefore(paletteContainer, this.canvas);
        } else {
            this.container.appendChild(paletteContainer);
        }
    }
    
    async renderPaletteItems(palette, category) {
        palette.innerHTML = '';
        
        if (!window.WorkflowNodeDefinitions) {
            console.error('WorkflowNodeDefinitions not loaded');
            return;
        }
        
        const nodeDefs = window.WorkflowNodeDefinitions;
        
        // Ensure options are loaded
        if (!nodeDefs.optionsLoaded) {
            const loaded = await nodeDefs.loadOptions();
            nodeDefs.optionsLoaded = Boolean(loaded);
        }
        
        // Add triggers
        Object.keys(nodeDefs.triggers).forEach(key => {
            const nodeDef = nodeDefs.triggers[key];
            if (category === 'all' || category === 'trigger') {
                this.addPaletteItem(palette, key, nodeDef, 'trigger');
            }
        });
        
        // Add condition
        if (category === 'all' || category === 'logic') {
            this.addPaletteItem(palette, 'condition', nodeDefs.conditions.condition, 'condition');
        }
        
        // Add actions
        Object.keys(nodeDefs.actions).forEach(key => {
            const nodeDef = nodeDefs.actions[key];
            if (category === 'all' || category === 'action') {
                this.addPaletteItem(palette, key, nodeDef, 'action');
            }
        });
        
        // Add delay (wait_for_days)
        if (category === 'all' || category === 'logic') {
            const delayDef = nodeDefs.actions.wait_for_days;
            this.addPaletteItem(palette, 'delay', delayDef, 'delay');
        }
    }
    
    addPaletteItem(palette, key, nodeDef, nodeType) {
        const isSidePalette = this.paletteContainer && this.paletteContainer.classList.contains('workflow-palette-container--side');
        const item = document.createElement('div');
        item.draggable = true;
        item.dataset.nodeKey = key;
        item.dataset.nodeType = nodeType;
        item.title = nodeDef.description || nodeDef.label;
        item.className = `workflow-palette-item${isSidePalette ? ' workflow-palette-item--side' : ''}`;
        item.style.setProperty('--node-color', nodeDef.color || '#64748b');
        item.setAttribute('role', 'group');
        item.setAttribute('aria-label', `${nodeDef.label}. ${nodeType}. Drag to the canvas or use Add.`);
        item.innerHTML = `
            <span class="workflow-palette-grip" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span>
            <span class="workflow-palette-icon" aria-hidden="true">${nodeDef.icon}</span>
            <span class="workflow-palette-copy">
                <small>${nodeType === 'delay' ? 'Logic' : nodeType}</small>
                <strong>${nodeDef.label}</strong>
            </span>
            <button type="button" class="workflow-palette-add" aria-label="Add ${nodeDef.label} to canvas">Add</button>
        `;

        const addButton = item.querySelector('.workflow-palette-add');
        if (addButton) {
            addButton.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                this.addNodeFromPalette(key, nodeType, nodeDef.color, nodeDef.label);
            });
        }
        
        item.addEventListener('dragstart', (e) => {
            e.dataTransfer.setData('nodeKey', key);
            e.dataTransfer.setData('nodeType', nodeType);
            e.dataTransfer.setData('color', nodeDef.color);
            e.dataTransfer.effectAllowed = 'copy';
            item.classList.add('is-dragging');
            document.body.classList.add('is-dragging-workflow-node');
            this.activeDragLabel = nodeDef.label;
        });
        
        item.addEventListener('dragend', () => {
            item.classList.remove('is-dragging');
            this.setDropState(false);
        });
        
        palette.appendChild(item);
    }
    
    filterPalette(searchTerm) {
        const items = this.palette.querySelectorAll('[data-node-key]');
        const term = searchTerm.toLowerCase();
        
        items.forEach(item => {
            const label = item.textContent.toLowerCase();
            const matches = label.includes(term);
            item.hidden = !matches;
        });
    }

    setDropState(active) {
        if (!this.canvas) return;
        this.canvas.classList.toggle('is-drop-target', active);
        document.body.classList.toggle('is-dragging-workflow-node', active);
        if (this.dropOverlay) {
            this.dropOverlay.hidden = !active;
        }
        if (!active && this.dropMarker) {
            this.dropMarker.hidden = true;
        }
    }

    snapCanvasPosition(x, y, nodeWidth = 220, nodeHeight = 96) {
        const grid = 24;
        const zoom = this.currentZoom || 1;
        const rect = this.canvas ? this.canvas.getBoundingClientRect() : { width: 960, height: 720 };
        const maxX = Math.max(grid, (rect.width / zoom) - nodeWidth - grid);
        const maxY = Math.max(grid, (rect.height / zoom) - nodeHeight - 76);
        return {
            x: Math.max(grid, Math.min(maxX, Math.round(x / grid) * grid)),
            y: Math.max(grid, Math.min(maxY, Math.round(y / grid) * grid))
        };
    }

    getCanvasPlacement(clientX, clientY) {
        const rect = this.canvas.getBoundingClientRect();
        const zoom = this.currentZoom || 1;
        return this.snapCanvasPosition(
            ((clientX - rect.left) / zoom) - 110,
            ((clientY - rect.top) / zoom) - 48
        );
    }

    getSuggestedNodePosition() {
        if (this.selectedNode) {
            const beside = this.snapCanvasPosition(this.selectedNode.x + 276, this.selectedNode.y);
            if (beside.x > this.selectedNode.x) {
                return beside;
            }
            return this.snapCanvasPosition(this.selectedNode.x, this.selectedNode.y + 144);
        }

        if (this.nodes.length > 0) {
            const lastNode = this.nodes[this.nodes.length - 1];
            return this.snapCanvasPosition(lastNode.x, lastNode.y + 144);
        }

        const rect = this.canvas.getBoundingClientRect();
        return this.snapCanvasPosition((rect.width / 2) - 110, Math.max(120, (rect.height / 2) - 100));
    }

    addNodeFromPalette(key, nodeType, color, label) {
        const position = this.getSuggestedNodePosition();
        this.addNode(key, position.x, position.y, color, nodeType);
        this.announceCanvasChange(`${label || 'Node'} added to the canvas.`);
    }

    announceCanvasChange(message) {
        window.dispatchEvent(new CustomEvent('workflow:stats-changed'));
        if (this.liveRegion) {
            this.liveRegion.textContent = '';
            window.requestAnimationFrame(() => {
                this.liveRegion.textContent = message;
            });
        }
    }
    
    setupEventListeners() {
        this.canvas.addEventListener('dragenter', (e) => {
            e.preventDefault();
            this.setDropState(true);
        });

        this.canvas.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (e.dataTransfer) {
                e.dataTransfer.dropEffect = 'copy';
            }
            this.setDropState(true);
            const position = this.getCanvasPlacement(e.clientX, e.clientY);
            if (this.dropMarker) {
                this.dropMarker.hidden = false;
                this.dropMarker.style.left = `${position.x}px`;
                this.dropMarker.style.top = `${position.y}px`;
            }
        });
        
        this.canvas.addEventListener('dragleave', (e) => {
            if (!e.relatedTarget || !this.canvas.contains(e.relatedTarget)) {
                this.setDropState(false);
            }
        });
        
        this.canvas.addEventListener('drop', (e) => {
            e.preventDefault();
            this.setDropState(false);
            
            const nodeKey = e.dataTransfer.getData('nodeKey');
            const nodeType = e.dataTransfer.getData('nodeType');
            const color = e.dataTransfer.getData('color');
            
            if (nodeKey || nodeType) {
                const position = this.getCanvasPlacement(e.clientX, e.clientY);
                this.addNode(nodeKey || nodeType, position.x, position.y, color, nodeType);
                this.announceCanvasChange(`${this.activeDragLabel || 'Node'} added to the canvas.`);
            }
            this.activeDragLabel = '';
        });
        
        // Zoom controls
        this.setupZoomControls();
        
        // History controls (undo/redo)
        this.setupHistoryControls();
        
        // Initialize minimap after canvas is ready
        setTimeout(() => {
            if (this.minimap) {
                this.minimap.init();
            }
        }, 100);
    }
    
    setupHistoryControls() {
        const controls = document.getElementById('workflow-controls') || document.createElement('div');
        controls.id = 'workflow-controls';
        controls.style.cssText = `
            position: absolute;
            top: 16px;
            left: 16px;
            display: flex;
            gap: 8px;
            z-index: 10;
            flex-wrap: wrap;
        `;
        
        const undoBtn = document.createElement('button');
        undoBtn.id = 'workflow-undo-btn';
        undoBtn.innerHTML = '↶';
        undoBtn.title = 'Undo (Ctrl+Z)';
        undoBtn.textContent = 'Undo';
        undoBtn.style.cssText = `
            min-width: 58px;
            height: 36px;
            padding: 0 14px;
            border: 1px solid rgba(226, 232, 240, 0.95);
            background: rgba(255, 255, 255, 0.94);
            border-radius: 999px;
            cursor: pointer;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(10px);
        `;
        undoBtn.addEventListener('click', () => this.historyManager.undo());
        
        const redoBtn = document.createElement('button');
        redoBtn.id = 'workflow-redo-btn';
        redoBtn.innerHTML = '↷';
        redoBtn.title = 'Redo (Ctrl+Y)';
        redoBtn.textContent = 'Redo';
        redoBtn.style.cssText = undoBtn.style.cssText;
        redoBtn.addEventListener('click', () => this.historyManager.redo());
        
        controls.appendChild(undoBtn);
        controls.appendChild(redoBtn);
        
        if (!document.getElementById('workflow-controls')) {
            this.canvas.appendChild(controls);
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
                e.preventDefault();
                this.historyManager.undo();
            } else if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
                e.preventDefault();
                this.historyManager.redo();
            }
        });
    }
    
    setupZoomControls() {
        const controls = document.createElement('div');
        controls.id = 'workflow-zoom-controls';
        controls.style.cssText = `
            position: absolute;
            top: 16px;
            right: 16px;
            display: flex;
            gap: 8px;
            z-index: 10;
            flex-wrap: wrap;
            justify-content: flex-end;
        `;
        
        const zoomIn = this.createButton('+', () => this.zoom(1.1));
        zoomIn.title = 'Zoom in';
        const zoomOut = this.createButton('−', () => this.zoom(0.9));
        zoomOut.title = 'Zoom out';
        zoomOut.textContent = '-';
        const resetZoom = this.createButton('⌂', () => this.zoom(1, true));
        resetZoom.title = 'Reset zoom';
        resetZoom.textContent = '100%';
        const zoomFit = this.createButton('⊡', () => this.zoomToFit());
        zoomFit.title = 'Zoom to fit';
        zoomFit.textContent = 'Fit';
        const autoLayout = this.createButton('⊞', () => this.autoLayout());
        autoLayout.title = 'Auto-layout nodes';
        autoLayout.textContent = 'Auto';

        controls.appendChild(zoomIn);
        controls.appendChild(zoomOut);
        controls.appendChild(resetZoom);
        controls.appendChild(zoomFit);
        controls.appendChild(autoLayout);
        
        this.canvas.appendChild(controls);
        this.currentZoom = 1;
    }
    
    createButton(label, onClick) {
        const btn = document.createElement('button');
        btn.textContent = label;
        btn.style.cssText = `
            min-width: 42px;
            height: 36px;
            padding: 0 12px;
            border: 1px solid rgba(226, 232, 240, 0.95);
            background: rgba(255, 255, 255, 0.94);
            border-radius: 999px;
            cursor: pointer;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(10px);
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease;
        `;
        btn.addEventListener('click', onClick);
        btn.addEventListener('mouseenter', () => {
            btn.style.transform = 'translateY(-1px)';
            btn.style.borderColor = 'rgba(148, 163, 184, 0.95)';
            btn.style.boxShadow = '0 16px 28px rgba(15, 23, 42, 0.1)';
        });
        btn.addEventListener('mouseleave', () => {
            btn.style.transform = 'translateY(0)';
            btn.style.borderColor = 'rgba(226, 232, 240, 0.95)';
            btn.style.boxShadow = '0 12px 24px rgba(15, 23, 42, 0.08)';
        });
        return btn;
    }
    
    zoom(factor, reset = false) {
        if (reset) {
            this.currentZoom = 1;
        } else {
            this.currentZoom *= factor;
            this.currentZoom = Math.max(0.5, Math.min(2, this.currentZoom));
        }

        this.nodesContainer.style.transform = `scale(${this.currentZoom})`;
        this.nodesContainer.style.transformOrigin = 'top left';
    }

    zoomToFit() {
        if (this.nodes.length === 0) return;
        const padding = 50;
        let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        this.nodes.forEach(n => {
            minX = Math.min(minX, n.x);
            minY = Math.min(minY, n.y);
            const nodeEl = document.getElementById(n.id);
            const w = nodeEl ? nodeEl.offsetWidth : 180;
            const h = nodeEl ? nodeEl.offsetHeight : 80;
            maxX = Math.max(maxX, n.x + w);
            maxY = Math.max(maxY, n.y + h);
        });
        const canvasRect = this.canvas.getBoundingClientRect();
        const scaleX = (canvasRect.width - padding * 2) / (maxX - minX + padding);
        const scaleY = (canvasRect.height - padding * 2) / (maxY - minY + padding);
        this.currentZoom = Math.max(0.3, Math.min(1.5, Math.min(scaleX, scaleY)));
        this.nodesContainer.style.transform = `scale(${this.currentZoom})`;
        this.nodesContainer.style.transformOrigin = 'top left';
    }

    autoLayout() {
        if (this.nodes.length === 0) return;
        const trigger = this.nodes.find(n => n.type === 'trigger');
        if (!trigger) return;
        const nodeWidth = 200;
        const nodeHeight = 100;
        const hGap = 80;
        const vGap = 60;
        const visited = new Set();
        const positions = {};
        const getOutgoing = (nodeId) =>
            this.connectionManager.connections.filter(c => c.fromNodeId === nodeId).map(c => c.toNodeId);

        const layout = (nodeId, x, y, depth) => {
            if (visited.has(nodeId)) return;
            visited.add(nodeId);
            positions[nodeId] = { x, y };
            const nextIds = getOutgoing(nodeId);
            nextIds.forEach((nextId, i) => {
                layout(nextId, x + nodeWidth + hGap, y + i * (nodeHeight + vGap), depth + 1);
            });
        };
        layout(trigger.id, 50, 50, 0);
        this.nodes.forEach(n => {
            if (positions[n.id]) {
                n.x = positions[n.id].x;
                n.y = positions[n.id].y;
                const el = document.getElementById(n.id);
                if (el) {
                    el.style.left = n.x + 'px';
                    el.style.top = n.y + 'px';
                }
            }
        });
        this.connectionManager.redrawAll();
    }

    addNode(keyOrType, x, y, color, nodeType = null) {
        this.nodeIdCounter++;
        const nodeId = `node_${this.nodeIdCounter}`;
        
        // Get node definition
        let nodeDef = null;
        let actualType = nodeType || keyOrType;
        
        if (window.WorkflowNodeDefinitions) {
            nodeDef = window.WorkflowNodeDefinitions.getNode(keyOrType);
            if (nodeDef) {
                actualType = nodeDef.type;
                color = nodeDef.color;
            }
        }
        
        // Determine node type if not provided
        if (!actualType) {
            if (keyOrType.startsWith('contact_') || keyOrType.startsWith('email_') || 
                keyOrType.startsWith('deal_') || keyOrType.startsWith('task_') ||
                keyOrType.startsWith('form_') || keyOrType.startsWith('stage_') ||
                keyOrType.startsWith('activity_') || keyOrType.startsWith('daily_') ||
                keyOrType.startsWith('weekly_') || keyOrType.startsWith('monthly_') ||
                keyOrType.startsWith('on_') || keyOrType.startsWith('webhook_') ||
                keyOrType.startsWith('api_')) {
                actualType = 'trigger';
            } else if (keyOrType === 'condition') {
                actualType = 'condition';
            } else if (keyOrType === 'delay' || keyOrType === 'wait_for_days') {
                actualType = 'delay';
            } else {
                actualType = 'action';
            }
        }
        
        const node = {
            id: nodeId,
            type: actualType,
            key: keyOrType,
            x: x,
            y: y,
            color: color || '#6c757d',
            data: this.getDefaultNodeData(keyOrType, actualType, nodeDef)
        };
        
        // Execute via history manager
        const command = new AddNodeCommand(this, node);
        this.historyManager.execute(command);
        this.updateEmptyState();
        this.selectNode(node);
        return node;
    }
    
    getDefaultNodeData(keyOrType, nodeType, nodeDef) {
        // Use node definition if available
        if (nodeDef && nodeDef.config) {
            const data = {};
            nodeDef.config.fields.forEach(field => {
                if (field.default !== undefined) {
                    data[field.name] = field.default;
                } else if (field.type === 'number') {
                    data[field.name] = 0;
                } else {
                    data[field.name] = '';
                }
            });
            return data;
        }
        
        // Fallback to defaults
        switch(nodeType) {
            case 'trigger':
                return { type: keyOrType || 'contact_created' };
            case 'condition':
                return { field: '', operator: '', value: '' };
            case 'action':
                return { type: keyOrType || 'send_email', subject: '', body: '' };
            case 'delay':
                return { days: 1 };
            default:
                return {};
        }
    }
    
    renderNode(node) {
        const nodeEl = document.createElement('div');
        nodeEl.id = node.id;
        nodeEl.className = 'workflow-node is-entering';
        nodeEl.dataset.nodeId = node.id;
        nodeEl.style.left = `${node.x}px`;
        nodeEl.style.top = `${node.y}px`;
        nodeEl.style.setProperty('--node-color', node.color || '#64748b');
        
        nodeEl.innerHTML = this.getNodeHTML(node);
        
        // Make draggable
        this.makeNodeDraggable(nodeEl, node);
        
        // Add click handler for selection
        nodeEl.addEventListener('click', (e) => {
            e.stopPropagation();
            
            // Multi-select with Ctrl/Cmd
            if (e.ctrlKey || e.metaKey) {
                if (this.shortcutManager.selectedNodes.has(node.id)) {
                    this.shortcutManager.selectedNodes.delete(node.id);
                    nodeEl.classList.remove('is-multi-selected');
                } else {
                    this.shortcutManager.selectedNodes.add(node.id);
                    nodeEl.classList.add('is-multi-selected');
                }
            } else {
                this.shortcutManager.deselectAll();
                this.selectNode(node);
            }
        });
        
        // Add delete button
        const deleteBtn = document.createElement('button');
        deleteBtn.innerHTML = '×';
        deleteBtn.type = 'button';
        deleteBtn.className = 'workflow-node-delete';
        deleteBtn.setAttribute('aria-label', 'Delete node');
        deleteBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            this.deleteNode(node.id);
        });
        nodeEl.appendChild(deleteBtn);
        
        this.nodesContainer.appendChild(nodeEl);

        nodeEl.addEventListener('animationend', () => {
            nodeEl.classList.remove('is-entering');
        }, { once: true });
        
        // Add connection handles
        this.connectionManager.addConnectionHandles(nodeEl, node);
    }
    
    getNodeHTML(node) {
        const icons = {
            trigger: '⚡',
            condition: '🔀',
            action: '⚙️',
            delay: '⏱️'
        };
        
        const labels = {
            trigger: 'Trigger',
            condition: 'Condition',
            action: 'Action',
            delay: 'Delay'
        };
        
        let content = `<div style="font-weight: 600; margin-bottom: 8px; color: ${node.color};">${icons[node.type]} ${labels[node.type]}</div>`;
        
        if (node.type === 'trigger') {
            content += `<select class="node-input" data-field="type" style="width: 100%; padding: 4px; border: 1px solid #dee2e6; border-radius: 4px;">
                <option value="contact_created" ${node.data.type === 'contact_created' ? 'selected' : ''}>Contact Created</option>
                <option value="email_opened" ${node.data.type === 'email_opened' ? 'selected' : ''}>Email Opened</option>
                <option value="stage_changed" ${node.data.type === 'stage_changed' ? 'selected' : ''}>Stage Changed</option>
            </select>`;
        } else if (node.type === 'action') {
            content += `<select class="node-input" data-field="type" style="width: 100%; padding: 4px; border: 1px solid #dee2e6; border-radius: 4px; margin-bottom: 4px;">
                <option value="send_email" ${node.data.type === 'send_email' ? 'selected' : ''}>Send Email</option>
                <option value="change_stage" ${node.data.type === 'change_stage' ? 'selected' : ''}>Change Stage</option>
                <option value="add_tag" ${node.data.type === 'add_tag' ? 'selected' : ''}>Add Tag</option>
            </select>`;
            if (node.data.type === 'send_email') {
                content += `<input type="text" class="node-input" data-field="subject" placeholder="Subject" value="${node.data.subject || ''}" style="width: 100%; padding: 4px; border: 1px solid #dee2e6; border-radius: 4px; margin-bottom: 4px;">`;
            }
        } else if (node.type === 'delay') {
            content += `<input type="number" class="node-input" data-field="days" placeholder="Days" value="${node.data.days || 1}" min="1" style="width: 100%; padding: 4px; border: 1px solid #dee2e6; border-radius: 4px;">`;
        }
        
        return content;
    }
    
    makeNodeDraggable(nodeEl, node) {
        let isDragging = false;
        let activePointerId = null;
        let startX;
        let startY;
        let startLeft;
        let startTop;

        const onPointerDown = (e) => {
            const interactiveTarget = e.target.closest('input, select, textarea, button, .connection-handle');
            if (interactiveTarget || (e.pointerType === 'mouse' && e.button !== 0)) {
                return;
            }
            
            isDragging = true;
            activePointerId = e.pointerId;
            this.isMovingNode = true;
            startX = e.clientX;
            startY = e.clientY;
            startLeft = node.x;
            startTop = node.y;
            this.moveStartPos = { x: startLeft, y: startTop };
            nodeEl.classList.add('is-moving');
            nodeEl.setPointerCapture?.(e.pointerId);
            e.preventDefault();
            document.addEventListener('pointermove', onPointerMove);
            document.addEventListener('pointerup', onPointerUp);
            document.addEventListener('pointercancel', onPointerUp);
        };
        
        const onPointerMove = (e) => {
            if (!isDragging || e.pointerId !== activePointerId) return;
            
            const zoom = this.currentZoom || 1;
            const dx = (e.clientX - startX) / zoom;
            const dy = (e.clientY - startY) / zoom;
            const position = this.snapCanvasPosition(startLeft + dx, startTop + dy);
            node.x = position.x;
            node.y = position.y;
            
            nodeEl.style.left = `${node.x}px`;
            nodeEl.style.top = `${node.y}px`;
            
            this.connectionManager.redrawAll();
        };
        
        const onPointerUp = (e) => {
            if (!isDragging || (e.pointerId != null && e.pointerId !== activePointerId)) return;

            if (this.moveStartPos && (node.x !== this.moveStartPos.x || node.y !== this.moveStartPos.y)) {
                const command = new MoveNodeCommand(
                    this,
                    node,
                    this.moveStartPos.x,
                    this.moveStartPos.y,
                    node.x,
                    node.y
                );
                this.historyManager.execute(command);
                this.announceCanvasChange('Node moved and snapped to the workflow grid.');
            }
            
            isDragging = false;
            activePointerId = null;
            this.isMovingNode = false;
            this.moveStartPos = null;
            nodeEl.classList.remove('is-moving');
            document.removeEventListener('pointermove', onPointerMove);
            document.removeEventListener('pointerup', onPointerUp);
            document.removeEventListener('pointercancel', onPointerUp);
        };

        nodeEl.addEventListener('pointerdown', onPointerDown);
        
        // Handle input changes
        nodeEl.addEventListener('change', (e) => {
            if (e.target.classList.contains('node-input')) {
                const field = e.target.dataset.field;
                const oldValue = node.data[field];
                const newValue = e.target.value;
                
                // Execute via history manager
                const command = new UpdateNodeDataCommand(this, node, field, oldValue, newValue);
                this.historyManager.execute(command);
            }
        });
    }
    
    selectNode(node) {
        // Deselect previous
        if (this.selectedNode) {
            const prevEl = document.getElementById(this.selectedNode.id);
            if (prevEl) {
                prevEl.classList.remove('selected');
            }
        }
        
        this.selectedNode = node;
        const nodeEl = document.getElementById(node.id);
        if (nodeEl) {
            nodeEl.classList.add('selected');
        }
        
        // Show configuration panel
        this.showNodeConfig(node);
    }
    
    showNodeConfig(node) {
        if (!this.configPanel) {
            this.configPanel = new NodeConfigPanel(this);
        }
        this.configPanel.show(node);
    }
    
    closeConfig() {
        if (this.configPanel) {
            this.configPanel.hide();
        }
        if (this.selectedNode) {
            document.getElementById(this.selectedNode.id)?.classList.remove('selected');
        }
        this.selectedNode = null;
    }
    
    updateEmptyState() {
        if (!this.emptyState) return;
        this.emptyState.hidden = this.nodes.length !== 0;
    }
    
    deleteNode(nodeId) {
        const node = this.nodes.find(n => n.id === nodeId);
        if (!node) return;
        
        const connections = this.connectionManager.getConnectionsForNode(nodeId);
        
        // Execute via history manager
        const command = new DeleteNodeCommand(this, node, connections);
        this.historyManager.execute(command);
        this.updateEmptyState();
        this.closeConfig();
        this.announceCanvasChange('Node deleted from the workflow.');
    }
    
    /**
     * Callback when connection is created
     */
    onConnectionCreated(connection) {
        // Record in history
        const command = new CreateConnectionCommand(this, connection);
        this.historyManager.execute(command);
    }
    
    /**
     * Callback when connection is deleted
     */
    onConnectionDeleted(connectionId) {
        // Find and record deletion
        const connection = this.connectionManager.connections.find(c => c.id === connectionId);
        if (connection) {
            // Restore it temporarily for undo
            this.connectionManager.connections.push(connection);
            const command = new DeleteConnectionCommand(this, connection);
            this.historyManager.execute(command);
        }
    }
    
    /**
     * Initialize SVG arrow markers
     */
    initArrowMarkers() {
        if (!this.svg.querySelector('#arrowhead')) {
            const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
            const marker = document.createElementNS('http://www.w3.org/2000/svg', 'marker');
            marker.id = 'arrowhead';
            marker.setAttribute('markerWidth', '10');
            marker.setAttribute('markerHeight', '10');
            marker.setAttribute('refX', '9');
            marker.setAttribute('refY', '3');
            marker.setAttribute('orient', 'auto');
            const polygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
            polygon.setAttribute('points', '0 0, 10 3, 0 6');
            polygon.setAttribute('fill', '#6c757d');
            marker.appendChild(polygon);
            defs.appendChild(marker);
            this.svg.appendChild(defs);
        }
    }
    
    loadFromForm() {
        // Load workflow data from form when switching from form to visual mode
        const triggerTypeEl = document.getElementById('trigger_type');
        const nameEl = document.getElementById('name');
        const visualNameEl = document.getElementById('visual-workflow-name');
        const actionsContainer = document.getElementById('actions_container');

        const triggerType = triggerTypeEl?.value?.trim();
        if (!triggerType) return;

        const actions = [];
        if (actionsContainer) {
            const actionDivs = actionsContainer.querySelectorAll('[id^="action_"]');
            actionDivs.forEach(div => {
                const match = div.id.match(/action_(\d+)/);
                if (!match) return;
                const id = match[1];
                const typeSelect = div.querySelector(`select[name="actions[${id}][type]"]`);
                const type = typeSelect?.value?.trim();
                if (!type) return;

                const action = { type };
                if (type === 'send_email') {
                    const tid = div.querySelector(`select[name="actions[${id}][template_id]"]`);
                    const subj = div.querySelector(`input[name="actions[${id}][subject]"]`);
                    const body = div.querySelector(`textarea[name="actions[${id}][body]"]`);
                    if (tid) action.template_id = parseInt(tid.value, 10) || 0;
                    if (subj) action.subject = subj.value || '';
                    if (body) action.body = body.value || '';
                } else if (type === 'send_whatsapp') {
                    const msg = div.querySelector(`textarea[name="actions[${id}][message]"]`);
                    if (msg) action.message = msg.value || '';
                } else if (type === 'change_stage') {
                    const stage = div.querySelector(`select[name="actions[${id}][stage]"]`);
                    if (stage) action.stage = stage.value || '';
                } else if (type === 'assign_to_user') {
                    const uid = div.querySelector(`select[name="actions[${id}][user_id]"]`);
                    if (uid) action.user_id = parseInt(uid.value, 10) || 0;
                } else if (type === 'wait_for_days') {
                    const days = div.querySelector(`input[name="actions[${id}][days]"]`);
                    if (days) action.days = parseInt(days.value, 10) || 1;
                }
                actions.push(action);
            });
        }

        if (actions.length === 0) return;

        const triggerConfig = { type: triggerType };
        const daysEl = document.getElementById('trigger_days');
        const fromStageEl = document.getElementById('from_stage');
        const toStageEl = document.getElementById('to_stage');
        if (triggerType === 'no_activity_for_days' && daysEl) triggerConfig.days = parseInt(daysEl.value, 10) || 7;
        if (triggerType === 'stage_changed') {
            if (fromStageEl) triggerConfig.from_stage = fromStageEl.value || '';
            if (toStageEl) triggerConfig.to_stage = toStageEl.value || '';
        }

        const workflowData = {
            name: nameEl?.value || visualNameEl?.value || 'Workflow',
            trigger_config: triggerConfig,
            actions: actions
        };

            this.clear();
            const triggerNode = this.addNodeFromData('trigger', triggerConfig.type || 'contact_created', 100, 100, triggerConfig, true);
            const createdNodes = [triggerNode];

            actions.forEach((action, index) => {
                const y = 200 + (index * 150);
                let node;
                if (action.type === 'wait_for_days') {
                    node = this.addNodeFromData('delay', 'wait_for_days', 100, y, { days: action.days || 1 }, true);
                } else {
                    node = this.addNodeFromData('action', action.type, 100, y, action, true);
                }
                createdNodes.push(node);
            });

        for (let i = 0; i < createdNodes.length - 1; i++) {
            const conn = {
                id: `conn_${++this.connectionManager.connectionIdCounter}`,
                fromNodeId: createdNodes[i].id,
                toNodeId: createdNodes[i + 1].id,
                branchType: null,
                type: 'success'
            };
            this.connectionManager.connections.push(conn);
            this.connectionManager.drawConnection(conn);
        }

        const name = workflowData.name;
        if (name && nameEl) nameEl.value = name;
        if (name && visualNameEl) visualNameEl.value = name;

        this.connectionManager.redrawAll();
        this.updateEmptyState();
    }
    
    exportToForm() {
        // Export visual workflow to form fields
        if (this.nodes.length === 0) return null;
        
        const triggerNode = this.nodes.find(n => n.type === 'trigger');
        if (!triggerNode) {
            this.notify('warning', 'Please add a trigger node before exporting or saving.', 'Trigger required');
            return null;
        }
        
        // Build workflow structure based on connections
        const workflowStructure = this.buildWorkflowStructure(triggerNode);
        
        const graph = this.exportGraph();

        const triggerConfig = Object.assign({}, triggerNode.data || {}, {
            type: triggerNode.data.type || triggerNode.key || 'contact_created'
        });

        return {
            trigger: triggerConfig,
            actions: workflowStructure.actions,
            conditions: workflowStructure.conditions,
            graph: graph,
            branches: this.connectionManager.export(),
            visual_data: {
                nodes: this.nodes,
                connections: this.connectionManager.export()
            }
        };
    }

    exportGraph() {
        const modeEl = document.getElementById('workflow-mode');
        const nameEl = document.getElementById('visual-workflow-name') || document.getElementById('name');
        return {
            version: 2,
            meta: {
                mode: modeEl?.value || 'mixed',
                name: nameEl?.value || 'Workflow'
            },
            nodes: this.nodes.map(node => ({
                id: node.id,
                type: node.type,
                subtype: node.key || node.data?.type || node.type,
                position: {
                    x: parseInt(node.x, 10) || 100,
                    y: parseInt(node.y, 10) || 100
                },
                config: Object.assign({}, node.data || {})
            })),
            edges: this.connectionManager.export().map((conn, index) => {
                const source = conn.fromNodeId || conn.from || conn.source || '';
                const sourceNode = this.nodes.find(node => node.id === source);
                const isConditionalBranch = sourceNode?.type === 'condition'
                    && ['true', 'false'].includes(String(conn.branchType || ''));

                return {
                    id: conn.id || `edge_${index + 1}`,
                    source: source,
                    target: conn.toNodeId || conn.to || conn.target || '',
                    branch: isConditionalBranch ? conn.branchType : 'default',
                    order: index
                };
            })
        };
    }
    
    /**
     * Export workflow as JSON
     */
    exportJSON() {
        const data = this.exportToForm();
        if (!data) return null;
        
        const json = JSON.stringify({
            name: document.getElementById('name')?.value || 'Workflow',
            trigger_config: data.trigger,
            conditions: data.conditions,
            actions: data.actions,
            visual_data: data.visual_data,
            branches: data.branches
        }, null, 2);
        
        // Download as file
        const blob = new Blob([json], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'workflow.json';
        a.click();
        URL.revokeObjectURL(url);
    }
    
    /**
     * Import workflow from JSON
     */
    importJSON(jsonData) {
        try {
            const data = typeof jsonData === 'string' ? JSON.parse(jsonData) : jsonData;
            if (Object.prototype.hasOwnProperty.call(data, 'lock_version')) {
                const parsedVersion = parseInt(data.lock_version, 10);
                this.lockVersion = Number.isNaN(parsedVersion) ? null : parsedVersion;
            }
            if (Object.prototype.hasOwnProperty.call(data, 'last_saved_at')) {
                this.lastSavedAt = data.last_saved_at || null;
            }
            
            // Clear existing
            this.clear();
            
            // Load nodes from visual_data
            const graph = data.graph || data.graph_json || null;

            if (graph && Array.isArray(graph.nodes)) {
                graph.nodes.forEach(nodeData => {
                    const node = {
                        id: nodeData.id,
                        type: nodeData.type,
                        key: nodeData.subtype || nodeData.key || nodeData.type,
                        x: nodeData.position?.x ?? nodeData.x ?? 100,
                        y: nodeData.position?.y ?? nodeData.y ?? 100,
                        color: nodeData.color,
                        data: Object.assign({}, nodeData.config || nodeData.data || {})
                    };
                    this.nodes.push(node);
                    const match = String(node.id).match(/(\d+)$/);
                    if (match) {
                        this.nodeIdCounter = Math.max(this.nodeIdCounter, parseInt(match[1], 10));
                    }
                    this.renderNode(node);
                });

                if (graph.meta?.mode) {
                    const modeEl = document.getElementById('workflow-mode');
                    if (modeEl) modeEl.value = graph.meta.mode;
                }

                if (Array.isArray(graph.edges)) {
                    this.connectionManager.import(graph.edges.map(edge => ({
                        id: edge.id,
                        from: edge.source || edge.from || edge.fromNodeId || '',
                        to: edge.target || edge.to || edge.toNodeId || '',
                        branchType: edge.branch || 'default',
                        type: edge.branch || 'default'
                    })));
                }
            } else if (data.visual_data && data.visual_data.nodes) {
                data.visual_data.nodes.forEach(nodeData => {
                    const node = {
                        ...nodeData,
                        id: `node_${++this.nodeIdCounter}`
                    };
                    this.nodes.push(node);
                    this.renderNode(node);
                });
                
                // Load connections
                if (data.visual_data.connections) {
                    this.connectionManager.import(data.visual_data.connections);
                }
            } else {
                // Fallback: create nodes from workflow structure
                if (data.trigger_config) {
                    const triggerNode = this.addNodeFromData('trigger', data.trigger_config.type || 'contact_created', 100, 100, data.trigger_config, true);
                }
                
                // Add actions
                if (data.actions && Array.isArray(data.actions)) {
                    data.actions.forEach((action, index) => {
                        const y = 200 + (index * 150);
                        if (action.type === 'wait_for_days') {
                            this.addNodeFromData('delay', 'wait_for_days', 100, y, { days: action.days || 1 }, true);
                        } else {
                            this.addNodeFromData('action', action.type, 100, y, action, true);
                        }
                    });
                }
            }
            
            // Set workflow name
            if (data.name && document.getElementById('name')) {
                document.getElementById('name').value = data.name;
            }
            
            this.connectionManager.redrawAll();
            this.updateEmptyState();
            return true;
        } catch (e) {
            console.error('Import error:', e);
            this.notify('error', 'Failed to import workflow: ' + e.message, 'Import failed');
            return false;
        }
    }
    
    addNodeFromData(type, key, x, y, data = {}, skipRedraw = false) {
        const nodeDef = window.WorkflowNodeDefinitions ? 
            window.WorkflowNodeDefinitions.getNode(key) : null;
        
        const node = {
            id: `node_${++this.nodeIdCounter}`,
            type: type,
            key: key,
            x: x,
            y: y,
            color: nodeDef?.color || '#6c757d',
            data: data
        };
        
        this.nodes.push(node);
        this.renderNode(node);
        if (!skipRedraw) {
            this.connectionManager.redrawAll();
        }
        
        return node;
    }
    
    /**
     * Load workflow from backend
     */
    async parseJsonResponse(response, fallbackMessage) {
        const rawBody = await response.text();
        try {
            return JSON.parse(rawBody);
        } catch (parseError) {
            const cleanBody = rawBody.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            throw new Error(cleanBody || fallbackMessage || `Request returned HTTP ${response.status}`);
        }
    }

    async loadFromBackend(workflowId) {
        try {
            const response = await fetch(`../api/workflows/load.php?id=${workflowId}`, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const result = await this.parseJsonResponse(response, `Workflow load returned HTTP ${response.status}`);
            
            if (result.success && (result.graph || result.workflow)) {
                const imported = this.importJSON(result.graph ? Object.assign({}, result.workflow || {}, { graph: result.graph }) : result.workflow);
                this.lockVersion = Number.isInteger(result.lock_version) ? result.lock_version : this.lockVersion;
                this.lastSavedAt = result.last_saved_at || this.lastSavedAt;
                return imported;
            } else {
                throw new Error(result.error || 'Failed to load workflow');
            }
        } catch (e) {
            console.error('Load error:', e);
            this.notify('error', 'Failed to load workflow: ' + e.message, 'Load failed');
            return false;
        }
    }
    
    /**
     * Save workflow to backend
     */
    buildSaveSuccessMessage(result) {
        const confidence = typeof result.confidence === 'number'
            ? ` Confidence: ${(result.confidence * 100).toFixed(0)}%.`
            : '';
        const reasons = Array.isArray(result.reasons) && result.reasons.length > 0
            ? ` Reasons: ${result.reasons.join(', ')}.`
            : '';

        switch (result.status) {
            case 'pending_approval':
                return (result.message || 'Workflow proposal saved and is pending approval.') + confidence + reasons;
            case 'suggested':
                return (result.message || 'Workflow suggestion generated without saving.') + confidence + reasons;
            case 'applied':
                return (result.message || 'Workflow applied successfully.') + confidence;
            default:
                return result.message || 'Workflow saved successfully!';
        }
    }

    async saveToBackend(workflowId = null) {
        const data = this.exportToForm();
        if (!data) {
            this.notify('error', 'Cannot save because the workflow is invalid.', 'Save blocked');
            return false;
        }
        
        // Validate
        const validation = this.validator.validate();
        if (!validation.valid) {
            this.validator.showValidationResults();
            return false;
        }
        
        const nameEl = document.getElementById('visual-workflow-name') || document.getElementById('name');
        const name = nameEl?.value || 'Workflow';
        const isActiveEl = document.getElementById('visual-is-active') || document.getElementById('is_active');
        const isActive = isActiveEl?.checked ?? true;
        
        // Validate trigger and actions match backend
        const triggerType = data.trigger.type;
        if (!triggerType) {
            this.notify('error', 'Invalid workflow: trigger type is required.', 'Save blocked');
            return false;
        }
        
        // Verify trigger exists in backend
        try {
            const response = await fetch('../api/workflows/available-nodes.php', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const backendData = await this.parseJsonResponse(response, `Workflow compatibility check returned HTTP ${response.status}`);
            if (backendData.success) {
                if (!backendData.triggers.includes(triggerType)) {
                    this.notify('warning', `Trigger "${triggerType}" may not be supported by the backend.`, 'Compatibility warning');
                }
                
                // Verify all actions
                const invalidActions = data.actions.filter(action => {
                    const actionType = action.type;
                    return actionType && !backendData.actions.includes(actionType);
                });
                
                if (invalidActions.length > 0) {
                    this.notify('warning', `Some actions may not be supported: ${invalidActions.map(a => a.type).join(', ')}`, 'Compatibility warning');
                }
            }
        } catch (e) {
            console.warn('Could not verify triggers/actions with backend:', e);
        }
        
        const payload = {
            id: workflowId,
            name: name,
            trigger: data.trigger,
            conditions: data.conditions,
            actions: data.actions,
            graph: data.graph,
            visual_data: data.visual_data,
            branches: data.branches,
            workflow_mode: document.getElementById('workflow-mode')?.value || 'mixed',
            is_active: isActive
        };
        if (workflowId && this.lockVersion !== null) {
            payload.expected_lock_version = this.lockVersion;
        }
        
        try {
            const response = await fetch('../api/workflows/save.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
            
            const result = await this.parseJsonResponse(response, `Workflow save returned HTTP ${response.status}`);

            if (response.status === 409 && result.error_code === 'trigger_key_conflict') {
                this.notify(
                    'warning',
                    result.message || result.error || 'This trigger key is already used by another webhook/API workflow in this workspace.',
                    'Trigger key conflict'
                );
                return false;
            }

            if (response.status === 409 || result.error_code === 'conflict') {
                const currentVersion = result.current?.lock_version;
                if (Number.isInteger(currentVersion)) {
                    this.lockVersion = currentVersion;
                }
                this.lastSavedAt = result.current?.updated_at || result.current?.fields?.last_saved_at || this.lastSavedAt;
                const timestamp = this.lastSavedAt ? ` Current saved timestamp: ${this.lastSavedAt}.` : '';
                this.notify(
                    'warning',
                    (result.message || 'This workflow changed since you opened it.') + timestamp + ' Reload latest, or save again after the latest version is loaded.',
                    'Save conflict'
                );
                return false;
            }
            
            if (result.success) {
                if (Number.isInteger(result.lock_version)) {
                    this.lockVersion = result.lock_version;
                }
                this.lastSavedAt = result.last_saved_at || this.lastSavedAt;
                this.notify('success', this.buildSaveSuccessMessage(result), 'Workflow updated');
                return result;
            } else {
                throw new Error(result.error || 'Failed to save workflow');
            }
        } catch (e) {
            console.error('Save error:', e);
            this.notify('error', 'Failed to save workflow: ' + e.message, 'Save failed');
            return false;
        }
    }
    
    /**
     * Build workflow structure following connections
     */
    buildWorkflowStructure(startNode) {
        const actions = [];
        const conditions = [];
        const visited = new Set();
        
        const traverse = (node) => {
            if (visited.has(node.id)) return;
            visited.add(node.id);
            
            if (node.type === 'condition') {
                conditions.push({
                    field: node.data.field || '',
                    operator: node.data.operator || '',
                    value: node.data.value || ''
                });
            } else if (node.type === 'action') {
                actions.push(Object.assign({ type: node.data.type || node.key || 'send_email' }, node.data));
            } else if (node.type === 'delay') {
                actions.push({
                    type: 'wait_for_days',
                    days: parseInt(node.data.days) || 1
                });
            }
            
            // Follow connections
            const connections = this.connectionManager.getConnectionsForNode(node.id);
            connections.forEach(conn => {
                if (conn.fromNodeId === node.id) {
                    const nextNode = this.nodes.find(n => n.id === conn.toNodeId);
                    if (nextNode) {
                        traverse(nextNode);
                    }
                }
            });
        };
        
        traverse(startNode);
        
        return { actions, conditions };
    }
    
    clear() {
        this.nodes = [];
        this.nodesContainer.innerHTML = '';
        this.svg.innerHTML = '';
        this.connectionManager.connections = [];
        this.initArrowMarkers();
        this.updateEmptyState();
        this.closeConfig();
    }
}

// Global instance
let workflowBuilder = null;

// Make WorkflowBuilder available globally
window.WorkflowBuilder = WorkflowBuilder;
window.workflowBuilder = workflowBuilder; // For debugging
