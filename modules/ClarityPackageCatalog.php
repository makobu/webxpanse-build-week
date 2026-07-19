<?php

declare(strict_types=1);

namespace CRM\Modules;

class ClarityPackageCatalog
{
    public const PACKAGE_SPRINT = 'sprint';
    public const PACKAGE_LAUNCH = 'launch';
    public const PACKAGE_OPERATOR = 'operator';
    public const PACKAGE_CORE = 'core';
    public const PACKAGE_GROWTH = 'growth';
    public const NICHE_SOLO_FOUNDERS = 'solo_founders';
    public const NICHE_INTERIORS_CONTRACTORS = 'interiors_contractors';
    public const NICHE_WHATSAPP_HEAVY_SMB = 'whatsapp_heavy_smb';
    public const NICHE_AGENCIES = 'agencies';
    public const NICHE_DISTRIBUTORS_WHOLESALERS = 'distributors_wholesalers';

    public function getPackages(): array
    {
        return [
            self::PACKAGE_SPRINT => [
                'key' => self::PACKAGE_SPRINT,
                'label' => 'Clarity Sprint',
                'summary' => 'For builders who need one focused session to turn a rough idea into an offer, price, and next action plan.',
                'price_range' => '$49-$99 one-time',
                'setup_fee_range' => 'Manual guided audit',
                'outcome' => 'Leave with a clear customer, first offer, pricing direction, and a 7-day action plan.',
                'use_when' => 'Use Sprint when the buyer is curious, early, and needs clarity before committing to a full launch program.',
                'capabilities' => [
                    'founder_guidance' => true,
                    'guided_launch' => false,
                    'operator_ai' => false,
                    'core_workflow' => false,
                    'growth_ai' => false,
                ],
                'features' => [
                    'Idea clarity audit',
                    'Target customer definition',
                    'First offer and pricing notes',
                    'First outreach message',
                    '7-day action plan',
                ],
            ],
            self::PACKAGE_LAUNCH => [
                'key' => self::PACKAGE_LAUNCH,
                'label' => 'AI Cofounder Launch',
                'summary' => 'A 30-day guided launch for side-hustlers, graduates, and first-time founders who need clarity, consistency, and first customer action.',
                'price_range' => '$149-$299 one-time',
                'setup_fee_range' => 'Included in 30-day launch',
                'outcome' => 'Turn the idea into a working founder workspace with offer, pricing, first prospects, outreach, follow-up, and weekly review.',
                'use_when' => 'Use Launch as the primary paid beta offer when the buyer wants hands-on help turning an idea into action.',
                'capabilities' => [
                    'founder_guidance' => true,
                    'guided_launch' => true,
                    'operator_ai' => true,
                    'core_workflow' => true,
                    'growth_ai' => true,
                ],
                'features' => [
                    'Clarity Journey setup',
                    'Offer and pricing definition',
                    'First 20 prospects',
                    'WhatsApp/email follow-up tasks',
                    'Weekly founder review',
                ],
            ],
            self::PACKAGE_OPERATOR => [
                'key' => self::PACKAGE_OPERATOR,
                'label' => 'Clarity Operator',
                'summary' => 'For founders who have completed launch setup and want the weekly AI cofounder rhythm to keep execution moving.',
                'price_range' => '$49-$99/mo',
                'setup_fee_range' => 'After Sprint or Launch',
                'outcome' => 'Keep offers, outreach, follow-up, money context, and weekly commitments visible after the launch sprint.',
                'use_when' => 'Use Operator when the founder has enough clarity to keep running the business inside Clarity each week.',
                'capabilities' => [
                    'founder_guidance' => true,
                    'guided_launch' => true,
                    'operator_ai' => true,
                    'core_workflow' => true,
                    'growth_ai' => true,
                ],
                'features' => [
                    'AI Coach and Founder Loop',
                    'Contacts, tasks, and deals',
                    'WhatsApp and email follow-up',
                    'Simple finance rhythm',
                    'Weekly review and next actions',
                ],
            ],
            self::PACKAGE_CORE => [
                'key' => self::PACKAGE_CORE,
                'label' => 'Clarity Core',
                'summary' => 'For teams that need one place to manage contacts, follow-up, tasks, and pipeline.',
                'price_range' => '$149-$299/mo',
                'setup_fee_range' => '$500-$2,000 setup',
                'outcome' => 'Stop losing quote requests and follow-up across inboxes, chats, and spreadsheets.',
                'use_when' => 'Use Core when the buyer mainly needs follow-up structure, ownership, and pipeline clarity.',
                'capabilities' => [
                    'core_workflow' => true,
                    'growth_ai' => false,
                ],
                'features' => [
                    'Contacts and companies',
                    'Deals and pipeline tracking',
                    'Tasks with ownership and due dates',
                    'WhatsApp and email visibility',
                    'Basic reporting',
                ],
            ],
            self::PACKAGE_GROWTH => [
                'key' => self::PACKAGE_GROWTH,
                'label' => 'Clarity Growth',
                'summary' => 'For teams that want AI guidance, automation, and stronger day-to-day operating visibility.',
                'price_range' => '$349-$750/mo',
                'setup_fee_range' => '$500-$2,000 setup',
                'outcome' => 'Keep deals moving, reduce dropped opportunities, and give operators clear daily priorities.',
                'use_when' => 'Use Growth when the team needs digesting, prioritization, and stronger daily operating control.',
                'capabilities' => [
                    'core_workflow' => true,
                    'growth_ai' => true,
                ],
                'features' => [
                    'Everything in Clarity Core',
                    'Daily AI digest',
                    'AI-guided prioritization',
                    'Automation support',
                    'Deeper reporting and visibility',
                ],
            ],
        ];
    }

    public function getPackage(string $packageKey): ?array
    {
        $packages = $this->getPackages();
        return $packages[$packageKey] ?? null;
    }

    public function getCapabilityMap(): array
    {
        return [
            'core_workflow' => [
                'label' => 'Core workflow',
                'description' => 'Contacts, deals, tasks, inbox visibility, ownership, and basic reporting.',
            ],
            'growth_ai' => [
                'label' => 'Growth AI',
                'description' => 'Digest, prioritization, richer automation, and deeper operating visibility.',
            ],
            'founder_guidance' => [
                'label' => 'Founder guidance',
                'description' => 'Clarity Journey, offer definition, pricing logic, validation, and first-customer focus.',
            ],
            'guided_launch' => [
                'label' => 'Guided launch',
                'description' => 'Hands-on setup that turns the idea into prospects, outreach, follow-up, and weekly commitments.',
            ],
            'operator_ai' => [
                'label' => 'Operator AI',
                'description' => 'AI Coach and Founder Loop support for weekly execution, customer follow-up, and operating rhythm.',
            ],
        ];
    }

    public function getLaunchModels(): array
    {
        return [
            'service_led_saas' => [
                'label' => 'Service-led SaaS',
                'description' => 'Lead with onboarding, operator support, and hands-on delivery while the workflow proves itself.',
            ],
            'done_for_you_setup' => [
                'label' => 'Done-for-you setup',
                'description' => 'Use when the team needs you to preload the workspace, seed records, and run the first workflow with them.',
            ],
            'pilot_rollout' => [
                'label' => 'Pilot rollout',
                'description' => 'Use for a controlled first cohort where success is defined by one repeatable motion and a short feedback loop.',
            ],
            'founder_launch_program' => [
                'label' => 'Founder launch program',
                'description' => 'Use for a paid 30-day founder cohort where clarity, first outreach, follow-up, and weekly review prove the product.',
            ],
        ];
    }

    public function getSuccessMilestones(): array
    {
        return [
            'first_5_customers' => [
                'label' => 'Close and onboard first 5 customers',
                'description' => 'Best when the workspace needs a full sales-to-handoff walkthrough.',
            ],
            'first_10_qualified_leads' => [
                'label' => 'Qualify first 10 active leads',
                'description' => 'Best for chat-heavy or inquiry-heavy demos focused on response speed and ownership.',
            ],
            'first_3_active_deals' => [
                'label' => 'Advance first 3 active deals',
                'description' => 'Best for proposal, approval, and stalled-pipeline demos.',
            ],
            'first_paid_signal' => [
                'label' => 'Capture first paid signal',
                'description' => 'Best for founder launches where success is a paid sprint, serious buyer conversation, quote, deposit, or validation signal.',
            ],
        ];
    }

    public function getDemoScenario(string $nicheKey, string $scenarioKey = ''): array
    {
        $profile = $this->getNicheProfile($nicheKey);
        $scenarios = array_values($profile['demo_scenarios'] ?? []);
        if ($scenarios === []) {
            return [];
        }

        foreach ($scenarios as $scenario) {
            if ((string) ($scenario['key'] ?? '') === $scenarioKey) {
                return $scenario;
            }
        }

        return $scenarios[0];
    }

    private function finalizeNicheProfile(array $profile): array
    {
        $scenarios = array_values((array) ($profile['demo_scenarios'] ?? []));
        if ($scenarios === []) {
            $scenarios = [[
                'key' => (string) ($profile['key'] ?? 'default_scenario'),
                'label' => 'Default demo scenario',
                'short_pitch' => (string) ($profile['summary'] ?? ''),
                'first_demo_win' => (string) ($profile['first_demo_win'] ?? ''),
                'storyline' => (string) ($profile['demo_seed_description'] ?? $profile['summary'] ?? ''),
                'seed_profile' => (string) ($profile['demo_seed_profile'] ?? ''),
                'expected_seed_summary' => [],
                'operator_checklist' => array_values((array) ($profile['demo_operator_checklist'] ?? [])),
                'recommended_package' => self::PACKAGE_CORE,
                'recommended_launch_model' => 'service_led_saas',
                'recommended_success_milestone' => 'first_5_customers',
                'dominant_channel' => (string) (($profile['primary_channels'][0] ?? 'Email')),
            ]];
        }

        $launchModels = $this->getLaunchModels();
        $successMilestones = $this->getSuccessMilestones();
        foreach ($scenarios as $index => $scenario) {
            $packageKey = (string) ($scenario['recommended_package'] ?? self::PACKAGE_CORE);
            $launchModelKey = (string) ($scenario['recommended_launch_model'] ?? 'service_led_saas');
            $milestoneKey = (string) ($scenario['recommended_success_milestone'] ?? 'first_5_customers');
            $summary = is_array($scenario['expected_seed_summary'] ?? null) ? $scenario['expected_seed_summary'] : [];

            $scenarios[$index]['key'] = (string) ($scenario['key'] ?? ('scenario_' . ($index + 1)));
            $scenarios[$index]['label'] = (string) ($scenario['label'] ?? 'Demo scenario');
            $scenarios[$index]['short_pitch'] = (string) ($scenario['short_pitch'] ?? $profile['summary'] ?? '');
            $scenarios[$index]['first_demo_win'] = (string) ($scenario['first_demo_win'] ?? $profile['first_demo_win'] ?? '');
            $scenarios[$index]['storyline'] = (string) ($scenario['storyline'] ?? $profile['demo_seed_description'] ?? '');
            $scenarios[$index]['seed_profile'] = (string) ($scenario['seed_profile'] ?? $profile['demo_seed_profile'] ?? '');
            $scenarios[$index]['operator_checklist'] = array_values((array) ($scenario['operator_checklist'] ?? $profile['demo_operator_checklist'] ?? []));
            $scenarios[$index]['dominant_channel'] = (string) ($scenario['dominant_channel'] ?? ($profile['primary_channels'][0] ?? 'Email'));
            $scenarios[$index]['recommended_package'] = $packageKey;
            $scenarios[$index]['recommended_launch_model'] = $launchModelKey;
            $scenarios[$index]['recommended_success_milestone'] = $milestoneKey;
            $scenarios[$index]['expected_seed_summary'] = [
                'contacts' => (int) ($summary['contacts'] ?? 0),
                'deals' => (int) ($summary['deals'] ?? 0),
                'tasks' => (int) ($summary['tasks'] ?? 0),
                'communications' => (int) ($summary['communications'] ?? 0),
            ];
            $scenarios[$index]['recommended_package_label'] = (string) (($this->getPackage($packageKey)['label'] ?? $packageKey));
            $scenarios[$index]['recommended_launch_model_label'] = (string) (($launchModels[$launchModelKey]['label'] ?? $launchModelKey));
            $scenarios[$index]['recommended_success_milestone_label'] = (string) (($successMilestones[$milestoneKey]['label'] ?? $milestoneKey));
        }

        $profile['demo_scenarios'] = $scenarios;
        $profile['demo_seed_profile'] = (string) ($profile['demo_seed_profile'] ?? ($scenarios[0]['seed_profile'] ?? ''));
        $profile['demo_seed_description'] = (string) ($profile['demo_seed_description'] ?? ($scenarios[0]['storyline'] ?? ''));

        return $profile;
    }

    public function getNicheProfile(string $nicheKey = self::NICHE_SOLO_FOUNDERS): array
    {
        $profiles = $this->getNicheProfiles();
        return $profiles[$nicheKey] ?? $profiles[self::NICHE_SOLO_FOUNDERS];
    }

    public function getNicheProfiles(): array
    {
        $profiles = [
            self::NICHE_SOLO_FOUNDERS => [
                'key' => self::NICHE_SOLO_FOUNDERS,
                'label' => 'Solo founders / side-hustlers',
                'summary' => 'Built for employed builders, recent graduates, and first-time founders who need an AI cofounder to turn a business idea into clear weekly action.',
                'workspace_summary' => 'Package Clarity as an AI cofounder for solo founders: clarify the idea, shape the offer, price it, find first prospects, follow up, and review weekly progress.',
                'audience' => 'Side-hustlers, recent graduates, first-time founders, and time-poor employed people starting with limited capital.',
                'primary_workflow' => 'Idea clarity to first customer action',
                'sales_motion' => 'Idea to offer to first outreach to follow-up to paid signal',
                'primary_channels' => ['WhatsApp', 'Email', 'LinkedIn'],
                'best_fit' => [
                    'Founders who have energy and a business idea but lack structure, confidence, or operating rhythm.',
                    'Solo builders who need marketing, pricing, finance, and follow-up guidance without hiring a team.',
                ],
                'operator_watchouts' => [
                    'The founder keeps researching instead of shipping a clear offer to real prospects.',
                    'Follow-up slips because business action is scattered across notes, chats, and memory.',
                ],
                'first_demo_win' => 'Show a rough idea becoming a priced launch offer, first prospects, outreach tasks, and a weekly review rhythm.',
                'default_company_industry' => 'Founder Launch / Solo Business',
                'default_company_tagline' => 'AI cofounder guidance from idea clarity to first customer action.',
                'default_icp_pain_points' => 'Unclear offer, weak pricing confidence, inconsistent action, no first customer list, and missed follow-up after outreach.',
                'default_icp_channels' => 'WhatsApp, LinkedIn, email, referrals, founder communities',
                'empty_state_copy' => [
                    'general' => 'Start with your idea, first customer guess, and the people you can contact this week.',
                    'deals' => 'Create a first paid-signal opportunity so Clarity can track offer, outreach, follow-up, and weekly review.',
                ],
                'demo_seed_profile' => 'solo_founder_launch',
                'demo_scenarios' => [
                    [
                        'key' => 'founder_idea_to_offer',
                        'label' => 'Idea to first offer',
                        'short_pitch' => 'Show how Clarity turns a scattered idea into a customer, offer, price, and next 7 actions.',
                        'first_demo_win' => 'Open the Clarity Journey context and show the first offer and pricing task.',
                        'storyline' => 'A recent graduate wants to turn a skill into a paid service but needs customer clarity, offer language, and pricing confidence.',
                        'seed_profile' => 'solo_founder_launch',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 2, 'tasks' => 4, 'communications' => 2],
                        'operator_checklist' => [
                            'Open the founder offer task and show the pricing decision still needed.',
                            'Show the warm prospect tied to the first paid sprint opportunity.',
                            'Explain how weekly review protects consistent action.',
                        ],
                        'recommended_package' => self::PACKAGE_LAUNCH,
                        'recommended_launch_model' => 'founder_launch_program',
                        'recommended_success_milestone' => 'first_paid_signal',
                        'dominant_channel' => 'WhatsApp',
                    ],
                    [
                        'key' => 'founder_first_outreach',
                        'label' => 'First outreach sprint',
                        'short_pitch' => 'Show the first 20 prospects, outreach message, and follow-up task that keeps action moving.',
                        'first_demo_win' => 'Open the first outreach task and the Launch Offer Follow-Up deal.',
                        'storyline' => 'An employed founder has limited time and needs a repeatable outreach and follow-up rhythm after work hours.',
                        'seed_profile' => 'solo_founder_launch',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 2, 'tasks' => 4, 'communications' => 2],
                        'operator_checklist' => [
                            'Open the first customer list and show who gets contacted first.',
                            'Show the draft outreach communication and follow-up task.',
                            'Explain how Clarity keeps the founder focused on the next action.',
                        ],
                        'recommended_package' => self::PACKAGE_LAUNCH,
                        'recommended_launch_model' => 'service_led_saas',
                        'recommended_success_milestone' => 'first_paid_signal',
                        'dominant_channel' => 'LinkedIn',
                    ],
                    [
                        'key' => 'founder_weekly_review',
                        'label' => 'Weekly review rhythm',
                        'short_pitch' => 'Show the founder loop that turns customer responses, pricing evidence, and follow-up into the next week of action.',
                        'first_demo_win' => 'Open the weekly review task and show the founder commitment due this week.',
                        'storyline' => 'A solo founder has started outreach and needs to turn early responses into pricing evidence, commitments, and a calmer weekly plan.',
                        'seed_profile' => 'solo_founder_launch',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 2, 'tasks' => 4, 'communications' => 2],
                        'operator_checklist' => [
                            'Open the weekly review task and explain what the founder learned.',
                            'Show the offer follow-up deal and next buyer action.',
                            'Finish with the AI cofounder rhythm: decide, act, follow up, review.',
                        ],
                        'recommended_package' => self::PACKAGE_OPERATOR,
                        'recommended_launch_model' => 'founder_launch_program',
                        'recommended_success_milestone' => 'first_paid_signal',
                        'dominant_channel' => 'Email',
                    ],
                ],
                'demo_seed_description' => 'Founder launch demo with a rough business idea, priced sprint offer, first prospects, outreach, follow-up, and weekly review.',
                'stage_mapping' => [
                    ['label' => 'Idea clarity', 'crm_stage' => 'prospecting'],
                    ['label' => 'Offer and pricing defined', 'crm_stage' => 'qualification'],
                    ['label' => 'First outreach sent', 'crm_stage' => 'proposal'],
                    ['label' => 'Follow-up / paid signal pending', 'crm_stage' => 'negotiation'],
                    ['label' => 'Won / learned / paused', 'crm_stage' => 'closed_won or closed_lost'],
                ],
                'lead_sources' => ['whatsapp', 'linkedin', 'email', 'referral', 'founder_community'],
                'task_templates' => [
                    [
                        'title' => 'Finalize first offer and pricing',
                        'description' => 'Turn the idea into one specific paid offer with a price, audience, and outcome.',
                        'priority' => 'urgent',
                        'days_until_due' => 0,
                    ],
                    [
                        'title' => 'Send first 20 warm outreach messages',
                        'description' => 'Contact the first prospect list with the launch offer and record responses.',
                        'priority' => 'high',
                        'days_until_due' => 1,
                    ],
                    [
                        'title' => 'Follow up after launch offer message',
                        'description' => 'Follow up with prospects who saw the offer but have not replied or decided.',
                        'priority' => 'high',
                        'days_until_due' => 2,
                    ],
                    [
                        'title' => 'Complete first weekly review',
                        'description' => 'Record what was learned, what moved, and the next weekly founder commitments.',
                        'priority' => 'medium',
                        'days_until_due' => 7,
                    ],
                ],
                'product_templates' => [
                    [
                        'name' => 'Clarity Sprint Offer',
                        'description' => 'A focused one-session paid offer that turns a customer problem into a practical next step.',
                        'category' => 'Service',
                        'pricing_info' => '$49-$99 one-time until the founder has stronger pricing evidence.',
                    ],
                    [
                        'name' => '30-Day AI Cofounder Launch',
                        'description' => 'Guided launch support for offer, pricing, first prospects, follow-up, and weekly review.',
                        'category' => 'Service',
                        'pricing_info' => '$149-$299 one-time, sold manually before self-serve billing is added.',
                    ],
                ],
                'tags' => [
                    ['name' => 'idea-clarity', 'color' => '#2563eb', 'description' => 'The founder is still clarifying customer, problem, or offer.'],
                    ['name' => 'offer-priced', 'color' => '#22c55e', 'description' => 'The first offer has a visible price or pricing path.'],
                    ['name' => 'first-outreach', 'color' => '#f59e0b', 'description' => 'The prospect list or first outreach action is active.'],
                    ['name' => 'paid-signal', 'color' => '#dc2626', 'description' => 'A serious buyer conversation, quote, deposit, or paid sprint signal is pending.'],
                ],
                'custom_fields' => [
                    ['field_name' => 'Founder Stage', 'field_type' => 'select', 'field_options' => ['Idea', 'Offer Drafted', 'Outreach Started', 'Paid Signal', 'Customer Won'], 'module' => 'contacts'],
                    ['field_name' => 'Warmth Level', 'field_type' => 'select', 'field_options' => ['Warm', 'Referral', 'Community', 'Cold'], 'module' => 'contacts'],
                    ['field_name' => 'Paid Signal Type', 'field_type' => 'select', 'field_options' => ['Conversation', 'Quote', 'Deposit', 'Paid Sprint', 'Referral'], 'module' => 'contacts'],
                ],
                'onboarding_hints' => [
                    'company_profile_complete' => 'Write the founder idea in plain language so Clarity can guide customer, offer, and action decisions.',
                    'service_catalog_ready' => 'Save one first offer with pricing before asking Clarity for outreach or scaling advice.',
                    'contact_import_ready' => 'Add the first 20 people or organizations the founder can contact this week.',
                    'task_ownership_ready' => 'Assign founder-owned tasks for offer, outreach, follow-up, and weekly review.',
                    'channels_ready' => 'Connect WhatsApp or email once the first outreach motion is ready to track real replies.',
                    'follow_up_workflow_ready' => 'Create follow-up tasks after the first offer message so interested prospects do not go cold.',
                    'digest_ready' => 'Use the digest only after the founder has real prospects, replies, or weekly commitments to review.',
                ],
                'demo_operator_checklist' => [
                    'Open with the AI cofounder promise: clarify the idea, decide the next move, and help implement it.',
                    'Show the first offer and pricing task before opening broader CRM screens.',
                    'Open the warm prospect deal and show the launch follow-up task.',
                    'Show the weekly review task as the consistency mechanism.',
                    'Finish with WhatsApp/email follow-up as the execution engine, not the headline.',
                ],
            ],
            self::NICHE_INTERIORS_CONTRACTORS => [
                'key' => self::NICHE_INTERIORS_CONTRACTORS,
                'label' => 'Interiors / contractors',
                'summary' => 'Built for quote-led, project-led businesses that sell through WhatsApp, email, site visits, and follow-up.',
                'workspace_summary' => 'Package Clarity CRM for interiors and contractors, keep quote follow-up visible, and onboard the first five customers with a repeatable project-sales workflow.',
                'audience' => 'Owner-led interiors, fit-out, renovation, and contractor teams.',
                'primary_workflow' => 'Quote and project follow-up',
                'sales_motion' => 'Inquiry to site visit to quote to approval',
                'primary_channels' => ['WhatsApp', 'Email', 'Site visit'],
                'best_fit' => [
                    'Teams selling custom projects with multiple approval steps.',
                    'Operators who need better visibility after site visits and quote sends.',
                ],
                'operator_watchouts' => [
                    'Quotes often stall after verbal interest when no approval owner is clear.',
                    'Site visits and measurements can happen, but the next task never gets pinned down.',
                ],
                'first_demo_win' => 'Show the overdue quote follow-up and how Clarity makes the next action owner visible.',
                'default_company_industry' => 'Interiors / Contracting',
                'default_company_tagline' => 'Quote-led sales and project follow-up in one workflow.',
                'default_icp_pain_points' => 'Missed quote follow-up, delayed site visits, stalled approvals, and scattered WhatsApp/email conversations.',
                'default_icp_channels' => 'WhatsApp, email, referrals, Instagram',
                'empty_state_copy' => [
                    'general' => 'Import your first leads or create a sample inquiry for the onboarding walkthrough.',
                    'deals' => 'Create a sample quote opportunity so the team can see how inquiry, site visit, and approval stages map into the pipeline.',
                ],
                'demo_seed_profile' => 'interiors_contractor',
                'demo_scenarios' => [
                    [
                        'key' => 'interiors_quote_revision',
                        'label' => 'Quote revision follow-up',
                        'short_pitch' => 'Show a quote-stage project where the client wants one change before approval.',
                        'first_demo_win' => 'Show the overdue quote follow-up and how Clarity makes the next action owner visible.',
                        'storyline' => 'A kitchen remodel quote is already out, the client asks for a revised finish option, and the team needs to follow up before the project goes cold.',
                        'seed_profile' => 'interiors_contractor',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 3, 'tasks' => 3, 'communications' => 2],
                        'operator_checklist' => [
                            'Open the proposal-stage deal and show the updated quote request.',
                            'Show the overdue follow-up task and the clear owner.',
                            'Open the client thread and explain what reply should happen next.',
                        ],
                        'recommended_package' => self::PACKAGE_CORE,
                        'recommended_launch_model' => 'service_led_saas',
                        'recommended_success_milestone' => 'first_3_active_deals',
                        'dominant_channel' => 'WhatsApp',
                    ],
                    [
                        'key' => 'interiors_site_visit_pipeline',
                        'label' => 'Site-visit pipeline',
                        'short_pitch' => 'Show how site visits, measurements, and quote prep are coordinated.',
                        'first_demo_win' => 'Start from a site-visit lead and show how Clarity converts measurements into the next proposal step.',
                        'storyline' => 'An office fit-out lead is qualified, a site visit is booked, and the operator needs a clean handoff from visit preparation into quote delivery.',
                        'seed_profile' => 'interiors_contractor',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 3, 'tasks' => 3, 'communications' => 2],
                        'operator_checklist' => [
                            'Open the qualification-stage deal tied to the site visit.',
                            'Show the checklist task that keeps measurements and scope clear.',
                            'Explain how the pipeline stage changes after the visit.',
                        ],
                        'recommended_package' => self::PACKAGE_CORE,
                        'recommended_launch_model' => 'done_for_you_setup',
                        'recommended_success_milestone' => 'first_5_customers',
                        'dominant_channel' => 'Site visit',
                    ],
                    [
                        'key' => 'interiors_stalled_approval',
                        'label' => 'Stalled approval',
                        'short_pitch' => 'Show a near-won project stuck on internal approval.',
                        'first_demo_win' => 'Highlight the negotiation-stage deal and the approval-chase task before momentum drops.',
                        'storyline' => 'The client said yes verbally, but procurement or budget approval has gone quiet and the operator needs to recover the deal.',
                        'seed_profile' => 'interiors_contractor',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 3, 'tasks' => 3, 'communications' => 2],
                        'operator_checklist' => [
                            'Open the negotiation-stage deal with high probability.',
                            'Show the pending approval task and close timeline risk.',
                            'Use the conversation thread to show exactly where the stall happened.',
                        ],
                        'recommended_package' => self::PACKAGE_GROWTH,
                        'recommended_launch_model' => 'pilot_rollout',
                        'recommended_success_milestone' => 'first_3_active_deals',
                        'dominant_channel' => 'Email',
                    ],
                ],
                'demo_seed_description' => 'Quote-led demo with a site visit, quote-stage opportunity, overdue follow-up, and delayed approval storyline.',
                'stage_mapping' => [
                    ['label' => 'Inquiry', 'crm_stage' => 'prospecting'],
                    ['label' => 'Site visit / qualification', 'crm_stage' => 'qualification'],
                    ['label' => 'Quote sent', 'crm_stage' => 'proposal'],
                    ['label' => 'Negotiation / approvals', 'crm_stage' => 'negotiation'],
                    ['label' => 'Won / lost', 'crm_stage' => 'closed_won or closed_lost'],
                ],
                'lead_sources' => ['whatsapp', 'email', 'walk_in', 'referral', 'instagram'],
                'task_templates' => [
                    [
                        'title' => 'Confirm site visit and measurements',
                        'description' => 'Lock in the site visit, confirm scope, and attach the follow-up owner.',
                        'priority' => 'high',
                        'days_until_due' => 1,
                    ],
                    [
                        'title' => 'Send quote follow-up after 48 hours',
                        'description' => 'Check whether the client reviewed the quote and surface any blockers.',
                        'priority' => 'urgent',
                        'days_until_due' => 2,
                    ],
                    [
                        'title' => 'Chase pending approval before project stalls',
                        'description' => 'Reach out before the opportunity goes cold after verbal interest.',
                        'priority' => 'high',
                        'days_until_due' => 4,
                    ],
                ],
                'product_templates' => [
                    [
                        'name' => 'Site Visit and Measurement',
                        'description' => 'Qualified site visit, measurements, and project notes for quotes.',
                        'category' => 'Service',
                        'pricing_info' => 'Charged per visit or bundled into the signed project quote.',
                    ],
                    [
                        'name' => 'Custom Joinery / Fit-Out Quote',
                        'description' => 'Detailed quote covering fabrication, materials, and finishing.',
                        'category' => 'Service',
                        'pricing_info' => 'Priced per project scope with optional staged payment terms.',
                    ],
                ],
                'tags' => [
                    ['name' => 'site-visit', 'color' => '#2563eb', 'description' => 'Opportunity requires a site visit or measurement.'],
                    ['name' => 'quote-sent', 'color' => '#f59e0b', 'description' => 'Quote has been sent and follow-up is expected.'],
                    ['name' => 'approval-pending', 'color' => '#7c3aed', 'description' => 'Customer is reviewing approval or budget.'],
                    ['name' => 'follow-up-urgent', 'color' => '#dc2626', 'description' => 'Follow-up window is at risk of going cold.'],
                ],
                'custom_fields' => [
                    ['field_name' => 'Project Type', 'field_type' => 'select', 'field_options' => ['Kitchen', 'Wardrobe', 'Office Fit-Out', 'Renovation', 'Aluminium / Windows'], 'module' => 'contacts'],
                    ['field_name' => 'Site Visit Date', 'field_type' => 'date', 'field_options' => [], 'module' => 'contacts'],
                    ['field_name' => 'Approval Stage', 'field_type' => 'select', 'field_options' => ['Awaiting quote review', 'Awaiting budget approval', 'Awaiting design sign-off', 'Approved'], 'module' => 'contacts'],
                ],
                'onboarding_hints' => [
                    'company_profile_complete' => 'Describe your project-sales business clearly so the team can pitch and qualify inquiries consistently.',
                    'service_catalog_ready' => 'Add at least one service or quote package with pricing context the team can send to customers.',
                    'contact_import_ready' => 'Import or create a few live project leads so the team can practice quote follow-up.',
                    'task_ownership_ready' => 'Assign a clear owner for site visits, quotes, and stalled approvals.',
                    'channels_ready' => 'Connect WhatsApp or email so real customer follow-up can be tracked.',
                    'follow_up_workflow_ready' => 'Create due-dated quote and approval follow-up steps so opportunities do not stall.',
                    'digest_ready' => 'Validate the digest if the Growth plan will be used to coach daily priorities.',
                ],
                'demo_operator_checklist' => [
                    'Confirm Clarity Core vs Growth positioning.',
                    'Start with a WhatsApp/email inquiry and show the linked contact record.',
                    'Show the quote-stage deal and the overdue follow-up task.',
                    'Open the stalled approval contact and highlight the next action owner.',
                    'Finish with the daily digest / AI guidance story if Growth is in scope.',
                ],
            ],
            self::NICHE_WHATSAPP_HEAVY_SMB => [
                'key' => self::NICHE_WHATSAPP_HEAVY_SMB,
                'label' => 'WhatsApp-heavy businesses',
                'summary' => 'Built for chat-first SMBs that handle most inbound sales conversations in WhatsApp and need faster follow-up, clearer ownership, and fewer dropped conversations.',
                'workspace_summary' => 'Package Clarity CRM for WhatsApp-heavy SMBs, keep reply speed and follow-up ownership visible, and onboard the first five customers with a repeatable chat-led sales workflow.',
                'audience' => 'General SMBs that close demand through inbound chat conversations.',
                'primary_workflow' => 'Lead follow-up',
                'sales_motion' => 'New chat to qualified conversation to pricing to decision',
                'primary_channels' => ['WhatsApp', 'Instagram', 'Facebook'],
                'best_fit' => [
                    'Businesses where most new leads start in WhatsApp rather than forms.',
                    'Teams that need faster first replies and cleaner follow-up ownership.',
                ],
                'operator_watchouts' => [
                    'Chats go cold after pricing is shared because nobody owns the next reply window.',
                    'High-intent leads disappear when WhatsApp follow-up lives in personal phones or memory.',
                ],
                'first_demo_win' => 'Start with a fresh WhatsApp inquiry, then show the overdue reply risk before the chat goes cold.',
                'default_company_industry' => 'WhatsApp-led SMB Sales',
                'default_company_tagline' => 'Chat-first lead follow-up and reply ownership in one workflow.',
                'default_icp_pain_points' => 'Slow replies, dropped WhatsApp conversations, unclear follow-up ownership, and leads going cold after pricing is shared.',
                'default_icp_channels' => 'WhatsApp, Instagram, Facebook, referrals',
                'empty_state_copy' => [
                    'general' => 'Import your first chat leads or create a sample WhatsApp inquiry for the onboarding walkthrough.',
                    'deals' => 'Create a sample chat-led opportunity so the team can see how inquiry, pricing follow-up, and stalled replies map into the pipeline.',
                ],
                'demo_seed_profile' => 'whatsapp_heavy_smb',
                'demo_scenarios' => [
                    [
                        'key' => 'whatsapp_fresh_inquiry',
                        'label' => 'Fresh WhatsApp inquiry',
                        'short_pitch' => 'Lead with first-response speed and clear ownership for a new inbound chat.',
                        'first_demo_win' => 'Start with a fresh WhatsApp inquiry, then show the overdue reply risk before the chat goes cold.',
                        'storyline' => 'A buyer sends a fresh WhatsApp inquiry and the team needs to capture the lead, respond quickly, and assign a reply owner.',
                        'seed_profile' => 'whatsapp_heavy_smb',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 3, 'tasks' => 3, 'communications' => 3],
                        'operator_checklist' => [
                            'Open the newest inbound chat and show how it became a contact.',
                            'Highlight the SLA / reply urgency and who owns the next response.',
                            'Show how the chat turns into a tracked deal.',
                        ],
                        'recommended_package' => self::PACKAGE_CORE,
                        'recommended_launch_model' => 'service_led_saas',
                        'recommended_success_milestone' => 'first_10_qualified_leads',
                        'dominant_channel' => 'WhatsApp',
                    ],
                    [
                        'key' => 'whatsapp_pricing_followup',
                        'label' => 'Pricing follow-up',
                        'short_pitch' => 'Show what happens after pricing is shared and the chat goes quiet.',
                        'first_demo_win' => 'Show the pricing-shared opportunity and the exact follow-up task that keeps momentum alive.',
                        'storyline' => 'Pricing has already been sent in chat, but the lead has not replied and the team needs a structured re-engagement step.',
                        'seed_profile' => 'whatsapp_heavy_smb',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 3, 'tasks' => 3, 'communications' => 3],
                        'operator_checklist' => [
                            'Open the proposal-stage deal created from the chat.',
                            'Show the pricing follow-up task and due date.',
                            'Open the conversation thread to demonstrate the assistant reply flow.',
                        ],
                        'recommended_package' => self::PACKAGE_GROWTH,
                        'recommended_launch_model' => 'pilot_rollout',
                        'recommended_success_milestone' => 'first_3_active_deals',
                        'dominant_channel' => 'WhatsApp',
                    ],
                    [
                        'key' => 'whatsapp_stalled_reengagement',
                        'label' => 'Stalled re-engagement',
                        'short_pitch' => 'Show how Clarity surfaces high-intent chats that are about to go cold.',
                        'first_demo_win' => 'Open a quiet but high-intent chat and show the re-engagement workflow before the lead is lost.',
                        'storyline' => 'A strong buyer has gone silent after asking key questions, and the operator needs to recover the conversation with urgency.',
                        'seed_profile' => 'whatsapp_heavy_smb',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 3, 'tasks' => 3, 'communications' => 3],
                        'operator_checklist' => [
                            'Open the stalled chat with high-intent tagging.',
                            'Show the re-engagement task and triage / owner cues.',
                            'Explain how the digest or AI prioritization would surface it daily.',
                        ],
                        'recommended_package' => self::PACKAGE_GROWTH,
                        'recommended_launch_model' => 'done_for_you_setup',
                        'recommended_success_milestone' => 'first_10_qualified_leads',
                        'dominant_channel' => 'WhatsApp',
                    ],
                ],
                'demo_seed_description' => 'Lead-follow-up demo with a fresh inbound WhatsApp inquiry, a pricing-shared opportunity, and a stalled chat needing re-engagement.',
                'stage_mapping' => [
                    ['label' => 'New WhatsApp inquiry', 'crm_stage' => 'prospecting'],
                    ['label' => 'Qualified conversation', 'crm_stage' => 'qualification'],
                    ['label' => 'Offer / pricing shared', 'crm_stage' => 'proposal'],
                    ['label' => 'Follow-up / decision pending', 'crm_stage' => 'negotiation'],
                    ['label' => 'Won / lost', 'crm_stage' => 'closed_won or closed_lost'],
                ],
                'lead_sources' => ['whatsapp', 'referral', 'instagram', 'facebook', 'walk_in'],
                'task_templates' => [
                    [
                        'title' => 'Respond to new WhatsApp inquiry within SLA',
                        'description' => 'Acknowledge the lead quickly, capture the ask, and assign the reply owner.',
                        'priority' => 'urgent',
                        'days_until_due' => 0,
                    ],
                    [
                        'title' => 'Send follow-up after pricing is shared',
                        'description' => 'Check whether the customer has reviewed the offer and surface the next action.',
                        'priority' => 'high',
                        'days_until_due' => 1,
                    ],
                    [
                        'title' => 'Re-engage stalled chat before it goes cold',
                        'description' => 'Reach out to a high-intent lead whose WhatsApp conversation has gone quiet.',
                        'priority' => 'high',
                        'days_until_due' => 2,
                    ],
                ],
                'product_templates' => [
                    [
                        'name' => 'WhatsApp Inquiry Handling',
                        'description' => 'Structured handling for inbound WhatsApp sales conversations and lead capture.',
                        'category' => 'Service',
                        'pricing_info' => 'Used as the core chat-response workflow for first-contact qualification.',
                    ],
                    [
                        'name' => 'Sales Follow-Up Workflow',
                        'description' => 'Structured follow-up after pricing, offers, or promises made in chat.',
                        'category' => 'Service',
                        'pricing_info' => 'Configured around response windows, pricing follow-up, and re-engagement steps.',
                    ],
                ],
                'tags' => [
                    ['name' => 'whatsapp-new', 'color' => '#22c55e', 'description' => 'Fresh WhatsApp inquiry awaiting first response.'],
                    ['name' => 'pricing-shared', 'color' => '#f59e0b', 'description' => 'Pricing or offer has been shared in chat.'],
                    ['name' => 'reply-overdue', 'color' => '#dc2626', 'description' => 'Reply SLA has been missed or follow-up is overdue.'],
                    ['name' => 'high-intent-chat', 'color' => '#2563eb', 'description' => 'Lead shows strong intent and should be prioritized.'],
                ],
                'custom_fields' => [
                    ['field_name' => 'Preferred Reply Window', 'field_type' => 'select', 'field_options' => ['Within 15 minutes', 'Within 1 hour', 'Same day', 'Next day'], 'module' => 'contacts'],
                    ['field_name' => 'Last WhatsApp Reply At', 'field_type' => 'date', 'field_options' => [], 'module' => 'contacts'],
                    ['field_name' => 'Lead Intent', 'field_type' => 'select', 'field_options' => ['Low', 'Medium', 'High', 'Ready to buy'], 'module' => 'contacts'],
                ],
                'onboarding_hints' => [
                    'company_profile_complete' => 'Describe the chat-first offer clearly so everyone replies from the same playbook.',
                    'service_catalog_ready' => 'Save at least one offer or pricing path the team can send quickly in WhatsApp.',
                    'contact_import_ready' => 'Import or create inbound WhatsApp leads so the team can test chat follow-up.',
                    'task_ownership_ready' => 'Assign a clear reply owner so new chats and follow-ups never sit unowned.',
                    'channels_ready' => 'Connect WhatsApp first so real customer chats and response gaps are visible.',
                    'follow_up_workflow_ready' => 'Create reply-window and re-engagement tasks so promising chats do not go cold.',
                    'digest_ready' => 'Validate the digest if Growth will be used to surface urgent chats each morning.',
                ],
                'demo_operator_checklist' => [
                    'Open with a fresh inbound WhatsApp inquiry and show how it becomes a contact.',
                    'Show the linked owner and the response SLA / overdue reply risk.',
                    'Show a pricing-shared opportunity and the next follow-up task.',
                    'Open the stalled chat and show how Clarity surfaces the re-engagement action.',
                    'Finish with digest or prioritization if the Growth package is in scope.',
                ],
            ],
            self::NICHE_AGENCIES => [
                'key' => self::NICHE_AGENCIES,
                'label' => 'Agencies',
                'summary' => 'Built for agencies that manage inbound leads, discovery, proposals, approvals, and client kickoff across WhatsApp and email.',
                'workspace_summary' => 'Package Clarity CRM for agencies, keep proposal follow-up and client handoff visible, and onboard the first five customers with a repeatable service-sales workflow.',
                'audience' => 'Creative, marketing, and service agencies running proposal-led sales.',
                'primary_workflow' => 'Proposal follow-up and kickoff handoff',
                'sales_motion' => 'Inquiry to discovery to proposal to kickoff',
                'primary_channels' => ['Email', 'WhatsApp', 'LinkedIn'],
                'best_fit' => [
                    'Teams selling retainers or scoped projects with multiple proposal touchpoints.',
                    'Agency owners who need clearer visibility between discovery, approval, and kickoff.',
                ],
                'operator_watchouts' => [
                    'Proposals get sent, but nobody follows up with a clear owner or date.',
                    'Deals feel won verbally, then kickoff details stay stuck in inboxes and chats.',
                ],
                'first_demo_win' => 'Show the proposal-stage deal first, then the kickoff handoff task that keeps momentum from dropping.',
                'default_company_industry' => 'Agency Services',
                'default_company_tagline' => 'Client follow-up, proposals, and kickoff ownership in one workflow.',
                'default_icp_pain_points' => 'Scattered client conversations, slow proposal follow-up, unclear ownership after verbal yes, and kickoff details living in inboxes.',
                'default_icp_channels' => 'Email, WhatsApp, LinkedIn, referrals',
                'empty_state_copy' => [
                    'general' => 'Import your first leads or create a sample client inquiry for the onboarding walkthrough.',
                    'deals' => 'Create a sample agency opportunity so the team can see how discovery, proposal, approval, and kickoff map into the pipeline.',
                ],
                'demo_seed_profile' => 'agencies',
                'demo_scenarios' => [
                    [
                        'key' => 'agency_new_brief',
                        'label' => 'New client brief',
                        'short_pitch' => 'Start with a fresh inquiry and move into discovery ownership.',
                        'first_demo_win' => 'Open the new inquiry and show how discovery gets assigned and tracked immediately.',
                        'storyline' => 'A new prospective client submits a brief, and the team needs to qualify the opportunity and keep discovery from being lost in inboxes.',
                        'seed_profile' => 'agencies',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 3, 'tasks' => 3, 'communications' => 3],
                        'operator_checklist' => [
                            'Open the newest agency inquiry and show the linked contact.',
                            'Highlight discovery ownership and the first follow-up task.',
                            'Explain how the deal enters the proposal path.',
                        ],
                        'recommended_package' => self::PACKAGE_CORE,
                        'recommended_launch_model' => 'service_led_saas',
                        'recommended_success_milestone' => 'first_10_qualified_leads',
                        'dominant_channel' => 'Email',
                    ],
                    [
                        'key' => 'agency_proposal_chase',
                        'label' => 'Proposal chase',
                        'short_pitch' => 'Show proposal-stage visibility and follow-up discipline after scope is sent.',
                        'first_demo_win' => 'Show the proposal-stage deal first, then the overdue proposal follow-up task.',
                        'storyline' => 'A scope and proposal are already out, but the client has not responded and the account team needs a clean chase motion.',
                        'seed_profile' => 'agencies',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 3, 'tasks' => 3, 'communications' => 3],
                        'operator_checklist' => [
                            'Open the proposal-stage opportunity and show probability/value.',
                            'Show the overdue proposal follow-up task.',
                            'Use the thread to demonstrate AI-assisted draft replies.',
                        ],
                        'recommended_package' => self::PACKAGE_GROWTH,
                        'recommended_launch_model' => 'pilot_rollout',
                        'recommended_success_milestone' => 'first_3_active_deals',
                        'dominant_channel' => 'Email',
                    ],
                    [
                        'key' => 'agency_kickoff_handoff',
                        'label' => 'Kickoff handoff',
                        'short_pitch' => 'Show the moment after verbal yes when execution can still slip.',
                        'first_demo_win' => 'Highlight the negotiation/won handoff and the kickoff task that protects momentum.',
                        'storyline' => 'The client has effectively approved, but kickoff details and ownership need to be locked before the team loses momentum.',
                        'seed_profile' => 'agencies',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 3, 'tasks' => 3, 'communications' => 3],
                        'operator_checklist' => [
                            'Open the late-stage deal and show why kickoff is still pending.',
                            'Show the kickoff handoff task and clear owner.',
                            'Explain how Clarity keeps post-approval execution visible.',
                        ],
                        'recommended_package' => self::PACKAGE_GROWTH,
                        'recommended_launch_model' => 'done_for_you_setup',
                        'recommended_success_milestone' => 'first_5_customers',
                        'dominant_channel' => 'Email',
                    ],
                ],
                'demo_seed_description' => 'Agency demo with a fresh inbound inquiry, a proposal-stage opportunity, a stalled approval, and a kickoff handoff task.',
                'stage_mapping' => [
                    ['label' => 'New inquiry', 'crm_stage' => 'prospecting'],
                    ['label' => 'Discovery / qualification', 'crm_stage' => 'qualification'],
                    ['label' => 'Proposal / scope sent', 'crm_stage' => 'proposal'],
                    ['label' => 'Approval / kickoff pending', 'crm_stage' => 'negotiation'],
                    ['label' => 'Won / lost', 'crm_stage' => 'closed_won or closed_lost'],
                ],
                'lead_sources' => ['referral', 'website', 'linkedin', 'email', 'whatsapp'],
                'task_templates' => [
                    [
                        'title' => 'Respond to new agency inquiry',
                        'description' => 'Acknowledge the lead, qualify the service need, and assign the relationship owner.',
                        'priority' => 'high',
                        'days_until_due' => 0,
                    ],
                    [
                        'title' => 'Follow up on proposal after 48 hours',
                        'description' => 'Check whether the client reviewed the scope and pricing, and surface blockers.',
                        'priority' => 'urgent',
                        'days_until_due' => 2,
                    ],
                    [
                        'title' => 'Confirm kickoff handoff after verbal yes',
                        'description' => 'Lock in kickoff timing, owner, and first deliverables before momentum drops.',
                        'priority' => 'high',
                        'days_until_due' => 1,
                    ],
                ],
                'product_templates' => [
                    [
                        'name' => 'Discovery Call and Brief',
                        'description' => 'Structured discovery session to capture client goals, budget, and scope before proposing work.',
                        'category' => 'Service',
                        'pricing_info' => 'Included in the sales process or packaged as a paid audit / strategy session.',
                    ],
                    [
                        'name' => 'Proposal and Scope Workflow',
                        'description' => 'Proposal packaging, follow-up, approval, and kickoff planning for agency engagements.',
                        'category' => 'Service',
                        'pricing_info' => 'Configured around proposal turnaround, approval follow-up, and kickoff handoff timing.',
                    ],
                ],
                'tags' => [
                    ['name' => 'new-brief', 'color' => '#2563eb', 'description' => 'Fresh client inquiry or brief awaiting first response.'],
                    ['name' => 'proposal-sent', 'color' => '#f59e0b', 'description' => 'Proposal or scope has been shared and follow-up is due.'],
                    ['name' => 'approval-pending', 'color' => '#7c3aed', 'description' => 'Client approval or procurement sign-off is still pending.'],
                    ['name' => 'kickoff-pending', 'color' => '#dc2626', 'description' => 'Deal is verbally won but kickoff ownership or timing is not locked in.'],
                ],
                'custom_fields' => [
                    ['field_name' => 'Service Line', 'field_type' => 'select', 'field_options' => ['Branding', 'Web Design', 'Paid Media', 'Content', 'Retainer'], 'module' => 'contacts'],
                    ['field_name' => 'Proposal Status', 'field_type' => 'select', 'field_options' => ['Drafting', 'Sent', 'Under review', 'Approved'], 'module' => 'contacts'],
                    ['field_name' => 'Kickoff Date', 'field_type' => 'date', 'field_options' => [], 'module' => 'contacts'],
                ],
                'onboarding_hints' => [
                    'company_profile_complete' => 'Describe your agency offer clearly so discovery, proposals, and handoff all follow the same playbook.',
                    'service_catalog_ready' => 'Add at least one core service or proposal package with pricing context the team can send quickly.',
                    'contact_import_ready' => 'Import or create a few real agency leads so the team can practice proposal follow-up.',
                    'task_ownership_ready' => 'Assign a clear owner for discovery, proposal follow-up, and kickoff handoff.',
                    'channels_ready' => 'Connect email first, then WhatsApp if client conversations regularly continue in chat.',
                    'follow_up_workflow_ready' => 'Create proposal and kickoff follow-up tasks so clients do not stall after interest is shown.',
                    'digest_ready' => 'Validate the digest if Growth will be used to surface approvals, stalled proposals, and kickoff priorities.',
                ],
                'demo_operator_checklist' => [
                    'Open with a fresh inbound client inquiry and show the linked contact and owner.',
                    'Show the discovery / qualification stage and the proposal-stage opportunity.',
                    'Open the stalled approval scenario and highlight the overdue follow-up task.',
                    'Show the kickoff handoff task so the team sees what happens after verbal yes.',
                    'Finish with digest or prioritization if the Growth package is in scope.',
                ],
            ],
            self::NICHE_DISTRIBUTORS_WHOLESALERS => [
                'key' => self::NICHE_DISTRIBUTORS_WHOLESALERS,
                'label' => 'Distributors / wholesalers',
                'summary' => 'Built for stock-moving businesses that handle repeat inquiries, pricing, availability, and reorder follow-up across WhatsApp, email, and calls.',
                'workspace_summary' => 'Package Clarity CRM for distributors and wholesalers, keep quote-to-reorder follow-up visible, and onboard the first five customers with a repeatable availability-and-pricing workflow.',
                'audience' => 'Distribution and wholesale teams serving repeat buyers and stock inquiries.',
                'primary_workflow' => 'Availability, pricing, and reorder follow-up',
                'sales_motion' => 'Stock inquiry to pricing to confirmation to reorder',
                'primary_channels' => ['WhatsApp', 'Email', 'Phone'],
                'best_fit' => [
                    'Businesses where repeat buyers ask for pricing and availability across multiple channels.',
                    'Teams that need better follow-up after price lists, quotes, or reorder windows.',
                ],
                'operator_watchouts' => [
                    'Regular buyers go quiet after a price list because no reorder task is scheduled.',
                    'Availability and confirmation details get scattered between calls, WhatsApp, and email.',
                ],
                'first_demo_win' => 'Show the price-list-shared deal and how Clarity flags reorder risk before the buyer slips away.',
                'default_company_industry' => 'Distribution / Wholesale',
                'default_company_tagline' => 'Availability, pricing, and reorder follow-up in one workflow.',
                'default_icp_pain_points' => 'Slow pricing replies, stock and availability questions scattered across channels, repeat buyers going quiet after quotes, and no clear owner for reorders.',
                'default_icp_channels' => 'WhatsApp, email, phone, referrals',
                'empty_state_copy' => [
                    'general' => 'Import your first buyers or create a sample stock inquiry for the onboarding walkthrough.',
                    'deals' => 'Create a sample distribution opportunity so the team can see how inquiry, pricing, reorder, and confirmation map into the pipeline.',
                ],
                'demo_seed_profile' => 'distributors_wholesalers',
                'demo_scenarios' => [
                    [
                        'key' => 'distribution_stock_inquiry',
                        'label' => 'Stock inquiry',
                        'short_pitch' => 'Start with a buyer asking for availability and show how response ownership is kept tight.',
                        'first_demo_win' => 'Open the stock inquiry and show how availability questions turn into a tracked deal and task.',
                        'storyline' => 'A buyer asks for stock and quantities, and the team needs to respond quickly while keeping the inquiry tied to the account owner.',
                        'seed_profile' => 'distributors_wholesalers',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 3, 'tasks' => 3, 'communications' => 3],
                        'operator_checklist' => [
                            'Open the newest stock inquiry and linked buyer record.',
                            'Show the first response / availability task.',
                            'Explain how the inquiry moves into pricing or reorder stages.',
                        ],
                        'recommended_package' => self::PACKAGE_CORE,
                        'recommended_launch_model' => 'service_led_saas',
                        'recommended_success_milestone' => 'first_10_qualified_leads',
                        'dominant_channel' => 'WhatsApp',
                    ],
                    [
                        'key' => 'distribution_price_list_followup',
                        'label' => 'Price-list follow-up',
                        'short_pitch' => 'Show what happens after pricing is shared and order confirmation lags.',
                        'first_demo_win' => 'Show the price-list-shared deal and the exact follow-up step before the buyer stalls.',
                        'storyline' => 'Pricing has already been shared, but the buyer has not confirmed quantities or delivery, and the team needs a structured chase.',
                        'seed_profile' => 'distributors_wholesalers',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 3, 'tasks' => 3, 'communications' => 3],
                        'operator_checklist' => [
                            'Open the proposal-stage or pricing-shared opportunity.',
                            'Show the due follow-up task for confirmation.',
                            'Use the buyer conversation to explain next-step clarity.',
                        ],
                        'recommended_package' => self::PACKAGE_GROWTH,
                        'recommended_launch_model' => 'pilot_rollout',
                        'recommended_success_milestone' => 'first_3_active_deals',
                        'dominant_channel' => 'Email',
                    ],
                    [
                        'key' => 'distribution_reorder_risk',
                        'label' => 'Reorder risk',
                        'short_pitch' => 'Show how repeat buyers are protected from going quiet after the last order.',
                        'first_demo_win' => 'Open the repeat-buyer scenario and show the reorder-risk task before the account slips away.',
                        'storyline' => 'A regular buyer has gone quiet after the last price or delivery conversation, and the operator needs to trigger a timely reorder follow-up.',
                        'seed_profile' => 'distributors_wholesalers',
                        'expected_seed_summary' => ['contacts' => 4, 'deals' => 3, 'tasks' => 3, 'communications' => 3],
                        'operator_checklist' => [
                            'Open the repeat buyer contact with reorder context.',
                            'Show the re-engagement task and risk tag.',
                            'Explain how Clarity makes repeat revenue follow-up visible.',
                        ],
                        'recommended_package' => self::PACKAGE_GROWTH,
                        'recommended_launch_model' => 'done_for_you_setup',
                        'recommended_success_milestone' => 'first_5_customers',
                        'dominant_channel' => 'Phone',
                    ],
                ],
                'demo_seed_description' => 'Distribution demo with a fresh stock inquiry, a pricing-shared reorder opportunity, and a stalled repeat buyer needing follow-up.',
                'stage_mapping' => [
                    ['label' => 'Stock inquiry', 'crm_stage' => 'prospecting'],
                    ['label' => 'Qualified buyer / requirements confirmed', 'crm_stage' => 'qualification'],
                    ['label' => 'Price list / quote shared', 'crm_stage' => 'proposal'],
                    ['label' => 'Reorder / confirmation pending', 'crm_stage' => 'negotiation'],
                    ['label' => 'Won / lost', 'crm_stage' => 'closed_won or closed_lost'],
                ],
                'lead_sources' => ['whatsapp', 'email', 'phone', 'referral', 'walk_in'],
                'task_templates' => [
                    [
                        'title' => 'Reply to stock availability inquiry',
                        'description' => 'Confirm availability, quantities, and assign the account owner quickly.',
                        'priority' => 'high',
                        'days_until_due' => 0,
                    ],
                    [
                        'title' => 'Follow up after price list is shared',
                        'description' => 'Check whether the buyer is ready to confirm quantities, delivery, or payment terms.',
                        'priority' => 'urgent',
                        'days_until_due' => 1,
                    ],
                    [
                        'title' => 'Re-engage stalled repeat buyer',
                        'description' => 'Reach out before a regular buyer delays or takes the order elsewhere.',
                        'priority' => 'high',
                        'days_until_due' => 2,
                    ],
                ],
                'product_templates' => [
                    [
                        'name' => 'Stock Availability and Pricing',
                        'description' => 'Structured handling of product availability, quantity checks, and fast pricing replies.',
                        'category' => 'Service',
                        'pricing_info' => 'Used for fast response on stock, pricing, and delivery windows.',
                    ],
                    [
                        'name' => 'Repeat Buyer Reorder Workflow',
                        'description' => 'Follow-up flow for repeat orders, replenishment reminders, and confirmation chasing.',
                        'category' => 'Service',
                        'pricing_info' => 'Configured around reorders, payment terms, and delivery confirmation timing.',
                    ],
                ],
                'tags' => [
                    ['name' => 'stock-request', 'color' => '#2563eb', 'description' => 'Buyer is asking about availability or quantities.'],
                    ['name' => 'price-list-sent', 'color' => '#f59e0b', 'description' => 'Pricing or quote has been shared and follow-up is due.'],
                    ['name' => 'repeat-buyer', 'color' => '#22c55e', 'description' => 'Existing buyer with reorder potential.'],
                    ['name' => 'reorder-risk', 'color' => '#dc2626', 'description' => 'Repeat order or confirmation is at risk of stalling.'],
                ],
                'custom_fields' => [
                    ['field_name' => 'Product Category', 'field_type' => 'select', 'field_options' => ['Building Materials', 'Electrical', 'Hardware', 'Food & Beverage', 'General Merchandise'], 'module' => 'contacts'],
                    ['field_name' => 'Average Order Size', 'field_type' => 'select', 'field_options' => ['Small', 'Medium', 'Large', 'Bulk'], 'module' => 'contacts'],
                    ['field_name' => 'Reorder Window', 'field_type' => 'select', 'field_options' => ['Weekly', 'Bi-weekly', 'Monthly', 'Ad hoc'], 'module' => 'contacts'],
                ],
                'onboarding_hints' => [
                    'company_profile_complete' => 'Describe your stock, delivery, and buyer types clearly so the team answers inquiries consistently.',
                    'service_catalog_ready' => 'Add at least one product category or pricing workflow the team can use when sharing availability and terms.',
                    'contact_import_ready' => 'Import or create a few active buyers so the team can practice reorder and pricing follow-up.',
                    'task_ownership_ready' => 'Assign a clear owner for stock replies, pricing follow-up, and repeat buyer reorders.',
                    'channels_ready' => 'Connect WhatsApp or email so availability requests and reorder follow-up are visible in one place.',
                    'follow_up_workflow_ready' => 'Create due-dated pricing and reorder tasks so buyers do not go quiet after asking for stock.',
                    'digest_ready' => 'Validate the digest if Growth will be used to surface urgent buyers, overdue confirmations, and reorder gaps.',
                ],
                'demo_operator_checklist' => [
                    'Open with a fresh stock inquiry and show the linked buyer record.',
                    'Show the price-list-shared opportunity and the overdue follow-up task.',
                    'Open the repeat buyer scenario and highlight reorder risk plus next action owner.',
                    'Show the deal stage where confirmation or reorder is pending.',
                    'Finish with digest or prioritization if the Growth package is in scope.',
                ],
            ],
        ];

        foreach ($profiles as $key => $profile) {
            $profiles[$key] = $this->finalizeNicheProfile($profile);
        }

        return $profiles;
    }

    public function getImplementationChecklist(): array
    {
        return [
            [
                'key' => 'workspace_created',
                'label' => 'Workspace created',
                'description' => 'Admin access works and the workspace brand/profile has been initialized.',
                'action_url' => 'settings.php?tab=general',
            ],
            [
                'key' => 'contact_import_complete',
                'label' => 'Contact import complete',
                'description' => 'At least one real contact or pilot inquiry is loaded for onboarding.',
                'action_url' => 'contacts_import.php',
            ],
            [
                'key' => 'pipeline_configured',
                'label' => 'Pipeline configured',
                'description' => 'The team understands how the niche sales motion maps into the CRM pipeline stages.',
                'action_url' => 'deals.php',
            ],
            [
                'key' => 'channels_configured',
                'label' => 'Communication channels configured',
                'description' => 'Email and WhatsApp are configured or their setup gaps are known.',
                'action_url' => 'settings.php?tab=email',
            ],
            [
                'key' => 'users_and_owners_configured',
                'label' => 'Users and owners configured',
                'description' => 'Primary owner, assignee, and handoff coverage are clear.',
                'action_url' => 'users.php',
            ],
            [
                'key' => 'follow_up_workflow_configured',
                'label' => 'First workflows / tasks configured',
                'description' => 'At least one follow-up task flow is live for key customer replies, proposals, or handoff steps.',
                'action_url' => 'tasks.php',
            ],
            [
                'key' => 'digest_validated',
                'label' => 'Digest / assistant validated',
                'description' => 'The team has tested the email assistant or confirmed why it is intentionally off.',
                'action_url' => 'settings.php?tab=email_assistant',
            ],
            [
                'key' => 'handoff_complete',
                'label' => 'Customer handoff complete',
                'description' => 'The operator has delivered the walkthrough, support path, and next review date.',
                'action_url' => 'settings.php',
            ],
        ];
    }
}
