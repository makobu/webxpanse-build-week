# Third-Party Enrichment APIs Guide

## Overview

For **reliable, accurate contact enrichment**, the CRM now supports integration with professional enrichment APIs. These services provide real, verified data from their databases.

## Available Services

### 1. Clearbit Enrichment API ⭐ **Recommended**
- **Best for:** Comprehensive contact and company data
- **Provides:** Name, email, phone, job title, company info, LinkedIn, Twitter, location
- **Accuracy:** Very High
- **Cost:** Paid (free tier available)
- **Setup:** Add `CLEARBIT_API_KEY` to `.env`

### 2. People Data Labs (PDL)
- **Best for:** Professional profiles and LinkedIn data
- **Provides:** Job titles, company info, LinkedIn URLs, social profiles
- **Accuracy:** High
- **Cost:** Paid
- **Setup:** Add `PDL_API_KEY` to `.env`

### 3. Hunter.io
- **Best for:** Email verification and finding emails
- **Provides:** Email verification, contact data, LinkedIn/Twitter
- **Accuracy:** High for email verification
- **Cost:** Paid (free tier: 25 searches/month)
- **Setup:** Add `HUNTER_API_KEY` to `.env`

## Setup Instructions

### Step 1: Get API Keys

1. **Clearbit:**
   - Sign up at https://clearbit.com
   - Get API key from dashboard
   - Free tier: 100 API calls/month

2. **People Data Labs:**
   - Sign up at https://www.peopledatalabs.com
   - Get API key from dashboard
   - Free tier: 100 API calls/month

3. **Hunter.io:**
   - Sign up at https://hunter.io
   - Get API key from dashboard
   - Free tier: 25 searches/month

### Step 2: Add to .env File

```env
# Third-Party Enrichment APIs
CLEARBIT_API_KEY=your_clearbit_api_key_here
PDL_API_KEY=your_pdl_api_key_here
HUNTER_API_KEY=your_hunter_api_key_here
```

### Step 3: Enable in Enrichment

Third-party enrichment is **enabled by default**. It will automatically:
1. Try Clearbit first (most comprehensive)
2. Fall back to PDL if Clearbit doesn't have data
3. Use Hunter.io for email verification

## How It Works

When you enrich a contact:

1. **Third-Party APIs** (Step 0) - Runs first, most reliable
   - Clearbit: Comprehensive data lookup
   - PDL: Professional profile data
   - Hunter.io: Email verification and domain intelligence
   - These are the only sources currently used to update structured contact fields in the core enrichment flow.

2. **Validation pass** (Step 5)
   - Applies safe corrections to existing values (format/data quality fixes).

3. **AI context generation** (Step 6)
   - Produces insights/recommendations in `ai_context`.
   - Does **not** infer and write structured profile fields.

> Note: Website/social extraction paths are currently disabled for structured field writes in the main enrichment flow to avoid placeholder or inferred profile data.

## Benefits

✅ **Real Data** - Actual verified contact information
✅ **LinkedIn URLs** - Real LinkedIn profiles, not constructed
✅ **Email Verification** - Know if emails are valid
✅ **Company Data** - Accurate company information
✅ **High Accuracy** - Professional databases vs. AI inference

## Cost Considerations

- **Free Tiers Available** - All services offer free tiers
- **Pay-as-you-go** - Only pay for what you use
- **Caching** - Results cached for 7 days to reduce API calls
- **Smart Fallback** - Uses free methods if APIs unavailable

## Example Response

**Clearbit Response:**
```json
{
  "status": "success",
  "source": "clearbit",
  "data": {
    "first_name": "John",
    "last_name": "Smith",
    "email": "john.smith@acme.com",
    "phone": "+1-555-123-4567",
    "job_title": "Senior Sales Manager",
    "company": "Acme Corp",
    "company_website": "https://acme.com",
    "company_size": 150,
    "company_industry": "Technology",
    "linkedin_url": "https://www.linkedin.com/in/john-smith-acme",
    "twitter_url": "https://twitter.com/johnsmith",
    "location": "San Francisco, CA, US"
  }
}
```

## Comparison: AI vs Third-Party APIs

| Feature | AI Inference | Third-Party APIs |
|---------|-------------|------------------|
| **LinkedIn URLs** | Constructed (may be wrong) | Real profiles ✅ |
| **Email Verification** | Not available | Yes ✅ |
| **Company Data** | Inferred | Verified ✅ |
| **Accuracy** | Medium (50-70%) | High (90%+) ✅ |
| **Cost** | Free | Paid (free tiers) |
| **Speed** | Fast | Fast ✅ |

## Recommendations

### For Best Results:
1. **Use Clearbit** - Most comprehensive, best value
2. **Combine with Hunter.io** - For email verification
3. **Use AI context output** - For insights and next actions, not structured field filling

### For Budget-Conscious:
1. **Start with free tiers** - 100-200 contacts/month
2. **Use caching** - Reduces API calls
3. **Prioritize high-value contacts** - Use APIs for important leads

## Troubleshooting

### APIs Not Working?
- Check API keys in `.env` file
- Verify API keys are valid
- Check API rate limits (free tiers have limits)
- Review error logs

### No Data Returned?
- Contact may not be present in provider databases
- Check that API keys and quotas are valid
- Ensure request includes usable identifiers (email or name+domain)
- Expect a no-op structured update when no verified provider data is found

### Rate Limits?
- Results are cached for 7 days
- Free tiers: 25-100 calls/month
- Consider upgrading for higher limits

---

**Last Updated:** 2024
**Version:** 1.0
