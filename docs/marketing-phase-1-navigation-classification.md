# Marketing Product Navigation Classification

The authenticated marketing workspace is split into three independently installed top-level products: Design, Social Media, and Campaign Manager. Each product exposes a short primary path in the navigation bar and keeps specialist tools on its product home. A product is shown only when its dedicated plugin is installed and the user has Marketing read access.

## Design

The Design menu keeps the conversion-surface path short:

- `design.php` - Design Home
- `marketing_landing_pages.php` - Landing Pages
- `forms.php` - Forms
- `marketing_assets.php` - Assets

Creative Production, Brand Library, and SEO Topics remain available from the Advanced Design Tools destination on Design's Assets tab.

## Social Media

The Social Media menu follows the publishing loop:

- `social_media.php` - Social Media Home
- `social_media.php?tab=composer` - Create Post
- `social_media.php?tab=calendar` - Publishing Calendar
- `marketing_reviews.php` - Reviews
- `social_media.php?tab=insights` - Insights

Content Studio, the wider Marketing Calendar, quality checks, distribution, channel exports, UTM links, email runs, and operator export packs remain available from Advanced Social Tools on Social Media Home.

## Campaign Manager

Campaign Manager owns strategy, orchestration, launch control, and measurement. Its primary menu is:

- `marketing.php` - Campaign Home
- `marketing_onboarding.php` - Setup
- `marketing_segments.php` - Audiences
- `marketing_briefs.php` - Campaigns
- `marketing_launch_readiness.php` - Launch
- `marketing_performance.php` - Results

Marketing Assistants is the draft-only AI marketing workspace inside Campaign Manager. Advanced Marketing Operations on Campaign Home contains personas, journeys, playbooks, campaign workspaces, launch controls, automation, attribution, reports, execution, diagnostics, and admin tools.

Detail and edit pages inherit the product family of their parent workflow. Plugin setup stays in Marketplace, but the corresponding top-level product remains active while its setup page is open.

## Public

These public/runtime surfaces stay outside authenticated product navigation:

- `marketing_landing_public.php`
- `marketing_track.php`
- `marketing_unsubscribe.php`
