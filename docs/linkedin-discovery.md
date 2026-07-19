# LinkedIn URL Discovery Feature

## Overview

The CRM now includes **automatic LinkedIn URL discovery**, similar to CRMs like Attio. This feature automatically finds LinkedIn profile URLs for your contacts, even when they're not manually provided.

## How It Works

When you enrich a contact, the system uses multiple strategies to discover LinkedIn URLs:

### Strategy 1: Extract from Company Website
- Scans company websites for LinkedIn profile links
- Uses AI to match LinkedIn profiles to the specific contact
- **Confidence:** High (0.8)

### Strategy 2: AI-Powered Discovery
- Uses AI to search and construct LinkedIn URLs based on:
  - Name (first name + last name)
  - Company name
  - Job title
  - Email address
- **Confidence:** Medium-High (0.6-0.8)

### Strategy 3: URL Pattern Construction
- Constructs likely LinkedIn URLs using common patterns:
  - `firstname-lastname`
  - `firstnamelastname`
- **Confidence:** Medium (0.5)
- **Note:** These URLs should be verified manually

## When Discovery Runs

LinkedIn discovery automatically runs when:
1. You click "🔍 Enrich with AI" on a contact
2. The contact **does not have** a LinkedIn URL already
3. The contact has at least a first name or last name

## Benefits

✅ **Automatic Discovery** - No need to manually search for LinkedIn profiles
✅ **Higher Enrichment Scores** - LinkedIn URL adds +10 points to enrichment score
✅ **Better Data Quality** - More complete contact profiles
✅ **Time Saving** - Reduces manual data entry
✅ **Smart Caching** - Results are cached to avoid redundant searches

## Example

**Before Enrichment:**
- Name: John Smith
- Company: Acme Corp
- LinkedIn URL: (empty)

**After Enrichment:**
- Name: John Smith
- Company: Acme Corp
- LinkedIn URL: `https://www.linkedin.com/in/john-smith` ✅ (discovered automatically)
- Job Title: Senior Sales Manager (enriched from LinkedIn)
- Location: San Francisco, CA (enriched from LinkedIn)

## Configuration

LinkedIn discovery is enabled by default. You can control it via enrichment options:

```php
$options = [
    'discover_linkedin' => true,  // Enable/disable LinkedIn discovery
    'extract_social' => true,     // Enable social media enrichment
    'sources' => ['linkedin', ...]
];
```

## Limitations

⚠️ **LinkedIn API Restrictions**
- LinkedIn doesn't provide a public API for profile search
- The system uses AI inference and web scraping (within legal limits)
- Some discovered URLs may need manual verification

⚠️ **Accuracy**
- Constructed URLs (Strategy 3) have lower confidence
- Always verify discovered URLs before using them
- Common names may have multiple LinkedIn profiles

⚠️ **Rate Limiting**
- Discovery is cached to avoid excessive API calls
- Results are cached for 7 days (high confidence) or 3 days (medium confidence)

## Best Practices

1. **Verify Discovered URLs**
   - Check that the LinkedIn profile matches the contact
   - Update if incorrect

2. **Provide Complete Information**
   - More contact data (company, job title) = better discovery accuracy

3. **Use High-Confidence Results**
   - URLs from company websites are most reliable
   - AI-discovered URLs are generally good
   - Constructed URLs should be verified

4. **Regular Re-enrichment**
   - Re-enrich contacts periodically
   - LinkedIn profiles may change over time

## Technical Details

### Discovery Sources
- **Website extraction:** Scans company websites for LinkedIn links
- **AI inference:** Uses AI to search and construct URLs
- **Pattern matching:** Common LinkedIn URL patterns

### Caching
- High-confidence results: 7 days
- Medium-confidence results: 3 days
- Cache key based on: name + company + email

### Logging
All discovery attempts are logged in `enrichment_sources` table:
- Source type: `linkedin_discovery`
- Source URL: Discovered LinkedIn URL
- Confidence score: 0.0-1.0
- Created timestamp

## Related Features

- **Enrichment Score** - LinkedIn URL adds +10 points
- **LinkedIn Enrichment** - Once URL is discovered, profile data is enriched
- **Enrichment Dashboard** - View contacts needing enrichment
- **Batch Enrichment** - Discover LinkedIn URLs for multiple contacts

---

**Last Updated:** 2024
**Version:** 1.0
