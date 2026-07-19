# Enrichment Score Guide

## Overview

The enrichment score is a 0-100% metric that measures how complete and enriched a contact's profile is. A higher score indicates more complete contact data, which helps with better lead qualification, personalization, and sales effectiveness.

## How the Score is Calculated

The enrichment score is calculated based on four main categories:

### 1. Basic Fields (40 points total)
- **First Name** - 5 points
- **Last Name** - 5 points
- **Email** - 10 points
- **Phone** - 5 points
- **Company** - 10 points
- **Job Title** - 5 points

### 2. Company Enrichment (30 points total)
- **Company Website** - 5 points
- **Company Size** - 5 points
- **Company Industry** - 5 points
- **Company Description** - 5 points
- **Company Founded Year** - 5 points
- **Company Revenue** - 5 points

### 3. Social/Professional (20 points total)
- **LinkedIn URL** - 10 points
- **Twitter URL** - 5 points
- **Location** - 5 points

### 4. Verification (10 points total)
- **Email Verified** - 10 points

**Maximum Score: 100 points**

## How to Improve Your Enrichment Score

### Method 1: Use Automatic Enrichment (Recommended)

1. **Navigate to Contact View**
   - Go to Contacts → Select a contact
   - Click the "🔍 Enrich with AI" button
   - The system will automatically:
     - **Discover LinkedIn URLs** automatically (like Attio) - finds LinkedIn profiles even if not provided
     - Extract data from company websites
     - Enrich from LinkedIn profiles (using discovered or existing URLs)
     - Enrich from Twitter profiles (if Twitter URL exists)
     - Infer missing fields using AI
     - Validate existing data

2. **What Gets Enriched Automatically:**
   - Company information (website, size, industry, description)
   - Professional details (job title, location)
   - Social media profiles
   - Missing contact fields

### Method 2: Manual Data Entry

You can manually add or edit contact information to improve the score:

1. **Edit Contact Fields:**
   - Go to Contact View → Click "Edit Contact"
   - Fill in missing fields:
     - Basic info (name, email, phone, company, job title)
     - Company details (website, size, industry, description, founded year, revenue)
     - Social profiles (LinkedIn URL, Twitter URL)
     - Location
   - Save changes (score recalculates automatically)

2. **Quick Wins for Score Improvement:**
   - Add LinkedIn URL (+10 points) - Highest impact!
   - Add Company Website (+5 points)
   - Add Company Industry (+5 points)
   - Add Location (+5 points)
   - Add Twitter URL (+5 points)

### Method 3: Bulk Enrichment

For multiple contacts:

1. **Use Enrichment Dashboard:**
   - Go to Enrichment Dashboard
   - View contacts with low scores (< 50%)
   - Select contacts and use batch enrichment

2. **Import with Complete Data:**
   - Use CSV import with all enrichment fields
   - Include: LinkedIn URL, Company Website, Industry, etc.

## Score Breakdown Examples

### Low Score (0-30%)
**Missing:** Most basic and enrichment fields
**Action:** Run automatic enrichment + add LinkedIn URL

### Medium Score (31-60%)
**Missing:** Some company details or social profiles
**Action:** Add LinkedIn URL, company website, industry

### Good Score (61-80%)
**Missing:** A few enrichment fields
**Action:** Add remaining company details, verify email

### Excellent Score (81-100%)
**Complete:** All major fields filled
**Maintenance:** Keep data updated, verify email

## Best Practices

### 1. Start with LinkedIn
- LinkedIn URL gives the highest single-point boost (+10 points)
- Enables LinkedIn enrichment for additional data

### 2. Prioritize Company Information
- Company details are worth 30 points total
- Helps with lead qualification and segmentation

### 3. Verify Email Addresses
- Email verification adds 10 points
- Improves deliverability and data quality

### 4. Regular Enrichment
- Re-enrich contacts periodically (quarterly recommended)
- Data changes over time (job titles, companies, etc.)

### 5. Complete Basic Fields First
- Basic fields (40 points) are the foundation
- Ensure name, email, phone, company are filled

## Understanding Score Impact

| Score Range | Quality Level | Use Case |
|------------|---------------|----------|
| 0-30% | Poor | Needs immediate enrichment |
| 31-50% | Fair | Basic contact info only |
| 51-70% | Good | Ready for outreach |
| 71-85% | Very Good | Well-qualified lead |
| 86-100% | Excellent | Fully enriched, ideal for personalization |

## Troubleshooting

### Score Not Updating?
- After manual edits, the score recalculates automatically
- If score doesn't update, refresh the page
- Check that fields are saved (not empty strings)

### Low Score Despite Data?
- Ensure fields are not just spaces
- Check that email is verified (if applicable)
- Verify LinkedIn/Twitter URLs are valid format

### How to Check What's Missing?
1. View contact details page
2. Check enrichment score breakdown
3. Compare with score calculation guide above
4. Fill missing high-value fields first

## API Usage

You can also recalculate scores programmatically:

```php
use CRM\Services\AIEnrichmentService;

$enrichmentService = new AIEnrichmentService();
$score = $enrichmentService->calculateEnrichmentScore($contactId);
```

## Related Features

- **Enrichment Dashboard** - View all contacts needing enrichment
- **Batch Enrichment** - Enrich multiple contacts at once
- **Enrichment History** - Track what data was enriched and when
- **Enrichment Sources** - See where data came from

---

**Last Updated:** 2024
**Version:** 1.0
