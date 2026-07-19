<?php

namespace CRM\Services;

use CRM\Security;
use InvalidArgumentException;

/**
 * Safe, versioned Form Studio document contract.
 *
 * Stored form documents can select code-owned field types and properties, but
 * cannot persist executable HTML, JavaScript, PHP, CSS, or database commands.
 */
final class FormDesignService
{
    public const SCHEMA_VERSION = 'crm.form/v1';
    public const MAX_FIELDS = 80;
    public const MAX_STEPS = 12;

    /** @return array<string,array<string,mixed>> */
    public function fieldRegistry(): array
    {
        return [
            'text' => $this->definition('Text', 'Input fields', 'fa-font', true),
            'email' => $this->definition('Email', 'Input fields', 'fa-envelope', true),
            'phone' => $this->definition('Phone', 'Input fields', 'fa-phone', true),
            'number' => $this->definition('Number', 'Input fields', 'fa-hashtag', true),
            'textarea' => $this->definition('Long answer', 'Input fields', 'fa-align-left', true),
            'select' => $this->definition('Dropdown', 'Choice fields', 'fa-chevron-down', true, true),
            'radio' => $this->definition('Radio buttons', 'Choice fields', 'fa-circle-dot', true, true),
            'checkbox' => $this->definition('Checkbox', 'Choice fields', 'fa-square-check', true),
            'date' => $this->definition('Date', 'Input fields', 'fa-calendar-day', true),
            'time' => $this->definition('Time', 'Input fields', 'fa-clock', true),
            'file' => $this->definition('File upload', 'Input fields', 'fa-cloud-arrow-up', true),
            'rating' => $this->definition('Rating', 'Choice fields', 'fa-star', true),
            'nps' => $this->definition('NPS score', 'Choice fields', 'fa-gauge-high', true),
            'hidden' => $this->definition('Hidden value', 'Advanced', 'fa-eye-slash', true),
            'heading' => $this->definition('Heading', 'Content', 'fa-heading', false),
            'paragraph' => $this->definition('Paragraph', 'Content', 'fa-paragraph', false),
            'divider' => $this->definition('Divider', 'Content', 'fa-minus', false),
            'page_break' => $this->definition('Page break', 'Structure', 'fa-table-columns', false),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function editorManifest(): array
    {
        $out = [];
        foreach ($this->fieldRegistry() as $type => $definition) {
            $out[] = ['type' => $type] + $definition;
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function templates(): array
    {
        return [
            $this->template('contact', 'Contact form', 'A concise, dependable contact path.', 'contact', [
                $this->field('text', 'Full name', 'full_name', true, ['mapping' => 'first_name']),
                $this->field('email', 'Work email', 'email', true, ['mapping' => 'email']),
                $this->field('phone', 'Phone', 'phone', false, ['mapping' => 'phone']),
                $this->field('textarea', 'How can we help?', 'message', true),
            ]),
            $this->template('lead_qualification', 'Lead qualification', 'Qualify intent across contact, company, and goals.', 'lead_generation', [
                $this->field('text', 'Full name', 'full_name', true, ['mapping' => 'first_name', 'step_id' => 'contact']),
                $this->field('email', 'Work email', 'email', true, ['mapping' => 'email', 'step_id' => 'contact']),
                $this->field('text', 'Company', 'company', true, ['mapping' => 'company', 'step_id' => 'company']),
                $this->field('select', 'Company size', 'company_size', true, ['options' => ['1-10', '11-50', '51-200', '201+'], 'step_id' => 'company']),
                $this->field('textarea', 'What would you like to improve?', 'goals', true, ['step_id' => 'goals']),
                $this->field('select', 'Expected budget', 'budget', false, ['options' => ['Under $1,000', '$1,000-$5,000', '$5,000-$20,000', '$20,000+'], 'step_id' => 'goals']),
            ], [
                ['id' => 'contact', 'title' => 'Contact', 'description' => 'How should we reach you?'],
                ['id' => 'company', 'title' => 'Company', 'description' => 'Tell us about the business.'],
                ['id' => 'goals', 'title' => 'Goals', 'description' => 'What outcome are you pursuing?'],
            ]),
            $this->template('quote_request', 'Quote request', 'Capture service, budget, timing, and project context.', 'sales', [
                $this->field('text', 'Full name', 'full_name', true, ['mapping' => 'first_name']),
                $this->field('email', 'Email', 'email', true, ['mapping' => 'email']),
                $this->field('select', 'Service needed', 'service', true, ['options' => ['Consulting', 'Implementation', 'Training', 'Support']]),
                $this->field('select', 'Budget range', 'budget', false, ['options' => ['Under $1,000', '$1,000-$5,000', '$5,000-$20,000', '$20,000+']]),
                $this->field('date', 'Preferred start date', 'start_date', false),
                $this->field('textarea', 'Project details', 'project_details', true),
            ]),
            $this->template('booking_request', 'Booking request', 'Collect the details needed before confirming a meeting.', 'booking', [
                $this->field('text', 'Full name', 'full_name', true, ['mapping' => 'first_name']),
                $this->field('email', 'Email', 'email', true, ['mapping' => 'email']),
                $this->field('date', 'Preferred date', 'preferred_date', true),
                $this->field('time', 'Preferred time', 'preferred_time', true),
                $this->field('textarea', 'What should we prepare?', 'meeting_context', false),
            ]),
            $this->template('event_registration', 'Event registration', 'A practical event registration flow.', 'events', [
                $this->field('text', 'Full name', 'full_name', true, ['mapping' => 'first_name']),
                $this->field('email', 'Email', 'email', true, ['mapping' => 'email']),
                $this->field('text', 'Company', 'company', false, ['mapping' => 'company']),
                $this->field('radio', 'Attendance', 'attendance', true, ['options' => ['In person', 'Online']]),
                $this->field('checkbox', 'Send me event updates', 'event_updates', false),
            ]),
            $this->template('application', 'Application form', 'A structured two-step application.', 'applications', [
                $this->field('text', 'Full name', 'full_name', true, ['mapping' => 'first_name', 'step_id' => 'profile']),
                $this->field('email', 'Email', 'email', true, ['mapping' => 'email', 'step_id' => 'profile']),
                $this->field('phone', 'Phone', 'phone', false, ['mapping' => 'phone', 'step_id' => 'profile']),
                $this->field('textarea', 'Relevant experience', 'experience', true, ['step_id' => 'application']),
                $this->field('file', 'Resume or supporting file', 'supporting_file', true, ['step_id' => 'application']),
            ], [
                ['id' => 'profile', 'title' => 'Profile', 'description' => 'Your contact details.'],
                ['id' => 'application', 'title' => 'Application', 'description' => 'Your experience and supporting file.'],
            ]),
            $this->template('feedback', 'Customer feedback', 'Measure sentiment and collect useful context.', 'feedback', [
                $this->field('rating', 'How was your experience?', 'rating', true),
                $this->field('nps', 'How likely are you to recommend us?', 'nps', true),
                $this->field('textarea', 'What should we improve?', 'feedback', false),
                $this->field('email', 'Email for follow-up', 'email', false, ['mapping' => 'email']),
            ]),
            $this->template('waitlist', 'Waitlist', 'A low-friction signup with useful segmentation.', 'signup', [
                $this->field('email', 'Work email', 'email', true, ['mapping' => 'email']),
                $this->field('select', 'Your role', 'role', false, ['options' => ['Founder', 'Marketing', 'Sales', 'Operations', 'Other']]),
                $this->field('textarea', 'What are you hoping to solve?', 'interest', false),
            ]),
        ];
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $form */
    public function normalizeDocument(array $document, array $form = []): array
    {
        if ($document === [] || empty($document['fields'])) {
            $document = $this->legacyDocument($form);
        }
        if ((string) ($document['schema'] ?? self::SCHEMA_VERSION) !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported form design schema version.');
        }

        $steps = [];
        $seenSteps = [];
        foreach (array_slice(is_array($document['steps'] ?? null) ? $document['steps'] : [], 0, self::MAX_STEPS) as $index => $rawStep) {
            if (!is_array($rawStep)) {
                continue;
            }
            $id = $this->identifier((string) ($rawStep['id'] ?? ''), 'step_' . ($index + 1));
            if (isset($seenSteps[$id])) {
                $id .= '_' . ($index + 1);
            }
            $seenSteps[$id] = true;
            $steps[] = [
                'id' => $id,
                'title' => $this->text((string) ($rawStep['title'] ?? 'Step ' . ($index + 1)), 100),
                'description' => $this->text((string) ($rawStep['description'] ?? ''), 300),
            ];
        }
        if ($steps === []) {
            $steps[] = ['id' => 'step_1', 'title' => 'Form', 'description' => ''];
            $seenSteps['step_1'] = true;
        }

        $registry = $this->fieldRegistry();
        $fields = [];
        $seenIds = [];
        $seenNames = [];
        foreach (array_slice(is_array($document['fields'] ?? null) ? $document['fields'] : [], 0, self::MAX_FIELDS) as $index => $rawField) {
            if (!is_array($rawField)) {
                continue;
            }
            $type = strtolower(trim((string) ($rawField['type'] ?? 'text')));
            if (!isset($registry[$type]) || $type === 'page_break') {
                throw new InvalidArgumentException('Unknown form field type: ' . ($type !== '' ? $type : 'missing type') . '.');
            }
            $id = $this->identifier((string) ($rawField['id'] ?? ''), $type . '_' . ($index + 1));
            if (isset($seenIds[$id])) {
                $id .= '_' . ($index + 1);
            }
            $seenIds[$id] = true;
            $collectsValue = !empty($registry[$type]['collects_value']);
            $name = $collectsValue ? $this->identifier((string) ($rawField['name'] ?? ''), 'field_' . ($index + 1)) : '';
            if ($name !== '' && isset($seenNames[$name])) {
                $name .= '_' . ($index + 1);
            }
            if ($name !== '') {
                $seenNames[$name] = true;
            }
            $stepId = $this->identifier((string) ($rawField['step_id'] ?? ''), $steps[0]['id']);
            if (!isset($seenSteps[$stepId])) {
                $stepId = $steps[0]['id'];
            }
            $options = array_values(array_unique(array_filter(array_map(
                fn($value): string => $this->text((string) $value, 120),
                is_array($rawField['options'] ?? null) ? $rawField['options'] : preg_split('/\r?\n/', (string) ($rawField['options'] ?? ''))
            ), static fn(string $value): bool => $value !== '')));
            $logic = is_array($rawField['logic'] ?? null) ? $rawField['logic'] : [];
            $conditions = [];
            foreach (array_slice(is_array($logic['conditions'] ?? null) ? $logic['conditions'] : [], 0, 5) as $condition) {
                if (!is_array($condition)) {
                    continue;
                }
                $conditions[] = [
                    'field_id' => $this->identifier((string) ($condition['field_id'] ?? ''), ''),
                    'operator' => $this->allowed((string) ($condition['operator'] ?? 'equals'), ['equals', 'not_equals', 'contains', 'not_empty', 'empty', 'greater_than', 'less_than'], 'equals'),
                    'value' => $this->text((string) ($condition['value'] ?? ''), 300),
                ];
            }
            $fields[] = [
                'id' => $id,
                'type' => $type,
                'step_id' => $stepId,
                'name' => $name,
                'label' => $this->text((string) ($rawField['label'] ?? $registry[$type]['label']), 180),
                'placeholder' => $this->text((string) ($rawField['placeholder'] ?? ''), 250),
                'help' => $this->text((string) ($rawField['help'] ?? ''), 500),
                'required' => $collectsValue && !empty($rawField['required']),
                'options' => array_slice($options, 0, 50),
                'default_value' => $this->text((string) ($rawField['default_value'] ?? ''), 1000),
                'mapping' => $this->allowed((string) ($rawField['mapping'] ?? ''), ['', 'first_name', 'last_name', 'email', 'phone', 'company', 'job_title', 'lead_source', 'notes'], ''),
                'width' => $this->allowed((string) ($rawField['width'] ?? 'full'), ['full', 'half'], 'full'),
                'validation' => $this->normalizeValidation(is_array($rawField['validation'] ?? null) ? $rawField['validation'] : []),
                'logic' => [
                    'enabled' => !empty($logic['enabled']) && $conditions !== [],
                    'action' => $this->allowed((string) ($logic['action'] ?? 'show'), ['show', 'hide'], 'show'),
                    'match' => $this->allowed((string) ($logic['match'] ?? 'all'), ['all', 'any'], 'all'),
                    'conditions' => $conditions,
                ],
                'accept' => $type === 'file' ? $this->text((string) ($rawField['accept'] ?? 'image/*,.pdf'), 200) : '',
                'max_size' => $type === 'file' ? min(20, max(1, (int) ($rawField['max_size'] ?? 5))) : 0,
            ];
        }

        $validIds = array_fill_keys(array_column($fields, 'id'), true);
        foreach ($fields as &$field) {
            $field['logic']['conditions'] = array_values(array_filter(
                $field['logic']['conditions'],
                static fn(array $condition): bool => isset($validIds[$condition['field_id']]) && $condition['field_id'] !== $field['id']
            ));
            if ($field['logic']['conditions'] === []) {
                $field['logic']['enabled'] = false;
            }
        }
        unset($field);

        $content = is_array($document['content'] ?? null) ? $document['content'] : [];
        $theme = is_array($document['theme'] ?? null) ? $document['theme'] : [];
        $metadata = is_array($document['metadata'] ?? null) ? $document['metadata'] : [];

        return [
            'schema' => self::SCHEMA_VERSION,
            'theme' => $this->normalizeTheme($theme),
            'content' => [
                'title' => $this->text((string) ($content['title'] ?? $form['name'] ?? 'Untitled form'), 255),
                'description' => $this->text((string) ($content['description'] ?? ''), 1000),
                'submit_label' => $this->text((string) ($content['submit_label'] ?? 'Submit'), 80) ?: 'Submit',
                'success_message' => $this->text((string) ($content['success_message'] ?? $form['success_message'] ?? 'Thank you! Your submission has been received.'), 1200),
                'redirect_url' => Security::sanitizeRedirectUrl((string) ($content['redirect_url'] ?? $form['redirect_url'] ?? ''), ''),
                'gdpr_enabled' => !empty($content['gdpr_enabled']),
                'gdpr_label' => $this->text((string) ($content['gdpr_label'] ?? 'I agree to the privacy policy'), 500),
            ],
            'steps' => $steps,
            'fields' => $fields,
            'metadata' => [
                'template_key' => $this->identifier((string) ($metadata['template_key'] ?? ''), ''),
                'template_version' => $this->text((string) ($metadata['template_version'] ?? ''), 32),
            ],
        ];
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $form */
    public function validateDocument(array $document, array $form = []): array
    {
        try {
            $document = $this->normalizeDocument($document, $form);
        } catch (\Throwable $e) {
            return ['valid' => false, 'score' => 0, 'errors' => [$e->getMessage()], 'warnings' => [], 'schema' => self::SCHEMA_VERSION];
        }
        $errors = [];
        $warnings = [];
        if (trim((string) $document['content']['title']) === '') {
            $errors[] = 'Add a form title.';
        }
        $inputFields = array_values(array_filter($document['fields'], fn(array $field): bool => !empty($this->fieldRegistry()[$field['type']]['collects_value'])));
        if ($inputFields === []) {
            $errors[] = 'Add at least one input field.';
        }
        $names = [];
        $hasContactPath = false;
        foreach ($inputFields as $field) {
            if ($field['name'] === '') {
                $errors[] = $field['label'] . ' needs an internal name.';
            } elseif (isset($names[$field['name']])) {
                $errors[] = 'Internal field names must be unique.';
            }
            $names[$field['name']] = true;
            if (in_array($field['type'], ['select', 'radio'], true) && $field['options'] === []) {
                $errors[] = $field['label'] . ' needs at least one option.';
            }
            if (in_array($field['type'], ['email', 'phone'], true) || in_array($field['mapping'], ['email', 'phone'], true)) {
                $hasContactPath = true;
            }
        }
        foreach ($document['steps'] as $step) {
            $stepFields = array_filter($document['fields'], static fn(array $field): bool => $field['step_id'] === $step['id']);
            if ($stepFields === []) {
                $warnings[] = $step['title'] . ' has no fields.';
            }
        }
        if (!$hasContactPath) {
            $warnings[] = 'Add an email or phone field so the CRM can follow up.';
        }
        if (count($inputFields) > 10 && count($document['steps']) === 1) {
            $warnings[] = 'Consider multiple steps for this longer form.';
        }
        $score = max(0, 100 - count($errors) * 25 - count($warnings) * 5);
        return ['valid' => $errors === [], 'score' => $score, 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)), 'schema' => self::SCHEMA_VERSION];
    }

    /** @param array<string,mixed> $document @return array<int,array<string,mixed>> */
    public function legacyFields(array $document): array
    {
        $document = $this->normalizeDocument($document);
        $out = [];
        foreach ($document['fields'] as $field) {
            if (empty($this->fieldRegistry()[$field['type']]['collects_value'])) {
                continue;
            }
            $type = match ($field['type']) {
                'radio', 'rating', 'nps' => 'select',
                'time', 'hidden' => 'text',
                default => $field['type'],
            };
            $out[] = [
                'id' => $field['id'],
                'label' => $field['label'],
                'name' => $field['name'],
                'type' => $type,
                'required' => $field['required'],
                'placeholder' => $field['placeholder'],
                'options' => $field['options'],
                'accept' => $field['accept'],
                'max_size' => $field['max_size'],
                'mapping' => $field['mapping'],
                'step_id' => $field['step_id'],
                'logic' => $field['logic'],
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    public function legacySettings(array $document): array
    {
        $document = $this->normalizeDocument($document);
        return [
            'primary_color' => $document['theme']['primary_color'],
            'background_color' => $document['theme']['page_color'],
            'button_color' => $document['theme']['button_color'],
            'text_color' => $document['theme']['text_color'],
            'layout' => $document['theme']['density'],
            'border_radius' => $document['theme']['radius'],
            'heading_font' => $document['theme']['heading_font'],
            'body_font' => $document['theme']['body_font'],
            'type_scale' => $document['theme']['type_scale'],
            'logo_path' => $document['theme']['logo_path'] ?: null,
            'header_image_path' => $document['theme']['header_image_path'] ?: null,
            'gdpr_enabled' => $document['content']['gdpr_enabled'],
            'gdpr_label' => $document['content']['gdpr_label'],
        ];
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $context */
    public function render(array $document, array $context = []): string
    {
        $document = $this->normalizeDocument($document, (array) ($context['form'] ?? []));
        $theme = $document['theme'];
        $mode = (string) ($context['mode'] ?? 'public');
        $activeStep = (string) ($context['active_step'] ?? $document['steps'][0]['id']);
        $values = is_array($context['values'] ?? null) ? $context['values'] : [];
        $visibility = $this->visibilityMap($document, $values);
        $typeScale = DesignTypographyCatalog::webScale((string) $theme['type_scale']);
        $style = sprintf(
            '--form-primary:%s;--form-button:%s;--form-page:%s;--form-surface:%s;--form-text:%s;--form-muted:%s;--form-radius:%dpx;--form-gap:%s;--form-heading-font:%s;--form-body-font:%s;--form-heading-scale:%s;--form-body-scale:%s',
            $this->e($theme['primary_color']), $this->e($theme['button_color']), $this->e($theme['page_color']),
            $this->e($theme['surface_color']), $this->e($theme['text_color']), $this->e($theme['muted_color']),
            (int) $theme['radius'], $theme['density'] === 'compact' ? '.78rem' : '1rem',
            $this->e(DesignTypographyCatalog::webFontCss((string) $theme['heading_font'])),
            $this->e(DesignTypographyCatalog::webFontCss((string) $theme['body_font'])),
            (string) $typeScale['heading'], (string) $typeScale['body']
        );
        $html = '<div class="form-runtime" data-form-schema="' . self::SCHEMA_VERSION . '" data-form-mode="' . $this->e($mode) . '" data-form-active-step="' . $this->e($activeStep) . '" style="' . $style . '">';
        $html .= '<div class="form-runtime__card">';
        if (!empty($context['header_url'])) {
            $html .= '<img class="form-runtime__header" src="' . $this->e((string) $context['header_url']) . '" alt="">';
        }
        $html .= '<div class="form-runtime__inner">';
        if (!empty($context['logo_url'])) {
            $html .= '<img class="form-runtime__logo" src="' . $this->e((string) $context['logo_url']) . '" alt="">';
        }
        $html .= '<header class="form-runtime__intro"><h1>' . $this->e($document['content']['title']) . '</h1>';
        if ($document['content']['description'] !== '') {
            $html .= '<p>' . nl2br($this->e($document['content']['description'])) . '</p>';
        }
        $html .= '</header>';
        if (count($document['steps']) > 1) {
            $html .= '<ol class="form-runtime__steps" aria-label="Form progress">';
            foreach ($document['steps'] as $index => $step) {
                $state = $step['id'] === $activeStep ? ' is-active' : '';
                $html .= '<li class="' . trim($state) . '" data-step-indicator="' . $this->e($step['id']) . '"><span>' . ($index + 1) . '</span><strong>' . $this->e($step['title']) . '</strong></li>';
            }
            $html .= '</ol>';
        }
        $html .= '<form class="form-runtime__form" method="post" action="' . $this->e((string) ($context['form_action'] ?? '')) . '"' . (!empty($context['has_file']) ? ' enctype="multipart/form-data"' : '') . '>';
        if (!empty($context['visitor_id'])) {
            $html .= '<input type="hidden" name="visitor_id" value="' . $this->e((string) $context['visitor_id']) . '">';
        }
        if (!empty($context['form_uuid'])) {
            $html .= '<input type="hidden" name="form_uuid" value="' . $this->e((string) $context['form_uuid']) . '">';
        }
        $html .= '<input type="hidden" name="form_active_step" value="' . $this->e($activeStep) . '" data-form-active-step-input>';
        if (!empty($context['error'])) {
            $html .= '<div class="form-runtime__alert form-runtime__alert--error" role="alert">' . $this->e((string) $context['error']) . '</div>';
        }
        foreach ($document['steps'] as $stepIndex => $step) {
            $hidden = $mode === 'public' && $step['id'] !== $activeStep ? ' hidden' : '';
            $html .= '<section class="form-runtime__step" data-form-step="' . $this->e($step['id']) . '"' . $hidden . '>';
            if ($step['description'] !== '') {
                $html .= '<p class="form-runtime__step-description">' . $this->e($step['description']) . '</p>';
            }
            $html .= '<div class="form-runtime__grid">';
            foreach ($document['fields'] as $field) {
                if ($field['step_id'] !== $step['id']) {
                    continue;
                }
                $html .= $this->renderField($field, $values, $mode, $visibility[$field['id']] ?? true);
            }
            $html .= '</div>';
            $isLast = $stepIndex === count($document['steps']) - 1;
            if ($isLast && $document['content']['gdpr_enabled']) {
                $html .= '<label class="form-runtime__consent"><input type="checkbox" name="gdpr_consent" value="1" required><span>' . $this->e($document['content']['gdpr_label']) . ' <b>*</b></span></label>';
            }
            $html .= '<div class="form-runtime__actions">';
            if ($stepIndex > 0) {
                $html .= '<button type="button" class="form-runtime__secondary" data-form-previous>Previous</button>';
            }
            if ($isLast) {
                $html .= '<button type="submit" class="form-runtime__primary">' . $this->e($document['content']['submit_label']) . '</button>';
            } else {
                $html .= '<button type="button" class="form-runtime__primary" data-form-next>Next <span aria-hidden="true">→</span></button>';
            }
            $html .= '</div></section>';
        }
        $html .= '</form></div></div></div>';
        return $html;
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $values @return array<string,bool> */
    public function visibilityMap(array $document, array $values): array
    {
        $document = $this->normalizeDocument($document);
        $byId = [];
        foreach ($document['fields'] as $field) {
            $byId[$field['id']] = $field;
        }
        $out = [];
        foreach ($document['fields'] as $field) {
            $logic = $field['logic'];
            if (empty($logic['enabled']) || empty($logic['conditions'])) {
                $out[$field['id']] = true;
                continue;
            }
            $results = [];
            foreach ($logic['conditions'] as $condition) {
                $source = $byId[$condition['field_id']] ?? null;
                $value = $source ? ($values[$source['name']] ?? '') : '';
                $results[] = $this->conditionMatches($value, $condition['operator'], $condition['value']);
            }
            $matched = $logic['match'] === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);
            $out[$field['id']] = $logic['action'] === 'hide' ? !$matched : $matched;
        }
        return $out;
    }

    /** @param array<string,mixed> $form */
    public function legacyDocument(array $form): array
    {
        $settings = is_array($form['settings'] ?? null) ? $form['settings'] : [];
        $fields = [];
        foreach (is_array($form['fields'] ?? null) ? $form['fields'] : [] as $index => $legacy) {
            if (!is_array($legacy)) {
                continue;
            }
            $type = (string) ($legacy['type'] ?? 'text');
            if (!isset($this->fieldRegistry()[$type]) || $type === 'page_break') {
                $type = 'text';
            }
            $fields[] = [
                'id' => $this->identifier((string) ($legacy['id'] ?? ''), $type . '_' . ($index + 1)),
                'type' => $type,
                'step_id' => 'step_1',
                'name' => (string) ($legacy['name'] ?? 'field_' . ($index + 1)),
                'label' => (string) ($legacy['label'] ?? ucfirst((string) ($legacy['name'] ?? 'Field'))),
                'placeholder' => (string) ($legacy['placeholder'] ?? ''),
                'help' => '',
                'required' => !empty($legacy['required']),
                'options' => is_array($legacy['options'] ?? null) ? $legacy['options'] : [],
                'default_value' => '',
                'mapping' => (string) ($legacy['mapping'] ?? ''),
                'width' => 'full',
                'validation' => [],
                'logic' => is_array($legacy['logic'] ?? null) ? $legacy['logic'] : [],
                'accept' => (string) ($legacy['accept'] ?? 'image/*,.pdf'),
                'max_size' => (int) ($legacy['max_size'] ?? 5),
            ];
        }
        return [
            'schema' => self::SCHEMA_VERSION,
            'theme' => [
                'primary_color' => (string) ($settings['primary_color'] ?? '#0f67ea'),
                'button_color' => (string) ($settings['button_color'] ?? '#0f67ea'),
                'page_color' => (string) ($settings['background_color'] ?? '#f4f7fb'),
                'surface_color' => '#ffffff',
                'text_color' => (string) ($settings['text_color'] ?? '#17243a'),
                'muted_color' => '#65758b',
                'radius' => (int) ($settings['border_radius'] ?? 12),
                'density' => (string) ($settings['layout'] ?? 'spacious'),
                'heading_font' => (string) ($settings['heading_font'] ?? 'system'),
                'body_font' => (string) ($settings['body_font'] ?? 'system'),
                'type_scale' => (string) ($settings['type_scale'] ?? 'balanced'),
                'logo_path' => (string) ($settings['logo_path'] ?? ''),
                'header_image_path' => (string) ($settings['header_image_path'] ?? ''),
            ],
            'content' => [
                'title' => (string) ($form['name'] ?? 'Untitled form'),
                'description' => '',
                'submit_label' => 'Submit',
                'success_message' => (string) ($form['success_message'] ?? 'Thank you! Your submission has been received.'),
                'redirect_url' => (string) ($form['redirect_url'] ?? ''),
                'gdpr_enabled' => !empty($settings['gdpr_enabled']),
                'gdpr_label' => (string) ($settings['gdpr_label'] ?? 'I agree to the privacy policy'),
            ],
            'steps' => [['id' => 'step_1', 'title' => 'Form', 'description' => '']],
            'fields' => $fields,
            'metadata' => ['template_key' => 'legacy_migration', 'template_version' => '1.0.0'],
        ];
    }

    /** @param array<string,mixed> $field @param array<string,mixed> $values */
    private function renderField(array $field, array $values, string $mode, bool $visible = true): string
    {
        $type = $field['type'];
        $value = (string) ($values[$field['name']] ?? $field['default_value']);
        $classes = 'form-runtime__field form-runtime__field--' . $this->e($type) . ($field['width'] === 'half' ? ' is-half' : '');
        $logic = $this->e(json_encode($field['logic'], JSON_UNESCAPED_SLASHES) ?: '{}');
        $html = '<div class="' . $classes . '" data-field-id="' . $this->e($field['id']) . '" data-field-type="' . $this->e($type) . '" data-field-logic="' . $logic . '"' . ($mode === 'public' && !$visible ? ' hidden' : '') . '>';
        if ($type === 'heading') {
            return $html . '<h2>' . $this->e($field['label']) . '</h2></div>';
        }
        if ($type === 'paragraph') {
            return $html . '<p>' . nl2br($this->e($field['label'])) . '</p></div>';
        }
        if ($type === 'divider') {
            return $html . '<hr></div>';
        }
        if ($type === 'hidden') {
            return $html . '<input type="hidden" name="' . $this->e($field['name']) . '" value="' . $this->e($value) . '"></div>';
        }
        $required = $field['required'] ? ' required' : '';
        $disabled = $mode === 'editor' ? ' disabled' : '';
        $id = 'field_' . $field['id'];
        if ($type === 'checkbox') {
            $html .= '<label class="form-runtime__check"><input type="checkbox" id="' . $this->e($id) . '" name="' . $this->e($field['name']) . '" value="1"' . $required . $disabled . '><span>' . $this->e($field['label']) . ($field['required'] ? ' <b>*</b>' : '') . '</span></label>';
        } else {
            $html .= '<label for="' . $this->e($id) . '">' . $this->e($field['label']) . ($field['required'] ? ' <b>*</b>' : '') . '</label>';
            $placeholder = $field['placeholder'] !== '' ? ' placeholder="' . $this->e($field['placeholder']) . '"' : '';
            if ($type === 'textarea') {
                $html .= '<textarea id="' . $this->e($id) . '" name="' . $this->e($field['name']) . '"' . $placeholder . $required . $disabled . '>' . $this->e($value) . '</textarea>';
            } elseif ($type === 'select') {
                $html .= '<select id="' . $this->e($id) . '" name="' . $this->e($field['name']) . '"' . $required . $disabled . '><option value="">Select an option</option>';
                foreach ($field['options'] as $option) {
                    $html .= '<option value="' . $this->e($option) . '"' . ($option === $value ? ' selected' : '') . '>' . $this->e($option) . '</option>';
                }
                $html .= '</select>';
            } elseif ($type === 'radio') {
                $html .= '<div class="form-runtime__choices">';
                foreach ($field['options'] as $optionIndex => $option) {
                    $html .= '<label><input type="radio" name="' . $this->e($field['name']) . '" value="' . $this->e($option) . '"' . ($option === $value ? ' checked' : '') . $required . $disabled . '><span>' . $this->e($option) . '</span></label>';
                }
                $html .= '</div>';
            } elseif (in_array($type, ['rating', 'nps'], true)) {
                $range = $type === 'rating' ? range(1, 5) : range(0, 10);
                $html .= '<div class="form-runtime__scale">';
                foreach ($range as $score) {
                    $html .= '<label><input type="radio" name="' . $this->e($field['name']) . '" value="' . $score . '"' . ((string) $score === $value ? ' checked' : '') . $required . $disabled . '><span>' . $score . '</span></label>';
                }
                $html .= '</div>';
            } elseif ($type === 'file') {
                $html .= '<input type="file" id="' . $this->e($id) . '" name="' . $this->e($field['name']) . '" accept="' . $this->e($field['accept']) . '"' . $required . $disabled . '><small>Maximum ' . (int) $field['max_size'] . ' MB</small>';
            } else {
                $inputType = match ($type) { 'phone' => 'tel', default => $type };
                $validation = $field['validation'];
                $attrs = '';
                foreach (['min_length' => 'minlength', 'max_length' => 'maxlength', 'min_value' => 'min', 'max_value' => 'max'] as $key => $attribute) {
                    if ($validation[$key] !== null) {
                        $attrs .= ' ' . $attribute . '="' . $this->e((string) $validation[$key]) . '"';
                    }
                }
                $html .= '<input type="' . $this->e($inputType) . '" id="' . $this->e($id) . '" name="' . $this->e($field['name']) . '" value="' . $this->e($value) . '"' . $placeholder . $attrs . $required . $disabled . '>';
            }
        }
        if ($field['help'] !== '') {
            $html .= '<small class="form-runtime__help">' . $this->e($field['help']) . '</small>';
        }
        return $html . '</div>';
    }

    /** @param array<string,mixed> $theme @return array<string,mixed> */
    private function normalizeTheme(array $theme): array
    {
        return [
            'primary_color' => $this->color((string) ($theme['primary_color'] ?? '#0f67ea'), '#0f67ea'),
            'button_color' => $this->color((string) ($theme['button_color'] ?? '#0f67ea'), '#0f67ea'),
            'page_color' => $this->color((string) ($theme['page_color'] ?? '#f4f7fb'), '#f4f7fb'),
            'surface_color' => $this->color((string) ($theme['surface_color'] ?? '#ffffff'), '#ffffff'),
            'text_color' => $this->color((string) ($theme['text_color'] ?? '#17243a'), '#17243a'),
            'muted_color' => $this->color((string) ($theme['muted_color'] ?? '#65758b'), '#65758b'),
            'radius' => min(32, max(0, (int) ($theme['radius'] ?? 12))),
            'density' => $this->allowed((string) ($theme['density'] ?? 'spacious'), ['compact', 'spacious'], 'spacious'),
            'heading_font' => DesignTypographyCatalog::normalizeWebFont((string) ($theme['heading_font'] ?? 'system')),
            'body_font' => DesignTypographyCatalog::normalizeWebFont((string) ($theme['body_font'] ?? 'system')),
            'type_scale' => DesignTypographyCatalog::normalizeWebScale((string) ($theme['type_scale'] ?? 'balanced')),
            'logo_path' => $this->safePath((string) ($theme['logo_path'] ?? '')),
            'header_image_path' => $this->safePath((string) ($theme['header_image_path'] ?? '')),
        ];
    }

    /** @param array<string,mixed> $validation @return array<string,int|float|null> */
    private function normalizeValidation(array $validation): array
    {
        $integer = static fn(mixed $value, int $min, int $max): ?int => $value === '' || $value === null ? null : min($max, max($min, (int) $value));
        $number = static fn(mixed $value): ?float => $value === '' || $value === null || !is_numeric($value) ? null : (float) $value;
        return [
            'min_length' => $integer($validation['min_length'] ?? null, 0, 5000),
            'max_length' => $integer($validation['max_length'] ?? null, 1, 12000),
            'min_value' => $number($validation['min_value'] ?? null),
            'max_value' => $number($validation['max_value'] ?? null),
        ];
    }

    private function conditionMatches(mixed $raw, string $operator, string $expected): bool
    {
        $value = is_array($raw) ? implode(',', $raw) : trim((string) $raw);
        return match ($operator) {
            'not_equals' => $value !== $expected,
            'contains' => $expected !== '' && stripos($value, $expected) !== false,
            'not_empty' => $value !== '',
            'empty' => $value === '',
            'greater_than' => is_numeric($value) && is_numeric($expected) && (float) $value > (float) $expected,
            'less_than' => is_numeric($value) && is_numeric($expected) && (float) $value < (float) $expected,
            default => $value === $expected,
        };
    }

    /** @return array<string,mixed> */
    private function definition(string $label, string $category, string $icon, bool $collectsValue, bool $hasOptions = false): array
    {
        return ['label' => $label, 'category' => $category, 'icon' => $icon, 'collects_value' => $collectsValue, 'has_options' => $hasOptions];
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function field(string $type, string $label, string $name, bool $required = false, array $extra = []): array
    {
        return array_merge([
            'id' => $type . '_' . substr(sha1($type . '|' . $name . '|' . $label), 0, 10),
            'type' => $type,
            'step_id' => 'step_1',
            'name' => $name,
            'label' => $label,
            'placeholder' => '',
            'help' => '',
            'required' => $required,
            'options' => [],
            'default_value' => '',
            'mapping' => '',
            'width' => 'full',
            'validation' => [],
            'logic' => [],
            'accept' => 'image/*,.pdf',
            'max_size' => 5,
        ], $extra);
    }

    /** @param array<int,array<string,mixed>> $fields @param array<int,array<string,mixed>>|null $steps @return array<string,mixed> */
    private function template(string $key, string $name, string $description, string $useCase, array $fields, ?array $steps = null): array
    {
        $steps ??= [['id' => 'step_1', 'title' => 'Form', 'description' => '']];
        return [
            'key' => $key,
            'name' => $name,
            'description' => $description,
            'use_case' => $useCase,
            'version' => '1.0.0',
            'document' => [
                'schema' => self::SCHEMA_VERSION,
                'theme' => $this->normalizeTheme([]),
                'content' => ['title' => $name, 'description' => '', 'submit_label' => 'Submit', 'success_message' => 'Thank you! Your submission has been received.', 'redirect_url' => '', 'gdpr_enabled' => false, 'gdpr_label' => 'I agree to the privacy policy'],
                'steps' => $steps,
                'fields' => $fields,
                'metadata' => ['template_key' => $key, 'template_version' => '1.0.0'],
            ],
        ];
    }

    private function text(string $value, int $max): string
    {
        $value = trim(strip_tags($value));
        return mb_substr($value, 0, $max);
    }

    private function identifier(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?: '';
        $value = trim($value, '_');
        return substr($value !== '' ? $value : $fallback, 0, 120);
    }

    /** @param array<int,string> $allowed */
    private function allowed(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function color(string $value, string $fallback): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', trim($value)) === 1 ? strtolower(trim($value)) : $fallback;
    }

    private function safePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        return $path === '' || str_contains($path, '..') || preg_match('#^[a-z]+:#i', $path) === 1 ? '' : substr($path, 0, 500);
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
