<div id="deal-transition-modal" class="deal-transition-modal" aria-hidden="true">
    <div class="deal-transition-dialog">
        <h3 id="deal-transition-modal-title" style="margin:0 0 0.4rem;color:#0f172a;">Confirm stage transition</h3>
        <p id="deal-transition-modal-copy" style="margin:0 0 1rem;color:#475569;font-size:0.875rem;"></p>
        <label style="display:block;margin-bottom:0.8rem;">
            <span style="font-weight:600;color:#0f172a;font-size:0.875rem;">Close date</span>
            <input type="date" id="deal-transition-close-date">
        </label>
        <label style="display:block;margin-bottom:0.8rem;">
            <span style="font-weight:600;color:#0f172a;font-size:0.875rem;">Reason</span>
            <input type="text" id="deal-transition-reason" placeholder="Required for Lost, useful for Won">
        </label>
        <label style="display:block;">
            <span style="font-weight:600;color:#0f172a;font-size:0.875rem;">Close note</span>
            <textarea id="deal-transition-close-note" rows="4" placeholder="Summarize the outcome for future context, AI, and automation."></textarea>
        </label>
        <div class="deal-transition-dialog-actions">
            <button type="button" class="secondary" id="deal-transition-cancel">Cancel</button>
            <button type="button" class="primary" id="deal-transition-confirm">Confirm transition</button>
        </div>
    </div>
</div>

<script>
(() => {
    const csrfToken = <?php echo json_encode(\CRM\Security::getCsrfToken()); ?>;
    const stageLabels = <?php echo json_encode($stageLabels); ?>;
    const stageColors = <?php echo json_encode($stageColors); ?>;
    const currentView = <?php echo json_encode($viewMode ?? 'list'); ?>;
    const pageMessage = document.getElementById('deal-page-message');
    const modal = document.getElementById('deal-transition-modal');
    const modalTitle = document.getElementById('deal-transition-modal-title');
    const modalCopy = document.getElementById('deal-transition-modal-copy');
    const closeDateInput = document.getElementById('deal-transition-close-date');
    const reasonInput = document.getElementById('deal-transition-reason');
    const closeNoteInput = document.getElementById('deal-transition-close-note');
    const confirmButton = document.getElementById('deal-transition-confirm');
    const cancelButton = document.getElementById('deal-transition-cancel');
    let pendingTransition = null;
    let draggedCard = null;

    const getApiCandidates = (relativePath) => {
        const clean = String(relativePath || '').replace(/^\/+/, '');
        const candidates = [];
        const add = (url) => {
            if (url && !candidates.includes(url)) {
                candidates.push(url);
            }
        };

        add('../api/' + clean);
        add('api/' + clean);
        add('/api/' + clean);

        const path = window.location.pathname || '';
        if (path.indexOf('/public/') >= 0) {
            const base = path.split('/public/')[0];
            add(base + '/api/' + clean);
        }
        if (path.indexOf('/crm/') === 0) {
            add('<?php echo rtrim(apiUrl(''), '/'); ?>/' + clean);
        }

        return candidates;
    };

    const fetchApiJson = async (relativePath, fetchOptions = {}) => {
        const candidates = getApiCandidates(relativePath);
        let lastError = null;

        for (let index = 0; index < candidates.length; index += 1) {
            try {
                const response = await fetch(candidates[index], fetchOptions);
                const text = await response.text();
                let data = null;

                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    throw new Error('Unexpected response from server.');
                }

                if (!response.ok) {
                    throw new Error(data.error || ('HTTP ' + response.status));
                }

                return data;
            } catch (error) {
                lastError = error;
            }
        }

        throw lastError || new Error('API request failed.');
    };

    const showMessage = (message, type = 'success') => {
        if (!pageMessage) return;
        pageMessage.className = `deal-page-message show ${type}`;
        pageMessage.textContent = message;
    };

    const getControlContext = (trigger) => {
        const card = trigger.closest('.deal-card');
        if (card) {
            return {
                dealId: parseInt(card.dataset.dealId || '0', 10),
                currentStage: card.dataset.currentStage || '',
                title: card.dataset.dealTitle || '',
                card,
                select: card.querySelector('[data-transition-select]'),
            };
        }
        return {
            dealId: parseInt(trigger.dataset.dealId || '0', 10),
            currentStage: trigger.dataset.currentStage || '',
            title: trigger.dataset.dealTitle || '',
            card: null,
            select: trigger.parentElement?.querySelector('[data-transition-select]'),
        };
    };

    const updateStageUi = (dealId, toStage, card) => {
        if (card) {
            card.dataset.currentStage = toStage;
            const pill = card.querySelector('[data-stage-pill]');
            if (pill) {
                pill.textContent = stageLabels[toStage] || toStage;
                pill.style.background = stageColors[toStage] || '#64748b';
            }
            const destinationColumn = document.querySelector(`.pipeline-dropzone[data-stage="${toStage}"]`);
            if (destinationColumn && currentView === 'pipeline') {
                destinationColumn.appendChild(card);
            }
            const select = card.querySelector('[data-transition-select]');
            if (select) {
                select.value = '';
            }
        }
        const rowBadge = document.querySelector(`[data-list-stage-badge="${dealId}"]`);
        if (rowBadge) {
            rowBadge.textContent = stageLabels[toStage] || toStage;
        }
        document.querySelectorAll(`[data-deal-id="${dealId}"]`).forEach((el) => {
            if (el.dataset) {
                el.dataset.currentStage = toStage;
            }
        });
    };

    const submitTransition = async (payload, context) => {
        try {
            const result = await fetchApiJson('deals/stage_transition.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({ ...payload, csrf_token: csrfToken }),
            });
            if (!result.success) {
                throw new Error(result.error || 'Transition failed');
            }
            updateStageUi(context.dealId, result.to_stage, context.card);
            showMessage(result.message || 'Deal stage updated.');
        } catch (error) {
            showMessage(error.message || 'Unable to update deal stage.', 'error');
        }
    };

    const closeModal = () => {
        pendingTransition = null;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        closeDateInput.value = '';
        reasonInput.value = '';
        closeNoteInput.value = '';
    };

    const openTerminalModal = (context, toStage) => {
        pendingTransition = { context, toStage };
        modalTitle.textContent = `Confirm move to ${stageLabels[toStage] || toStage}`;
        modalCopy.textContent = toStage === 'closed_lost'
            ? `Capture why "${context.title}" was lost so notes, AI context, and follow-up automations stay accurate.`
            : `Capture the closing context for "${context.title}" so reporting, AI context, and automations stay accurate.`;
        closeDateInput.value = new Date().toISOString().slice(0, 10);
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    };

    document.querySelectorAll('[data-transition-button]').forEach((button) => {
        button.addEventListener('click', () => {
            const context = getControlContext(button);
            const toStage = context.select?.value || '';
            if (!context.dealId || !toStage) {
                showMessage('Choose a destination stage first.', 'error');
                return;
            }
            if (toStage === 'closed_won' || toStage === 'closed_lost') {
                openTerminalModal(context, toStage);
                return;
            }
            submitTransition({ deal_id: context.dealId, to_stage: toStage }, context);
        });
    });

    document.querySelectorAll('.deal-card').forEach((card) => {
        card.addEventListener('dragstart', () => {
            draggedCard = card;
            card.style.opacity = '0.55';
        });
        card.addEventListener('dragend', () => {
            card.style.opacity = '1';
            draggedCard = null;
            document.querySelectorAll('.pipeline-dropzone').forEach((zone) => zone.classList.remove('drag-active'));
        });
    });

    document.querySelectorAll('.pipeline-dropzone').forEach((zone) => {
        zone.addEventListener('dragover', (event) => {
            event.preventDefault();
            zone.classList.add('drag-active');
        });
        zone.addEventListener('dragleave', () => zone.classList.remove('drag-active'));
        zone.addEventListener('drop', (event) => {
            event.preventDefault();
            zone.classList.remove('drag-active');
            if (!draggedCard) return;
            const context = {
                dealId: parseInt(draggedCard.dataset.dealId || '0', 10),
                currentStage: draggedCard.dataset.currentStage || '',
                title: draggedCard.dataset.dealTitle || '',
                card: draggedCard,
            };
            const toStage = zone.dataset.stage || '';
            if (!context.dealId || !toStage || toStage === context.currentStage) {
                return;
            }
            if (toStage === 'closed_won' || toStage === 'closed_lost') {
                openTerminalModal(context, toStage);
                return;
            }
            submitTransition({ deal_id: context.dealId, to_stage: toStage }, context);
        });
    });

    confirmButton?.addEventListener('click', () => {
        if (!pendingTransition) {
            return;
        }
        const payload = {
            deal_id: pendingTransition.context.dealId,
            to_stage: pendingTransition.toStage,
            close_date: closeDateInput.value,
            reason: reasonInput.value,
            close_note: closeNoteInput.value,
        };
        submitTransition(payload, pendingTransition.context);
        closeModal();
    });

    cancelButton?.addEventListener('click', closeModal);
    modal?.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.querySelectorAll('.deal-automation-mode-btn').forEach((button) => {
        button.addEventListener('click', async () => {
            const mode = button.dataset.mode || '';
            try {
                const result = await fetchApiJson('deals/automation_mode.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken,
                    },
                    body: JSON.stringify({ mode, csrf_token: csrfToken }),
                });
                if (!result.success) {
                    throw new Error(result.error || 'Unable to update automation mode.');
                }
                showMessage('Deal automation mode updated. Refreshing...');
                window.location.reload();
            } catch (error) {
                showMessage(error.message || 'Unable to update automation mode.', 'error');
            }
        });
    });
})();
</script>
