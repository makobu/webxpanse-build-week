# Final Devpost field package

Live requirements verified July 19, 2026 for OpenAI Build Week (`challenge_slug: openai`). The project page is `WebXpanse - Clarity`, Devpost project ID `1349031`.

## Deliverables

- Project: `1349031`
- Category: `Work & Productivity`
- Video: public YouTube URL, under three minutes
- Website: not required
- ZIP file: not required

## Required custom answers

| Field ID | Devpost field | Prepared value |
| --- | --- | --- |
| `27945` | Submitter Type | `Individual` |
| `27946` | Country of Residence | `Kenya` |
| `27947` | Category | `Work & Productivity` |
| `27948` | Code repository URL | `https://github.com/makobu/webxpanse-build-week` |
| `27950` | `/feedback` Session ID | `019f74ad-26fd-7713-a474-cb83d9cb101e` |

## Optional judge-testing field (`27949`)

Use this after the repository URL is available:

> Follow the source-evaluation setup in the OpenAI Build Week README. After preparing the evaluation environment and running migrations, create the isolated judging workspace with `php scripts/create_build_week_demo.php --presenter-email=YOUR_OWNER_EMAIL --ttl-hours=24`. The command prints a one-use magic-login URL and temporary credentials. The workspace is simulation-first, does not arm live message delivery, and expires automatically. The public video demonstrates the same flow.

Field `27951` is not applicable because WebXpanse is submitted as a Work & Productivity application, not a plugin or developer tool.

## Final submission payload template

```json
{
  "challenge_slug": "openai",
  "project": "1349031",
  "video_url": "REPLACE_WITH_PUBLIC_YOUTUBE_URL",
  "custom_answers": [
    {"submission_field_id": 27945, "value": "Individual"},
    {"submission_field_id": 27946, "value": ["Kenya"]},
    {"submission_field_id": 27947, "value": "Work & Productivity"},
    {"submission_field_id": 27948, "value": "https://github.com/makobu/webxpanse-build-week"},
    {"submission_field_id": 27949, "value": "REPLACE_WITH_THE_JUDGE_TESTING_TEXT_ABOVE"},
    {"submission_field_id": 27950, "value": "019f74ad-26fd-7713-a474-cb83d9cb101e"}
  ]
}
```

Do not call the final submission action while any `REPLACE_` or bracketed placeholder remains.
