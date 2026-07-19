(function () {
  'use strict';

  function ensureStyles() {
    if (document.getElementById('ai-ui-consistency-styles')) {
      return;
    }
    var style = document.createElement('style');
    style.id = 'ai-ui-consistency-styles';
    style.textContent = '' +
      '.ai-ui-chip{display:inline-flex;align-items:center;gap:.35rem;padding:4px 8px;border-radius:999px;border:1px solid transparent;font-size:12px;font-weight:700;line-height:1.2;}' +
      '.ai-ui-chip-row{display:flex;gap:.45rem;flex-wrap:wrap;align-items:center;}' +
      '.ai-ui-chip-success{background:#dcfce7;color:#166534;border-color:#86efac;}' +
      '.ai-ui-chip-warning{background:#fef3c7;color:#92400e;border-color:#fcd34d;}' +
      '.ai-ui-chip-danger{background:#fee2e2;color:#991b1b;border-color:#fca5a5;}' +
      '.ai-ui-chip-neutral{background:#e2e8f0;color:#334155;border-color:#cbd5e1;}' +
      '.ai-ui-status-panel{margin-top:.6rem;padding:.7rem .85rem;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;color:#475569;font-size:12px;}' +
      '.ai-ui-status-text{margin-top:.55rem;line-height:1.5;}' +
      '.ai-ui-status-actions{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.65rem;}' +
      '.ai-ui-retry{border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#0f172a;padding:.45rem .7rem;font:inherit;font-weight:700;cursor:pointer;}' +
      '.ai-ui-inline-note{margin-top:.55rem;color:#64748b;font-size:12px;line-height:1.5;}';
    document.head.appendChild(style);
  }

  function escapeHtml(value) {
    var div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
  }

  function normalizeDecision(decision) {
    return String(decision || '').trim().toLowerCase();
  }

  function getDecisionMeta(decision) {
    switch (normalizeDecision(decision)) {
      case 'allow':
      case 'approved':
      case 'normal':
        return { label: 'Ready', tone: 'success' };
      case 'allow_with_warning':
        return { label: 'Use with warnings', tone: 'warning' };
      case 'suggest_only':
        return { label: 'Suggestion only', tone: 'warning' };
      case 'approval_required':
      case 'pending':
        return { label: 'Approval required', tone: 'warning' };
      case 'blocked':
      case 'reject':
      case 'rejected':
      case 'expired':
        return { label: 'Blocked', tone: 'danger' };
      case 'failed':
        return { label: 'Failed', tone: 'danger' };
      case 'retry_pending':
      case 'waiting':
        return { label: 'Retry pending', tone: 'warning' };
      case 'paused':
        return { label: 'Paused', tone: 'danger' };
      case 'diagnostics_only':
        return { label: 'Diagnostics only', tone: 'neutral' };
      default:
        return { label: String(decision || 'Unknown').replace(/_/g, ' '), tone: 'neutral' };
    }
  }

  function getQualityMeta(score) {
    if (typeof score !== 'number' || !isFinite(score)) {
      return { label: 'Context unknown', tone: 'neutral' };
    }
    if (score >= 0.85) {
      return { label: 'Context high', tone: 'success' };
    }
    if (score >= 0.65) {
      return { label: 'Context medium', tone: 'warning' };
    }
    return { label: 'Context low', tone: 'danger' };
  }

  function getWarningLabel(warning) {
    switch (String(warning || '').trim().toLowerCase()) {
      case 'stale_context':
      case 'stale_context_present':
        return 'Stale context';
      case 'bundle_trimmed':
      case 'prompt_warning':
        return 'Prompt warning';
      case 'required_context_trimmed':
        return 'Required context trimmed';
      case 'prompt_overload':
      case 'prompt_overload_detected':
        return 'Prompt overload';
      case 'low_signal_bundle':
      case 'low_signal_context_bundle':
        return 'Low-signal context';
      case 'missing_context':
        return 'Missing context';
      case 'fallback':
        return 'Fallback used';
      default:
        return String(warning || '').replace(/_/g, ' ');
    }
  }

  function buildChip(label, tone) {
    ensureStyles();
    return '<span class="ai-ui-chip ai-ui-chip-' + escapeHtml(tone || 'neutral') + '">' + escapeHtml(label) + '</span>';
  }

  function normalizePromptWarnings(quality) {
    if (!quality || typeof quality !== 'object') {
      return [];
    }
    if (Array.isArray(quality.warnings)) {
      return quality.warnings;
    }
    return [];
  }

  function renderInfoChips(options) {
    ensureStyles();
    var chips = [];
    var opts = options || {};

    if (opts.decision) {
      var decision = getDecisionMeta(opts.decision);
      chips.push(buildChip(decision.label, decision.tone));
    }
    if (opts.fallback) {
      chips.push(buildChip('Fallback used', 'warning'));
    }
    if (opts.paused) {
      chips.push(buildChip('Paused', 'danger'));
    }
    if (opts.approvalRequired) {
      chips.push(buildChip('Approval required', 'warning'));
    }
    if (opts.mode) {
      chips.push(buildChip('Mode: ' + String(opts.mode), 'neutral'));
    }
    if (typeof opts.qualityScore === 'number') {
      var quality = getQualityMeta(opts.qualityScore);
      chips.push(buildChip(quality.label, quality.tone));
    }

    var warningItems = [];
    if (Array.isArray(opts.warnings)) {
      warningItems = warningItems.concat(opts.warnings);
    }
    if (Array.isArray(opts.promptWarnings)) {
      warningItems = warningItems.concat(opts.promptWarnings);
    }
    if (Array.isArray(opts.missingContextFlags)) {
      warningItems = warningItems.concat(opts.missingContextFlags.map(function () { return 'missing_context'; }));
    }

    var seen = {};
    warningItems.forEach(function (warning) {
      var label = getWarningLabel(warning);
      var key = label.toLowerCase();
      if (seen[key]) return;
      seen[key] = true;
      chips.push(buildChip(label, label === 'Missing context' ? 'danger' : 'warning'));
    });

    return chips.length ? '<div class="ai-ui-chip-row">' + chips.join('') + '</div>' : '';
  }

  function buildStatusText(options) {
    var opts = options || {};
    var text = String(opts.summary || opts.explanation || '').trim();
    if (!text && opts.decision) {
      text = getDecisionMeta(opts.decision).label + '.';
    }
    if (!text) {
      text = 'AI status available.';
    }
    return text;
  }

  function renderStatusPanel(options) {
    ensureStyles();
    var opts = options || {};
    var chips = renderInfoChips({
      decision: opts.decision || (opts.policy && opts.policy.decision),
      fallback: opts.fallback,
      paused: opts.paused,
      approvalRequired: opts.approvalRequired || (opts.policy && opts.policy.approval_required),
      mode: opts.mode,
      qualityScore: typeof opts.qualityScore === 'number' ? opts.qualityScore : null,
      warnings: opts.warnings || (opts.policy && opts.policy.warnings) || [],
      promptWarnings: opts.promptWarnings || [],
      missingContextFlags: opts.missingContextFlags || []
    });
    var text = buildStatusText({
      summary: opts.summary,
      explanation: opts.explanation,
      decision: opts.decision || (opts.policy && opts.policy.decision)
    });
    return '<div class="ai-ui-status-panel" role="status" aria-live="polite">' + chips + '<div class="ai-ui-status-text">' + escapeHtml(text) + '</div></div>';
  }

  function renderExecutionStatus(status) {
    var value = status && typeof status === 'object' ? status : {};
    var state = String(value.state || '').toLowerCase();
    var decision = state === 'ready' || state === 'cached'
      ? 'allow'
      : (state === 'blocked' ? 'blocked' : (state === 'fallback' ? 'allow_with_warning' : 'failed'));
    var mode = '';
    if (state === 'cached') mode = 'cached';
    if (state === 'fallback') mode = 'fallback';
    return renderStatusPanel({
      decision: decision,
      fallback: !!value.fallback_used,
      paused: value.mode === 'paused',
      mode: mode,
      summary: value.message || '',
      warnings: (value.blocked_reason ? [value.blocked_reason] : []).concat(value.prompt_truncated ? ['prompt_warning'] : [])
    });
  }

  window.AIUiConsistency = {
    escapeHtml: escapeHtml,
    getDecisionMeta: getDecisionMeta,
    getQualityMeta: getQualityMeta,
    getWarningLabel: getWarningLabel,
    normalizePromptWarnings: normalizePromptWarnings,
    renderInfoChips: renderInfoChips,
    renderStatusPanel: renderStatusPanel,
    renderExecutionStatus: renderExecutionStatus,
    buildStatusText: buildStatusText
  };
})();
