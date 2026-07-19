<?php
$invoice = $invoice ?? [];
$lineItems = $invoice['line_items'] ?? [];
$invoiceDocumentType = $_POST['document_type'] ?? ($invoice['document_type'] ?? ($prefill['document_type'] ?? 'invoice'));
$invoiceCurrency = $_POST['currency'] ?? ($invoice['currency'] ?? ($defaultCurrency['code'] ?? 'USD'));
$selectedAssigned = $_POST['assigned_to'] ?? ($invoice['assigned_to'] ?? ($prefill['assigned_to'] ?? ''));
$selectedContact = $_POST['contact_id'] ?? ($invoice['contact_id'] ?? ($prefill['contact_id'] ?? ''));
$selectedCompany = $_POST['company_id'] ?? ($invoice['company_id'] ?? ($prefill['company_id'] ?? ''));
$selectedDeal = $_POST['deal_id'] ?? ($invoice['deal_id'] ?? ($prefill['deal_id'] ?? ''));
$taxMode = $_POST['tax_mode'] ?? ($invoice['tax_mode'] ?? ($invoiceSettings['default_tax_mode'] ?? 'exclusive'));
$taxRate = $_POST['tax_rate'] ?? ($invoice['tax_rate'] ?? ($invoiceSettings['default_tax_rate'] ?? 0));
$paymentTerms = $_POST['payment_terms_days'] ?? ($invoice['payment_terms_days'] ?? ($invoiceSettings['default_payment_terms_days'] ?? 14));
$issueDate = $_POST['issue_date'] ?? ($invoice['issue_date'] ?? date('Y-m-d'));
$dueDate = $_POST['due_date'] ?? ($invoice['due_date'] ?? '');
$validUntil = $_POST['valid_until'] ?? ($invoice['valid_until'] ?? '');
$titleValue = $_POST['title'] ?? ($invoice['title'] ?? ($prefill['title'] ?? ''));
$introValue = $_POST['intro_text'] ?? ($invoice['intro_text'] ?? ($invoiceSettings['proposal_intro_text'] ?? ''));
$notesValue = $_POST['notes'] ?? ($invoice['notes'] ?? ($invoiceSettings['default_notes'] ?? ''));
$termsValue = $_POST['terms'] ?? ($invoice['terms'] ?? ($invoiceSettings['default_terms'] ?? ''));
$billingName = $_POST['billing_name'] ?? ($invoice['billing_name'] ?? '');
$billingEmail = $_POST['billing_email'] ?? ($invoice['billing_email'] ?? '');
$billingPhone = $_POST['billing_phone'] ?? ($invoice['billing_phone'] ?? '');
$billingAddress = $_POST['billing_address'] ?? ($invoice['billing_address'] ?? '');
$shippingAddress = $_POST['shipping_address'] ?? ($invoice['shipping_address'] ?? '');
$selectedTemplateKey = $_POST['template_key'] ?? ($invoice['template_key'] ?? ($invoiceSettings['default_template_key'] ?? ($invoiceSettings['visual_theme'] ?? 'classic')));
$composerBasePath = rtrim(getBasePath(), '/');
$documentTypeLabels = [
    'quote' => 'Quote',
    'proforma' => 'Proforma Invoice',
    'invoice' => 'Invoice',
    'credit_note' => 'Credit Note',
];
?>

<style>
    .commercial-document-page {
        --composer-border: #d8e2ef;
        --composer-text: #0f172a;
        --composer-muted: #64748b;
        --composer-soft: #f6f8fb;
        --composer-surface: #ffffff;
        --composer-accent: #0f766e;
        --composer-accent-strong: #115e59;
        --composer-warm: #f59e0b;
        background: var(--composer-soft);
        color: var(--composer-text);
        min-height: calc(100vh - 80px);
        padding: 1rem 0 3rem;
    }

    .commercial-document-page .container {
        box-sizing: border-box;
        max-width: 1540px;
        padding: 0 clamp(0.75rem, 1.5vw, 1.25rem);
        width: 100%;
    }

    .commercial-document-form,
    .commercial-composer-main,
    .commercial-composer-rail {
        display: grid;
        gap: 1rem;
    }

    .commercial-composer-hero {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 1rem;
        align-items: start;
        border: 1px solid var(--composer-border);
        border-radius: 8px;
        background: var(--composer-surface);
        padding: 1.1rem;
        box-shadow: 0 14px 36px rgba(15, 23, 42, 0.08);
    }

    .commercial-composer-title {
        min-width: 0;
    }

    .commercial-composer-title h1 {
        margin: 0;
        color: var(--composer-text);
        font-size: 2rem;
        font-weight: 800;
        line-height: 1.08;
        letter-spacing: 0;
        overflow-wrap: anywhere;
    }

    .commercial-composer-title p {
        max-width: 780px;
        margin: 0.45rem 0 0;
        color: var(--composer-muted);
        font-size: 0.95rem;
        line-height: 1.5;
    }

    .commercial-type-pills,
    .commercial-composer-actions,
    .commercial-card-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .commercial-type-pills {
        margin-top: 0.85rem;
    }

    .commercial-type-pill,
    .commercial-summary-pill,
    .commercial-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 800;
        line-height: 1.2;
        white-space: nowrap;
    }

    .commercial-type-pill {
        background: #eef2f7;
        color: #334155;
        padding: 0.38rem 0.68rem;
    }

    .commercial-type-pill.is-active {
        background: var(--composer-text);
        color: #ffffff;
    }

    .commercial-composer-actions {
        justify-content: flex-end;
    }

    .commercial-document-page .btn-premium-primary,
    .commercial-document-page .btn-premium-secondary {
        min-height: 2.35rem;
        align-items: center;
        border-radius: 8px;
        font-weight: 800;
        justify-content: center;
        line-height: 1.2;
        text-decoration: none;
    }

    .commercial-document-page .btn-premium-primary {
        background: var(--composer-accent);
        color: #ffffff;
    }

    .commercial-document-page .btn-premium-primary:hover,
    .commercial-document-page .btn-premium-primary:focus-visible {
        background: var(--composer-accent-strong);
        color: #ffffff;
    }

    .commercial-document-page .btn-premium-secondary {
        background: #ffffff;
        border: 1px solid var(--composer-border);
        color: #334155;
    }

    .commercial-document-page .btn-premium-secondary:hover,
    .commercial-document-page .btn-premium-secondary:focus-visible {
        border-color: #94a3b8;
        background: #f8fafc;
        color: #0f172a;
    }

    .commercial-document-page .btn-premium-primary:focus-visible,
    .commercial-document-page .btn-premium-secondary:focus-visible,
    .commercial-field :is(input, select, textarea):focus-visible,
    .invoice-line-items-table :is(input, select, textarea):focus-visible {
        outline: 3px solid rgba(20, 184, 166, 0.22);
        outline-offset: 2px;
    }

    .commercial-composer-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.6rem;
    }

    .commercial-summary-pill {
        border: 1px solid var(--composer-border);
        border-radius: 8px;
        background: #ffffff;
        color: #334155;
        padding: 0.65rem 0.75rem;
        min-width: 0;
    }

    .commercial-summary-pill i {
        color: var(--composer-accent);
    }

    .commercial-summary-pill span {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .commercial-composer-layout {
        align-items: start;
        display: grid;
        gap: 1rem;
        grid-template-columns: minmax(0, 1fr) minmax(360px, 460px);
    }

    .commercial-composer-rail {
        align-self: start;
        max-height: calc(100vh - 104px);
        overflow: auto;
        position: sticky;
        top: 88px;
        scrollbar-width: thin;
    }

    .commercial-card {
        border: 1px solid var(--composer-border);
        border-radius: 8px;
        background: var(--composer-surface);
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
    }

    .commercial-card-header {
        align-items: flex-start;
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        justify-content: space-between;
        margin-bottom: 1rem;
    }

    .commercial-card-header h2 {
        color: var(--composer-text);
        font-size: 1rem;
        font-weight: 800;
        letter-spacing: 0;
        line-height: 1.25;
        margin: 0;
    }

    .commercial-card-header p,
    .commercial-card-copy {
        color: var(--composer-muted);
        font-size: 0.84rem;
        line-height: 1.45;
        margin: 0.25rem 0 0;
    }

    .commercial-section-kicker {
        color: var(--composer-accent);
        font-size: 0.7rem;
        font-weight: 900;
        letter-spacing: 0;
        margin: 0 0 0.25rem;
        text-transform: uppercase;
    }

    .commercial-field-grid {
        display: grid;
        gap: 0.9rem;
    }

    .commercial-field-grid--three {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .commercial-field-grid--two {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .commercial-field {
        display: grid;
        gap: 0.35rem;
        min-width: 0;
    }

    .commercial-field > span {
        color: #1f2937;
        font-size: 0.82rem;
        font-weight: 800;
        line-height: 1.25;
    }

    .commercial-field input:not([type="checkbox"]),
    .commercial-field select,
    .commercial-field textarea,
    .invoice-line-items-table input,
    .invoice-line-items-table select,
    .invoice-line-items-table textarea {
        box-sizing: border-box;
        width: 100%;
        border: 1px solid var(--composer-border);
        border-radius: 8px;
        background: #ffffff;
        color: var(--composer-text);
        font: inherit;
        min-height: 2.45rem;
        padding: 0.62rem 0.7rem;
    }

    .commercial-field textarea,
    .invoice-line-items-table textarea {
        resize: vertical;
    }

    .commercial-template-description {
        color: var(--composer-muted);
        font-size: 0.8rem;
        line-height: 1.45;
    }

    .commercial-line-items-scroll {
        border: 1px solid var(--composer-border);
        border-radius: 8px;
        overflow: auto;
    }

    .invoice-line-items-table {
        border-collapse: separate;
        border-spacing: 0;
        min-width: 880px;
        width: 100%;
    }

    .invoice-line-items-table th {
        background: #f8fafc;
        border-bottom: 1px solid var(--composer-border);
        color: #475569;
        font-size: 0.74rem;
        font-weight: 900;
        padding: 0.7rem;
        text-align: left;
        text-transform: uppercase;
    }

    .invoice-line-items-table th:not(:first-child) {
        text-align: right;
    }

    .invoice-line-items-table td {
        border-bottom: 1px solid #e8eef6;
        padding: 0.58rem;
        vertical-align: top;
    }

    .invoice-line-items-table tbody tr:last-child td {
        border-bottom: none;
    }

    .line-description-stack,
    .line-pricing-actions {
        display: grid;
        gap: 0.42rem;
    }

    .line-pricing-actions {
        align-items: center;
        grid-template-columns: minmax(0, 1fr) auto;
    }

    .line-pricing-hint {
        color: var(--composer-muted);
        font-size: 0.78rem;
        line-height: 1.35;
    }

    .commercial-preview-launch-card {
        display: grid;
        gap: 0.25rem;
    }

    .commercial-preview-launch-card .commercial-card-header {
        margin-bottom: 0.65rem;
    }

    .commercial-preview-open {
        width: 100%;
    }

    .commercial-preview-modal[hidden] {
        display: none !important;
    }

    .commercial-preview-modal {
        --composer-border: #d8e2ef;
        --composer-text: #0f172a;
        --composer-accent: #0f766e;
        align-items: center;
        background: rgba(15, 23, 42, 0.62);
        backdrop-filter: blur(8px);
        display: grid;
        inset: 0;
        justify-items: center;
        padding: 1rem;
        position: fixed;
        z-index: 14000;
    }

    .commercial-preview-dialog {
        background: #ffffff;
        border: 1px solid var(--composer-border);
        border-radius: 8px;
        box-shadow: 0 24px 72px rgba(15, 23, 42, 0.32);
        display: grid;
        grid-template-rows: auto minmax(0, 1fr);
        max-height: calc(100vh - 2rem);
        overflow: hidden;
        width: min(1040px, calc(100vw - 2rem));
    }

    .commercial-preview-modal-header {
        align-items: flex-start;
        border-bottom: 1px solid var(--composer-border);
        display: flex;
        gap: 1rem;
        justify-content: space-between;
        padding: 1rem;
    }

    .commercial-preview-modal-header h2 {
        color: var(--composer-text);
        font-size: 1.1rem;
        font-weight: 800;
        letter-spacing: 0;
        line-height: 1.25;
        margin: 0.15rem 0 0;
    }

    .commercial-preview-close {
        align-items: center;
        background: #ffffff;
        border: 1px solid var(--composer-border);
        border-radius: 8px;
        color: #334155;
        display: inline-flex;
        font-weight: 800;
        gap: 0.45rem;
        justify-content: center;
        line-height: 1.2;
        min-height: 2.35rem;
        padding: 0.55rem 0.9rem;
    }

    .commercial-preview-close:hover,
    .commercial-preview-close:focus-visible {
        background: #f8fafc;
        border-color: #94a3b8;
        color: #0f172a;
    }

    .commercial-preview-close:focus-visible {
        outline: 3px solid rgba(20, 184, 166, 0.22);
        outline-offset: 2px;
    }

    #commercial-live-preview {
        background: #f8fafc;
        border: 0;
        height: min(78vh, 760px);
        width: 100%;
    }

    body.commercial-preview-modal-open {
        overflow: hidden;
    }

    .commercial-status-pill {
        background: #f8fafc;
        color: #475569;
        padding: 0.38rem 0.65rem;
    }

    .commercial-guidance-card {
        background: #fffdf8;
        border-color: #f1e7d6;
    }

    .commercial-controls-grid {
        display: grid;
        gap: 0.75rem;
        grid-template-columns: 1fr 1fr;
    }

    .commercial-totals-card {
        background: #fbfaf7;
    }

    .commercial-totals {
        display: grid;
        gap: 0.5rem;
        font-size: 0.95rem;
    }

    .commercial-total-row {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
    }

    .commercial-total-row--grand {
        border-top: 1px solid var(--composer-border);
        color: var(--composer-text);
        font-size: 1.08rem;
        font-weight: 900;
        padding-top: 0.55rem;
    }

    .commercial-revision-toggle {
        align-items: center;
        color: var(--composer-muted);
        display: flex;
        font-size: 0.9rem;
        gap: 0.5rem;
    }

    @media (max-width: 1180px) {
        .commercial-composer-layout {
            grid-template-columns: minmax(0, 1fr);
        }

        .commercial-composer-rail {
            max-height: none;
            overflow: visible;
            position: static;
        }
    }

    @media (max-width: 860px) {
        .commercial-composer-hero {
            grid-template-columns: minmax(0, 1fr);
        }

        .commercial-composer-actions {
            justify-content: flex-start;
        }

        .commercial-composer-summary,
        .commercial-field-grid--three,
        .commercial-field-grid--two,
        .commercial-controls-grid {
            grid-template-columns: minmax(0, 1fr);
        }

        .commercial-composer-title h1 {
            font-size: 1.55rem;
            line-height: 1.15;
        }

        .commercial-document-page .btn-premium-primary,
        .commercial-document-page .btn-premium-secondary {
            flex: 1 1 10rem;
        }

        #commercial-live-preview {
            height: 72vh;
        }
    }

    @media (max-width: 520px) {
        .commercial-document-page {
            padding-top: 0.75rem;
        }

        .commercial-document-page .container {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        .commercial-composer-hero,
        .commercial-card {
            padding: 0.85rem;
        }

        .commercial-preview-modal {
            padding: 0.5rem;
        }

        .commercial-preview-dialog {
            max-height: calc(100vh - 1rem);
            width: 100%;
        }

        .commercial-preview-modal-header {
            align-items: flex-start;
            flex-direction: row;
        }

        .commercial-preview-close {
            flex: 0 0 auto;
            padding: 0;
            width: 2.35rem;
        }

        .commercial-preview-close span {
            display: none;
        }

        .commercial-composer-title h1 {
            font-size: 1.35rem;
        }

        .commercial-composer-title p {
            font-size: 0.88rem;
        }

        .commercial-summary-pill {
            padding: 0.55rem 0.65rem;
        }

        .line-pricing-actions {
            grid-template-columns: minmax(0, 1fr);
        }
    }
</style>

<div class="page-premium commercial-document-page">
    <div class="container commercial-document-container">
        <form id="commercial-document-form" class="commercial-document-form" method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo \CRM\Security::getCsrfToken(); ?>">
            <?php if (array_key_exists('lock_version', $invoice)): ?>
                <input type="hidden" name="expected_lock_version" value="<?php echo (int) ($_POST['expected_lock_version'] ?? $invoice['lock_version']); ?>">
            <?php endif; ?>
            <input type="hidden" id="line_items_json" name="line_items_json" value="<?php echo htmlspecialchars(json_encode($lineItems), ENT_QUOTES, 'UTF-8'); ?>">

            <section class="commercial-composer-hero" aria-labelledby="commercial-document-heading">
                <div class="commercial-composer-title">
                    <h1 id="commercial-document-heading"><?php echo htmlspecialchars($pageHeading ?? 'Invoice'); ?></h1>
                    <p><?php echo htmlspecialchars($pageSubheading ?? 'Create and manage a commercial document'); ?></p>
                    <div class="commercial-type-pills" aria-label="Available document types">
                        <?php foreach ($documentTypeLabels as $typeKey => $typeLabel): ?>
                            <span class="commercial-type-pill <?php echo $invoiceDocumentType === $typeKey ? 'is-active' : ''; ?>" data-document-type="<?php echo htmlspecialchars($typeKey); ?>">
                                <?php echo htmlspecialchars($typeLabel); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="commercial-composer-actions">
                    <?php if (!empty($invoice['id'])): ?>
                        <a href="<?php echo $composerBasePath; ?>/invoice_pdf.php?id=<?php echo (int) $invoice['id']; ?>" target="_blank" class="btn-premium-secondary">
                            <i class="fas fa-file-pdf" aria-hidden="true"></i><span>PDF</span>
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo $composerBasePath; ?>/invoices.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left" aria-hidden="true"></i><span>Back</span>
                    </a>
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-file-circle-plus" aria-hidden="true"></i><span><?php echo htmlspecialchars($submitLabel ?? 'Save Document'); ?></span>
                    </button>
                </div>
            </section>

            <div class="commercial-composer-summary" aria-label="Draft summary">
                <div class="commercial-summary-pill"><i class="fas fa-file-lines" aria-hidden="true"></i><span id="composer-summary-type"><?php echo htmlspecialchars($documentTypeLabels[$invoiceDocumentType] ?? 'Invoice'); ?></span></div>
                <div class="commercial-summary-pill"><i class="fas fa-coins" aria-hidden="true"></i><span id="composer-summary-currency"><?php echo htmlspecialchars($invoiceCurrency); ?></span></div>
                <div class="commercial-summary-pill"><i class="fas fa-calendar-check" aria-hidden="true"></i><span id="composer-summary-terms"><?php echo htmlspecialchars((string) $paymentTerms); ?> days</span></div>
                <div class="commercial-summary-pill"><i class="fas fa-eye" aria-hidden="true"></i><span id="composer-summary-preview">Preview syncing</span></div>
            </div>

            <div class="commercial-composer-layout">
                <div class="commercial-composer-main">
                    <section class="content-card commercial-card" aria-labelledby="commercial-setup-heading">
                        <div class="commercial-card-header">
                            <div>
                                <p class="commercial-section-kicker">Setup</p>
                                <h2 id="commercial-setup-heading">Document setup</h2>
                            </div>
                        </div>
                        <div class="commercial-field-grid commercial-field-grid--three">
                            <label class="commercial-field">
                                <span>Document type</span>
                                <select name="document_type">
                                    <?php foreach (\CRM\Modules\Invoices::TYPES as $type): ?>
                                        <option value="<?php echo $type; ?>" <?php echo $invoiceDocumentType === $type ? 'selected' : ''; ?>><?php echo htmlspecialchars($documentTypeLabels[$type] ?? ucfirst(str_replace('_', ' ', $type))); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="commercial-field">
                                <span>Issue date</span>
                                <input type="date" name="issue_date" value="<?php echo htmlspecialchars($issueDate); ?>">
                            </label>
                            <label class="commercial-field">
                                <span>Payment terms (days)</span>
                                <input type="number" name="payment_terms_days" min="1" max="365" value="<?php echo htmlspecialchars((string) $paymentTerms); ?>">
                            </label>
                            <label class="commercial-field">
                                <span>Due date</span>
                                <input type="date" name="due_date" value="<?php echo htmlspecialchars($dueDate); ?>">
                            </label>
                            <label class="commercial-field">
                                <span>Valid until</span>
                                <input type="date" name="valid_until" value="<?php echo htmlspecialchars($validUntil); ?>">
                            </label>
                            <label class="commercial-field">
                                <span>Document template</span>
                                <select name="template_key">
                                    <?php foreach (($invoiceTemplates ?? []) as $template): ?>
                                        <option value="<?php echo htmlspecialchars((string) ($template['key'] ?? 'classic')); ?>" <?php echo $selectedTemplateKey === (string) ($template['key'] ?? 'classic') ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string) ($template['label'] ?? 'Classic')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="commercial-template-description">
                                    <?php
                                    foreach (($invoiceTemplates ?? []) as $template) {
                                        if (($template['key'] ?? '') === $selectedTemplateKey) {
                                            echo htmlspecialchars((string) ($template['description'] ?? ''));
                                            break;
                                        }
                                    }
                                    ?>
                                </span>
                            </label>
                            <label class="commercial-field">
                                <span>Currency</span>
                                <select name="currency">
                                    <?php foreach ($activeCurrencies as $currency): ?>
                                        <?php
                                        $currencyCode = (string) ($currency['code'] ?? '');
                                        $currencyName = (string) ($currency['name'] ?? '');
                                        $currencyLabel = trim($currencyCode . ($currencyName !== '' ? ' - ' . $currencyName : ''));
                                        ?>
                                        <option value="<?php echo htmlspecialchars($currency['code']); ?>" <?php echo $invoiceCurrency === $currency['code'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($currencyLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                    </section>

                    <section class="content-card commercial-card" aria-labelledby="commercial-context-heading">
                        <div class="commercial-card-header">
                            <div>
                                <p class="commercial-section-kicker">Recipient</p>
                                <h2 id="commercial-context-heading">Context and opening copy</h2>
                            </div>
                        </div>
                        <div class="commercial-field-grid commercial-field-grid--two">
                            <label class="commercial-field">
                                <span>Title</span>
                                <input type="text" name="title" value="<?php echo htmlspecialchars($titleValue); ?>">
                            </label>
                            <label class="commercial-field">
                                <span>Deal link</span>
                                <select name="deal_id">
                                    <option value="">No linked deal</option>
                                    <?php foreach ($deals as $deal): ?>
                                        <option value="<?php echo (int) $deal['id']; ?>" <?php echo (string) $selectedDeal === (string) $deal['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($deal['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="commercial-field">
                                <span>Contact</span>
                                <select name="contact_id">
                                    <option value="">No linked contact</option>
                                    <?php foreach ($contacts as $contact): ?>
                                        <option value="<?php echo (int) $contact['id']; ?>" <?php echo (string) $selectedContact === (string) $contact['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) . ' - ' . ($contact['email'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="commercial-field">
                                <span>Company</span>
                                <select name="company_id">
                                    <option value="">No linked company</option>
                                    <?php foreach ($companies as $company): ?>
                                        <option value="<?php echo (int) $company['id']; ?>" <?php echo (string) $selectedCompany === (string) $company['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($company['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <label class="commercial-field" style="margin-top:.9rem;">
                            <span>Intro text</span>
                            <textarea name="intro_text" rows="3"><?php echo htmlspecialchars($introValue); ?></textarea>
                        </label>
                    </section>

                    <section class="content-card commercial-card" aria-labelledby="commercial-line-items-heading">
                        <div class="commercial-card-header">
                            <div>
                                <p class="commercial-section-kicker">Pricing</p>
                                <h2 id="commercial-line-items-heading">Line items</h2>
                                <p>Use products or custom items. Add pricing explanations when catalog prices are missing and infer unit prices automatically.</p>
                            </div>
                            <div class="commercial-card-actions">
                                <button type="button" id="pull-deal-line-items" class="btn-premium-secondary"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i><span>Pull deal items</span></button>
                                <button type="button" id="add-line-item" class="btn-premium-secondary"><i class="fas fa-plus" aria-hidden="true"></i><span>Add line</span></button>
                            </div>
                        </div>
                        <div class="commercial-line-items-scroll">
                            <table class="invoice-line-items-table">
                                <thead>
                                    <tr>
                                        <th>Description</th>
                                        <th>Qty</th>
                                        <th>Unit</th>
                                        <th>Discount %</th>
                                        <th>Tax %</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="invoice-line-items-body"></tbody>
                            </table>
                        </div>
                        <template id="invoice-line-item-template">
                            <tr class="invoice-line-row">
                                <td>
                                    <div class="line-description-stack">
                                        <select class="line-product">
                                            <option value="">Custom item</option>
                                            <?php if (!empty($workspacePackages)): ?><optgroup label="Clarity workspace packages"><?php endif; ?>
                                            <?php foreach (($workspacePackages ?? []) as $package): ?>
                                                <?php foreach ((array) ($package['price_variants'] ?? []) as $variant): ?>
                                                    <option
                                                        value="package:<?php echo (int) ($variant['billing_plan_price_id'] ?? 0); ?>"
                                                        data-source-type="workspace_package"
                                                        data-billing-plan-price-id="<?php echo (int) ($variant['billing_plan_price_id'] ?? 0); ?>"
                                                        data-currency="<?php echo htmlspecialchars((string) ($variant['currency'] ?? 'KES')); ?>"
                                                        data-price="<?php echo (float) ($variant['amount'] ?? 0); ?>"
                                                        data-name="<?php echo htmlspecialchars((string) ($package['name'] ?? 'Workspace package')); ?>"
                                                        data-description="<?php echo htmlspecialchars((string) ($package['name'] ?? 'Workspace package') . ' - ' . ucfirst((string) ($variant['interval_unit'] ?? 'monthly'))); ?>"
                                                        data-pricing-info="System-managed recurring package. Subscription access activates only through checkout or an audited operator activation."
                                                    >
                                                        <?php echo htmlspecialchars((string) ($package['name'] ?? 'Workspace package') . ' - ' . ucfirst((string) ($variant['interval_unit'] ?? 'monthly')) . ' (' . (string) ($variant['currency'] ?? 'KES') . ' ' . number_format((float) ($variant['amount'] ?? 0), 0) . ')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                            <?php if (!empty($workspacePackages)): ?></optgroup><optgroup label="Business offers"><?php endif; ?>
                                            <?php foreach ($products as $product): ?>
                                                <option
                                                    value="<?php echo (int) $product['id']; ?>"
                                                    data-source-type="workspace_offer"
                                                    data-price="<?php echo (float) ($product['unit_price'] ?? 0); ?>"
                                                    data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                                    data-description="<?php echo htmlspecialchars((string) ($product['description'] ?? '')); ?>"
                                                    data-pricing-info="<?php echo htmlspecialchars((string) ($product['pricing_info'] ?? '')); ?>"
                                                >
                                                    <?php echo htmlspecialchars($product['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if (!empty($workspacePackages)): ?></optgroup><?php endif; ?>
                                        </select>
                                        <input type="text" class="line-description" placeholder="Description">
                                        <textarea class="line-pricing-context" rows="3" placeholder="Pricing explanation, package rules, ranges, retainers, per-seat or per-site notes"></textarea>
                                        <div class="line-pricing-actions">
                                            <small class="line-pricing-hint">Explain the price model clearly for AI or automatic inference.</small>
                                            <button type="button" class="infer-line-price btn-premium-secondary"><i class="fas fa-calculator" aria-hidden="true"></i><span>Infer price</span></button>
                                        </div>
                                    </div>
                                </td>
                                <td><input type="number" class="line-qty" min="0.01" step="0.01" value="1"></td>
                                <td><input type="number" class="line-unit-price" min="0" step="0.01" value="0"></td>
                                <td><input type="number" class="line-discount" min="0" max="100" step="0.01" value="0"></td>
                                <td><input type="number" class="line-tax" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars((string) $taxRate); ?>"></td>
                                <td><button type="button" class="remove-line btn-premium-secondary"><i class="fas fa-trash" aria-hidden="true"></i><span>Remove</span></button></td>
                            </tr>
                        </template>
                    </section>

                    <section class="content-card commercial-card" aria-labelledby="commercial-final-copy-heading">
                        <div class="commercial-card-header">
                            <div>
                                <p class="commercial-section-kicker">Final copy</p>
                                <h2 id="commercial-final-copy-heading">Notes and terms</h2>
                            </div>
                        </div>
                        <div class="commercial-field-grid commercial-field-grid--two">
                            <label class="commercial-field">
                                <span>Notes</span>
                                <textarea name="notes" rows="6"><?php echo htmlspecialchars($notesValue); ?></textarea>
                            </label>
                            <label class="commercial-field">
                                <span>Terms</span>
                                <textarea name="terms" rows="6"><?php echo htmlspecialchars($termsValue); ?></textarea>
                            </label>
                        </div>
                    </section>
                </div>

                <aside class="commercial-composer-rail" aria-label="Commercial document companion">
                    <section class="content-card commercial-card commercial-preview-launch-card" aria-labelledby="commercial-preview-heading">
                        <div class="commercial-card-header">
                            <div>
                                <h2 id="commercial-preview-heading">Live preview</h2>
                                <p>Preview stays synced while you compose.</p>
                            </div>
                            <div id="live-preview-status" class="commercial-status-pill">Syncing...</div>
                        </div>
                        <button type="button" id="open-commercial-preview" class="btn-premium-primary commercial-preview-open">
                            <i class="fas fa-up-right-from-square" aria-hidden="true"></i>
                            <span>Open preview</span>
                        </button>
                    </section>

                    <section id="commercial-guidance-panel" class="content-card commercial-card commercial-guidance-card" aria-labelledby="commercial-guidance-heading">
                        <div class="commercial-card-header">
                            <div>
                                <h2 id="commercial-guidance-heading">Commercial guidance</h2>
                                <p>Composer guidance is loading...</p>
                            </div>
                            <div id="commercial-stage-pill" class="commercial-status-pill">No deal linked yet</div>
                        </div>
                        <div id="commercial-context-summary" class="commercial-card-copy">Link a deal, contact, or company to reduce manual entry.</div>
                    </section>

                    <section class="content-card commercial-card" aria-labelledby="commercial-billing-heading">
                        <div class="commercial-card-header">
                            <div>
                                <h2 id="commercial-billing-heading">Billing</h2>
                            </div>
                            <button type="button" id="copy-linked-billing" class="btn-premium-secondary"><i class="fas fa-copy" aria-hidden="true"></i><span>Copy linked billing</span></button>
                        </div>
                        <div class="commercial-field-grid">
                            <label class="commercial-field">
                                <span>Billing name</span>
                                <input type="text" name="billing_name" value="<?php echo htmlspecialchars($billingName); ?>" autocomplete="name">
                            </label>
                            <label class="commercial-field">
                                <span>Billing email</span>
                                <input type="email" name="billing_email" value="<?php echo htmlspecialchars($billingEmail); ?>" autocomplete="email">
                            </label>
                            <label class="commercial-field">
                                <span>Billing phone</span>
                                <input type="text" name="billing_phone" value="<?php echo htmlspecialchars($billingPhone); ?>" autocomplete="tel">
                            </label>
                            <label class="commercial-field">
                                <span>Billing address</span>
                                <textarea name="billing_address" rows="4" autocomplete="street-address"><?php echo htmlspecialchars($billingAddress); ?></textarea>
                            </label>
                            <label class="commercial-field">
                                <span>Shipping address (optional)</span>
                                <textarea name="shipping_address" rows="3"><?php echo htmlspecialchars($shippingAddress); ?></textarea>
                            </label>
                        </div>
                    </section>

                    <section class="content-card commercial-card" aria-labelledby="commercial-controls-heading">
                        <div class="commercial-card-header">
                            <div>
                                <h2 id="commercial-controls-heading">Commercial controls</h2>
                            </div>
                        </div>
                        <div class="commercial-field-grid">
                            <label class="commercial-field">
                                <span>Assigned to</span>
                                <select name="assigned_to">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($users as $userRow): ?>
                                        <option value="<?php echo (int) $userRow['id']; ?>" <?php echo (string) $selectedAssigned === (string) $userRow['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($userRow['email']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="commercial-controls-grid">
                                <label class="commercial-field">
                                    <span>Tax mode</span>
                                    <select name="tax_mode">
                                        <option value="exclusive" <?php echo $taxMode === 'exclusive' ? 'selected' : ''; ?>>Tax exclusive</option>
                                        <option value="inclusive" <?php echo $taxMode === 'inclusive' ? 'selected' : ''; ?>>Tax inclusive</option>
                                        <option value="none" <?php echo $taxMode === 'none' ? 'selected' : ''; ?>>No tax</option>
                                    </select>
                                </label>
                                <label class="commercial-field">
                                    <span>Default tax %</span>
                                    <input type="number" name="tax_rate" min="0" step="0.01" value="<?php echo htmlspecialchars((string) $taxRate); ?>">
                                </label>
                            </div>
                            <?php if (!empty($invoice['id'])): ?>
                                <label class="commercial-revision-toggle">
                                    <input type="checkbox" name="increment_revision" value="1">
                                    <span>Increment revision and mark as revised</span>
                                </label>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="content-card commercial-card commercial-totals-card" aria-labelledby="commercial-totals-heading">
                        <div class="commercial-card-header">
                            <div>
                                <h2 id="commercial-totals-heading">Totals</h2>
                            </div>
                            <div id="totals-document-label" class="commercial-card-copy">Draft total</div>
                        </div>
                        <div id="invoice-totals" class="commercial-totals">
                            <div class="commercial-total-row"><span>Subtotal</span><strong id="total-subtotal">0.00</strong></div>
                            <div class="commercial-total-row"><span>Discount</span><strong id="total-discount">0.00</strong></div>
                            <div class="commercial-total-row"><span>Tax</span><strong id="total-tax">0.00</strong></div>
                            <div class="commercial-total-row commercial-total-row--grand"><span>Total</span><strong id="total-grand">0.00</strong></div>
                        </div>
                    </section>

                    <section class="content-card commercial-card" aria-labelledby="commercial-products-heading">
                        <div class="commercial-card-header">
                            <div>
                                <h2 id="commercial-products-heading">Suggested products</h2>
                                <p>Recommendations are based on the linked deal, contact, company, and commercialization stage.</p>
                            </div>
                            <button type="button" id="add-all-recommendations" class="btn-premium-secondary"><i class="fas fa-plus" aria-hidden="true"></i><span>Add all</span></button>
                        </div>
                        <div id="invoice-product-recommendations" class="commercial-field-grid">
                            <div class="commercial-card-copy">Choose a deal, contact, or company to load product suggestions.</div>
                        </div>
                    </section>
                </aside>
            </div>
        </form>
    </div>
</div>

<div id="commercial-preview-modal" class="commercial-preview-modal" hidden aria-hidden="true">
    <div class="commercial-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="commercial-preview-modal-title">
        <div class="commercial-preview-modal-header">
            <div>
                <p class="commercial-section-kicker">Preview</p>
                <h2 id="commercial-preview-modal-title">Live document preview</h2>
            </div>
            <button type="button" id="close-commercial-preview" class="commercial-preview-close" aria-label="Close live preview">
                <i class="fas fa-times" aria-hidden="true"></i>
                <span>Close</span>
            </button>
        </div>
        <iframe id="commercial-live-preview" title="Commercial document live preview"></iframe>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('commercial-document-form');
    const initialItems = <?php echo json_encode(array_values($lineItems)); ?>;
    const tbody = document.getElementById('invoice-line-items-body');
    const template = document.getElementById('invoice-line-item-template');
    const addButton = document.getElementById('add-line-item');
    const hiddenInput = document.getElementById('line_items_json');
    const currencyEl = form.querySelector('select[name="currency"]');
    const taxModeEl = form.querySelector('select[name="tax_mode"]');
    const taxRateEl = form.querySelector('input[name="tax_rate"]');
    const dealEl = form.querySelector('select[name="deal_id"]');
    const contactEl = form.querySelector('select[name="contact_id"]');
    const companyEl = form.querySelector('select[name="company_id"]');
    const documentTypeEl = form.querySelector('select[name="document_type"]');
    const recommendationEl = document.getElementById('invoice-product-recommendations');
    const previewFrame = document.getElementById('commercial-live-preview');
    const previewStatusEl = document.getElementById('live-preview-status');
    const previewModal = document.getElementById('commercial-preview-modal');
    const openPreviewButton = document.getElementById('open-commercial-preview');
    const closePreviewButton = document.getElementById('close-commercial-preview');
    const guidancePanel = document.getElementById('commercial-guidance-panel');
    const contextSummaryEl = document.getElementById('commercial-context-summary');
    const stagePillEl = document.getElementById('commercial-stage-pill');
    const addAllRecommendationsButton = document.getElementById('add-all-recommendations');
    const pullDealLineItemsButton = document.getElementById('pull-deal-line-items');
    const copyLinkedBillingButton = document.getElementById('copy-linked-billing');
    const totalsLabelEl = document.getElementById('totals-document-label');
    const summaryTypeEl = document.getElementById('composer-summary-type');
    const summaryCurrencyEl = document.getElementById('composer-summary-currency');
    const summaryTermsEl = document.getElementById('composer-summary-terms');
    const summaryPreviewEl = document.getElementById('composer-summary-preview');
    const documentTypePills = form.querySelectorAll('[data-document-type]');
    const previewUrl = '<?php echo $composerBasePath; ?>/../api/invoice_draft_preview.php';
    const contextUrl = '<?php echo $composerBasePath; ?>/../api/invoice_draft_context.php';
    const documentTypeLabels = <?php echo json_encode($documentTypeLabels); ?>;
    const dirtyFields = new Set();
    const latestContext = { data: null };
    let contextAbortController = null;
    let previewAbortController = null;
    let debounceHandle = null;
    let applyingSuggestedValues = false;
    let previewTrigger = null;

    if (previewModal && previewModal.parentElement !== document.body) {
        document.body.appendChild(previewModal);
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function documentLabel(type) {
        return documentTypeLabels[type] || String(type || 'invoice').replace(/_/g, ' ');
    }

    function syncComposerSummary() {
        const documentType = documentTypeEl ? documentTypeEl.value : 'invoice';
        const paymentTermsEl = form.querySelector('input[name="payment_terms_days"]');
        if (summaryTypeEl) {
            summaryTypeEl.textContent = documentLabel(documentType);
        }
        if (summaryCurrencyEl && currencyEl) {
            summaryCurrencyEl.textContent = currencyEl.value || '';
        }
        if (summaryTermsEl && paymentTermsEl) {
            const days = paymentTermsEl.value ? String(paymentTermsEl.value) : '0';
            summaryTermsEl.textContent = days + ' days';
        }
        documentTypePills.forEach(function (pill) {
            pill.classList.toggle('is-active', pill.getAttribute('data-document-type') === documentType);
        });
    }

    function rowToData(row) {
        const product = row.querySelector('.line-product');
        const selected = product.options[product.selectedIndex];
        const sourceType = selected ? (selected.getAttribute('data-source-type') || 'manual') : 'manual';
        return {
            product_id: sourceType === 'workspace_offer' ? (product.value || null) : null,
            catalog_source_type: sourceType,
            billing_plan_price_id: sourceType === 'workspace_package' ? parseInt(selected.getAttribute('data-billing-plan-price-id') || '0', 10) : null,
            description: row.querySelector('.line-description').value || '',
            pricing_context: row.querySelector('.line-pricing-context').value || '',
            quantity: parseFloat(row.querySelector('.line-qty').value || '0'),
            unit_price: parseFloat(row.querySelector('.line-unit-price').value || '0'),
            discount_percent: parseFloat(row.querySelector('.line-discount').value || '0'),
            tax_percent: parseFloat(row.querySelector('.line-tax').value || taxRateEl.value || '0')
        };
    }

    function renderTotals(items) {
        const currency = currencyEl ? currencyEl.value : '';
        const taxMode = taxModeEl ? taxModeEl.value : 'exclusive';
        let subtotal = 0;
        let discount = 0;
        let tax = 0;
        let grand = 0;
        items.forEach(function (item) {
            const qty = Math.max(0, item.quantity || 0);
            const unit = Math.max(0, item.unit_price || 0);
            const gross = qty * unit;
            const discountAmount = gross * ((item.discount_percent || 0) / 100);
            const base = gross - discountAmount;
            const taxAmount = taxMode === 'none' ? 0 : base * ((item.tax_percent || 0) / 100);
            subtotal += base;
            discount += discountAmount;
            tax += taxAmount;
            grand += taxMode === 'inclusive' ? base : base + taxAmount;
        });
        document.getElementById('total-subtotal').textContent = currency + ' ' + subtotal.toFixed(2);
        document.getElementById('total-discount').textContent = currency + ' ' + discount.toFixed(2);
        document.getElementById('total-tax').textContent = currency + ' ' + tax.toFixed(2);
        document.getElementById('total-grand').textContent = currency + ' ' + grand.toFixed(2);
        totalsLabelEl.textContent = documentLabel(documentTypeEl ? documentTypeEl.value : 'invoice') + ' total';
        syncComposerSummary();
    }

    function serialize() {
        const items = Array.from(tbody.querySelectorAll('.invoice-line-row')).map(rowToData).filter(item => item.description.trim() !== '');
        hiddenInput.value = JSON.stringify(items);
        renderTotals(items);
        return items;
    }

    async function inferPriceForRow(row) {
        const hint = row.querySelector('.line-pricing-hint');
        const payload = rowToData(row);
        hint.textContent = 'Inferring unit price...';
        try {
            const response = await fetch('<?php echo getBasePath(); ?>/../api/invoice_pricing_infer.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.error || 'Could not infer price.');
            }
            if (parseFloat(result.unit_price || 0) > 0) {
                row.querySelector('.line-unit-price').value = parseFloat(result.unit_price).toFixed(2);
            }
            hint.textContent = result.reasoning || 'Price inferred.';
            scheduleComposerRefresh();
        } catch (error) {
            hint.textContent = error.message || 'Could not infer price.';
        }
    }

    function bindRow(row) {
        row.querySelector('.remove-line').addEventListener('click', function () {
            row.remove();
            scheduleComposerRefresh();
        });
        row.querySelector('.infer-line-price').addEventListener('click', function () {
            inferPriceForRow(row);
        });
        row.querySelectorAll('input, select, textarea').forEach(function (input) {
            input.addEventListener('input', scheduleComposerRefresh);
            input.addEventListener('change', function () {
                if (input.classList.contains('line-product')) {
                    const selected = input.options[input.selectedIndex];
                    const price = selected ? selected.getAttribute('data-price') : '';
                    const description = selected ? selected.getAttribute('data-description') : '';
                    const productName = selected ? selected.getAttribute('data-name') : '';
                    const pricingInfo = selected ? selected.getAttribute('data-pricing-info') : '';
                    const packageCurrency = selected ? selected.getAttribute('data-currency') : '';
                    if (price) {
                        row.querySelector('.line-unit-price').value = price;
                    }
                    if (!row.querySelector('.line-description').value) {
                        row.querySelector('.line-description').value = description || productName || selected.textContent.trim();
                    }
                    if (!row.querySelector('.line-pricing-context').value && pricingInfo) {
                        row.querySelector('.line-pricing-context').value = pricingInfo;
                    }
                    if (packageCurrency && currencyEl) {
                        const matchingCurrency = Array.from(currencyEl.options).some(function (option) { return option.value === packageCurrency; });
                        if (matchingCurrency) {
                            currencyEl.value = packageCurrency;
                        }
                    }
                    if ((!price || parseFloat(price) <= 0) && (pricingInfo || row.querySelector('.line-pricing-context').value)) {
                        inferPriceForRow(row);
                        return;
                    }
                }
                scheduleComposerRefresh();
            });
        });
    }

    function addRow(item) {
        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('.invoice-line-row');
        const sourceType = item && item.catalog_source_type ? item.catalog_source_type : (item && item.product_id ? 'workspace_offer' : 'manual');
        row.querySelector('.line-product').value = sourceType === 'workspace_package' && item.billing_plan_price_id
            ? 'package:' + String(item.billing_plan_price_id)
            : (item && item.product_id ? String(item.product_id) : '');
        row.querySelector('.line-description').value = item && item.description ? item.description : '';
        row.querySelector('.line-pricing-context').value = item && item.pricing_context ? item.pricing_context : '';
        row.querySelector('.line-qty').value = item && item.quantity ? item.quantity : 1;
        row.querySelector('.line-unit-price').value = item && item.unit_price ? item.unit_price : 0;
        row.querySelector('.line-discount').value = item && item.discount_percent ? item.discount_percent : 0;
        row.querySelector('.line-tax').value = item && item.tax_percent ? item.tax_percent : (taxRateEl ? taxRateEl.value : 0);
        bindRow(row);
        tbody.appendChild(fragment);
    }

    function addRecommendationAsLine(item) {
        addRow({
            product_id: item.product_id || null,
            description: item.description || item.name || '',
            pricing_context: item.pricing_info || item.reason || '',
            quantity: item.recommended_quantity || 1,
            unit_price: item.unit_price || 0,
            discount_percent: 0,
            tax_percent: taxRateEl ? parseFloat(taxRateEl.value || '0') : 0
        });
    }

    function renderRecommendations(items) {
        if (!recommendationEl) return;
        if (!Array.isArray(items) || items.length === 0) {
            recommendationEl.innerHTML = '<div style="color:var(--charcoal-grey);font-size:.9rem;">No product suggestions yet for the current context.</div>';
            addAllRecommendationsButton.disabled = true;
            return;
        }

        addAllRecommendationsButton.disabled = false;
        recommendationEl.innerHTML = items.map(function (item) {
            const unitPrice = parseFloat(item.unit_price || 0);
            const priceLabel = currencyEl.value + ' ' + unitPrice.toFixed(2);
            const pricingNote = unitPrice <= 0 ? '<div style="color:#b45309;font-size:.8rem;">Price not set in catalog. Review before saving.</div>' : '';
            return ''
                + '<div style="border:1px solid var(--border-color);border-radius:10px;padding:.85rem;background:#fff;">'
                + '<div style="display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start;">'
                + '<div>'
                + '<div style="font-weight:600;color:var(--midnight-black);">' + escapeHtml(item.name) + '</div>'
                + '<div style="font-size:.85rem;color:var(--charcoal-grey);margin-top:.15rem;">' + escapeHtml(item.reason || '') + '</div>'
                + '<div style="font-size:.85rem;color:var(--midnight-black);margin-top:.35rem;">' + escapeHtml(priceLabel) + ' - Confidence ' + escapeHtml(String(item.confidence || 0)) + '</div>'
                + pricingNote
                + '</div>'
                + '<button type="button" class="btn-premium-secondary add-recommended-product"'
                + ' data-product-id="' + escapeHtml(String(item.product_id || '')) + '"'
                + ' data-name="' + escapeHtml(item.name || '') + '"'
                + ' data-description="' + escapeHtml(item.description || '') + '"'
                + ' data-pricing-info="' + escapeHtml(item.pricing_info || item.reason || '') + '"'
                + ' data-unit-price="' + escapeHtml(String(unitPrice)) + '"'
                + ' data-recommended-quantity="' + escapeHtml(String(item.recommended_quantity || 1)) + '">Add</button>'
                + '</div>'
                + '</div>';
        }).join('');

        recommendationEl.querySelectorAll('.add-recommended-product').forEach(function (button) {
            button.addEventListener('click', function () {
                addRecommendationAsLine({
                    product_id: button.getAttribute('data-product-id'),
                    name: button.getAttribute('data-name'),
                    description: button.getAttribute('data-description'),
                    pricing_info: button.getAttribute('data-pricing-info'),
                    unit_price: parseFloat(button.getAttribute('data-unit-price') || '0'),
                    recommended_quantity: parseFloat(button.getAttribute('data-recommended-quantity') || '1')
                });
                scheduleComposerRefresh();
            });
        });
    }

    function renderGuidance(context) {
        const guidance = context.guidance || {};
        const readiness = context.readiness || {};
        const linked = context.linked_entities || {};
        const blockers = Array.isArray(readiness.blocking_issues) ? readiness.blocking_issues : [];
        const warnings = Array.isArray(readiness.warnings) ? readiness.warnings : [];
        const rationale = Array.isArray(guidance.rationale) ? guidance.rationale : [];
        const stageLabel = guidance.stage_label ? guidance.stage_label + ' stage' : 'No deal linked yet';
        const summaryParts = [];

        if (linked.deal && linked.deal.title) {
            summaryParts.push('Deal: ' + linked.deal.title);
        }
        if (linked.contact && linked.contact.name) {
            summaryParts.push('Contact: ' + linked.contact.name);
        }
        if (linked.company && linked.company.name) {
            summaryParts.push('Company: ' + linked.company.name);
        }

        guidancePanel.innerHTML = ''
            + '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">'
            + '<div>'
            + '<h2 style="margin:0;color:var(--midnight-black);font-size:1rem;">Commercial guidance</h2>'
            + '<p style="margin:.25rem 0 0 0;color:var(--charcoal-grey);font-size:.85rem;">' + escapeHtml(guidance.summary || 'Composer guidance is ready.') + '</p>'
            + '</div>'
            + '<div style="display:inline-flex;align-items:center;padding:.35rem .65rem;border-radius:999px;background:' + (blockers.length > 0 ? '#fee2e2' : '#e2e8f0') + ';color:' + (blockers.length > 0 ? '#991b1b' : '#334155') + ';font-size:.78rem;font-weight:700;">' + escapeHtml(stageLabel) + '</div>'
            + '</div>'
            + '<div style="color:var(--charcoal-grey);font-size:.85rem;">' + escapeHtml(summaryParts.length > 0 ? summaryParts.join(' - ') : 'Link a deal, contact, or company to reduce manual entry.') + '</div>'
            + '<div style="padding:.85rem;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">'
            + '<div style="font-weight:700;color:var(--midnight-black);margin-bottom:.25rem;">' + escapeHtml(guidance.headline || 'Stay with the current document type.') + '</div>'
            + '<div style="font-size:.86rem;color:var(--charcoal-grey);">Recommended type: <strong style="color:var(--midnight-black);">' + escapeHtml(guidance.recommended_document_label || documentLabel(documentTypeEl.value)) + '</strong></div>'
            + '</div>'
            + blockers.map(function (issue) {
                return '<div style="padding:.7rem;border-radius:10px;background:#fff1f2;color:#991b1b;font-size:.88rem;">Blocker: ' + escapeHtml(issue.message || '') + '</div>';
            }).join('')
            + warnings.map(function (warning) {
                return '<div style="padding:.7rem;border-radius:10px;background:#fffbeb;color:#92400e;font-size:.88rem;">Warning: ' + escapeHtml(warning.message || '') + '</div>';
            }).join('')
            + rationale.map(function (reason) {
                return '<div style="padding:.7rem;border-radius:10px;background:#f8fafc;color:#475569;font-size:.86rem;">' + escapeHtml(reason) + '</div>';
            }).join('');
    }

    function collectPayload() {
        const items = serialize();
        return {
            document_type: documentTypeEl ? documentTypeEl.value : 'invoice',
            deal_id: dealEl ? dealEl.value : '',
            contact_id: contactEl ? contactEl.value : '',
            company_id: companyEl ? companyEl.value : '',
            assigned_to: form.querySelector('select[name="assigned_to"]') ? form.querySelector('select[name="assigned_to"]').value : '',
            currency: currencyEl ? currencyEl.value : '',
            issue_date: form.querySelector('input[name="issue_date"]') ? form.querySelector('input[name="issue_date"]').value : '',
            due_date: form.querySelector('input[name="due_date"]') ? form.querySelector('input[name="due_date"]').value : '',
            valid_until: form.querySelector('input[name="valid_until"]') ? form.querySelector('input[name="valid_until"]').value : '',
            payment_terms_days: form.querySelector('input[name="payment_terms_days"]') ? form.querySelector('input[name="payment_terms_days"]').value : '',
            tax_mode: taxModeEl ? taxModeEl.value : 'exclusive',
            tax_rate: taxRateEl ? taxRateEl.value : '0',
            title: form.querySelector('input[name="title"]') ? form.querySelector('input[name="title"]').value : '',
            intro_text: form.querySelector('textarea[name="intro_text"]') ? form.querySelector('textarea[name="intro_text"]').value : '',
            notes: form.querySelector('textarea[name="notes"]') ? form.querySelector('textarea[name="notes"]').value : '',
            terms: form.querySelector('textarea[name="terms"]') ? form.querySelector('textarea[name="terms"]').value : '',
            billing_name: form.querySelector('input[name="billing_name"]') ? form.querySelector('input[name="billing_name"]').value : '',
            billing_email: form.querySelector('input[name="billing_email"]') ? form.querySelector('input[name="billing_email"]').value : '',
            billing_phone: form.querySelector('input[name="billing_phone"]') ? form.querySelector('input[name="billing_phone"]').value : '',
            billing_address: form.querySelector('textarea[name="billing_address"]') ? form.querySelector('textarea[name="billing_address"]').value : '',
            shipping_address: form.querySelector('textarea[name="shipping_address"]') ? form.querySelector('textarea[name="shipping_address"]').value : '',
            template_key: form.querySelector('select[name="template_key"]') ? form.querySelector('select[name="template_key"]').value : '',
            line_items: items
        };
    }

    function setFieldValue(fieldName, value, force) {
        const field = form.querySelector('[name="' + fieldName + '"]');
        if (!field || value === undefined || value === null) {
            return false;
        }
        const stringValue = String(value);
        const suggestedValue = field.dataset.suggestedValue || '';
        const canApply = force || String(field.value || '').trim() === '' || !dirtyFields.has(fieldName) || String(field.value || '') === suggestedValue;
        if (!canApply || String(field.value || '') === stringValue) {
            return false;
        }
        applyingSuggestedValues = true;
        field.value = stringValue;
        field.dataset.suggestedValue = stringValue;
        applyingSuggestedValues = false;
        return true;
    }

    function applyAutofill(context) {
        const autofill = context.autofill || {};
        let changed = false;
        changed = setFieldValue('contact_id', autofill.contact_id || '', false) || changed;
        changed = setFieldValue('company_id', autofill.company_id || '', false) || changed;
        changed = setFieldValue('assigned_to', autofill.assigned_to || '', false) || changed;
        changed = setFieldValue('currency', autofill.currency || '', false) || changed;
        changed = setFieldValue('tax_mode', autofill.tax_mode || '', false) || changed;
        changed = setFieldValue('tax_rate', autofill.tax_rate || '', false) || changed;
        changed = setFieldValue('payment_terms_days', autofill.payment_terms_days || '', false) || changed;
        changed = setFieldValue('issue_date', autofill.issue_date || '', false) || changed;
        changed = setFieldValue('due_date', autofill.due_date || '', false) || changed;
        changed = setFieldValue('valid_until', autofill.valid_until || '', false) || changed;
        changed = setFieldValue('title', autofill.title || '', false) || changed;
        changed = setFieldValue('intro_text', autofill.intro_text || '', false) || changed;
        changed = setFieldValue('billing_name', autofill.billing_name || '', false) || changed;
        changed = setFieldValue('billing_email', autofill.billing_email || '', false) || changed;
        changed = setFieldValue('billing_phone', autofill.billing_phone || '', false) || changed;
        changed = setFieldValue('billing_address', autofill.billing_address || '', false) || changed;
        changed = setFieldValue('shipping_address', autofill.shipping_address || '', false) || changed;
        copyLinkedBillingButton.disabled = !autofill.billing_name && !autofill.billing_email && !autofill.billing_phone && !autofill.billing_address;
        pullDealLineItemsButton.disabled = !Array.isArray(context.deal_line_items) || context.deal_line_items.length === 0;
        return changed;
    }

    async function loadContext(payload) {
        if (contextAbortController) {
            contextAbortController.abort();
        }
        contextAbortController = new AbortController();
        recommendationEl.innerHTML = '<div style="color:var(--charcoal-grey);font-size:.9rem;">Loading composer guidance...</div>';
        try {
            const response = await fetch(contextUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
                signal: contextAbortController.signal
            });
            const context = await response.json();
            if (!response.ok || !context.success) {
                throw new Error(context.error || 'Unable to load composer guidance.');
            }
            latestContext.data = context;
            renderRecommendations(context.suggested_products || []);
            renderGuidance(context);
            if (applyAutofill(context)) {
                scheduleComposerRefresh();
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            recommendationEl.innerHTML = '<div style="color:#991b1b;font-size:.9rem;">' + escapeHtml(error.message || 'Unable to load recommendations') + '</div>';
            guidancePanel.innerHTML = '<div style="padding:.75rem;border-radius:10px;background:#fff1f2;color:#991b1b;font-size:.9rem;">' + escapeHtml(error.message || 'Unable to load composer guidance.') + '</div>';
        }
    }

    async function loadPreview(payload) {
        if (previewAbortController) {
            previewAbortController.abort();
        }
        previewAbortController = new AbortController();
        previewStatusEl.textContent = 'Updating preview...';
        previewStatusEl.style.background = '#eff6ff';
        previewStatusEl.style.color = '#1d4ed8';
        if (summaryPreviewEl) {
            summaryPreviewEl.textContent = 'Preview updating';
        }
        try {
            const response = await fetch(previewUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
                signal: previewAbortController.signal
            });
            const html = await response.text();
            if (!response.ok) {
                throw new Error('Unable to refresh live preview.');
            }
            previewFrame.srcdoc = html;
            previewStatusEl.textContent = 'Live preview synced';
            previewStatusEl.style.background = '#ecfdf5';
            previewStatusEl.style.color = '#065f46';
            if (summaryPreviewEl) {
                summaryPreviewEl.textContent = 'Preview synced';
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            previewFrame.srcdoc = '<!doctype html><html><body style="font-family:Arial,sans-serif;padding:24px;color:#991b1b;background:#fff7f7;">'
                + escapeHtml(error.message || 'Unable to refresh live preview.')
                + '</body></html>';
            previewStatusEl.textContent = 'Preview unavailable';
            previewStatusEl.style.background = '#fff1f2';
            previewStatusEl.style.color = '#991b1b';
            if (summaryPreviewEl) {
                summaryPreviewEl.textContent = 'Preview unavailable';
            }
        }
    }

    function copyBillingFromContext() {
        if (!latestContext.data || !latestContext.data.autofill) {
            return;
        }
        const autofill = latestContext.data.autofill;
        setFieldValue('billing_name', autofill.billing_name || '', true);
        setFieldValue('billing_email', autofill.billing_email || '', true);
        setFieldValue('billing_phone', autofill.billing_phone || '', true);
        setFieldValue('billing_address', autofill.billing_address || '', true);
        setFieldValue('shipping_address', autofill.shipping_address || '', true);
        scheduleComposerRefresh();
    }

    function addAllRecommendations() {
        const suggestions = latestContext.data && Array.isArray(latestContext.data.suggested_products)
            ? latestContext.data.suggested_products
            : [];
        if (suggestions.length === 0) {
            return;
        }
        suggestions.forEach(addRecommendationAsLine);
        scheduleComposerRefresh();
    }

    function appendDealLineItems() {
        const items = latestContext.data && Array.isArray(latestContext.data.deal_line_items)
            ? latestContext.data.deal_line_items
            : [];
        if (items.length === 0) {
            return;
        }
        items.forEach(addRow);
        scheduleComposerRefresh();
    }

    function openPreviewModal() {
        if (!previewModal) {
            return;
        }
        previewTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        previewModal.hidden = false;
        previewModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('commercial-preview-modal-open');
        if (closePreviewButton) {
            closePreviewButton.focus();
        }
    }

    function closePreviewModal() {
        if (!previewModal) {
            return;
        }
        previewModal.hidden = true;
        previewModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('commercial-preview-modal-open');
        if (previewTrigger && typeof previewTrigger.focus === 'function') {
            previewTrigger.focus();
        }
    }

    function scheduleComposerRefresh() {
        if (debounceHandle) {
            clearTimeout(debounceHandle);
        }
        debounceHandle = setTimeout(function () {
            const payload = collectPayload();
            loadContext(payload);
            loadPreview(payload);
        }, 280);
    }

    function trackDirtyFields() {
        form.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (field) {
            if (field.name === 'csrf_token' || field.name === 'line_items_json' || field.closest('.invoice-line-row')) {
                return;
            }
            field.addEventListener('input', function () {
                if (applyingSuggestedValues) {
                    return;
                }
                dirtyFields.add(field.name);
            });
            field.addEventListener('change', function () {
                if (applyingSuggestedValues) {
                    return;
                }
                dirtyFields.add(field.name);
            });
        });
    }

    addButton.addEventListener('click', function () {
        addRow();
        scheduleComposerRefresh();
    });
    addAllRecommendationsButton.addEventListener('click', addAllRecommendations);
    pullDealLineItemsButton.addEventListener('click', appendDealLineItems);
    copyLinkedBillingButton.addEventListener('click', copyBillingFromContext);
    if (openPreviewButton) {
        openPreviewButton.addEventListener('click', openPreviewModal);
    }
    if (closePreviewButton) {
        closePreviewButton.addEventListener('click', closePreviewModal);
    }
    if (previewModal) {
        previewModal.addEventListener('click', function (event) {
            if (event.target === previewModal) {
                closePreviewModal();
            }
        });
    }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && previewModal && !previewModal.hidden) {
            closePreviewModal();
        }
    });

    if (Array.isArray(initialItems) && initialItems.length > 0) {
        initialItems.forEach(addRow);
    } else {
        addRow();
    }

    trackDirtyFields();

    form.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (field) {
        if (field.name === 'csrf_token' || field.name === 'line_items_json' || field.closest('.invoice-line-row')) {
            return;
        }
        field.addEventListener('input', scheduleComposerRefresh);
        field.addEventListener('change', scheduleComposerRefresh);
    });

    addAllRecommendationsButton.disabled = true;
    pullDealLineItemsButton.disabled = true;
    copyLinkedBillingButton.disabled = true;
    serialize();
    scheduleComposerRefresh();
})();
</script>
