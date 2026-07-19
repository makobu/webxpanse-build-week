<?php

namespace CRM\Services;

use InvalidArgumentException;

/**
 * Safe, versioned document contract and renderer for CRM landing pages.
 *
 * The registry is deliberately code-owned. A stored document may select blocks
 * and properties, but it cannot introduce executable markup or new behavior.
 */
final class LandingPageDesignService
{
    public const SCHEMA_VERSION = 'crm.design/v1';
    public const MAX_BLOCKS = 60;
    private const MAX_TEXT = 12000;

    /** @return array<string,array<string,mixed>> */
    public function blockRegistry(): array
    {
        return [
            'hero' => $this->definition('Hero', 'Layout', 'fa-wand-magic-sparkles', [
                $this->field('eyebrow', 'Eyebrow', 'text', 'A smarter way to grow'),
                $this->field('heading', 'Heading', 'textarea', 'Turn more interest into customers', true),
                $this->field('body', 'Supporting copy', 'textarea', 'Connect every campaign to the CRM actions that move revenue forward.'),
                $this->field('primary_label', 'Primary button', 'text', 'Get started'),
                $this->field('primary_href', 'Primary destination', 'url', '#contact'),
                $this->field('secondary_label', 'Secondary button', 'text', 'See how it works'),
                $this->field('secondary_href', 'Secondary destination', 'url', '#benefits'),
                $this->field('media_file_id', 'Hero media', 'media', 0),
                $this->field('image_alt', 'Image description', 'text', ''),
            ]),
            'text_image' => $this->definition('Text & Image', 'Layout', 'fa-image', [
                $this->field('eyebrow', 'Eyebrow', 'text', 'Built for your workflow'),
                $this->field('heading', 'Heading', 'textarea', 'Keep the next step clear'),
                $this->field('body', 'Body', 'textarea', 'Explain the value in plain language and support it with an approved visual.'),
                $this->field('media_file_id', 'Media', 'media', 0),
                $this->field('image_alt', 'Image description', 'text', ''),
                $this->field('image_side', 'Image position', 'select', 'right', false, ['left', 'right']),
            ]),
            'benefits' => $this->definition('Benefits', 'Content', 'fa-circle-check', [
                $this->field('heading', 'Heading', 'text', 'Why teams choose us'),
                $this->field('body', 'Introduction', 'textarea', 'A focused set of reasons to take the next step.'),
                $this->field('items', 'Benefits (one per line)', 'lines', "Launch faster\nCapture every enquiry\nMeasure what converts"),
            ]),
            'logo_strip' => $this->definition('Logo strip', 'Trust', 'fa-building', [
                $this->field('heading', 'Heading', 'text', 'Trusted by ambitious teams'),
                $this->field('items', 'Company names (one per line)', 'lines', "Northstar\nAcme\nLumon\nPioneer"),
            ]),
            'testimonials' => $this->definition('Testimonials', 'Trust', 'fa-quote-left', [
                $this->field('heading', 'Heading', 'text', 'What customers say'),
                $this->field('quote', 'Quote', 'textarea', 'We finally have one clear path from campaign to follow-up.'),
                $this->field('name', 'Customer name', 'text', 'Amina K.'),
                $this->field('role', 'Role or company', 'text', 'Growth lead'),
                $this->field('media_file_id', 'Customer media', 'media', 0),
                $this->field('image_alt', 'Image description', 'text', ''),
            ]),
            'pricing' => $this->definition('Pricing', 'Conversion', 'fa-tags', [
                $this->field('heading', 'Heading', 'text', 'Simple pricing'),
                $this->field('body', 'Introduction', 'textarea', 'Choose a focused starting point and expand as you grow.'),
                $this->field('plan_name', 'Plan name', 'text', 'Growth'),
                $this->field('price', 'Price', 'text', '$49'),
                $this->field('period', 'Period', 'text', 'per month'),
                $this->field('features', 'Features (one per line)', 'lines', "Unlimited landing pages\nCRM forms\nConversion tracking"),
                $this->field('button_label', 'Button label', 'text', 'Choose Growth'),
                $this->field('button_href', 'Destination', 'url', '#contact'),
            ]),
            'faq' => $this->definition('FAQ', 'Trust', 'fa-circle-question', [
                $this->field('heading', 'Heading', 'text', 'Frequently asked questions'),
                $this->field('items', 'Questions (Question | Answer)', 'pairs', "How quickly can we launch? | Most teams publish their first page in one session.\nCan we use our CRM form? | Yes. Select an existing workspace form in the page settings."),
            ]),
            'gallery' => $this->definition('Gallery', 'Media', 'fa-images', [
                $this->field('heading', 'Heading', 'text', 'See it in action'),
                $this->field('media_ids', 'Media', 'media_multi', ''),
                $this->field('image_alt', 'Gallery description', 'text', 'Product and customer highlights'),
            ]),
            'crm_form' => $this->definition('CRM Form', 'CRM', 'fa-rectangle-list', [
                $this->field('eyebrow', 'Eyebrow', 'text', 'Start a conversation'),
                $this->field('heading', 'Heading', 'text', 'Tell us what you need'),
                $this->field('body', 'Supporting copy', 'textarea', 'Your submission goes directly into the connected CRM workflow.'),
                $this->field('form_id', 'CRM form', 'form', 0, true),
                $this->field('height', 'Embed height', 'number', 620),
            ]),
            'booking' => $this->definition('Booking', 'CRM', 'fa-calendar-check', [
                $this->field('eyebrow', 'Eyebrow', 'text', 'Choose a time'),
                $this->field('heading', 'Heading', 'text', 'Book a conversation'),
                $this->field('body', 'Supporting copy', 'textarea', 'Open the live CRM booking flow and choose an available time.'),
                $this->field('booking_url', 'CRM booking URL', 'booking_url', 'meeting_schedule.php?profile=default', true),
                $this->field('button_label', 'Button label', 'text', 'Book a time'),
                $this->field('display', 'Display', 'select', 'button', false, ['button', 'embed']),
                $this->field('height', 'Embed height', 'number', 760),
            ]),
            'whatsapp' => $this->definition('WhatsApp', 'CRM', 'fa-brands fa-whatsapp', [
                $this->field('eyebrow', 'Eyebrow', 'text', 'Prefer WhatsApp?'),
                $this->field('heading', 'Heading', 'text', 'Message the team'),
                $this->field('body', 'Supporting copy', 'textarea', 'Start a direct conversation and we will take it from there.'),
                $this->field('phone', 'WhatsApp number', 'phone', '', true),
                $this->field('message', 'Prefilled message', 'textarea', 'Hello, I would like to learn more.'),
                $this->field('button_label', 'Button label', 'text', 'Chat on WhatsApp'),
            ]),
            'cta' => $this->definition('Call to action', 'Conversion', 'fa-arrow-right', [
                $this->field('heading', 'Heading', 'text', 'Ready to move forward?'),
                $this->field('body', 'Supporting copy', 'textarea', 'Choose the next step and keep the momentum going.'),
                $this->field('button_label', 'Button label', 'text', 'Get started'),
                $this->field('button_href', 'Destination', 'url', '#contact', true),
            ]),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function editorManifest(): array
    {
        $manifest = [];
        foreach ($this->blockRegistry() as $key => $definition) {
            $manifest[] = [
                'type' => $key,
                'label' => $definition['label'],
                'category' => $definition['category'],
                'icon' => $definition['icon'],
                'fields' => $definition['fields'],
                'default_props' => $this->defaultProps($definition),
            ];
        }
        return $manifest;
    }

    /** @return array<int,array<string,mixed>> */
    public function templates(): array
    {
        return [
            $this->template('lead_capture', 'Lead capture', 'Collect qualified enquiries with proof, FAQ, and a connected CRM form.', 'lead_generation', 'general', [
                $this->block('hero', ['eyebrow' => 'A clearer way to grow', 'heading' => 'Turn campaign interest into qualified conversations', 'body' => 'Give every visitor one confident path from first impression to CRM follow-up.', 'primary_label' => 'Start a conversation', 'primary_href' => '#contact']),
                $this->block('benefits', ['heading' => 'A conversion path your team can operate', 'items' => "Focused message\nTrusted proof\nCRM-owned follow-up"]),
                $this->block('testimonials', []),
                $this->block('faq', []),
                $this->block('crm_form', ['heading' => 'Tell us what you are working on']),
            ]),
            $this->template('demo_booking', 'Demo booking', 'Move high-intent visitors into the live CRM booking flow.', 'booking', 'services', [
                $this->block('hero', ['eyebrow' => 'See the workflow live', 'heading' => 'Book a focused product walkthrough', 'body' => 'Choose a time that works and bring the workflow you want to improve.', 'primary_label' => 'Choose a time', 'primary_href' => '#booking']),
                $this->block('benefits', ['heading' => 'What the session covers', 'items' => "Your current workflow\nA practical CRM setup\nClear next steps"]),
                $this->block('testimonials', ['quote' => 'The session focused on our real process, not a generic product tour.']),
                $this->block('booking', []),
                $this->block('faq', ['items' => "How long is the session? | Most walkthroughs take 30 minutes.\nDo I need to prepare? | Bring one workflow or campaign you want to improve."]),
            ]),
            $this->template('product_launch', 'Product launch', 'Present value, pricing, proof, and a direct conversion action.', 'product_launch', 'ecommerce', [
                $this->block('hero', ['eyebrow' => 'Now available', 'heading' => 'Launch with a page built to convert', 'body' => 'Show the outcome, answer objections, and connect every click to the next CRM action.']),
                $this->block('text_image', ['heading' => 'Designed around the customer decision', 'body' => 'Balance product storytelling with a clear, measurable next step.']),
                $this->block('gallery', []),
                $this->block('pricing', []),
                $this->block('faq', []),
                $this->block('cta', ['heading' => 'Start your launch', 'button_label' => 'Get started']),
            ]),
            $this->template('whatsapp_campaign', 'WhatsApp campaign', 'Convert mobile campaign traffic into a prefilled WhatsApp conversation.', 'messaging', 'local_business', [
                $this->block('hero', ['eyebrow' => 'Fast answers, real people', 'heading' => 'Get the information you need on WhatsApp', 'body' => 'Skip the back-and-forth. Start with a prefilled message and continue in the channel you already use.', 'primary_label' => 'Message us', 'primary_href' => '#whatsapp']),
                $this->block('benefits', ['items' => "Quick response\nMobile-first experience\nConversation recorded by your team"]),
                $this->block('testimonials', []),
                $this->block('whatsapp', []),
            ]),
            $this->template('event_registration', 'Event registration', 'Promote an event, establish trust, and send registrations into a connected CRM form.', 'event_registration', 'events', [
                $this->block('hero', ['eyebrow' => 'Reserve your place', 'heading' => 'Join a practical session built around real outcomes', 'body' => 'Show visitors what they will learn, who the session is for, and how to register in one focused page.', 'primary_label' => 'Register now', 'primary_href' => '#contact']),
                $this->block('benefits', ['heading' => 'What you will take away', 'items' => "A clear working framework\nExamples you can reuse\nTime for focused questions"]),
                $this->block('text_image', ['eyebrow' => 'Your host', 'heading' => 'Learn from people doing the work', 'body' => 'Introduce the speaker, facilitator, or team and make the event feel credible before asking for registration.']),
                $this->block('faq', ['items' => "Who is this for? | Teams that want a practical, immediately useful session.\nWill there be a recording? | Add your recording and attendance policy here."]),
                $this->block('crm_form', ['heading' => 'Complete your registration']),
            ]),
            $this->template('service_quote', 'Service and quote', 'Explain a service offer and collect the information needed for a useful quote.', 'quote_request', 'services', [
                $this->block('hero', ['eyebrow' => 'A better starting point', 'heading' => 'Get a quote shaped around what you actually need', 'body' => 'Set expectations, clarify the offer, and capture enough context for a useful first response.', 'primary_label' => 'Request a quote', 'primary_href' => '#contact']),
                $this->block('benefits', ['heading' => 'What working together looks like', 'items' => "Clear scope before work begins\nOne accountable point of contact\nProgress you can measure"]),
                $this->block('testimonials', []),
                $this->block('faq', ['items' => "How soon will I hear back? | Set the response time your team can consistently meet.\nWhat details should I provide? | Share the outcome, timing, and any important constraints."]),
                $this->block('crm_form', ['heading' => 'Tell us about the work']),
            ]),
            $this->template('waitlist_newsletter', 'Waitlist and newsletter', 'Build anticipation and collect segmented signups before a launch.', 'signup', 'general', [
                $this->block('hero', ['eyebrow' => 'Be first to know', 'heading' => 'Join the list for early access and useful updates', 'body' => 'Give people a concrete reason to subscribe and keep the signup path deliberately short.', 'primary_label' => 'Join the list', 'primary_href' => '#contact']),
                $this->block('benefits', ['heading' => 'What subscribers receive', 'items' => "Early access\nPractical launch updates\nNo unnecessary noise"]),
                $this->block('logo_strip', ['heading' => 'Built with teams like yours in mind']),
                $this->block('crm_form', ['heading' => 'Save your place', 'body' => 'Join now and we will send the next useful update directly to you.']),
            ]),
            $this->template('thank_you', 'Thank-you page', 'Confirm the conversion and guide the visitor toward one useful next step.', 'post_conversion', 'general', [
                $this->block('hero', ['eyebrow' => 'You are all set', 'heading' => 'Thank you - we have received your details', 'body' => 'Set a clear expectation for what happens next and keep the momentum with one relevant action.', 'primary_label' => 'Explore what happens next', 'primary_href' => '/', 'secondary_label' => '', 'secondary_href' => '']),
                $this->block('text_image', ['eyebrow' => 'Next step', 'heading' => 'Here is what to expect', 'body' => 'Explain the response time, preparation, or resource that will help the customer move forward.']),
                $this->block('cta', ['heading' => 'Want to keep exploring?', 'body' => 'Offer one relevant resource, booking path, or conversation while intent is still high.', 'button_label' => 'Continue', 'button_href' => '/']),
            ]),
        ];
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $page */
    public function normalizeDocument(array $document, array $page = []): array
    {
        if ($document === [] || empty($document['blocks'])) {
            $document = $this->legacyDocument($page);
        }
        $schema = trim((string) ($document['schema'] ?? self::SCHEMA_VERSION));
        if ($schema !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported design schema version.');
        }
        $blocks = is_array($document['blocks'] ?? null) ? array_values($document['blocks']) : [];
        if (count($blocks) > self::MAX_BLOCKS) {
            throw new InvalidArgumentException('A landing page can contain at most ' . self::MAX_BLOCKS . ' blocks.');
        }
        $registry = $this->blockRegistry();
        $seen = [];
        $normalizedBlocks = [];
        foreach ($blocks as $position => $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('Every design block must be an object.');
            }
            $type = strtolower(trim((string) ($raw['type'] ?? '')));
            if (!isset($registry[$type])) {
                throw new InvalidArgumentException('Unknown design block: ' . ($type !== '' ? $type : 'missing type') . '.');
            }
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($raw['id'] ?? '')) ?: '';
            if ($id === '' || isset($seen[$id])) {
                $id = $this->blockId($type, $position);
            }
            $seen[$id] = true;
            $normalizedBlocks[] = [
                'id' => $id,
                'type' => $type,
                'props' => $this->normalizeProps($registry[$type], is_array($raw['props'] ?? null) ? $raw['props'] : []),
                'style' => $this->normalizeStyle(is_array($raw['style'] ?? null) ? $raw['style'] : []),
                'visibility' => $this->normalizeVisibility(is_array($raw['visibility'] ?? null) ? $raw['visibility'] : []),
            ];
        }
        return [
            'schema' => self::SCHEMA_VERSION,
            'theme' => $this->normalizeTheme(is_array($document['theme'] ?? null) ? $document['theme'] : []),
            'blocks' => $normalizedBlocks,
            'metadata' => [
                'template_key' => $this->cleanText((string) (($document['metadata']['template_key'] ?? '')), 120),
                'template_version' => $this->cleanText((string) (($document['metadata']['template_version'] ?? '')), 32),
            ],
        ];
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $page */
    public function validateDocument(array $document, array $page = []): array
    {
        $errors = [];
        $warnings = [];
        try {
            $document = $this->normalizeDocument($document, $page);
        } catch (\Throwable $e) {
            return ['valid' => false, 'score' => 0, 'errors' => [$e->getMessage()], 'warnings' => [], 'schema' => self::SCHEMA_VERSION];
        }
        $conversionBlocks = 0;
        foreach ($document['blocks'] as $index => $block) {
            $label = ($this->blockRegistry()[$block['type']]['label'] ?? $block['type']) . ' block ' . ($index + 1);
            $props = $block['props'];
            if ($block['type'] === 'hero' && trim((string) $props['heading']) === '') {
                $errors[] = $label . ' needs a heading.';
            }
            if (in_array($block['type'], ['hero', 'text_image', 'testimonials'], true)
                && (int) ($props['media_file_id'] ?? 0) > 0
                && trim((string) ($props['image_alt'] ?? '')) === '') {
                $warnings[] = $label . ' needs an image description.';
            }
            if ($block['type'] === 'gallery' && trim((string) ($props['media_ids'] ?? '')) !== '' && trim((string) ($props['image_alt'] ?? '')) === '') {
                $warnings[] = $label . ' needs a gallery description.';
            }
            if ($block['type'] === 'crm_form') {
                $conversionBlocks++;
                $formId = (int) ($props['form_id'] ?? $page['form_id'] ?? 0);
                if ($formId <= 0) {
                    $errors[] = $label . ' is not connected to a CRM form.';
                } elseif ((int) ($page['form_id'] ?? 0) === $formId && isset($page['form_design_status']) && (string) $page['form_design_status'] !== 'published') {
                    $errors[] = $label . ' is connected to a draft form. Publish the form before publishing this page.';
                }
            }
            if ($block['type'] === 'booking') {
                $conversionBlocks++;
                if ($this->safeBookingUrl((string) ($props['booking_url'] ?? '')) === '') {
                    $errors[] = $label . ' needs a valid CRM booking URL.';
                }
            }
            if ($block['type'] === 'whatsapp') {
                $conversionBlocks++;
                if (strlen(preg_replace('/\D+/', '', (string) ($props['phone'] ?? '')) ?: '') < 8) {
                    $errors[] = $label . ' needs a complete international WhatsApp number.';
                }
            }
            if ($block['type'] === 'cta') {
                $conversionBlocks++;
                if ($this->safeUrl((string) ($props['button_href'] ?? '')) === '') {
                    $errors[] = $label . ' needs a valid destination.';
                }
            }
        }
        if ($document['blocks'] === []) {
            $errors[] = 'Add at least one block before publishing.';
        }
        if ($conversionBlocks === 0) {
            $errors[] = 'Add a CRM Form, Booking, WhatsApp, or Call to action block.';
        }
        $score = max(0, 100 - (count($errors) * 25) - (count($warnings) * 5));
        return ['valid' => $errors === [], 'score' => $score, 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)), 'schema' => self::SCHEMA_VERSION];
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $context */
    public function render(array $document, array $context = []): string
    {
        $document = $this->normalizeDocument($document, (array) ($context['page'] ?? []));
        $theme = $document['theme'];
        $typeScale = DesignTypographyCatalog::webScale((string) $theme['type_scale']);
        $style = sprintf(
            '--ds-primary:%s;--ds-secondary:%s;--ds-page:%s;--ds-surface:%s;--ds-text:%s;--ds-muted:%s;--ds-radius:%dpx;--ds-section-space:%dpx;--ds-heading-font:%s;--ds-body-font:%s;--ds-heading-scale:%s;--ds-body-scale:%s',
            $this->e($theme['primary_color']), $this->e($theme['secondary_color']), $this->e($theme['page_color']),
            $this->e($theme['surface_color']), $this->e($theme['text_color']), $this->e($theme['muted_color']),
            (int) $theme['radius'], (int) $theme['section_spacing'],
            $this->e(DesignTypographyCatalog::webFontCss((string) $theme['heading_font'])),
            $this->e(DesignTypographyCatalog::webFontCss((string) $theme['body_font'])),
            (string) $typeScale['heading'], (string) $typeScale['body']
        );
        $html = '<div class="ds-page" data-design-schema="' . self::SCHEMA_VERSION . '" style="' . $style . '">';
        foreach ($document['blocks'] as $block) {
            $html .= $this->renderBlock($block, $context);
        }
        return $html . '</div>';
    }

    /** @param array<string,mixed> $document */
    public function legacyFields(array $document): array
    {
        $document = $this->normalizeDocument($document);
        $body = $proof = $faq = $cta = $forms = [];
        $headline = '';
        $heroMediaId = null;
        foreach ($document['blocks'] as $block) {
            $p = $block['props'];
            if ($block['type'] === 'hero' && $headline === '') {
                $headline = (string) $p['heading'];
                $heroMediaId = (int) ($p['media_file_id'] ?? 0) ?: null;
            }
            if ($block['type'] === 'text_image') {
                $body[] = ['heading' => (string) $p['heading'], 'body' => (string) $p['body']];
            } elseif ($block['type'] === 'benefits') {
                $body[] = ['heading' => (string) $p['heading'], 'body' => implode(' • ', $this->lines((string) $p['items']))];
            } elseif ($block['type'] === 'testimonials') {
                $proof[] = ['heading' => (string) $p['heading'], 'body' => '“' . (string) $p['quote'] . '” — ' . trim((string) $p['name'] . ', ' . (string) $p['role'], ', ')];
            } elseif ($block['type'] === 'faq') {
                foreach ($this->pairs((string) $p['items']) as $pair) {
                    $faq[] = ['heading' => $pair[0], 'body' => $pair[1]];
                }
            } elseif ($block['type'] === 'cta') {
                $cta[] = ['heading' => (string) $p['heading'], 'body' => (string) $p['body']];
            } elseif ($block['type'] === 'crm_form') {
                $forms[] = ['type' => 'crm_form', 'form_id' => (int) $p['form_id'], 'heading' => (string) $p['heading']];
            }
        }
        return [
            'headline' => $headline !== '' ? $headline : null,
            'hero_media_file_id' => $heroMediaId,
            'body_sections_json' => $body,
            'proof_blocks_json' => $proof,
            'faq_blocks_json' => $faq,
            'cta_blocks_json' => $cta,
            'form_blocks_json' => $forms,
            'theme_settings_json' => $document['theme'],
        ];
    }

    /** @param array<string,mixed> $page */
    public function legacyDocument(array $page): array
    {
        $blocks = [];
        $blocks[] = $this->block('hero', [
            'heading' => (string) ($page['headline'] ?? $page['title'] ?? 'A page built for action'),
            'body' => (string) ($page['meta_description'] ?? ''),
            'media_file_id' => (int) ($page['hero_media_file_id'] ?? 0),
            'image_alt' => (string) (($page['_media']['hero']['alt_text'] ?? '')),
        ]);
        foreach ((array) ($page['body_sections_json'] ?? $page['body_sections'] ?? []) as $section) {
            if (is_array($section)) {
                $blocks[] = $this->block('text_image', ['heading' => (string) ($section['heading'] ?? ''), 'body' => (string) ($section['body'] ?? '')]);
            }
        }
        foreach ((array) ($page['proof_blocks_json'] ?? $page['proof_blocks'] ?? []) as $section) {
            if (is_array($section)) {
                $blocks[] = $this->block('testimonials', ['heading' => (string) ($section['heading'] ?? 'Customer proof'), 'quote' => (string) ($section['body'] ?? '')]);
            }
        }
        $legacyFaqBlocks = (array) ($page['faq_blocks_json'] ?? $page['faq_blocks'] ?? []);
        if ($legacyFaqBlocks !== []) {
            $pairs = [];
            foreach ($legacyFaqBlocks as $section) {
                if (is_array($section)) {
                    $pairs[] = (string) ($section['heading'] ?? 'Question') . ' | ' . (string) ($section['body'] ?? '');
                }
            }
            $blocks[] = $this->block('faq', ['items' => implode("\n", $pairs)]);
        }
        $legacyCtaBlocks = (array) ($page['cta_blocks_json'] ?? $page['cta_blocks'] ?? []);
        $legacyVariants = (array) ($page['cta_variants_json'] ?? $page['cta_variants'] ?? []);
        $cta = (array) (($legacyCtaBlocks[0] ?? []));
        $variant = (array) (($legacyVariants[0] ?? []));
        $legacyDestination = (string) ($variant['destination'] ?? '#contact');
        if (in_array(strtolower($legacyDestination), ['form', 'lead_capture', 'demo_request', 'contact'], true)) {
            $legacyDestination = '#contact';
        }
        if ($cta !== [] || $variant !== [] || (int) ($page['form_id'] ?? 0) <= 0) {
            $blocks[] = $this->block('cta', [
                'heading' => (string) ($cta['heading'] ?? 'Take the next step'),
                'body' => (string) ($cta['body'] ?? 'Contact the team to continue.'),
                'button_label' => (string) ($variant['label'] ?? 'Get started'),
                'button_href' => $legacyDestination,
            ]);
        }
        if ((int) ($page['form_id'] ?? 0) > 0) {
            $blocks[] = $this->block('crm_form', ['form_id' => (int) $page['form_id']]);
        }
        return [
            'schema' => self::SCHEMA_VERSION,
            'theme' => array_merge($this->defaultTheme(), is_array($page['theme_settings_json'] ?? $page['theme_settings'] ?? null) ? ($page['theme_settings_json'] ?? $page['theme_settings']) : []),
            'blocks' => $blocks,
            'metadata' => ['template_key' => 'legacy_migration', 'template_version' => '1.0.0'],
        ];
    }

    /** @param array<string,mixed> $block @param array<string,mixed> $context */
    private function renderBlock(array $block, array $context): string
    {
        $p = $block['props'];
        $style = $block['style'];
        $visibility = $block['visibility'];
        $classes = ['ds-block', 'ds-block--' . $block['type'], 'ds-align--' . $style['alignment'], 'ds-space--' . $style['padding']];
        if ($style['background'] !== 'transparent') {
            $classes[] = 'ds-block--custom-background';
            if ($this->isDarkColor($style['background'])) {
                $classes[] = 'ds-block--dark-background';
            }
        }
        foreach (['desktop', 'tablet', 'mobile'] as $viewport) {
            if (!empty($visibility['hide_' . $viewport])) {
                $classes[] = 'ds-hide-' . $viewport;
            }
        }
        $styleVariables = [
            '--ds-block-bg:' . $this->e($style['background']),
            '--ds-max:' . (int) $style['max_width'] . 'px',
        ];
        if ($style['heading_font'] !== 'inherit') {
            $styleVariables[] = '--ds-heading-font:' . $this->e(DesignTypographyCatalog::webFontCss((string) $style['heading_font']));
        }
        if ($style['body_font'] !== 'inherit') {
            $styleVariables[] = '--ds-body-font:' . $this->e(DesignTypographyCatalog::webFontCss((string) $style['body_font']));
        }
        if ($style['type_scale'] !== 'inherit') {
            $blockScale = DesignTypographyCatalog::webScale((string) $style['type_scale']);
            $styleVariables[] = '--ds-heading-scale:' . (string) $blockScale['heading'];
            $styleVariables[] = '--ds-body-scale:' . (string) $blockScale['body'];
        }
        $attrs = ' id="' . $this->e($block['id']) . '" class="' . implode(' ', $classes) . '" data-design-block="' . $this->e($block['type']) . '" style="' . implode(';', $styleVariables) . '">';
        $open = '<section' . $attrs . '<div class="ds-block__inner">';
        $close = '</div></section>';
        $eyebrow = !empty($p['eyebrow']) ? '<p class="ds-eyebrow">' . $this->e($p['eyebrow']) . '</p>' : '';
        $body = !empty($p['body']) ? '<p class="ds-copy">' . nl2br($this->e($p['body']), false) . '</p>' : '';
        $heading = fn(string $fallback = ''): string => '<h2>' . $this->e((string) ($p['heading'] ?? $fallback)) . '</h2>';

        if ($block['type'] === 'hero') {
            return $open . '<div class="ds-hero__copy">' . $eyebrow . '<h1>' . $this->e($p['heading']) . '</h1>' . $body
                . '<div class="ds-actions">' . $this->button($p['primary_label'], $p['primary_href'], true, $context) . $this->button($p['secondary_label'], $p['secondary_href'], false, $context) . '</div></div>'
                . $this->media((int) $p['media_file_id'], (string) $p['image_alt'], $context, 'ds-hero__media') . $close;
        }
        if ($block['type'] === 'text_image') {
            $copy = '<div class="ds-split__copy">' . $eyebrow . $heading() . $body . '</div>';
            $media = $this->media((int) $p['media_file_id'], (string) $p['image_alt'], $context, 'ds-split__media');
            return $open . '<div class="ds-split ds-split--' . $this->e($p['image_side']) . '">' . ($p['image_side'] === 'left' ? $media . $copy : $copy . $media) . '</div>' . $close;
        }
        if ($block['type'] === 'benefits') {
            $items = '';
            foreach ($this->lines((string) $p['items']) as $item) {
                $items .= '<li><span aria-hidden="true">✓</span>' . $this->e($item) . '</li>';
            }
            return $open . $heading() . $body . '<ul class="ds-benefits">' . $items . '</ul>' . $close;
        }
        if ($block['type'] === 'logo_strip') {
            $items = '';
            foreach ($this->lines((string) $p['items']) as $item) {
                $items .= '<li>' . $this->e($item) . '</li>';
            }
            return $open . $heading() . '<ul class="ds-logos" aria-label="Customer organizations">' . $items . '</ul>' . $close;
        }
        if ($block['type'] === 'testimonials') {
            return $open . $heading() . '<div class="ds-quote">' . $this->media((int) $p['media_file_id'], (string) $p['image_alt'], $context, 'ds-quote__media')
                . '<blockquote>“' . $this->e($p['quote']) . '”</blockquote><p><strong>' . $this->e($p['name']) . '</strong><span>' . $this->e($p['role']) . '</span></p></div>' . $close;
        }
        if ($block['type'] === 'pricing') {
            $features = '';
            foreach ($this->lines((string) $p['features']) as $item) {
                $features .= '<li>✓ ' . $this->e($item) . '</li>';
            }
            return $open . $heading() . $body . '<article class="ds-price"><p class="ds-price__name">' . $this->e($p['plan_name']) . '</p><p class="ds-price__value">' . $this->e($p['price']) . '<span>' . $this->e($p['period']) . '</span></p><ul>' . $features . '</ul>' . $this->button($p['button_label'], $p['button_href'], true, $context) . '</article>' . $close;
        }
        if ($block['type'] === 'faq') {
            $items = '';
            foreach ($this->pairs((string) $p['items']) as $pair) {
                $items .= '<details><summary>' . $this->e($pair[0]) . '</summary><p>' . nl2br($this->e($pair[1]), false) . '</p></details>';
            }
            return $open . $heading() . '<div class="ds-faq">' . $items . '</div>' . $close;
        }
        if ($block['type'] === 'gallery') {
            $gallery = '';
            foreach ($this->ids((string) $p['media_ids']) as $id) {
                $gallery .= $this->media($id, (string) $p['image_alt'], $context, 'ds-gallery__item');
            }
            return $open . $heading() . '<div class="ds-gallery">' . ($gallery !== '' ? $gallery : '<div class="ds-empty-media">Add approved media</div>') . '</div>' . $close;
        }
        if ($block['type'] === 'crm_form') {
            $formRecord = (array) ($context['forms_by_id'][(int) $p['form_id']] ?? []);
            $formUuid = (string) (($formRecord['uuid'] ?? '') ?: ($context['form_uuid'] ?? ''));
            $formUrl = $formUuid !== '' ? 'form.php?uuid=' . rawurlencode($formUuid) . '&embed=1' : '';
            $mode = (string) ($context['mode'] ?? 'public');
            $previewToken = (string) (($formRecord['preview_token'] ?? '') ?: ($context['form_preview_token'] ?? ''));
            if ($formUrl !== '' && $mode !== 'public' && $previewToken !== '') {
                $formUrl .= '&preview_token=' . rawurlencode($previewToken);
            }
            if ($formUrl !== '' && !empty($context['public_token'])) {
                $formUrl .= '&landing_token=' . rawurlencode((string) $context['public_token']);
            }
            $embed = $formUrl !== '' ? '<iframe class="ds-embed ds-form-embed" title="' . $this->e($p['heading']) . '" src="' . $this->e($formUrl) . '" loading="lazy" style="height:' . (int) $p['height'] . 'px"></iframe>' : '<div class="ds-connection-warning">Connect an active CRM form before publishing.</div>';
            return $open . '<div id="contact">' . $eyebrow . $heading() . $body . $embed . '</div>' . $close;
        }
        if ($block['type'] === 'booking') {
            $url = $this->safeBookingUrl((string) $p['booking_url']);
            $content = $p['display'] === 'embed' && $url !== ''
                ? '<iframe class="ds-embed ds-booking-embed" title="' . $this->e($p['heading']) . '" src="' . $this->e($url) . '" loading="lazy" style="height:' . (int) $p['height'] . 'px"></iframe>'
                : $this->button($p['button_label'], $url, true, $context);
            return $open . '<div id="booking">' . $eyebrow . $heading() . $body . $content . '</div>' . $close;
        }
        if ($block['type'] === 'whatsapp') {
            $phone = preg_replace('/\D+/', '', (string) $p['phone']) ?: '';
            $url = $phone !== '' ? 'https://wa.me/' . $phone . '?text=' . rawurlencode((string) $p['message']) : '';
            return $open . '<div id="whatsapp">' . $eyebrow . $heading() . $body . $this->button($p['button_label'], $url, true, $context) . '</div>' . $close;
        }
        return $open . $heading() . $body . '<div class="ds-actions">' . $this->button($p['button_label'], $p['button_href'], true, $context) . '</div>' . $close;
    }

    /** @param array<string,mixed> $context */
    private function media(int $id, string $fallbackAlt, array $context, string $class): string
    {
        if ($id <= 0) {
            return '<div class="' . $this->e($class) . ' ds-empty-media" aria-hidden="true"><span>Approved media</span></div>';
        }
        $media = (array) (($context['media_by_id'][$id] ?? []));
        $url = trim((string) ($media['display_url'] ?? $media['url'] ?? ''));
        if ($url === '') {
            return '<div class="' . $this->e($class) . ' ds-empty-media"><span>Media unavailable</span></div>';
        }
        $alt = trim($fallbackAlt) !== '' ? $fallbackAlt : (string) ($media['alt_text'] ?? $media['title'] ?? '');
        if ((string) ($media['media_type'] ?? '') === 'video') {
            return '<figure class="' . $this->e($class) . '"><video controls preload="metadata" src="' . $this->e($url) . '"></video></figure>';
        }
        return '<figure class="' . $this->e($class) . '"><img src="' . $this->e($url) . '" alt="' . $this->e($alt) . '" loading="lazy"></figure>';
    }

    /** @param array<string,mixed> $context */
    private function button(mixed $label, mixed $href, bool $primary, array $context): string
    {
        $label = trim((string) $label);
        $href = $this->safeUrl((string) $href);
        if ($label === '' || $href === '') {
            return '';
        }
        $external = preg_match('#^https?://#i', $href) === 1;
        return '<a class="ds-button ' . ($primary ? 'ds-button--primary' : 'ds-button--secondary') . '" href="' . $this->e($href) . '" data-marketing-cta="1" data-cta-label="' . $this->e($label) . '" data-cta-destination="' . $this->e($href) . '"' . ($external ? ' rel="noopener"' : '') . '>' . $this->e($label) . '</a>';
    }

    /** @param array<string,mixed> $definition @param array<string,mixed> $props */
    private function normalizeProps(array $definition, array $props): array
    {
        $out = [];
        foreach ($definition['fields'] as $field) {
            $key = $field['key'];
            $value = array_key_exists($key, $props) ? $props[$key] : $field['default'];
            $type = $field['type'];
            if ($type === 'number') {
                $out[$key] = min(1400, max(240, (int) $value));
            } elseif (in_array($type, ['media', 'form'], true)) {
                $out[$key] = max(0, (int) $value);
            } elseif ($type === 'select') {
                $value = (string) $value;
                $out[$key] = in_array($value, $field['options'], true) ? $value : (string) $field['default'];
            } elseif ($type === 'phone') {
                $out[$key] = substr(preg_replace('/[^0-9+() .-]/', '', (string) $value) ?: '', 0, 40);
            } elseif ($type === 'booking_url') {
                $out[$key] = $this->safeBookingUrl((string) $value);
            } elseif ($type === 'url') {
                $out[$key] = $this->safeUrl((string) $value);
            } else {
                $out[$key] = $this->cleanText((string) $value, self::MAX_TEXT, true);
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $style */
    private function normalizeStyle(array $style): array
    {
        $requestedAlignment = (string) ($style['alignment'] ?? 'left');
        $requestedPadding = (string) ($style['padding'] ?? 'normal');
        $alignment = in_array($requestedAlignment, ['left', 'center'], true) ? $requestedAlignment : 'left';
        $padding = in_array($requestedPadding, ['compact', 'normal', 'spacious'], true) ? $requestedPadding : 'normal';
        $background = $this->color((string) ($style['background'] ?? 'transparent'), 'transparent');
        $headingFont = (string) ($style['heading_font'] ?? 'inherit');
        $bodyFont = (string) ($style['body_font'] ?? 'inherit');
        $typeScale = (string) ($style['type_scale'] ?? 'inherit');
        return [
            'alignment' => $alignment,
            'padding' => $padding,
            'background' => $background,
            'max_width' => min(1600, max(640, (int) ($style['max_width'] ?? 1180))),
            'heading_font' => $headingFont === 'inherit' ? 'inherit' : DesignTypographyCatalog::normalizeWebFont($headingFont),
            'body_font' => $bodyFont === 'inherit' ? 'inherit' : DesignTypographyCatalog::normalizeWebFont($bodyFont),
            'type_scale' => $typeScale === 'inherit' ? 'inherit' : DesignTypographyCatalog::normalizeWebScale($typeScale),
        ];
    }

    /** @param array<string,mixed> $visibility */
    private function normalizeVisibility(array $visibility): array
    {
        return [
            'hide_desktop' => !empty($visibility['hide_desktop']),
            'hide_tablet' => !empty($visibility['hide_tablet']),
            'hide_mobile' => !empty($visibility['hide_mobile']),
        ];
    }

    /** @param array<string,mixed> $theme */
    private function normalizeTheme(array $theme): array
    {
        $defaults = $this->defaultTheme();
        foreach (['primary_color', 'secondary_color', 'page_color', 'surface_color', 'text_color', 'muted_color'] as $field) {
            $defaults[$field] = $this->color((string) ($theme[$field] ?? $defaults[$field]), $defaults[$field]);
        }
        $defaults['radius'] = min(32, max(0, (int) ($theme['radius'] ?? $defaults['radius'])));
        $defaults['section_spacing'] = min(160, max(32, (int) ($theme['section_spacing'] ?? $defaults['section_spacing'])));
        $legacyFont = (string) ($theme['font_family'] ?? 'system');
        $defaults['heading_font'] = DesignTypographyCatalog::normalizeWebFont((string) ($theme['heading_font'] ?? $legacyFont));
        $defaults['body_font'] = DesignTypographyCatalog::normalizeWebFont((string) ($theme['body_font'] ?? $legacyFont));
        $defaults['type_scale'] = DesignTypographyCatalog::normalizeWebScale((string) ($theme['type_scale'] ?? $defaults['type_scale']));
        return $defaults;
    }

    /** @return array<string,mixed> */
    private function defaultTheme(): array
    {
        return [
            'primary_color' => '#0f67ea',
            'secondary_color' => '#0f9f76',
            'page_color' => '#f6f8fb',
            'surface_color' => '#ffffff',
            'text_color' => '#132238',
            'muted_color' => '#5b6b7f',
            'heading_font' => 'system',
            'body_font' => 'system',
            'type_scale' => 'balanced',
            'radius' => 16,
            'section_spacing' => 80,
        ];
    }

    /** @param array<int,array<string,mixed>> $fields @return array<string,mixed> */
    private function definition(string $label, string $category, string $icon, array $fields): array
    {
        return ['label' => $label, 'category' => $category, 'icon' => $icon, 'fields' => $fields];
    }

    /** @param array<int,string> $options @return array<string,mixed> */
    private function field(string $key, string $label, string $type, mixed $default, bool $required = false, array $options = []): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'default' => $default, 'required' => $required, 'options' => $options];
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    private function defaultProps(array $definition): array
    {
        $out = [];
        foreach ($definition['fields'] as $field) {
            $out[$field['key']] = $field['default'];
        }
        return $out;
    }

    /** @param array<string,mixed> $props @return array<string,mixed> */
    private function block(string $type, array $props): array
    {
        $definition = $this->blockRegistry()[$type];
        return [
            'id' => $this->blockId($type, random_int(1, 999999)),
            'type' => $type,
            'props' => array_merge($this->defaultProps($definition), $props),
            'style' => ['alignment' => in_array($type, ['hero', 'cta', 'logo_strip'], true) ? 'center' : 'left', 'padding' => 'normal', 'background' => 'transparent', 'max_width' => 1180, 'heading_font' => 'inherit', 'body_font' => 'inherit', 'type_scale' => 'inherit'],
            'visibility' => ['hide_desktop' => false, 'hide_tablet' => false, 'hide_mobile' => false],
        ];
    }

    /** @param array<int,array<string,mixed>> $blocks @return array<string,mixed> */
    private function template(string $key, string $name, string $description, string $useCase, string $industry, array $blocks): array
    {
        foreach ($blocks as $index => &$block) {
            $block['id'] = $key . '_' . (string) ($block['type'] ?? 'block') . '_' . ($index + 1);
        }
        unset($block);

        return [
            'key' => $key,
            'name' => $name,
            'description' => $description,
            'use_case' => $useCase,
            'industry' => $industry,
            'version' => '1.0.0',
            'status' => 'approved',
            'document' => ['schema' => self::SCHEMA_VERSION, 'theme' => $this->defaultTheme(), 'blocks' => $blocks, 'metadata' => ['template_key' => $key, 'template_version' => '1.0.0']],
        ];
    }

    private function blockId(string $type, int $seed): string
    {
        return $type . '_' . substr(hash('sha256', $type . ':' . $seed . ':' . microtime(true)), 0, 12);
    }

    private function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '#') || str_starts_with($url, '/') || preg_match('#^[a-zA-Z0-9_.-]+\.php(?:\?|$)#', $url) === 1) {
            return substr($url, 0, 1000);
        }
        if (filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return substr($url, 0, 1000);
        }
        return '';
    }

    private function safeBookingUrl(string $url): string
    {
        $url = $this->safeUrl($url);
        if ($url === '') {
            return '';
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        if (!str_ends_with($path, 'meeting_schedule.php') && !str_contains($path, '/meeting_schedule.php')) {
            return '';
        }
        $query = [];
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?: ''), $query);
        $hasShare = trim((string) ($query['share'] ?? '')) !== '';
        $hasPublicProfile = trim((string) ($query['workspace'] ?? '')) !== '' && trim((string) ($query['profile'] ?? '')) !== '';
        return $hasShare || $hasPublicProfile ? $url : '';
    }

    private function color(string $value, string $fallback): string
    {
        if ($value === 'transparent') {
            return $value;
        }
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : $fallback;
    }

    private function isDarkColor(string $value): bool
    {
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $value, $matches) !== 1) {
            return false;
        }
        $hex = $matches[1];
        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));

        return (($red * 299) + ($green * 587) + ($blue * 114)) / 1000 < 145;
    }

    /** @return array<int,string> */
    private function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: []), static fn(string $line): bool => $line !== ''));
    }

    /** @return array<int,array{0:string,1:string}> */
    private function pairs(string $value): array
    {
        $pairs = [];
        foreach ($this->lines($value) as $line) {
            [$question, $answer] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if ($question !== '') {
                $pairs[] = [$question, $answer];
            }
        }
        return $pairs;
    }

    /** @return array<int,int> */
    private function ids(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('intval', preg_split('/[^0-9]+/', $value) ?: []), static fn(int $id): bool => $id > 0)));
    }

    private function cleanText(string $value, int $max, bool $preserveLines = false): string
    {
        $value = strip_tags($value);
        $value = str_replace(["\0", "\r"], ['', ''], $value);
        if (!$preserveLines) {
            $value = preg_replace('/\s+/u', ' ', $value) ?: '';
        }
        return trim(mb_substr($value, 0, $max));
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
