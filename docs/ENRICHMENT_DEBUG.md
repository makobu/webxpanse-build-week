# Enrichment Process Debugging Guide

## Overview

The enrichment process has been instrumented with detailed logging to track each step of the process. This helps identify exactly where failures occur.

## Log File Location

All enrichment debug logs are stored in:
```
.cursor/enrichment_debug.log
```

## Viewing Logs

### Command Line
```bash
php scripts/view_enrichment_log.php
```

This will show the 5 most recent enrichment sessions with detailed step-by-step information.

### Log Format

Each log entry contains:
- `session_id`: Unique identifier for the enrichment session
- `timestamp`: When the step occurred
- `step`: The step name (e.g., `INIT`, `STEP_0`, `STEP_6`, `CONTEXT_START`)
- `message`: Human-readable description
- `data`: Additional context data

## Enrichment Steps Tracked

1. **INIT**: API initialization and database setup
2. **INPUT**: Request parsing and validation
3. **OPTIONS**: Enrichment options configuration
4. **SERVICE**: Service creation
5. **ENRICH_START**: Beginning of enrichment process
6. **ENRICH_CONTACT**: Contact data loaded
7. **STEP_0**: Third-party API enrichment (Clearbit, PDL, Hunter.io)
8. **STEP_1/STEP_2/STEP_2b**: Web/social extraction paths (currently disabled for structured field writes)
9. **STEP_3**: Email extraction path (requires explicit email content; skipped in generic enrich flow)
10. **STEP_5**: Data validation
11. **STEP_6**: AI context generation
    - `CONTEXT_START`: Context generation begins
    - `CONTEXT_LOAD`: Contact loading
    - `CONTEXT_SOURCES`: Enrichment sources fetched
    - `CONTEXT_HISTORY`: Enrichment history fetched
    - `CONTEXT_ANALYZE`: Profile analysis
    - `CONTEXT_INSIGHTS`: Insights identification
    - `CONTEXT_RECOMMENDATIONS`: Recommendations generation
    - `CONTEXT_SUMMARY`: Summary formatting
12. **STEP_7**: Verified-data merge and contact update

## Error Tracking

When an error occurs, the log will include:
- Error message
- File and line number
- Stack trace (if debug mode enabled)
- Session ID for correlation

## Common Issues

### Missing Database Tables
If you see errors about missing tables (`enrichment_sources`, `enrichment_history`), treat this as migration debt that should be fixed immediately. Core enrichment may continue in some paths, but source/history visibility and auditability will be degraded.

### AI Context Generation Failures
If `STEP_6_ERROR` appears, check:
- AI service configuration
- API keys for AI services
- Database connection

### Third-Party API Failures
If `STEP_0` shows errors, check:
- API keys for Clearbit, PDL, Hunter.io
- Network connectivity
- Rate limits

## Debugging Workflow

1. Click "Enrich with AI" button
2. If error occurs, note the session ID from the error message
3. Run `php scripts/view_enrichment_log.php`
4. Find the session ID in the log
5. Review each step to identify where it failed
6. Check the `data` field for additional context

## Example Log Entry

```json
{
  "session_id": "enrich_1234567890",
  "timestamp": "2024-01-15 10:30:45",
  "step": "STEP_6",
  "message": "AI context generated",
  "data": {
    "has_summary": true,
    "insights_count": 3
  }
}
```
