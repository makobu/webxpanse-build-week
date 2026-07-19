(function () {
  'use strict';

  var config = window.AICoachConfig || {};

  function apiBaseFromConfig() {
    var basePath = (config.basePath || '').replace(/\\/g, '/').trim();
    if (!basePath) return '';
    if (/^[a-zA-Z]:\//.test(basePath)) return '';
    if (basePath.indexOf('/home/') === 0 || basePath.indexOf('/xampp/') === 0) return '';
    return basePath.replace(/\/public\/?$/, '');
  }

  function normalizeApiUrl(url, fallback) {
    var raw = (url || '').toString().replace(/\\/g, '/').trim();
    if (!raw) raw = fallback;

    // Keep absolute http(s) URLs untouched.
    if (/^https?:\/\//i.test(raw)) return raw;

    // Repair malformed filesystem-like URLs such as /C:/xampp/.../api/...
    var apiIndex = raw.toLowerCase().indexOf('/api/');
    if (apiIndex !== -1) {
      var apiBase = apiBaseFromConfig();
      var normalized = (apiBase + raw.slice(apiIndex)).replace(/\/{2,}/g, '/');
      return normalized;
    }

    // If it still looks like a filesystem path, fallback to known-good URL.
    if (/^[a-zA-Z]:\//.test(raw) || raw.indexOf('/xampp/') !== -1 || raw.indexOf('/home/') !== -1) {
      return fallback;
    }
    return raw;
  }

  var recommendationsUrl = normalizeApiUrl(config.recommendationsUrl, '../api/ai-coach/recommendations.php');
  var onboardingUrl = normalizeApiUrl(config.onboardingUrl, '../api/ai-coach/onboarding.php');
  var ideaValidationUrl = normalizeApiUrl(config.ideaValidationUrl, '../api/ai-coach/idea-validation.php');
  var strategyProfileUrl = normalizeApiUrl(config.strategyProfileUrl, '../api/ai-coach/strategy-profile.php');
  var smartTemplateStatusUrl = normalizeApiUrl(config.smartTemplateStatusUrl, '../api/smart_templates/status.php');
  var smartTemplateGenerateUrl = normalizeApiUrl(config.smartTemplateGenerateUrl, '../api/smart_templates/generate.php');
  var dismissCelebrationUrl = normalizeApiUrl(config.dismissCelebrationUrl, '../api/ai-coach/dismiss-celebration.php');
  var taskCreateUrl = normalizeApiUrl(config.taskCreateUrl, '../api/tasks/create.php');
  var feedbackUrl = normalizeApiUrl(config.feedbackUrl, '../api/ai/feedback.php');
  var linkTaskUrl = normalizeApiUrl(config.linkTaskUrl, '../api/ai/link_task_to_guidance.php');
  var marketplaceFeedbackUrl = normalizeApiUrl(config.marketplaceFeedbackUrl, '../api/chat/marketplace_feedback.php');
  var marketplaceEventUrl = normalizeApiUrl(config.marketplaceEventUrl, '../api/workspace/marketplace_recommendation_event.php');
  var activationBundleEventUrl = normalizeApiUrl(config.activationBundleEventUrl, '../api/workspace/marketplace_activation_bundle_event.php');

  function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function escapeHtml(s) {
    if (!s) return '';
    var div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
  }

  function escapeAttribute(s) {
    return escapeHtml(s).replace(/"/g, '&quot;');
  }

  function appendMarketplaceAttribution(url, skillKey) {
    var raw = String(url || 'workspace_skills.php').trim() || 'workspace_skills.php';
    var separator = raw.indexOf('?') === -1 ? '?' : '&';
    return raw + separator + 'source=coach&marketplace_skill=' + encodeURIComponent(skillKey || '');
  }

  function appendQueryParam(url, key, value) {
    var raw = String(url || 'workspace_skills.php').trim() || 'workspace_skills.php';
    var separator = raw.indexOf('?') === -1 ? '?' : '&';
    return raw + separator + encodeURIComponent(key) + '=' + encodeURIComponent(value || '');
  }

  function activationBundleUrl(bundle, surface) {
    var nextAction = (bundle && bundle.next_action) || {};
    var url = nextAction.url || 'workspace_skills.php';
    url = appendQueryParam(url, 'activation_bundle_key', (bundle && bundle.bundle_key) || '');
    url = appendQueryParam(url, 'source', 'activation_bundle');
    url = appendQueryParam(url, 'surface', surface || 'coach');
    return url;
  }

  function trackMarketplaceRecommendationEvent(card, eventType, targetUrl) {
    if (!card || !window.fetch) return;
    var skillKey = card.getAttribute('data-marketplace-skill-key') || '';
    if (!skillKey) return;
    try {
      window.fetch(marketplaceEventUrl, {
        method: 'POST',
        keepalive: true,
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          csrf_token: getCsrfToken(),
          skill_key: skillKey,
          surface: 'coach',
          event_type: eventType || 'cta_clicked',
          source: 'coach',
          target_url: targetUrl || '',
          setup_journey_present: !!card.getAttribute('data-marketplace-next-setup-step'),
          setup_journey_next_step_key: (parseJsonSafely(card.getAttribute('data-marketplace-next-setup-step') || '{}').data || {}).step_key || ''
        })
      }).catch(function () {});
    } catch (e) {}
  }

  function trackActivationBundleEvent(bundle, eventType, targetUrl, source) {
    if (!bundle || !bundle.bundle_key || !window.fetch) return;
    var nextAction = bundle.next_action || {};
    try {
      window.fetch(activationBundleEventUrl, {
        method: 'POST',
        keepalive: true,
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          csrf_token: getCsrfToken(),
          activation_bundle_key: bundle.bundle_key || '',
          surface: 'coach',
          event_type: eventType || 'cta_clicked',
          source: source || 'coach',
          target_url: targetUrl || '',
          next_action_skill_key: nextAction.skill_key || ''
        })
      }).catch(function () {});
    } catch (e) {}
  }

  function parseJsonSafely(raw) {
    try {
      var parsed = JSON.parse(raw || '{}');
      if (!parsed || typeof parsed !== 'object') {
        return { ok: false, data: {} };
      }
      return { ok: true, data: parsed };
    } catch (err) {
      return { ok: false, data: {} };
    }
  }

  function safeJsonAttribute(value) {
    try {
      return escapeAttribute(JSON.stringify(value || {}));
    } catch (e) {
      return '{}';
    }
  }

  function renderMarketplaceSetupJourney(rec) {
    var journey = rec.marketplace_setup_journey || {};
    var progress = rec.marketplace_setup_progress || journey.progress || {};
    var nextStep = rec.marketplace_next_setup_step || journey.next_step || {};
    var total = parseInt(progress.total || '0', 10) || 0;
    if (!total || !nextStep || !nextStep.label) return '';
    var completed = parseInt(progress.completed || '0', 10) || 0;
    return '<div class="ai-coach-marketplace-setup-journey">' +
      '<span class="ai-coach-marketplace-setup-progress">Setup progress ' + escapeHtml(String(completed)) + '/' + escapeHtml(String(total)) + '</span>' +
      '<span class="ai-coach-marketplace-setup-next">' + escapeHtml(nextStep.label) + '</span>' +
    '</div>';
  }

  function renderActivationBundleGuidance(bundle) {
    if (!bundle || !bundle.bundle_key) return '';
    var progress = bundle.progress || {};
    var nextAction = bundle.next_action || {};
    var total = parseInt(progress.total || '0', 10) || 0;
    var installed = parseInt(progress.installed || '0', 10) || 0;
    var progressText = total ? 'Setup progress ' + installed + '/' + total : 'Setup progress is being checked';
    var adaptiveHtml = bundle.adaptive_guidance
      ? '<div class="ai-coach-activation-bundle-note">' + escapeHtml(bundle.adaptive_guidance) + '</div>'
      : '';
    var insightHtml = bundle.insight_label
      ? '<span class="ai-coach-activation-bundle-chip">' + escapeHtml(bundle.insight_label) + '</span>'
      : '';
    var href = activationBundleUrl(bundle, 'coach');
    return '<div class="ai-coach-activation-bundle-guidance" data-activation-bundle-key="' + escapeAttribute(bundle.bundle_key) + '" data-activation-bundle="' + safeJsonAttribute(bundle) + '">' +
      '<div class="ai-coach-activation-bundle-kicker">Activation path</div>' +
      '<div class="ai-coach-activation-bundle-title">' + escapeHtml(bundle.label || 'Activation bundle') + '</div>' +
      '<div class="ai-coach-activation-bundle-summary">' + escapeHtml(bundle.summary || 'A grouped setup path for this workspace.') + '</div>' +
      '<div class="ai-coach-activation-bundle-progress"><span>' + escapeHtml(progressText) + '</span>' +
        (nextAction.label ? '<span>' + escapeHtml(nextAction.label) + '</span>' : '') +
      '</div>' +
      adaptiveHtml +
      (insightHtml ? '<div class="ai-coach-activation-bundle-insight">' + insightHtml + '</div>' : '') +
      '<a class="ai-coach-btn-marketplace ai-coach-btn-activation-bundle" href="' + escapeAttribute(href) + '">' + escapeHtml(nextAction.label ? 'Open ' + nextAction.label : 'Open next step') + '</a>' +
    '</div>';
  }

  function recommendationErrorMessage(data, parsedOk, status) {
    var message = data && data.error ? data.error : 'Unable to load AI Coach recommendations. Please retry.';
    if (!parsedOk) {
      message = 'Unable to load AI Coach recommendations. Please retry.';
    }
    if (status >= 400) {
      message += ' (HTTP ' + status + ')';
    }
    return message;
  }

  function impactClass(impact) {
    var v = (impact || '').toLowerCase();
    if (v === 'high') return 'ai-coach-badge-impact-high';
    if (v === 'low') return 'ai-coach-badge-impact-low';
    return 'ai-coach-badge-impact-medium';
  }

  function effortClass(effort) {
    var v = (effort || '').toLowerCase();
    if (v === 'low') return 'ai-coach-badge-effort-low';
    if (v === 'high') return 'ai-coach-badge-effort-high';
    return 'ai-coach-badge-effort-medium';
  }

  function coachModeLabel(mode) {
    var value = String(mode || '').trim();
    if (value === '1') return 'getting started mode';
    if (value === '2') return 'growth mode';
    if (value === '3') return 'quiet mode';
    if (value.toLowerCase() === 'auto') return 'automatic mode';
    return 'standard mode';
  }

  function coachContextLabel(score) {
    if (typeof score !== 'number') {
      return 'Your setup status is still being checked.';
    }
    if (score >= 0.8) {
      return 'Your setup looks strong.';
    }
    if (score >= 0.55) {
      return 'A few setup details still need attention.';
    }
    return 'Some important setup details are still missing.';
  }

  function humanCapabilityGap(gap) {
    var value = String(gap || '').trim().toLowerCase();
    if (value === 'company profile') return 'your business details';
    if (value === 'product pricing') return 'your product prices';
    if (value === 'invoicing') return 'your invoice setup';
    if (value === 'commercial automation') return 'your sales automation setup';
    if (value === 'workflows configured') return 'your workflow setup';
    return gap || '';
  }

  function formatCoachGapList(gaps) {
    var values = (gaps || []).map(humanCapabilityGap).filter(Boolean);
    if (values.length === 0) return '';
    if (values.length === 1) return values[0];
    if (values.length === 2) return values[0] + ' and ' + values[1];
    return values.slice(0, -1).join(', ') + ', and ' + values[values.length - 1];
  }

  function renderGenerationStatus(status) {
    if (!status || typeof status !== 'object') return '';
    var source = String(status.source || '').trim();
    var message = 'Generated just now';
    if (source === 'cached_ai') {
      message = 'Using cached AI guidance';
    } else if (source === 'deterministic_fallback') {
      message = 'Using fallback guidance because AI is unavailable';
    } else if (source === 'readiness_blocked') {
      message = 'Recommendations are waiting on setup';
    } else if (source === 'runtime_blocked') {
      message = 'AI Coach is currently unavailable';
    }
    if (status.provider_message && (source === 'deterministic_fallback' || source === 'runtime_blocked')) {
      message += ': ' + String(status.provider_message);
    }
    var executionHtml = status.ai_status && window.AIUiConsistency
      ? window.AIUiConsistency.renderExecutionStatus(status.ai_status)
      : '';
    return '<div class="ai-coach-generation-status" role="status" aria-live="polite" data-generation-source="' + escapeAttribute(source) + '">' + escapeHtml(message) + '</div>' + executionHtml;
  }

  function renderEvidence(evidence) {
    if (!Array.isArray(evidence) || evidence.length === 0) return '';
    var chips = evidence.map(function (item) {
      if (!item) return '';
      if (typeof item === 'string') {
        return '<span class="ai-coach-evidence-chip">' + escapeHtml(item) + '</span>';
      }
      var label = String(item.label || '').trim();
      var value = String(item.value || '').trim();
      var text = label && value ? label + ': ' + value : (label || value);
      return text ? '<span class="ai-coach-evidence-chip">' + escapeHtml(text) + '</span>' : '';
    }).filter(Boolean).join('');
    return chips ? '<div class="ai-coach-evidence">' + chips + '</div>' : '';
  }

  function formatFinanceMoney(value, currency) {
    var amount = Number(value || 0);
    if (!isFinite(amount)) amount = 0;
    return String(currency || 'USD') + ' ' + amount.toLocaleString(undefined, {
      minimumFractionDigits: 0,
      maximumFractionDigits: 2
    });
  }

  function renderFinancialEvidence(evidence) {
    if (!evidence || typeof evidence !== 'object') return '';
    var currency = evidence.currency || 'USD';
    var rows = [
      ['Revenue this month', formatFinanceMoney(evidence.revenue_this_month, currency)],
      ['Expenses this month', formatFinanceMoney(evidence.expenses_this_month, currency)]
    ];
    if (evidence.runway_months !== null && typeof evidence.runway_months !== 'undefined') {
      rows.push(['Runway', String(evidence.runway_months) + ' months']);
    }
    if (Number(evidence.target_deal_value || 0) > 0 || evidence.avg_paid_invoice !== null && typeof evidence.avg_paid_invoice !== 'undefined') {
      rows.push([
        'Target vs paid invoice',
        formatFinanceMoney(evidence.target_deal_value || 0, currency) + ' / ' + formatFinanceMoney(evidence.avg_paid_invoice || 0, currency)
      ]);
    }
    var conflict = evidence.top_conflict || {};
    var warnings = Array.isArray(evidence.pricing_warnings) ? evidence.pricing_warnings : [];
    var flags = Array.isArray(evidence.guidance_flags) ? evidence.guidance_flags : [];
    var topWarning = conflict.operating_evidence || warnings[0] || flags[0] || '';
    if (!topWarning && Number(evidence.revenue_this_month || 0) <= 0 && Number(evidence.expenses_this_month || 0) <= 0) {
      return '';
    }
    var rowHtml = rows.map(function (row) {
      return '<div><span>' + escapeHtml(row[0]) + '</span><strong>' + escapeHtml(row[1]) + '</strong></div>';
    }).join('');
    return '<div class="ai-coach-why" style="background:#f0fdf4;border-color:#bbf7d0;">' +
      '<div class="ai-coach-why-title">Financial evidence</div>' +
      '<div class="ai-coach-onboarding-summary">' + rowHtml + '</div>' +
      (topWarning ? '<div class="ai-coach-why-text" style="margin-top:.7rem;">' + escapeHtml(topWarning) + '</div>' : '') +
    '</div>';
  }

  function recommendationUrl(forceRefresh) {
    return forceRefresh ? appendQueryParam(recommendationsUrl, 'refresh', '1') : recommendationsUrl;
  }

  function feedbackSuccessMessage(feedbackType) {
    if (feedbackType === 'useful') return 'Thanks, similar advice may appear more often.';
    if (feedbackType === 'not_useful') return 'Thanks, similar advice will be ranked lower.';
    if (feedbackType === 'already_done') return 'Got it, this will be treated as completed.';
    if (feedbackType === 'dismissed') return 'Thanks, similar advice will appear less often.';
    return 'Saved.';
  }

  function hideCardWithUndo(card, message) {
    if (!card || !card.parentNode) return;
    var placeholder = document.createElement('div');
    placeholder.className = 'ai-coach-feedback-undo';
    placeholder.innerHTML = '<span>' + escapeHtml(message) + '</span><button type="button">Undo</button>';
    card.parentNode.insertBefore(placeholder, card);
    card.style.display = 'none';
    var undoBtn = placeholder.querySelector('button');
    if (undoBtn) {
      undoBtn.addEventListener('click', function () {
        card.style.display = '';
        if (placeholder.parentNode) placeholder.parentNode.removeChild(placeholder);
      });
    }
  }

  function renderCoachStatusSummary(diagnostics) {
    var parts = [];
    var goals = Array.isArray(diagnostics.using_goals) ? diagnostics.using_goals : [];
    var gaps = formatCoachGapList(diagnostics.capability_gaps || []);

    parts.push('Coach is in ' + coachModeLabel(diagnostics.mode) + '.');
    parts.push(coachContextLabel(diagnostics.context_quality_score));

    if (goals.length > 0) {
      parts.push('It is focusing on ' + goals.join(', ') + ' right now.');
    } else {
      parts.push('You have not set a goal yet, so it is focusing on the basics first.');
    }

    if (gaps) {
      parts.push('The main things to finish are ' + gaps + '.');
    }

    if ((diagnostics.suppressed_count || 0) > 0) {
      parts.push('More advanced suggestions will appear after the basics are in place.');
    }

    return parts.join(' ');
  }

  function signalToneClass(tone) {
    var value = String(tone || '').toLowerCase();
    if (value === 'success') return ' is-success';
    if (value === 'warning') return ' is-warning';
    if (value === 'danger') return ' is-danger';
    return '';
  }

  function renderSignalGroup(title, signals, mode) {
    if (!Array.isArray(signals) || signals.length === 0) return '';
    var chips = signals.map(function (signal) {
      if (typeof signal === 'string') {
        return '<span class="ai-coach-signal-chip">' + escapeHtml(signal) + '</span>';
      }
      var label = signal.label ? String(signal.label) : '';
      var value = signal.value ? String(signal.value) : '';
      var text = label && value ? (label + ': ' + value) : (label || value);
      if (!text) return '';
      return '<span class="ai-coach-signal-chip' + signalToneClass(signal.tone) + '">' + escapeHtml(text) + '</span>';
    }).filter(Boolean).join('');
    if (!chips) return '';
    return '<div class="ai-coach-signal-group ai-coach-signal-group-' + escapeAttribute(mode || 'default') + '">' +
      '<div class="ai-coach-signal-title">' + escapeHtml(title) + '</div>' +
      '<div class="ai-coach-signal-chips">' + chips + '</div>' +
    '</div>';
  }

  function renderLearningTransparency(summary, adminSummary) {
    var feedbackSummary = summary && typeof summary === 'object' ? summary : {};
    var accepted = parseInt(feedbackSummary.accepted || '0', 10) || 0;
    var rejected = parseInt(feedbackSummary.rejected || '0', 10) || 0;
    var dismissed = parseInt(feedbackSummary.dismissed || '0', 10) || 0;
    var messages = [];
    if (accepted > 0) {
      messages.push('Boosted by similar helpful feedback');
    }
    if ((rejected + dismissed) > 0) {
      messages.push('Adjusted because similar advice was marked less useful');
    }
    if (adminSummary && typeof adminSummary === 'object') {
      if (adminSummary.boosted) {
        messages.push('Boosted by admin tuning');
      }
      if (adminSummary.muted) {
        messages.push('Muted by admin tuning');
      }
    }
    if (!messages.length) return '';
    return '<div class="ai-coach-learning-note">' + messages.map(function (message) {
      return '<span>' + escapeHtml(message) + '</span>';
    }).join('') + '</div>';
  }

  function renderCard(rec, sectionKey, variant) {
    var impactCls = impactClass(rec.impact);
    var effortCls = effortClass(rec.effort);
    var subtasks = rec.suggested_subtasks || [];
    var dataSubtasks = escapeAttribute(JSON.stringify(subtasks));
    var targetId = rec.target_id || 0;
    var targetTitle = rec.target_title || '';
    var guidanceRunId = rec.guidance_run_id || 0;
    var recommendationKey = rec.recommendation_key || '';
    var feedbackSignature = rec.feedback_signature || '';
    var whySignals = Array.isArray(rec.why_signals) ? rec.why_signals : [];
    var trustSignals = Array.isArray(rec.trust_signals) ? rec.trust_signals : [];
    var evidence = Array.isArray(rec.evidence) ? rec.evidence : [];
    var sourceContext = rec.source_context || {};
    var marketplaceSkillKey = rec.marketplace_skill_key || '';
    var marketplaceSetupUrl = rec.marketplace_setup_url || '';
    var marketplaceCtaLabel = rec.marketplace_cta_label || 'Open Marketplace';
    var marketplaceFeedbackEnabled = !!rec.marketplace_feedback_enabled;
    var marketplaceSetupJourney = rec.marketplace_setup_journey || {};
    var marketplaceNextSetupStep = rec.marketplace_next_setup_step || (marketplaceSetupJourney.next_step || {});
    var marketplaceSetupProgress = rec.marketplace_setup_progress || (marketplaceSetupJourney.progress || {});
    var marketplaceActivationBundle = rec.marketplace_activation_bundle || {};
    var taskState = rec.task_state || 'available';
    var linkedTaskId = rec.linked_task_id || 0;
    var linkedTaskLink = rec.linked_task_link || '';
    var taskActionLabel = variant === 'featured' ? 'Add to my tasks' : 'Add as task';
    var taskActionHtml = taskState === 'created'
      ? '<a class="ai-coach-btn-created-task" href="' + escapeHtml(linkedTaskLink || ('task_view.php?id=' + linkedTaskId)) + '">Task created</a>'
      : '<button type="button" class="ai-coach-btn-add-task">' + taskActionLabel + '</button>';
    var marketplaceActionHtml = marketplaceSkillKey
      ? '<a class="ai-coach-btn-marketplace" href="' + escapeAttribute(appendMarketplaceAttribution(marketplaceSetupUrl || 'workspace_skills.php', marketplaceSkillKey)) + '">' + escapeHtml(marketplaceCtaLabel) + '</a>'
      : '';
    var marketplaceFeedbackHtml = marketplaceSkillKey && marketplaceFeedbackEnabled
      ? '<button type="button" class="ai-coach-btn-marketplace-feedback" data-marketplace-feedback-type="snoozed">Snooze</button>' +
        '<button type="button" class="ai-coach-btn-marketplace-feedback" data-marketplace-feedback-type="dismissed">Dismiss</button>'
      : '';
    var setupJourneyHtml = marketplaceSkillKey && marketplaceFeedbackEnabled
      ? renderMarketplaceSetupJourney(rec)
      : '';
    var whySignalsHtml = renderSignalGroup('Why this appeared', whySignals, 'why');
    var trustSignalsHtml = renderSignalGroup('Trust signals', trustSignals, 'trust');
    var evidenceHtml = renderEvidence(evidence);
    var learningTransparencyHtml = renderLearningTransparency(rec.feedback_summary || null, rec.admin_tuning_summary || null);
    var effortValue = String(rec.effort || 'Medium').toLowerCase();
    var effortEstimate = effortValue === 'low' ? '15 min' : (effortValue === 'high' ? '60+ min' : '30 min');
    var feedbackHtml =
      '<details class="ai-coach-feedback-menu">' +
        '<summary>Rate recommendation</summary>' +
        '<div class="ai-coach-feedback-options">' +
          '<button type="button" class="ai-coach-btn-feedback" data-feedback-type="useful">Useful</button>' +
          '<button type="button" class="ai-coach-btn-feedback" data-feedback-type="not_useful">Not relevant</button>' +
          '<button type="button" class="ai-coach-btn-feedback" data-feedback-type="already_done">Already done</button>' +
          '<button type="button" class="ai-coach-btn-feedback" data-feedback-type="dismissed">Show fewer like this</button>' +
        '</div>' +
      '</details>';
    var detailHtml =
      evidenceHtml +
      (targetTitle ? '<div class="ai-coach-card-target">Supports target: <strong>' + escapeHtml(targetTitle) + '</strong></div>' : '') +
      setupJourneyHtml +
      learningTransparencyHtml +
      whySignalsHtml +
      trustSignalsHtml +
      '<div class="ai-coach-badges">' +
        '<span class="ai-coach-badge ' + impactCls + '"><i class="fas fa-chart-line" aria-hidden="true"></i> ' + escapeHtml(rec.impact || 'Medium') + ' impact</span>' +
        '<span class="ai-coach-badge ' + effortCls + '"><i class="far fa-clock" aria-hidden="true"></i> ' + effortEstimate + '</span>' +
      '</div>' +
      '<div class="ai-coach-card-actions">' +
        taskActionHtml +
        marketplaceActionHtml +
        feedbackHtml +
        marketplaceFeedbackHtml +
      '</div>' +
      '<div class="ai-coach-card-feedback-status" role="status" aria-live="polite"></div>';
    var cardClass = 'ai-coach-card' + (variant ? ' ai-coach-card-' + variant : '');
    var attributes =
      ' data-title="' + escapeAttribute(rec.title) + '" data-reason="' + escapeAttribute(rec.reason) + '" data-subtasks="' + dataSubtasks + '" data-target-id="' + escapeAttribute(String(targetId)) + '" data-target-title="' + escapeAttribute(targetTitle) + '" data-guidance-run-id="' + escapeAttribute(String(guidanceRunId)) + '" data-recommendation-key="' + escapeAttribute(recommendationKey) + '" data-feedback-signature="' + escapeAttribute(feedbackSignature) + '" data-section-key="' + escapeAttribute(sectionKey || '') + '" data-source-recommendation-type="' + escapeAttribute(rec.source_recommendation_type || '') + '" data-trust-signals="' + safeJsonAttribute(trustSignals) + '" data-why-signals="' + safeJsonAttribute(whySignals) + '" data-evidence="' + safeJsonAttribute(evidence) + '" data-source-context="' + safeJsonAttribute(sourceContext) + '" data-marketplace-skill-key="' + escapeAttribute(marketplaceSkillKey) + '" data-marketplace-setup-url="' + escapeAttribute(marketplaceSetupUrl) + '" data-marketplace-next-setup-step="' + safeJsonAttribute(marketplaceNextSetupStep) + '" data-marketplace-setup-progress="' + safeJsonAttribute(marketplaceSetupProgress) + '" data-marketplace-activation-bundle="' + safeJsonAttribute(marketplaceActivationBundle) + '"';

    if (variant === 'queue') {
      return (
        '<article class="' + cardClass + '"' + attributes + '>' +
          '<button type="button" class="ai-coach-card-toggle" aria-expanded="false">' +
            '<span class="ai-coach-queue-icon" aria-hidden="true"><i class="fas fa-bullseye"></i></span>' +
            '<span class="ai-coach-queue-copy"><strong>' + escapeHtml(rec.title) + '</strong>' +
              (rec.reason ? '<span>' + escapeHtml(rec.reason) + '</span>' : '') +
            '</span>' +
            '<span class="ai-coach-queue-impact">' + escapeHtml(rec.impact || 'Medium') + '</span>' +
            '<i class="fas fa-chevron-down ai-coach-queue-chevron" aria-hidden="true"></i>' +
          '</button>' +
          '<div class="ai-coach-card-detail" hidden>' + detailHtml + '</div>' +
        '</article>'
      );
    }

    return (
      '<article class="' + cardClass + '"' + attributes + '>' +
        (variant === 'featured' ? '<div class="ai-coach-card-kicker">Highest impact</div>' : '') +
        '<div class="ai-coach-card-title">' + escapeHtml(rec.title) + '</div>' +
        (rec.reason ? '<div class="ai-coach-card-reason">' + escapeHtml(rec.reason) + '</div>' : '') +
        detailHtml +
      '</article>'
    );
  }

  function renderSection(title, items) {
    if (!items || items.length === 0) return '';
    var sectionKey = (title || '').toLowerCase().replace(/[^a-z0-9]+/g, '_');
    var cards = items.map(function (r) { return renderCard(r, sectionKey); }).join('');
    return (
      '<div class="ai-coach-section">' +
        '<h3 class="ai-coach-section-title">' + escapeHtml(title) + '</h3>' +
        '<div class="ai-coach-cards">' + cards + '</div>' +
      '</div>'
    );
  }

  var celebrationMessages = {
    first_contact: 'You added your first contact!',
    first_task: 'You created your first task!',
    first_deal: 'You closed your first deal!'
  };

  function renderCoachOnboardingForm(data) {
    var readiness = data.ai_coach_readiness || data || {};
    var payload = readiness.onboarding_payload || data.onboarding_payload || {};
    var company = payload.company || {};
    var products = payload.products || [];
    var strategy = payload.strategy || {};
    var idea = payload.idea_validation || {};
    var personalBrief = payload.personal_brief || {};
    var clarityJourney = payload.clarity_journey || {};
    var briefing = payload.workspace_briefing || {};
    var missing = Array.isArray(readiness.missing_requirements) ? readiness.missing_requirements : [];
    var marketplaceUrl = readiness.marketplace_url || data.marketplace_url || 'workspace_skills.php?source=coach';
    var onboardingHref = 'settings.php?tab=company';
    var journeyStage = (readiness.clarity_journey_readiness && readiness.clarity_journey_readiness.current_stage_key) ||
      (clarityJourney.readiness && clarityJourney.readiness.current_stage_key) || '';
    var clarityHref = 'startup_journey.php' + (journeyStage ? '?stage=' + encodeURIComponent(journeyStage) : '');
    var sourceLabels = {
      manual: 'Manual brief',
      clarity_journey: 'Clarity Journey',
      mixed: 'Journey + manual',
      missing: 'Needs context'
    };
    var personalSource = readiness.personal_brief_source || (personalBrief && personalBrief.source) || 'missing';
    var maturityLabels = {
      pre_clarity_journey: 'Before Journey',
      journey_complete_founder_loop_next: 'Founder Loop next',
      founder_loop_active: 'Founder Loop active',
      operating_system_active: 'Operating system active'
    };
    var operatingMaturity = readiness.operating_maturity || (data && data.operating_maturity) || '';
    var coachContextStatus = readiness.coach_context_ready || readiness.clarity_journey_ready
      ? 'Clarity Journey context'
      : 'Optional Journey context';
    var personalStrategyStatus = readiness.personal_strategy_refinement_ready || readiness.personal_brief_ready
      ? (sourceLabels[personalSource] || 'Saved')
      : 'Optional';
    var needsMarketplace = readiness.ai_coach_installed === false || readiness.ai_coach_enabled === false ||
      missing.some(function (item) { return item && item.action === 'marketplace'; });

    if (needsMarketplace) {
      return '<div class="ai-coach-tab-content" data-tab="recommendations" id="ai-coach-panel-recommendations" role="tabpanel">' +
        '<div class="ai-coach-why">' +
          '<div class="ai-coach-why-title">Marketplace setup needed</div>' +
          '<div class="ai-coach-why-text">AI Coach only shows recommendations here. Install or enable AI Coach from Marketplace before recommendations can run.</div>' +
          '<div style="margin-top:.85rem;"><a class="ai-coach-btn-marketplace" href="' + escapeAttribute(marketplaceUrl) + '">Open Marketplace setup</a></div>' +
        '</div>' +
      '</div>';
    }

    var missingItems = missing.filter(function (item) { return item && (item.action === 'settings' || item.action === 'onboarding' || item.action === 'clarity_journey'); }).slice(0, 6).map(function (item) {
      return '<span class="ai-coach-onboarding-chip">' + escapeHtml(item.label || item.message || 'Missing context') + '</span>';
    }).join('');
    var productName = products && products.length ? (products[0].name || '') : '';
    var briefRows = [
      ['Company', company.company_name || 'Not saved yet'],
      ['Offer', productName || 'Not saved yet'],
      ['Shared context', readiness.company_context_ready ? 'Ready' : 'Needs setup'],
      ['Clarity Journey', readiness.clarity_journey_ready ? 'Complete' : 'Optional context'],
      ['Coach context', coachContextStatus],
      ['Personal strategy', personalStrategyStatus],
      ['Operating maturity', maturityLabels[operatingMaturity] || 'Needs signal'],
      ['Snapshot', (personalBrief.active_strategy_snapshot && personalBrief.active_strategy_snapshot.version) ? 'Version ' + personalBrief.active_strategy_snapshot.version : 'Not created yet'],
      ['Brief score', typeof briefing.readiness_score === 'number' ? briefing.readiness_score + '%' : 'Not checked']
    ].map(function (row) {
      return '<div><span>' + escapeHtml(row[0]) + '</span><strong>' + escapeHtml(row[1]) + '</strong></div>';
    }).join('');

    if (readiness.company_context_ready === false) {
      return '<div class="ai-coach-tab-content" data-tab="recommendations" id="ai-coach-panel-recommendations" role="tabpanel">' +
        '<div class="ai-coach-why">' +
          '<div class="ai-coach-why-title">Add the company profile basics first</div>' +
          '<div class="ai-coach-why-text">AI Coach uses the company profile as the shared baseline. Products, voice, and offer context can be added from Settings when you are ready.</div>' +
        '</div>' +
        '<div class="ai-coach-onboarding-summary">' + briefRows + '</div>' +
        (missingItems ? '<div class="ai-coach-onboarding-missing">' + missingItems + '</div>' : '') +
        '<div class="ai-coach-idea-validation-actions">' +
          '<a class="ai-coach-btn-primary" href="' + escapeAttribute(onboardingHref) + '">Open company settings</a>' +
        '</div>' +
      '</div>';
    }

    if (readiness.clarity_journey_ready === false) {
      return '<div class="ai-coach-tab-content" data-tab="recommendations" id="ai-coach-panel-recommendations" role="tabpanel">' +
        '<div class="ai-coach-why">' +
          '<div class="ai-coach-why-title">Optional Journey context can improve Coach guidance</div>' +
          '<div class="ai-coach-why-text">AI Coach can use Clarity Journey for richer customer, problem, offer, GTM, MVP, metrics, and OKR context. This improves strategy recommendations, but it does not block automation readiness.</div>' +
        '</div>' +
        '<div class="ai-coach-onboarding-summary">' + briefRows + '</div>' +
        (missingItems ? '<div class="ai-coach-onboarding-missing">' + missingItems + '</div>' : '') +
        '<div class="ai-coach-idea-validation-actions">' +
          '<a class="ai-coach-btn-primary" href="' + escapeAttribute(clarityHref) + '">Open Clarity Journey</a>' +
        '</div>' +
      '</div>';
    }

    if (readiness.recommendations_ready || readiness.coach_context_ready || readiness.clarity_journey_ready) {
      return '<div class="ai-coach-tab-content" data-tab="recommendations" id="ai-coach-panel-recommendations" role="tabpanel">' +
        '<div class="ai-coach-why">' +
          '<div class="ai-coach-why-title">AI Coach has your Journey context</div>' +
          '<div class="ai-coach-why-text">Your Journey context can sharpen Coach recommendations. Refresh once the remaining shared workspace gates are clear, or use Personal Strategy to add an optional lens.</div>' +
        '</div>' +
        '<div class="ai-coach-onboarding-summary">' + briefRows + '</div>' +
        (missingItems ? '<div class="ai-coach-onboarding-missing">' + missingItems + '</div>' : '') +
        (!readiness.recommendations_ready ? '<div class="ai-coach-idea-validation-actions"><a class="ai-coach-btn-primary" href="' + escapeAttribute(onboardingHref) + '">Open shared setup</a></div>' : '') +
      '</div>';
    }

    return '<div class="ai-coach-tab-content" data-tab="recommendations" id="ai-coach-panel-recommendations" role="tabpanel">' +
      '<div class="ai-coach-why">' +
        '<div class="ai-coach-why-title">Finish shared setup</div>' +
        '<div class="ai-coach-why-text">AI Coach no longer needs a separate required brief. Complete the shared setup items above; optional Journey context can improve later strategy recommendations.</div>' +
      '</div>' +
      '<div class="ai-coach-onboarding-summary">' + briefRows + '</div>' +
      (missingItems ? '<div class="ai-coach-onboarding-missing">' + missingItems + '</div>' : '') +
      '<div class="ai-coach-idea-validation-actions">' +
        '<a class="ai-coach-btn-primary" href="' + escapeAttribute(onboardingHref) + '">Open shared setup</a>' +
      '</div>' +
    '</div>';
  }

  function renderRecommendationsContent(data) {
    if (data && data.recommendations_ready === false) {
      return renderCoachOnboardingForm(data);
    }

    var rec = data.recommendations || data;
    var foundationGaps = rec.foundation_gaps || [];
    var priorities = rec.priorities || [];
    var quickWins = rec.quick_wins || [];
    var missing = rec.missing_features || [];
    var activationBundleGuidance = rec.activation_bundle_guidance || data.activation_bundle_guidance || null;
    var why = rec.why_this_matters || data.why_this_matters || '';
    var diagnostics = data.diagnostics || rec.diagnostics || {};
    var generationStatus = data.generation_status || rec.generation_status || {};
    var assumptionConflicts = data.assumption_conflicts || rec.assumption_conflicts || [];
    var financialEvidence = data.financial_evidence || rec.financial_evidence || {};
    var celebration = data.celebration;
    var leanCanvasEnabled = !!(data.lean_canvas_enabled || rec.lean_canvas_enabled);
    var leanCanvasCompleteness = data.lean_canvas_completeness || rec.lean_canvas_completeness || 0;
    var leanCanvasMissingBlocks = data.lean_canvas_missing_blocks || rec.lean_canvas_missing_blocks || [];
    var buckets = [
      { key: 'todays_priorities', items: priorities },
      { key: 'quick_wins', items: quickWins },
      { key: 'foundation_gaps', items: foundationGaps },
      { key: 'missing_features', items: missing }
    ];
    var featured = null;
    var featuredSection = '';
    buckets.some(function (bucket) {
      if (bucket.items.length) {
        featured = bucket.items[0];
        featuredSection = bucket.key;
        return true;
      }
      return false;
    });
    var queue = [];
    buckets.forEach(function (bucket) {
      bucket.items.forEach(function (item) {
        if (item !== featured) queue.push({ item: item, section: bucket.key });
      });
    });
    var readiness = data.ai_coach_readiness || {};
    var missingContext = Array.isArray(diagnostics.missing_context_flags) ? diagnostics.missing_context_flags.length : 0;
    if (!missingContext && Array.isArray(readiness.missing_requirements)) {
      missingContext = readiness.missing_requirements.length;
    }
    var hasCards = !!featured || queue.length > 0;
    var statusSummary = renderCoachStatusSummary(diagnostics);
    var chipsHtml = window.AIUiConsistency
      ? window.AIUiConsistency.renderInfoChips({
          mode: '',
          qualityScore: typeof diagnostics.context_quality_score === 'number' ? diagnostics.context_quality_score : null,
          promptWarnings: (diagnostics.context_bundle_quality && diagnostics.context_bundle_quality.warnings) || [],
          missingContextFlags: diagnostics.missing_context_flags || []
        })
      : '';

    var html = '<div class="ai-coach-tab-content" data-tab="recommendations" id="ai-coach-panel-recommendations" role="tabpanel">';
    html += '<header class="ai-coach-focus-heading">' +
      '<div><h3>Today\'s focus</h3><p>' + escapeHtml(
        hasCards
          ? 'The strongest moves Coach found for follow-through and pipeline momentum.'
          : 'Coach is ready to translate new workspace activity into the next best moves.'
      ) + '</p></div>' +
      renderGenerationStatus(generationStatus) +
    '</header>';
    if (celebration && celebrationMessages[celebration]) {
      html += '<div class="ai-coach-celebration" data-celebration="' + escapeHtml(celebration) + '">' +
        '<span class="ai-coach-celebration-text">' + escapeHtml(celebrationMessages[celebration]) + '</span>' +
        '<button type="button" class="ai-coach-celebration-dismiss">Got it</button></div>';
    }
    if (featured) {
      html += renderCard(featured, featuredSection, 'featured');
    }
    if (!hasCards && !activationBundleGuidance) {
      html += '<div class="ai-coach-why"><div class="ai-coach-why-title">No recommendations ready</div><div class="ai-coach-why-text">Coach ran, but there are no actionable recommendations to show yet. Add workspace activity or complete Marketplace setup, then try again.</div></div>';
    }
    html += '<div class="ai-coach-focus-layout">' +
      '<section class="ai-coach-focus-queue" aria-labelledby="ai-coach-focus-queue-title">' +
        '<div class="ai-coach-section-heading"><h4 id="ai-coach-focus-queue-title">Focus queue</h4><span>' + queue.length + ' next</span></div>' +
        (queue.length ? queue.slice(0, 6).map(function (entry) {
          return renderCard(entry.item, entry.section, 'queue');
        }).join('') : '<p class="ai-coach-empty-copy">Your highest-impact move is the only action Coach recommends right now.</p>') +
        (hasCards ? '<button type="button" class="ai-coach-plan-day"><i class="far fa-calendar-check" aria-hidden="true"></i><span><strong>Plan my day</strong><small>Review the top moves before adding them to tasks</small></span></button>' : '') +
      '</section>' +
      '<aside class="ai-coach-pulse" aria-labelledby="ai-coach-pulse-title">' +
        '<h4 id="ai-coach-pulse-title">Coach pulse</h4>' +
        '<div class="ai-coach-pulse-row"><i class="far fa-flag" aria-hidden="true"></i><span><strong>' + priorities.length + '</strong> ' + (priorities.length === 1 ? 'priority' : 'priorities') + '</span></div>' +
        '<div class="ai-coach-pulse-row"><i class="fas fa-bolt" aria-hidden="true"></i><span><strong>' + quickWins.length + '</strong> quick ' + (quickWins.length === 1 ? 'win' : 'wins') + '</span></div>' +
        '<div class="ai-coach-pulse-row"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i><span><strong>' + missingContext + '</strong> context ' + (missingContext === 1 ? 'gap' : 'gaps') + '</span></div>' +
        '<div class="ai-coach-pulse-readiness"><span>Recommendation context</span><strong>' + escapeHtml(coachContextLabel(diagnostics.context_quality_score)) + '</strong></div>' +
        '<button type="button" class="ai-coach-pulse-explain">View why Coach chose these</button>' +
      '</aside>' +
    '</div>';

    var reasoningHtml = '';
    reasoningHtml += activationBundleGuidance ? renderActivationBundleGuidance(activationBundleGuidance) : '';
    reasoningHtml += renderFinancialEvidence(financialEvidence);
    if (assumptionConflicts.length) {
      reasoningHtml += '<div class="ai-coach-why ai-coach-assumption-note"><div class="ai-coach-why-title">Journey assumptions to check</div><div class="ai-coach-why-text">' + assumptionConflicts.slice(0, 3).map(function(conflict) {
        return escapeHtml((conflict.operating_evidence || '') + ' ' + (conflict.suggested_next_action || ''));
      }).join('<br>') + '</div></div>';
    }
    if (leanCanvasEnabled) {
      reasoningHtml += '<div class="ai-coach-why"><div class="ai-coach-why-title">Lean Canvas context</div><div class="ai-coach-why-text">Completeness: ' + escapeHtml(String(leanCanvasCompleteness)) + '%. Missing blocks: ' + escapeHtml(leanCanvasMissingBlocks.length ? leanCanvasMissingBlocks.map(humanizeLeanCanvasBlock).join(', ') : 'none') + '.</div></div>';
    }
    reasoningHtml += why ? '<div class="ai-coach-why"><div class="ai-coach-why-title">Why this matters</div><div class="ai-coach-why-text">' + escapeHtml(why) + '</div></div>' : '';
    reasoningHtml += (chipsHtml || statusSummary) ? '<div class="ai-coach-why"><div class="ai-coach-why-title">Recommendation context</div>' + (chipsHtml ? '<div class="ai-coach-context-chips">' + chipsHtml + '</div>' : '') + '<div class="ai-coach-why-text">' + escapeHtml(statusSummary) + '</div></div>' : '';
    if (reasoningHtml) {
      html += '<details class="ai-coach-reasoning-panel"><summary>Why Coach chose these recommendations</summary><div class="ai-coach-reasoning-content">' + reasoningHtml + '</div></details>';
    }
    html += '</div>';
    return html;
  }

  function coachContextStrength(data) {
    var readiness = data && data.ai_coach_readiness ? data.ai_coach_readiness : {};
    var payload = readiness.onboarding_payload || data.onboarding_payload || {};
    var products = Array.isArray(payload.products) ? payload.products.filter(function (product) {
      return product && String(product.name || '').trim() !== '';
    }) : [];
    var signals = [
      !!readiness.company_context_ready,
      products.length > 0,
      !!readiness.clarity_journey_ready,
      !!readiness.inherited_context_ready,
      !!readiness.strategy_ready,
      !!readiness.idea_validation_ready
    ];
    var complete = signals.filter(Boolean).length;
    return Math.round((complete / signals.length) * 100);
  }

  function renderCoachShell(data) {
    var strength = coachContextStrength(data || {});
    var readiness = data.ai_coach_readiness || {};
    var contextHref = readiness.marketplace_url || data.marketplace_url || 'workspace_skills.php?module=ai_coach&setup_tab=workspace_readiness#setup';
    return '<div class="ai-coach-workspace">' +
      '<aside class="ai-coach-sidebar">' +
        '<div class="ai-coach-tabs" role="tablist" aria-label="AI Coach sections" aria-orientation="vertical">' +
          '<button type="button" role="tab" aria-selected="true" aria-controls="ai-coach-panel-recommendations" class="ai-coach-tab active" data-tab-target="recommendations"><i class="far fa-compass" aria-hidden="true"></i><span>Daily Focus</span></button>' +
          '<button type="button" role="tab" aria-selected="false" aria-controls="ai-coach-panel-idea-validation" class="ai-coach-tab" data-tab-target="idea-validation"><i class="far fa-lightbulb" aria-hidden="true"></i><span>Idea Lab</span></button>' +
          '<button type="button" role="tab" aria-selected="false" aria-controls="ai-coach-panel-personal-strategy" class="ai-coach-tab" data-tab-target="personal-strategy"><i class="fas fa-chart-line" aria-hidden="true"></i><span>My Strategy</span></button>' +
          '<button type="button" role="tab" aria-selected="false" aria-controls="ai-coach-panel-smart-templates" class="ai-coach-tab" data-tab-target="smart-templates"><i class="far fa-file-alt" aria-hidden="true"></i><span>Smart Templates</span></button>' +
        '</div>' +
        '<div class="ai-coach-context-meter">' +
          '<div><span>Context strength</span><strong>' + strength + '%</strong></div>' +
          '<span class="ai-coach-context-track"><span style="width:' + strength + '%"></span></span>' +
          '<a href="' + escapeAttribute(contextHref) + '">Improve context</a>' +
        '</div>' +
      '</aside>' +
      '<div class="ai-coach-tab-panels">' +
        renderRecommendationsContent(data) +
        '<div class="ai-coach-tab-content ai-coach-tab-hidden" data-tab="idea-validation" id="ai-coach-panel-idea-validation" role="tabpanel"><div class="ai-coach-loading">Loading Idea Lab...</div></div>' +
        '<div class="ai-coach-tab-content ai-coach-tab-hidden" data-tab="personal-strategy" id="ai-coach-panel-personal-strategy" role="tabpanel"><div class="ai-coach-loading">Loading My Strategy...</div></div>' +
        '<div class="ai-coach-tab-content ai-coach-tab-hidden" data-tab="smart-templates" id="ai-coach-panel-smart-templates" role="tabpanel"><div class="ai-coach-loading">Loading Smart Templates...</div></div>' +
      '</div>' +
    '</div>';
  }

  function activateCoachTab(modal, bodyEl, tabName) {
    if (!bodyEl) return;
    bodyEl.querySelectorAll('.ai-coach-tab').forEach(function (tab) {
      var active = tab.getAttribute('data-tab-target') === tabName;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
      tab.setAttribute('tabindex', active ? '0' : '-1');
    });
    bodyEl.querySelectorAll('.ai-coach-tab-content').forEach(function (panel) {
      panel.classList.toggle('ai-coach-tab-hidden', panel.getAttribute('data-tab') !== tabName);
    });

    if (tabName === 'idea-validation') {
      loadProfilePanel(bodyEl, 'idea-validation', ideaValidationUrl, renderIdeaValidationForm, '.ai-coach-idea-validation-form', 'Failed to load Idea Lab.', 'Failed to save Idea Lab.');
    } else if (tabName === 'personal-strategy') {
      loadProfilePanel(bodyEl, 'personal-strategy', strategyProfileUrl, renderPersonalStrategyForm, '.ai-coach-personal-strategy-form', 'Failed to load Personal Strategy.', 'Failed to save Personal Strategy.');
    } else if (tabName === 'smart-templates') {
      loadSmartTemplatePanel(bodyEl);
    }
  }

  function attachCoachTabs(modal, bodyEl) {
    if (!bodyEl) return;
    bodyEl.querySelectorAll('.ai-coach-tab').forEach(function (tab) {
      tab.addEventListener('click', function () {
        activateCoachTab(modal, bodyEl, tab.getAttribute('data-tab-target') || 'recommendations');
      });
    });
  }

  function renderIdeaValidationForm(ideaData) {
    var d = ideaData || {};
    var snapshot = d.active_strategy_snapshot || null;
    var snapshotLabel = snapshot && snapshot.version ? 'Strategy snapshot v' + snapshot.version : 'No strategy snapshot yet';
    var saveStatusText = d.__save_status || '';
    var saveStatusState = d.__save_status_state || '';

    return (
      '<div class="ai-coach-idea-lab">' +
        '<header class="ai-coach-panel-heading">' +
          '<div><h3>Idea Lab</h3><p>Give Coach the assumptions behind an offer, then use daily recommendations to test them against real CRM activity.</p></div>' +
          '<span class="ai-coach-snapshot-label"><i class="fas fa-layer-group" aria-hidden="true"></i> ' + escapeHtml(snapshotLabel) + '</span>' +
        '</header>' +
        '<form class="ai-coach-idea-validation-form">' +
          '<div class="ai-coach-form-grid">' +
            '<label class="ai-coach-form-field ai-coach-form-field-wide" for="ai-coach-idea-value">' +
              '<span>Value proposition</span><small>What outcome are you promising, and why should it matter now?</small>' +
              '<textarea id="ai-coach-idea-value" name="value_proposition" rows="3" placeholder="Example: Turn every warm lead into a clear next step">' + escapeHtml(d.value_proposition || '') + '</textarea>' +
            '</label>' +
            '<label class="ai-coach-form-field" for="ai-coach-idea-market">' +
              '<span>Target market</span><small>Who is this idea specifically for?</small>' +
              '<textarea id="ai-coach-idea-market" name="target_market" rows="3" placeholder="Founder-led service businesses with active sales conversations">' + escapeHtml(d.target_market || '') + '</textarea>' +
            '</label>' +
            '<label class="ai-coach-form-field" for="ai-coach-idea-pains">' +
              '<span>Pain points</span><small>What recurring problem creates urgency?</small>' +
              '<textarea id="ai-coach-idea-pains" name="pain_points" rows="3" placeholder="Dropped follow-ups, unclear ownership, stalled proposals">' + escapeHtml(d.pain_points || '') + '</textarea>' +
            '</label>' +
            '<label class="ai-coach-form-field ai-coach-form-field-wide" for="ai-coach-idea-assumptions">' +
              '<span>Assumptions to test</span><small>What must be true for this idea to work?</small>' +
              '<textarea id="ai-coach-idea-assumptions" name="assumptions_to_test" rows="3" placeholder="Customers will pay for guided execution before full automation">' + escapeHtml(d.assumptions_to_test || '') + '</textarea>' +
            '</label>' +
            '<label class="ai-coach-form-field" for="ai-coach-idea-competitors">' +
              '<span>Alternatives and competitors</span><small>What do customers use today?</small>' +
              '<textarea id="ai-coach-idea-competitors" name="competitors" rows="3" placeholder="Spreadsheets, generic CRMs, manual reminders">' + escapeHtml(d.competitors || '') + '</textarea>' +
            '</label>' +
            '<label class="ai-coach-form-field" for="ai-coach-idea-differentiator">' +
              '<span>Differentiator</span><small>Why should your approach win?</small>' +
              '<textarea id="ai-coach-idea-differentiator" name="differentiator" rows="3" placeholder="Workspace-specific recommendations tied to tracked work">' + escapeHtml(d.differentiator || '') + '</textarea>' +
            '</label>' +
          '</div>' +
          '<div class="ai-coach-personal-form-actions">' +
            '<button type="submit" class="ai-coach-btn-primary">Save idea context</button>' +
            '<span class="ai-coach-profile-save-status' + (saveStatusState ? ' is-' + escapeAttribute(saveStatusState) : '') + '" role="status" aria-live="polite">' + escapeHtml(saveStatusText) + '</span>' +
          '</div>' +
        '</form>' +
      '</div>'
    );
  }

  function renderPersonalStrategyForm(strategyData) {
    var d = strategyData || {};
    var readiness = d.ai_coach_readiness || {};
    var payload = d.onboarding_payload || readiness.onboarding_payload || {};
    var clarityJourney = payload.clarity_journey || {};
    var journeyReadiness = d.clarity_journey_readiness || readiness.clarity_journey_readiness || clarityJourney.readiness || {};
    var progress = journeyReadiness.progress || (clarityJourney.readiness && clarityJourney.readiness.progress) || {};
    var stageKey = journeyReadiness.current_stage_key || (clarityJourney.readiness && clarityJourney.readiness.current_stage_key) || '';
    var clarityHref = 'startup_journey.php' + (stageKey ? '?stage=' + encodeURIComponent(stageKey) : '');
    var source = readiness.personal_brief_source || (readiness.personal_strategy_refinement_ready ? 'manual' : 'clarity_journey');
    var sourceText = source === 'mixed' ? 'Clarity Journey plus personal notes'
      : (source === 'manual' ? 'Personal notes saved'
        : (source === 'clarity_journey' ? 'Clarity Journey' : 'Optional'));
    var snapshot = d.active_strategy_snapshot || readiness.active_strategy_snapshot || null;
    var missing = Array.isArray(d.optional_personal_strategy_missing)
      ? d.optional_personal_strategy_missing
      : (Array.isArray(readiness.optional_personal_strategy_missing) ? readiness.optional_personal_strategy_missing : []);
    var missingHtml = missing.slice(0, 4).map(function (item) {
      return '<span>' + escapeHtml(item.label || item.field || 'Optional context') + '</span>';
    }).join('');
    var progressText = progress.total
      ? String(progress.completed || 0) + '/' + String(progress.total) + ' stages'
      : (readiness.clarity_journey_ready ? 'Complete' : 'Needs Journey');
    var journeyStatus = readiness.clarity_journey_ready || d.clarity_journey_ready ? 'Complete' : progressText;
    var snapshotText = snapshot && snapshot.version ? 'Version ' + snapshot.version : 'Not created yet';
    var contextItems = [
      ['Journey', journeyStatus],
      ['Progress', progressText],
      ['Source', sourceText],
      ['Snapshot', snapshotText]
    ].map(function (row) {
      return '<span><strong>' + escapeHtml(row[0]) + '</strong> ' + escapeHtml(row[1]) + '</span>';
    }).join('');
    var saveStatusText = d.__save_status || '';
    var saveStatusState = d.__save_status_state || '';

    return (
      '<div class="ai-coach-personal-strategy">' +
      '<div class="ai-coach-personal-strategy-intro">' +
        '<strong>Clarity Journey is optional context for stronger Coach guidance.</strong>' +
        '<span>Use this space for the personal operating lens, market angle, strategy bet, and voice notes you want AI Coach to consider for you.</span>' +
      '</div>' +
      '<div class="ai-coach-personal-context">' +
        '<div class="ai-coach-personal-context-main">' + contextItems + '</div>' +
        '<a class="ai-coach-personal-context-link" href="' + escapeAttribute(clarityHref) + '">Open Clarity Journey</a>' +
        (missingHtml ? '<div class="ai-coach-personal-missing"><strong>Optional gaps</strong> ' + missingHtml + '</div>' : '') +
      '</div>' +
      '<form class="ai-coach-personal-strategy-form">' +
        '<label for="ai-coach-personal-market-focus">Target market focus</label>' +
        '<input type="text" id="ai-coach-personal-market-focus" name="target_market_focus" placeholder="Optional: which market are you personally focused on?" value="' + escapeHtml(d.target_market_focus || '') + '">' +
        '<label for="ai-coach-personal-market-view">Market view</label>' +
        '<textarea id="ai-coach-personal-market-view" name="market_view" placeholder="Optional: what should Coach understand about this market right now?" rows="3">' + escapeHtml(d.market_view || '') + '</textarea>' +
        '<label for="ai-coach-personal-hypothesis">Strategy hypothesis</label>' +
        '<textarea id="ai-coach-personal-hypothesis" name="strategy_hypothesis" placeholder="Optional: what approach should Coach help you test?" rows="3">' + escapeHtml(d.strategy_hypothesis || '') + '</textarea>' +
        '<label for="ai-coach-personal-tone">Tone preset</label>' +
        '<select id="ai-coach-personal-tone" name="draft_tone_preset">' +
          '<option value="">Use channel default</option>' +
          '<option value="professional"' + ((d.draft_tone_preset || '') === 'professional' ? ' selected' : '') + '>Professional</option>' +
          '<option value="warm"' + ((d.draft_tone_preset || '') === 'warm' ? ' selected' : '') + '>Warm</option>' +
          '<option value="consultative"' + ((d.draft_tone_preset || '') === 'consultative' ? ' selected' : '') + '>Consultative</option>' +
          '<option value="direct"' + ((d.draft_tone_preset || '') === 'direct' ? ' selected' : '') + '>Direct</option>' +
          '<option value="friendly"' + ((d.draft_tone_preset || '') === 'friendly' ? ' selected' : '') + '>Friendly</option>' +
        '</select>' +
        '<label for="ai-coach-personal-voice">Personal voice notes</label>' +
        '<textarea id="ai-coach-personal-voice" name="draft_voice_notes" placeholder="Optional: phrases, constraints, or style notes for your drafts" rows="3">' + escapeHtml(d.draft_voice_notes || '') + '</textarea>' +
        '<div class="ai-coach-personal-form-actions">' +
          '<button type="submit" class="ai-coach-btn-primary">Save optional strategy</button>' +
          '<span class="ai-coach-profile-save-status' + (saveStatusState ? ' is-' + escapeAttribute(saveStatusState) : '') + '" role="status" aria-live="polite">' + escapeHtml(saveStatusText) + '</span>' +
        '</div>' +
      '</form>' +
      '</div>'
    );
  }

  function renderSmartTemplateNudge(status) {
    if (!status || typeof status !== 'object') {
      return '<div class="ai-coach-error">Unable to load smart template readiness.</div>';
    }

    var missing = Array.isArray(status.readiness && status.readiness.missing_requirements)
      ? status.readiness.missing_requirements.map(function (item) { return item.label; }).filter(Boolean)
      : [];
    var summary = status.is_ready
      ? (status.has_active_set
          ? 'Your personal smart template pack is active and ready to reuse or regenerate.'
          : 'Your company profile, strategy, and idea validation are ready. You can generate your personal email and workflow pack now.')
      : 'Finish these before generation: ' + escapeHtml(missing.join(', ')) + '.';
    var generateDisabled = status.is_ready ? '' : ' disabled';
    var actionLabel = status.has_active_set ? 'Regenerate Pack' : 'Generate Pack';

    return (
      '<div class="ai-coach-why" style="background:#eef2ff;border-color:#c7d2fe;">' +
        '<div class="ai-coach-why-title">Smart Templates</div>' +
        '<div class="ai-coach-why-text">' + summary + '</div>' +
        '<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:.75rem;">' +
          '<a href="email_templates.php" class="ai-coach-btn-feedback" style="text-decoration:none;display:inline-flex;align-items:center;">Email Templates</a>' +
          '<a href="workflow_templates.php" class="ai-coach-btn-feedback" style="text-decoration:none;display:inline-flex;align-items:center;">Workflow Templates</a>' +
          '<button type="button" class="ai-coach-btn-primary ai-coach-smart-template-generate"' + generateDisabled + '>' + actionLabel + '</button>' +
        '</div>' +
        '<div class="ai-coach-smart-template-status" style="margin-top:.5rem;font-size:12px;color:#475569;"></div>' +
      '</div>'
    );
  }

  function loadSmartTemplateNudge(bodyEl) {
    var recommendationsPanel = bodyEl && bodyEl.querySelector('.ai-coach-tab-content[data-tab="recommendations"]');
    if (!recommendationsPanel) return;

    var slot = recommendationsPanel.querySelector('.ai-coach-smart-template-slot');
    if (!slot) {
      slot = document.createElement('div');
      slot.className = 'ai-coach-smart-template-slot';
      recommendationsPanel.insertBefore(slot, recommendationsPanel.firstChild);
    }
    slot.innerHTML = '<div class="ai-coach-loading">Loading smart template readiness...</div>';

    var request = new XMLHttpRequest();
    request.open('GET', smartTemplateStatusUrl);
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.onload = function () {
      var payload = {};
      try { payload = JSON.parse(request.responseText || '{}'); } catch (err) {}
      if (request.status >= 200 && request.status < 300) {
        slot.innerHTML = renderSmartTemplateNudge(payload);
        attachSmartTemplateGenerate(slot, bodyEl);
      } else {
        slot.innerHTML = '<div class="ai-coach-error">Unable to load smart template readiness.</div>';
      }
    };
    request.onerror = function () {
      slot.innerHTML = '<div class="ai-coach-error">Unable to load smart template readiness.</div>';
    };
    request.send();
  }

  function loadSmartTemplatePanel(bodyEl) {
    var panel = bodyEl && bodyEl.querySelector('.ai-coach-tab-content[data-tab="smart-templates"]');
    if (!panel || panel.getAttribute('data-loaded') === '1') return;
    panel.setAttribute('data-loaded', '1');
    panel.innerHTML = '<div class="ai-coach-loading">Loading Smart Templates...</div>';

    var request = new XMLHttpRequest();
    request.open('GET', smartTemplateStatusUrl);
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.onload = function () {
      var payload = {};
      try { payload = JSON.parse(request.responseText || '{}'); } catch (err) {}
      if (request.status >= 200 && request.status < 300) {
        panel.innerHTML = renderSmartTemplateNudge(payload);
        attachSmartTemplateGenerate(panel, bodyEl);
      } else {
        panel.innerHTML = '<div class="ai-coach-error">Unable to load smart template readiness.</div>';
      }
    };
    request.onerror = function () {
      panel.innerHTML = '<div class="ai-coach-error">Unable to load smart template readiness.</div>';
    };
    request.send();
  }

  function attachSmartTemplateGenerate(slot, bodyEl) {
    var button = slot && slot.querySelector('.ai-coach-smart-template-generate');
    var statusEl = slot && slot.querySelector('.ai-coach-smart-template-status');
    if (!button) return;

    button.addEventListener('click', function () {
      button.disabled = true;
      if (statusEl) statusEl.textContent = 'Generating your smart template pack...';

      var request = new XMLHttpRequest();
      request.open('POST', smartTemplateGenerateUrl);
      request.setRequestHeader('Content-Type', 'application/json');
      request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      request.onload = function () {
        var payload = {};
        try { payload = JSON.parse(request.responseText || '{}'); } catch (err) {}
        if (request.status >= 200 && request.status < 300 && payload.success) {
          if (statusEl) statusEl.textContent = 'Smart template pack generated.';
          loadSmartTemplateNudge(bodyEl);
        } else {
          button.disabled = false;
          if (statusEl) statusEl.textContent = payload.error || 'Failed to generate smart templates.';
        }
      };
      request.onerror = function () {
        button.disabled = false;
        if (statusEl) statusEl.textContent = 'Network error. Please try again.';
      };
      request.send(JSON.stringify({ csrf_token: getCsrfToken() }));
    });
  }

  function attachProfileSubmit(panel, formSelector, saveUrl, renderFn, errorMessage, onSuccess) {
    var form = panel && panel.querySelector(formSelector);
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var submitBtn = form.querySelector('button[type="submit"]');
      var statusEl = form.querySelector('.ai-coach-profile-save-status');
      if (submitBtn) submitBtn.disabled = true;
      if (statusEl) {
        statusEl.textContent = 'Saving...';
        statusEl.className = 'ai-coach-profile-save-status is-saving';
      }
      var fd = new FormData(form);
      fd.append('csrf_token', getCsrfToken());
      var payload = {};
      fd.forEach(function (v, k) { payload[k] = v; });
      var saveReq = new XMLHttpRequest();
      saveReq.open('POST', saveUrl);
      saveReq.setRequestHeader('Content-Type', 'application/json');
      saveReq.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      saveReq.onload = function () {
        var res = {};
        try { res = JSON.parse(saveReq.responseText || '{}'); } catch (err) {}
        if (saveReq.status >= 200 && saveReq.status < 300 && res.success) {
          res.__save_status = 'Saved.';
          res.__save_status_state = 'success';
          panel.innerHTML = renderFn(res);
          attachProfileSubmit(panel, formSelector, saveUrl, renderFn, errorMessage, onSuccess);
          if (typeof onSuccess === 'function') onSuccess(res);
        } else {
          if (submitBtn) submitBtn.disabled = false;
          if (statusEl) {
            statusEl.textContent = res.error || errorMessage;
            statusEl.className = 'ai-coach-profile-save-status is-error';
          }
        }
      };
      saveReq.onerror = function () {
        if (submitBtn) submitBtn.disabled = false;
        if (statusEl) {
          statusEl.textContent = 'Network error. Please try again.';
          statusEl.className = 'ai-coach-profile-save-status is-error';
        }
      };
      saveReq.send(JSON.stringify(payload));
    });
  }

  function loadProfilePanel(bodyEl, tabName, url, renderFn, formSelector, loadErrorMessage, saveErrorMessage) {
    var panel = bodyEl.querySelector('.ai-coach-tab-panels .ai-coach-tab-content[data-tab="' + tabName + '"]');
    if (!panel || panel.getAttribute('data-loaded') === '1') return;
    panel.setAttribute('data-loaded', '1');
    var request = new XMLHttpRequest();
    request.open('GET', url);
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.onload = function () {
      var payload = {};
      try { payload = JSON.parse(request.responseText || '{}'); } catch (e) {}
      if (panel) {
        panel.innerHTML = renderFn(payload);
        attachProfileSubmit(panel, formSelector, url, renderFn, saveErrorMessage, function () {
          loadSmartTemplateNudge(bodyEl);
        });
      }
    };
    request.onerror = function () {
      if (panel) panel.innerHTML = '<div class="ai-coach-error">' + loadErrorMessage + '</div>';
    };
    request.send();
  }

  function showAddTaskLayer(container, title, reason, suggestedSubtasks, targetId, targetTitle) {
    var card = container && container.__aiCoachActiveCard ? container.__aiCoachActiveCard : null;
    var subtasksHtml = (suggestedSubtasks || []).map(function (s, i) {
      return (
        '<div class="ai-coach-subtask-item">' +
          '<input type="text" name="subtask_' + i + '" value="' + escapeHtml(s) + '" placeholder="Subtask ' + (i + 1) + '">' +
        '</div>'
      );
    }).join('');

    var targetHtml = (targetId && targetTitle)
      ? '<div class="ai-coach-add-task-target">Supports target: <strong>' + escapeHtml(targetTitle) + '</strong></div>'
      : '';

    var layer = document.createElement('div');
    var previousFocus = document.activeElement;
    layer.className = 'ai-coach-add-task-modal';
    layer.setAttribute('role', 'dialog');
    layer.setAttribute('aria-modal', 'true');
    layer.setAttribute('aria-labelledby', 'ai-coach-add-task-title');
    layer.setAttribute('tabindex', '-1');
    layer.innerHTML =
      '<h3 id="ai-coach-add-task-title">Add as task</h3>' +
      targetHtml +
      '<form class="ai-coach-add-task-form">' +
        '<label for="ai-coach-task-title">Title</label>' +
        '<input type="text" id="ai-coach-task-title" name="title" required value="' + escapeHtml(title) + '">' +
        '<label for="ai-coach-task-desc">Description (optional)</label>' +
        '<textarea id="ai-coach-task-desc" name="description" placeholder="Optional notes">' + escapeHtml(reason || '') + '</textarea>' +
        (subtasksHtml ? '<label>Subtasks</label><div class="ai-coach-subtask-list">' + subtasksHtml + '</div>' : '') +
        '<div class="ai-coach-add-task-actions">' +
          '<button type="button" class="ai-coach-btn-secondary ai-coach-add-task-cancel">Cancel</button>' +
          '<button type="submit" class="ai-coach-btn-primary">Create task</button>' +
        '</div>' +
        '<div class="ai-coach-add-task-status" role="status" aria-live="polite"></div>' +
      '</form>';
    container.appendChild(layer);

    var form = layer.querySelector('.ai-coach-add-task-form');
    var cancelBtn = layer.querySelector('.ai-coach-add-task-cancel');
    var submitBtn = form.querySelector('button[type="submit"]');
    var statusEl = layer.querySelector('.ai-coach-add-task-status');
    var titleInput = form.querySelector('[name="title"]');

    function removeLayer() {
      if (layer.parentNode) layer.parentNode.removeChild(layer);
      if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    }

    cancelBtn.addEventListener('click', removeLayer);
    layer.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        removeLayer();
        return;
      }
      if (event.key !== 'Tab') return;
      var focusable = Array.prototype.slice.call(layer.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled])'));
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    if (titleInput) {
      titleInput.focus();
      titleInput.select();
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var titleVal = (form.querySelector('[name="title"]') || {}).value;
      var descVal = (form.querySelector('[name="description"]') || {}).value;
      var subtaskInputs = form.querySelectorAll('.ai-coach-subtask-item input');
      var subtasks = [];
      subtaskInputs.forEach(function (inp) {
        var v = (inp.value || '').trim();
        if (v) subtasks.push(v);
      });

      var payload = {
        csrf_token: getCsrfToken(),
        title: titleVal,
        description: descVal,
        subtasks: subtasks,
        target_id: targetId || 0,
        metadata_json: card ? {
          source_surface: 'ai_coach',
          source_recommendation_type: card.getAttribute('data-source-recommendation-type') || card.getAttribute('data-section-key') || 'coach_recommendation',
          guidance_run_id: parseInt(card.getAttribute('data-guidance-run-id') || '0', 10) || null,
          recommendation_key: card.getAttribute('data-recommendation-key') || null,
          feedback_signature: card.getAttribute('data-feedback-signature') || null,
          marketplace_skill_key: card.getAttribute('data-marketplace-skill-key') || null,
          marketplace_setup_url: card.getAttribute('data-marketplace-setup-url') || null,
          marketplace_next_setup_step: parseJsonSafely(card.getAttribute('data-marketplace-next-setup-step') || '{}').data,
          marketplace_setup_progress: parseJsonSafely(card.getAttribute('data-marketplace-setup-progress') || '{}').data,
          marketplace_activation_bundle: parseJsonSafely(card.getAttribute('data-marketplace-activation-bundle') || '{}').data,
          source_context: parseJsonSafely(card.getAttribute('data-source-context') || '{}').data,
          evidence: parseJsonSafely(card.getAttribute('data-evidence') || '[]').data || [],
          trust_signals: parseJsonSafely(card.getAttribute('data-trust-signals') || '[]').data || [],
          why_signals: parseJsonSafely(card.getAttribute('data-why-signals') || '[]').data || []
        } : null
      };

      var req = new XMLHttpRequest();
      submitBtn.disabled = true;
      cancelBtn.disabled = true;
      if (statusEl) statusEl.textContent = 'Creating task...';
      req.open('POST', taskCreateUrl);
      req.setRequestHeader('Content-Type', 'application/json');
      req.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      req.onload = function () {
        var res = {};
        try { res = JSON.parse(req.responseText || '{}'); } catch (err) {}
        if (req.status >= 200 && req.status < 300 && res.task_id) {
          removeLayer();
          function finishTaskFlow() {
            if (!card) return;
            var addBtn = card.querySelector('.ai-coach-btn-add-task');
            if (addBtn) {
              var href = res.link || ('task_view.php?id=' + res.task_id);
              addBtn.outerHTML = '<a class="ai-coach-btn-created-task" href="' + escapeAttribute(href) + '">Task created</a>';
            }
            var statusEl = card.querySelector('.ai-coach-card-feedback-status');
            if (statusEl) {
              statusEl.innerHTML = 'Saved and linked to task. ' + (res.link ? '<a href="' + escapeAttribute(res.link) + '">Open task</a>' : '');
            }
          }
          if (card) {
            var linkReq = new XMLHttpRequest();
            linkReq.open('POST', linkTaskUrl);
            linkReq.setRequestHeader('Content-Type', 'application/json');
            linkReq.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            linkReq.onload = finishTaskFlow;
            linkReq.onerror = finishTaskFlow;
            linkReq.send(JSON.stringify({
              csrf_token: getCsrfToken(),
              task_id: res.task_id,
              guidance_run_id: parseInt(card.getAttribute('data-guidance-run-id') || '0', 10) || 0,
              surface: 'coach',
              recommendation_key: card.getAttribute('data-recommendation-key') || '',
              source_recommendation_type: card.getAttribute('data-source-recommendation-type') || card.getAttribute('data-section-key') || 'coach_recommendation',
              metadata_json: {
                feedback_signature: card.getAttribute('data-feedback-signature') || '',
                source_section: card.getAttribute('data-section-key') || '',
                source_context: parseJsonSafely(card.getAttribute('data-source-context') || '{}').data,
                evidence: parseJsonSafely(card.getAttribute('data-evidence') || '[]').data || [],
                trust_signals: parseJsonSafely(card.getAttribute('data-trust-signals') || '[]').data || [],
                why_signals: parseJsonSafely(card.getAttribute('data-why-signals') || '[]').data || []
              }
            }));
          } else {
            finishTaskFlow();
          }
        } else {
          if (statusEl) statusEl.textContent = res.error || 'Failed to create task.';
          submitBtn.disabled = false;
          cancelBtn.disabled = false;
          if (titleInput) titleInput.focus();
        }
      };
      req.onerror = function () {
        if (statusEl) statusEl.textContent = 'Network error. Please try again.';
        submitBtn.disabled = false;
        cancelBtn.disabled = false;
        if (titleInput) titleInput.focus();
      };
      req.send(JSON.stringify(payload));
    });
  }

  function attachCoachOnboardingSubmit(modal, bodyEl) {
    var form = bodyEl && bodyEl.querySelector('.ai-coach-onboarding-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var statusEl = form.querySelector('.ai-coach-onboarding-status');
      var submitBtn = form.querySelector('button[type="submit"]');
      var payload = {};
      var fd = new FormData(form);
      fd.forEach(function (v, k) { payload[k] = v; });
      payload.csrf_token = getCsrfToken();
      if (statusEl) statusEl.textContent = 'Saving...';
      if (submitBtn) submitBtn.disabled = true;

      var req = new XMLHttpRequest();
      req.open('POST', onboardingUrl);
      req.setRequestHeader('Content-Type', 'application/json');
      req.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      req.onload = function () {
        if (submitBtn) submitBtn.disabled = false;
        var res = {};
        try { res = JSON.parse(req.responseText || '{}'); } catch (err) {}
        if (req.status >= 200 && req.status < 300 && res.success) {
          if (bodyEl) bodyEl.innerHTML = '<div class="ai-coach-loading">Loading recommendations...</div>';
          loadCoachContent(modal, bodyEl);
        } else if (statusEl) {
          statusEl.textContent = res.error || 'Please complete all required fields.';
        }
      };
      req.onerror = function () {
        if (submitBtn) submitBtn.disabled = false;
        if (statusEl) statusEl.textContent = 'Network error. Please try again.';
      };
      req.send(JSON.stringify(payload));
    });
  }

  function recommendationTaskData(card) {
    var rawSubtasks = card ? card.getAttribute('data-subtasks') || '[]' : '[]';
    var subtasks = [];
    try { subtasks = JSON.parse(rawSubtasks); } catch (e) {}
    return {
      title: card ? card.getAttribute('data-title') || '' : '',
      reason: card ? card.getAttribute('data-reason') || '' : '',
      subtasks: subtasks,
      targetId: card ? parseInt(card.getAttribute('data-target-id') || '0', 10) || 0 : 0,
      targetTitle: card ? card.getAttribute('data-target-title') || '' : ''
    };
  }

  function showPlanDayLayer(container, cards) {
    var candidates = (cards || []).filter(function (card) {
      return !!card.querySelector('.ai-coach-btn-add-task');
    }).slice(0, 3);
    if (!candidates.length) return;

    var previousFocus = document.activeElement;
    var layer = document.createElement('div');
    layer.className = 'ai-coach-add-task-modal ai-coach-plan-day-layer';
    layer.setAttribute('role', 'dialog');
    layer.setAttribute('aria-modal', 'true');
    layer.setAttribute('aria-labelledby', 'ai-coach-plan-day-title');
    layer.setAttribute('tabindex', '-1');
    layer.innerHTML =
      '<div class="ai-coach-plan-day-head">' +
        '<div><span>Daily focus</span><h3 id="ai-coach-plan-day-title">Build today\'s plan</h3><p>Review Coach\'s strongest moves, then add only the work you want to commit to.</p></div>' +
        '<button type="button" class="ai-coach-modal-close ai-coach-plan-day-close" aria-label="Close daily plan"><i class="fas fa-times" aria-hidden="true"></i></button>' +
      '</div>' +
      '<div class="ai-coach-plan-day-list">' + candidates.map(function (card, index) {
        var task = recommendationTaskData(card);
        return '<article class="ai-coach-plan-day-item">' +
          '<span class="ai-coach-plan-day-number">' + (index + 1) + '</span>' +
          '<div><strong>' + escapeHtml(task.title) + '</strong><p>' + escapeHtml(task.reason) + '</p></div>' +
          '<button type="button" class="ai-coach-btn-primary ai-coach-plan-day-review" data-plan-index="' + index + '">Review &amp; add</button>' +
        '</article>';
      }).join('') + '</div>' +
      '<div class="ai-coach-plan-day-foot"><i class="fas fa-shield-alt" aria-hidden="true"></i> Nothing is created until you review and confirm each task.</div>';
    container.appendChild(layer);

    function removeLayer() {
      if (layer.parentNode) layer.parentNode.removeChild(layer);
      if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    }

    var close = layer.querySelector('.ai-coach-plan-day-close');
    if (close) close.addEventListener('click', removeLayer);
    layer.querySelectorAll('.ai-coach-plan-day-review').forEach(function (button) {
      button.addEventListener('click', function () {
        var index = parseInt(button.getAttribute('data-plan-index') || '-1', 10);
        var card = candidates[index];
        if (!card) return;
        var task = recommendationTaskData(card);
        if (layer.parentNode) layer.parentNode.removeChild(layer);
        container.__aiCoachActiveCard = card;
        showAddTaskLayer(container, task.title, task.reason, task.subtasks, task.targetId, task.targetTitle);
      });
    });
    layer.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        removeLayer();
        return;
      }
      if (event.key !== 'Tab') return;
      var focusable = Array.prototype.slice.call(layer.querySelectorAll('button:not([disabled]), a[href]'));
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    if (close) close.focus();
  }

  function attachCoachRecommendationActions(modal, bodyEl) {
    bodyEl.querySelectorAll('.ai-coach-card-toggle').forEach(function (toggle) {
      toggle.addEventListener('click', function () {
        var card = toggle.closest('.ai-coach-card');
        var detail = card ? card.querySelector('.ai-coach-card-detail') : null;
        if (!detail) return;
        var expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        detail.hidden = expanded;
      });
    });
    bodyEl.querySelectorAll('.ai-coach-plan-day').forEach(function (button) {
      button.addEventListener('click', function () {
        showPlanDayLayer(modal, Array.prototype.slice.call(bodyEl.querySelectorAll('.ai-coach-card')));
      });
    });
    bodyEl.querySelectorAll('.ai-coach-pulse-explain').forEach(function (button) {
      button.addEventListener('click', function () {
        var reasoning = bodyEl.querySelector('.ai-coach-reasoning-panel');
        if (!reasoning) return;
        reasoning.open = true;
        if (typeof reasoning.scrollIntoView === 'function') {
          reasoning.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      });
    });
    bodyEl.querySelectorAll('.ai-coach-refresh').forEach(function (btn) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        loadCoachContent(modal, bodyEl, true);
      });
    });
    bodyEl.querySelectorAll('.ai-coach-btn-add-task').forEach(function (btn) {
      var card = btn.closest('.ai-coach-card');
      if (!card) return;
      btn.addEventListener('click', function () {
        modal.__aiCoachActiveCard = card;
        var task = recommendationTaskData(card);
        showAddTaskLayer(modal, task.title, task.reason, task.subtasks, task.targetId, task.targetTitle);
      });
    });
    bodyEl.querySelectorAll('.ai-coach-btn-feedback').forEach(function (btn) {
      var card = btn.closest('.ai-coach-card');
      if (!card) return;
      btn.addEventListener('click', function () {
        var statusEl = card.querySelector('.ai-coach-card-feedback-status');
        var feedbackType = btn.getAttribute('data-feedback-type') || 'useful';
        var buttonLabel = (btn.textContent || '').trim();
        btn.disabled = true;
        var request = new XMLHttpRequest();
        request.open('POST', feedbackUrl);
        request.setRequestHeader('Content-Type', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.onload = function () {
          btn.disabled = false;
          var res = {};
          try { res = JSON.parse(request.responseText || '{}'); } catch (err) {}
          if (request.status >= 200 && request.status < 300 && res.success) {
            var successMessage = feedbackSuccessMessage(feedbackType);
            if (feedbackType === 'dismissed') {
              hideCardWithUndo(card, successMessage);
            } else if (statusEl) {
              statusEl.textContent = successMessage;
            }
          } else if (statusEl) {
            statusEl.textContent = res.error || 'Unable to save feedback.';
          }
        };
        request.onerror = function () {
          btn.disabled = false;
          if (statusEl) statusEl.textContent = 'Unable to save feedback.';
        };
        request.send(JSON.stringify({
          csrf_token: getCsrfToken(),
          surface: 'coach',
          guidance_run_id: parseInt(card.getAttribute('data-guidance-run-id') || '0', 10) || 0,
          recommendation_key: card.getAttribute('data-recommendation-key') || '',
          feedback_type: feedbackType,
          metadata_json: {
            feedback_signature: card.getAttribute('data-feedback-signature') || '',
            feedback_button_label: buttonLabel,
            source_section: card.getAttribute('data-section-key') || '',
            source_recommendation_type: card.getAttribute('data-source-recommendation-type') || '',
            trust_signals: parseJsonSafely(card.getAttribute('data-trust-signals') || '[]').data || [],
            why_signals: parseJsonSafely(card.getAttribute('data-why-signals') || '[]').data || []
          }
        }));
      });
    });
    bodyEl.querySelectorAll('.ai-coach-btn-marketplace').forEach(function (link) {
      var card = link.closest('.ai-coach-card');
      link.addEventListener('click', function () {
        if (card) {
          trackMarketplaceRecommendationEvent(card, 'cta_clicked', link.getAttribute('href') || '');
        }
      });
    });
    bodyEl.querySelectorAll('.ai-coach-btn-activation-bundle').forEach(function (link) {
      var holder = link.closest('.ai-coach-activation-bundle-guidance');
      if (!holder) return;
      link.addEventListener('click', function () {
        var bundle = parseJsonSafely(holder.getAttribute('data-activation-bundle') || '{}').data;
        trackActivationBundleEvent(bundle, 'cta_clicked', link.getAttribute('href') || '', 'coach');
      });
    });
    bodyEl.querySelectorAll('.ai-coach-btn-marketplace-feedback').forEach(function (btn) {
      var card = btn.closest('.ai-coach-card');
      if (!card) return;
      btn.addEventListener('click', function () {
        var statusEl = card.querySelector('.ai-coach-card-feedback-status');
        var skillKey = card.getAttribute('data-marketplace-skill-key') || '';
        if (!skillKey) return;
        btn.disabled = true;
        if (statusEl) statusEl.textContent = btn.getAttribute('data-marketplace-feedback-type') === 'snoozed' ? 'Snoozing...' : 'Dismissing...';
        var request = new XMLHttpRequest();
        request.open('POST', marketplaceFeedbackUrl);
        request.setRequestHeader('Content-Type', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.onload = function () {
          btn.disabled = false;
          var res = {};
          try { res = JSON.parse(request.responseText || '{}'); } catch (err) {}
          if (request.status >= 200 && request.status < 300 && res.success) {
            if (card.parentNode) card.parentNode.removeChild(card);
          } else if (statusEl) {
            statusEl.textContent = res.error || 'Unable to save Marketplace feedback.';
          }
        };
        request.onerror = function () {
          btn.disabled = false;
          if (statusEl) statusEl.textContent = 'Unable to save Marketplace feedback.';
        };
        request.send(JSON.stringify({
          csrf_token: getCsrfToken(),
          skill_key: skillKey,
          feedback_type: btn.getAttribute('data-marketplace-feedback-type') || 'dismissed',
          source: 'coach'
        }));
      });
    });
    bodyEl.querySelectorAll('.ai-coach-celebration-dismiss').forEach(function (btn) {
      var el = btn.closest('.ai-coach-celebration');
      if (!el) return;
      var celebration = el.getAttribute('data-celebration');
      btn.addEventListener('click', function () {
        var req = new XMLHttpRequest();
        req.open('POST', dismissCelebrationUrl);
        req.setRequestHeader('Content-Type', 'application/json');
        req.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        req.send(JSON.stringify({ csrf_token: getCsrfToken(), celebration: celebration }));
        if (el.parentNode) el.parentNode.removeChild(el);
      });
    });
  }

  function loadCoachContent(modal, bodyEl, forceRefresh) {
    var req = new XMLHttpRequest();
    var updatedEl = modal ? modal.querySelector('[data-ai-coach-updated]') : null;
    var headerRefresh = modal ? modal.querySelector('.ai-coach-header-refresh') : null;
    if (updatedEl) updatedEl.textContent = forceRefresh ? 'Refreshing brief...' : 'Building your brief...';
    if (headerRefresh) headerRefresh.disabled = true;
    req.open('GET', recommendationUrl(!!forceRefresh));
    req.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    if (bodyEl && bodyEl.querySelector('.ai-coach-tab-content[data-tab="recommendations"]')) {
      bodyEl.querySelector('.ai-coach-tab-content[data-tab="recommendations"]').innerHTML = '<div class="ai-coach-loading">Refreshing recommendations...</div>';
    }
    req.onload = function () {
      if (!bodyEl) return;
      var parsed = parseJsonSafely(req.responseText);
      var data = parsed.data;
      if (req.status >= 200 && req.status < 300) {
        if (!parsed.ok) {
          bodyEl.innerHTML = '<div class="ai-coach-error">' + escapeHtml(recommendationErrorMessage(data, parsed.ok, req.status)) + '</div>';
          if (updatedEl) updatedEl.textContent = 'Update unavailable';
          if (headerRefresh) headerRefresh.disabled = false;
          return;
        }
        bodyEl.innerHTML = renderCoachShell(data);
        if (updatedEl) updatedEl.textContent = forceRefresh ? 'Updated just now' : 'Ready for today';
        if (headerRefresh) headerRefresh.disabled = false;
        attachCoachTabs(modal, bodyEl);
        attachCoachOnboardingSubmit(modal, bodyEl);
        attachCoachRecommendationActions(modal, bodyEl);
      } else {
        bodyEl.innerHTML = '<div class="ai-coach-error">' + escapeHtml(recommendationErrorMessage(data, parsed.ok, req.status)) + '</div>';
        if (updatedEl) updatedEl.textContent = 'Update unavailable';
        if (headerRefresh) headerRefresh.disabled = false;
      }
    };
    req.onerror = function () {
      if (bodyEl) bodyEl.innerHTML = '<div class="ai-coach-error">Network error. Please try again.</div>';
      if (updatedEl) updatedEl.textContent = 'Update unavailable';
      if (headerRefresh) headerRefresh.disabled = false;
    };
    req.send();
  }

  function openModal() {
    var existingOverlay = document.getElementById('ai-coach-overlay');
    if (existingOverlay && existingOverlay.parentNode) {
      existingOverlay.parentNode.removeChild(existingOverlay);
    }

    var previousFocus = document.activeElement;
    var overlay = document.createElement('div');
    overlay.className = 'ai-coach-overlay';
    overlay.id = 'ai-coach-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'ai-coach-title');
    overlay.innerHTML =
      '<div class="ai-coach-modal" tabindex="-1">' +
        '<div class="ai-coach-modal-header">' +
          '<div class="ai-coach-modal-brand">' +
            '<span class="ai-coach-modal-mark" aria-hidden="true"><i class="fas fa-brain"></i></span>' +
            '<div><h2 id="ai-coach-title">AI Coach</h2><p>Your workspace, translated into the next best moves.</p></div>' +
          '</div>' +
          '<div class="ai-coach-modal-tools">' +
            '<span class="ai-coach-updated" data-ai-coach-updated role="status" aria-live="polite">Building your brief...</span>' +
            '<button type="button" class="ai-coach-header-refresh" aria-label="Refresh AI Coach brief"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>' +
            '<button type="button" class="ai-coach-modal-close" id="ai-coach-close" aria-label="Close AI Coach">' +
              '<i class="fas fa-times" aria-hidden="true"></i>' +
            '</button>' +
          '</div>' +
        '</div>' +
        '<div class="ai-coach-modal-body">' +
          '<div class="ai-coach-loading">Loading recommendations\u2026</div>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);

    var modal = overlay && overlay.querySelector('.ai-coach-modal');
    var bodyEl = overlay && overlay.querySelector('.ai-coach-modal-body');
    var prevBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function closeModal() {
      if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
      document.body.style.overflow = prevBodyOverflow;
      document.removeEventListener('keydown', onKey);
      if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    }

    function onKey(e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeModal();
        return;
      }
      if (e.key !== 'Tab') return;
      var focusable = Array.prototype.slice.call(overlay.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), a[href]'))
        .filter(function (element) { return element.offsetParent !== null; });
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeModal();
    });
    var closeBtn = document.getElementById('ai-coach-close');
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    var refreshBtn = overlay.querySelector('.ai-coach-header-refresh');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', function () {
        loadCoachContent(modal, bodyEl, true);
      });
    }

    document.addEventListener('keydown', onKey);
    if (closeBtn) closeBtn.focus();

    loadCoachContent(modal, bodyEl);
  }

  var openBtn = document.getElementById('ai-coach-open');
  if (openBtn) openBtn.addEventListener('click', openModal);
})();
