/**
 * Rich Text Editor for Notes
 * Uses Quill.js for a lightweight, modern rich text editor
 */

(function() {
    'use strict';

    let quillReady = null;
    let observer = null;
    let lazyVisibilityObserver = null;

    function editorIsInitialized(editorContainer) {
        return editorContainer.dataset.initialized === 'true'
            || editorContainer.dataset.initialized === 'fallback';
    }

    function editorIsLazyPending(editorContainer) {
        return Boolean(editorContainer.dataset.richTextLazy)
            && editorContainer.dataset.lazyActivated !== 'true';
    }

    function editorCanInitialize(editorContainer) {
        return !editorIsInitialized(editorContainer) && !editorIsLazyPending(editorContainer);
    }

    function hasInitializableEditors() {
        return Array.prototype.some.call(document.querySelectorAll('.rich-text-editor'), editorCanInitialize);
    }

    function activateLazyEditor(editorContainer) {
        if (!editorContainer || editorIsInitialized(editorContainer)) {
            return;
        }

        editorContainer.dataset.lazyActivated = 'true';
        initRichTextEditors();
    }

    function bindLazyEditorActivation() {
        document.querySelectorAll('.rich-text-editor[data-rich-text-lazy]').forEach(function(editorContainer) {
            if (editorContainer.dataset.lazyBound === 'true') {
                return;
            }

            editorContainer.addEventListener('focusin', function() {
                activateLazyEditor(editorContainer);
            }, { once: true });
            editorContainer.addEventListener('pointerdown', function() {
                activateLazyEditor(editorContainer);
            }, { once: true });

            if (editorContainer.dataset.richTextLazy === 'visible' && 'IntersectionObserver' in window) {
                if (!lazyVisibilityObserver) {
                    lazyVisibilityObserver = new IntersectionObserver(function(entries) {
                        entries.forEach(function(entry) {
                            if (!entry.isIntersecting) {
                                return;
                            }

                            lazyVisibilityObserver.unobserve(entry.target);
                            activateLazyEditor(entry.target);
                        });
                    }, { rootMargin: '120px 0px' });
                }
                lazyVisibilityObserver.observe(editorContainer);
            } else if (editorContainer.dataset.richTextLazy === 'visible') {
                window.addEventListener('load', function() {
                    activateLazyEditor(editorContainer);
                }, { once: true });
            }

            editorContainer.dataset.lazyBound = 'true';
        });
    }

    function loadStylesheetOnce(href) {
        if (document.querySelector('link[href="' + href + '"]')) {
            return;
        }

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
    }

    function loadScriptOnce(src) {
        const existing = document.querySelector('script[src="' + src + '"]');
        if (existing) {
            return new Promise(function(resolve, reject) {
                if (existing.dataset.loaded === 'true' || window.Quill) {
                    resolve();
                    return;
                }
                const timeout = window.setTimeout(function() {
                    if (window.Quill) {
                        resolve();
                        return;
                    }
                    reject(new Error('Timed out loading Quill.'));
                }, 5000);
                existing.addEventListener('load', function() {
                    window.clearTimeout(timeout);
                    existing.dataset.loaded = 'true';
                    resolve();
                }, { once: true });
                existing.addEventListener('error', function() {
                    window.clearTimeout(timeout);
                    reject(new Error('Failed to load Quill.'));
                }, { once: true });
            });
        }

        return new Promise(function(resolve, reject) {
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.addEventListener('load', function() {
                script.dataset.loaded = 'true';
                resolve();
            }, { once: true });
            script.addEventListener('error', reject, { once: true });
            document.head.appendChild(script);
        });
    }

    function ensureQuillReady() {
        if (window.Quill) {
            return Promise.resolve();
        }

        if (quillReady) {
            return quillReady;
        }

        loadStylesheetOnce('https://cdn.quilljs.com/1.3.6/quill.snow.css');
        quillReady = loadScriptOnce('https://cdn.quilljs.com/1.3.6/quill.js')
            .then(function() {
                if (!window.Quill) {
                    throw new Error('Quill did not initialize.');
                }
            })
            .catch(function(error) {
                quillReady = null;
                throw error;
            });

        return quillReady;
    }

    function markRichTextUnavailable() {
        document.querySelectorAll('.rich-text-editor').forEach(function(editorContainer) {
            const textarea = editorContainer.querySelector('textarea');
            if (textarea) {
                textarea.style.display = '';
            }
            editorContainer.dataset.initialized = 'fallback';
        });
    }
    
    // Initialize all rich text editors on the page
    function initRichTextEditors() {
        bindLazyEditorActivation();

        const editors = Array.prototype.filter.call(
            document.querySelectorAll('.rich-text-editor'),
            editorCanInitialize
        );

        if (!editors.length) {
            return;
        }

        if (!window.Quill) {
            ensureQuillReady()
                .then(initRichTextEditors)
                .catch(function(error) {
                    console.warn('Rich text editor unavailable; using textarea fallback.', error);
                    markRichTextUnavailable();
                });
            return;
        }
        
        editors.forEach(function(editorContainer) {
            const textarea = editorContainer.querySelector('textarea');
            if (!textarea) {
                return;
            }
            
            // Create editor div
            const editorDiv = document.createElement('div');
            editorDiv.style.minHeight = '150px';
            editorDiv.style.border = '1px solid var(--border-color)';
            editorDiv.style.borderRadius = '4px';
            editorDiv.style.backgroundColor = 'white';
            textarea.style.display = 'none';
            textarea.parentNode.insertBefore(editorDiv, textarea);
            
            // Initialize Quill
            const quill = new Quill(editorDiv, {
                theme: 'snow',
                modules: {
                    toolbar: [
                        [{ 'header': [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        [{ 'color': [] }, { 'background': [] }],
                        ['link'],
                        ['clean']
                    ]
                },
                placeholder: textarea.placeholder || 'Add a note... (Type @ to mention someone)'
            });
            
            // Add @mention autocomplete
            initMentionAutocomplete(quill, editorContainer);
            
            // Set initial content if textarea has value
            if (textarea.value) {
                try {
                    const delta = JSON.parse(textarea.value);
                    quill.setContents(delta);
                } catch (e) {
                    // If not JSON, treat as plain text
                    quill.setText(textarea.value);
                }
            }
            
            // Update textarea on text change
            quill.on('text-change', function() {
                syncEditorFields(editorContainer, textarea, quill);
            });

            const form = editorContainer.closest('form');
            if (form && form.dataset.richTextSyncBound !== 'true') {
                form.addEventListener('submit', function() {
                    form.querySelectorAll('.rich-text-editor').forEach(function(container) {
                        const sourceTextarea = container.querySelector('textarea');
                        if (!sourceTextarea) {
                            return;
                        }
                        const instance = container.quillInstance;
                        if (!instance) {
                            return;
                        }
                        syncEditorFields(container, sourceTextarea, instance);
                    });
                });
                form.dataset.richTextSyncBound = 'true';
            }
            
            // Mark as initialized
            editorContainer.dataset.initialized = 'true';
            editorContainer.quillInstance = quill;
        });
    }

    function syncEditorFields(editorContainer, textarea, quill) {
        const content = quill.root.innerHTML;
        const delta = JSON.stringify(quill.getContents());
        textarea.value = delta;

        let htmlInput = editorContainer.querySelector('input[name="' + textarea.name + '_html"]');
        if (!htmlInput) {
            htmlInput = document.createElement('input');
            htmlInput.type = 'hidden';
            htmlInput.name = textarea.name + '_html';
            editorContainer.appendChild(htmlInput);
        }
        htmlInput.value = content;
    }
    
    // @mention autocomplete functionality
    function initMentionAutocomplete(quill, container) {
        const state = {
            mentionList: null,
            mentionIndex: -1,
            mentionStart: -1,
            mentionSearch: ''
        };
        
        // Create mention dropdown
        const mentionDropdown = document.createElement('div');
        mentionDropdown.id = 'mention-dropdown-' + Date.now();
        mentionDropdown.style.cssText = 'display: none; position: absolute; background: white; border: 1px solid var(--border-color); border-radius: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto; z-index: 1000; min-width: 200px;';
        document.body.appendChild(mentionDropdown);
        
        quill.on('text-change', function(delta, oldDelta, source) {
            if (source !== 'user') return;
            
            const range = quill.getSelection();
            if (!range) return;
            
            const text = quill.getText(0, range.index);
            const match = text.match(/@(\w*)$/);
            
            if (match) {
                state.mentionStart = range.index - match[0].length;
                state.mentionSearch = match[1];
                
                // Fetch users
                fetch('../api/users_autocomplete.php?q=' + encodeURIComponent(state.mentionSearch) + '&limit=5')
                    .then(response => response.json())
                    .then(users => {
                        if (users.length > 0) {
                            showMentionDropdown(users, quill, mentionDropdown, container, state);
                        } else {
                            hideMentionDropdown(mentionDropdown, state);
                        }
                    })
                    .catch(err => {
                        console.error('Error fetching users:', err);
                        hideMentionDropdown(mentionDropdown, state);
                    });
            } else {
                hideMentionDropdown(mentionDropdown, state);
            }
        });
        
        // Handle keyboard navigation
        quill.on('selection-change', function(range) {
            if (!range) {
                hideMentionDropdown(mentionDropdown, state);
            }
        });
        
        // Keyboard events for mention dropdown
        quill.root.addEventListener('keydown', function(e) {
            if (state.mentionList && state.mentionList.length > 0) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    state.mentionIndex = Math.min(state.mentionIndex + 1, state.mentionList.length - 1);
                    updateMentionHighlight(mentionDropdown, state.mentionIndex);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    state.mentionIndex = Math.max(state.mentionIndex - 1, 0);
                    updateMentionHighlight(mentionDropdown, state.mentionIndex);
                } else if (e.key === 'Enter' || e.key === 'Tab') {
                    e.preventDefault();
                    if (state.mentionIndex >= 0 && state.mentionIndex < state.mentionList.length) {
                        insertMention(quill, state.mentionList[state.mentionIndex], state.mentionStart, state.mentionSearch.length);
                        hideMentionDropdown(mentionDropdown, state);
                    }
                } else if (e.key === 'Escape') {
                    hideMentionDropdown(mentionDropdown, state);
                }
            }
        });
        
        // Click to select mention
        mentionDropdown.addEventListener('click', function(e) {
            const item = e.target.closest('.mention-item');
            if (item) {
                const index = parseInt(item.dataset.index);
                if (state.mentionList && state.mentionList[index]) {
                    insertMention(quill, state.mentionList[index], state.mentionStart, state.mentionSearch.length);
                    hideMentionDropdown(mentionDropdown, state);
                }
            }
        });
    }
    
    function showMentionDropdown(users, quill, dropdown, container, state) {
        state.mentionList = users;
        state.mentionIndex = 0;
        
        dropdown.innerHTML = '';
        users.forEach((user, index) => {
            const item = document.createElement('div');
            item.className = 'mention-item';
            item.dataset.index = index;
            item.style.cssText = 'padding: var(--spacing-sm) var(--spacing-md); cursor: pointer; border-bottom: 1px solid var(--border-color);';
            item.innerHTML = `
                <div style="font-weight: 500; color: var(--midnight-black);">${user.display}</div>
                <div style="font-size: 12px; color: var(--charcoal-grey);">${user.email}</div>
            `;
            item.onmouseover = () => {
                item.style.backgroundColor = 'var(--light-grey)';
            };
            item.onmouseout = () => {
                item.style.backgroundColor = 'white';
            };
            dropdown.appendChild(item);
        });
        
        // Position dropdown
        const editorRect = container.getBoundingClientRect();
        dropdown.style.top = (editorRect.bottom + window.scrollY) + 'px';
        dropdown.style.left = (editorRect.left + window.scrollX) + 'px';
        dropdown.style.display = 'block';
        
        updateMentionHighlight(dropdown, 0);
    }
    
    function hideMentionDropdown(dropdown, state) {
        dropdown.style.display = 'none';
        state.mentionList = null;
        state.mentionIndex = -1;
    }
    
    function updateMentionHighlight(dropdown, index) {
        const items = dropdown.querySelectorAll('.mention-item');
        items.forEach((item, i) => {
            if (i === index) {
                item.style.backgroundColor = 'var(--accent-blue)';
                item.style.color = 'white';
            } else {
                item.style.backgroundColor = 'white';
                item.style.color = 'inherit';
            }
        });
    }
    
    function insertMention(quill, user, start, searchLength) {
        const range = quill.getSelection();
        if (!range) return;
        
        // Delete the @ and search text
        quill.deleteText(start, searchLength + 1);
        
        // Insert mention as formatted text
        quill.insertText(start, '@' + user.username, {
            'mention': true,
            'mention-id': user.id,
            'mention-email': user.email
        });
        
        quill.setSelection(start + user.username.length + 1);
    }
    
    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            bindLazyEditorActivation();
            initRichTextEditors();
        });
    } else {
        bindLazyEditorActivation();
        initRichTextEditors();
    }
    
    // Re-initialize after dynamic content loads
    observer = new MutationObserver(function(mutations) {
        bindLazyEditorActivation();

        if (!hasInitializableEditors()) {
            return;
        }
        initRichTextEditors();
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
})();
