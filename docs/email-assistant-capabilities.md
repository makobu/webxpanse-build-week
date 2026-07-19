# Email Assistant Capabilities

The Personal Assistant processes inbound emails sent to the configured system address. It answers questions, executes instructions, and sends styled replies. Each skill can be enabled or disabled in **Settings > Email Assistant > Skills**.

## Permissions and Security

- Only **admin users** or **whitelisted sender emails** can send instructions.
- Configure whitelisted senders in Settings > Email Assistant > Security.
- Each skill has its own toggle; new skills default to **disabled** for safety.

## Skills Overview

| Skill | Env Toggle | Description |
|-------|------------|-------------|
| Q&A | `EMAIL_ASSISTANT_Q&A_ENABLED` | Ask questions about CRM data |
| Create task | `EMAIL_ASSISTANT_INSTRUCTIONS_ENABLED` | Add tasks from email |
| Create contact | `EMAIL_ASSISTANT_SKILL_CREATE_CONTACT` | Add new contacts |
| Update contact | `EMAIL_ASSISTANT_SKILL_UPDATE_CONTACT` | Edit contact fields |
| Delete contact | `EMAIL_ASSISTANT_SKILL_DELETE_CONTACT` | Permanently delete contacts (irreversible) |
| Add note | `EMAIL_ASSISTANT_SKILL_ADD_NOTE` | Add notes to contacts |
| Pipeline status | `EMAIL_ASSISTANT_SKILL_GET_PIPELINE` | Get deal and pipeline summaries |
| List tasks | `EMAIL_ASSISTANT_SKILL_LIST_TASKS` | Get today's or overdue tasks |
| Schedule event | `EMAIL_ASSISTANT_SKILL_SCHEDULE_EVENT` | Create calendar events |
| Run report | `EMAIL_ASSISTANT_SKILL_RUN_REPORT` | Execute a report and return summary |
| Enrich contact | `EMAIL_ASSISTANT_SKILL_ENRICH_CONTACT` | Fetch and merge contact data from APIs |
| Verify email | `EMAIL_ASSISTANT_SKILL_VERIFY_EMAIL` | Verify deliverability and update contact |

---

## Q&A

**What it does:** Answers questions about your CRM data using AI and context (contact count, task count, pipeline stats, pending tasks).

**Sample commands:**
- "How many contacts do I have?"
- "What's my pipeline value?"
- "What are my pending tasks?"

**Expected response:** Concise answer (2–4 sentences) based on CRM context.

---

## Create Task

**What it does:** Creates a task assigned to you with optional due date.

**Sample commands:**
- "Create task: Call John tomorrow"
- "Add task: Follow up on proposal by Friday"
- "Task: Review contract 2025-03-01"

**Expected response:** `Task created: "Call John tomorrow" (ID: 123). Due: 2025-02-27.`

---

## Create Contact

**What it does:** Adds a new contact with name, email, phone, company.

**Sample commands:**
- "Add contact: Jane Doe, jane@acme.com"
- "Create contact John Smith, john@example.com, Acme Inc"

**Expected response:** `Contact created: Jane Doe (jane@acme.com) (ID: 456).`

**Constraints:** Email is required. Duplicate email returns an error.

---

## Update Contact

**What it does:** Updates editable fields on an existing contact identified by name or email.

**Sample commands:**
- "Update contact John: set company to Acme Inc"
- "Edit contact john@example.com: change phone to 555-1234"
- "Update contact Jane Doe: set stage to qualified"

**Expected response:** `Contact John Smith updated. Changed: company, stage.`

**Editable fields:** first_name, last_name, email, phone, company, job_title, stage.

---

## Delete Contact

**What it does:** Permanently deletes a contact and related data (notes, documents, tag assignments). **This action cannot be undone.**

**Sample commands:**
- "Delete contact john@example.com"
- "Remove contact John Doe"

**Expected response:** `Contact deleted: John Doe (john@example.com). This action cannot be undone.`

**Safety:** Contact must be found by name or email. If not found, deletion is cancelled.

---

## Add Note

**What it does:** Adds a note to a contact identified by name or email.

**Sample commands:**
- "Add note to John: called him about the proposal"
- "Note to john@example.com: left voicemail"

**Expected response:** `Note added to John Smith.`

---

## Pipeline Status

**What it does:** Returns deal pipeline summary by stage with counts and values.

**Sample commands:**
- "What's my pipeline value?"
- "Pipeline status"
- "How many deals do I have?"

**Expected response:** Stage-by-stage breakdown plus total value, won, and lost counts.

---

## List Tasks

**What it does:** Lists your tasks (assigned or created by you), up to 15.

**Sample commands:**
- "What are my tasks for today?"
- "List my tasks"
- "Show tasks"

**Expected response:** Bulleted list with title, status, and due date.

---

## Schedule Event

**What it does:** Creates a calendar event with title, start time, optional end time and description.

**Sample commands:**
- "Schedule meeting tomorrow at 2pm - Team sync"
- "Book appointment next Monday 10am - Client call"

**Expected response:** `Event scheduled: "Team sync" at 2025-02-27 14:00:00 (ID: 789).`

---

## Run Report

**What it does:** Executes a report by name and returns row count.

**Sample commands:**
- "Run report: Contacts"
- "Send me the contacts report"

**Expected response:** `Report "Contacts" executed. Rows: 42.`

**Note:** Report name must match an existing report.

---

## Enrich Contact

**What it does:** Fetches and merges contact data from third-party APIs (Clearbit, Hunter.io, People Data Labs) into the contact record.

**Sample commands:**
- "Enrich contact john@example.com"
- "Enrich contact John"

**Expected response:** `Contact John Smith enriched. Updated 5 field(s).`

**Dependencies:** `CLEARBIT_API_KEY` or `HUNTER_API_KEY` or `PDL_API_KEY` in `.env`. See [Third-Party Enrichment](third-party-enrichment.md).

---

## Verify Email

**What it does:** Verifies email deliverability via Hunter.io and updates the contact's `email_verified` field if the contact exists.

**Sample commands:**
- "Verify email john@example.com"
- "Verify email for contact John"

**Expected response:**
```
Email: john@example.com
Status: Deliverable
Confidence: 95%
Contact record updated with verification status.
```

**Dependencies:** `HUNTER_API_KEY` in `.env`.

**Verification statuses:** deliverable, undeliverable, risky, unknown.

---

## Phased Rollout

Enable skills gradually to reduce risk. Recommended order:

1. **Low-risk (read-only):** Q&A, Pipeline status, List tasks
2. **Low-risk (create):** Create task, Create contact, Add note
3. **Mutation:** Update contact, Enrich contact, Verify email
4. **Destructive:** Delete contact (enable last, requires explicit admin decision)

**Verification steps:**

- **Test digest:** Settings > Email Assistant > Send Test Digest. Confirms SMTP and renderer work.
- **Inbound worker:** Run `php cli/fetch_incoming_emails.php` (or via cron). Processes emails sent to the system address.
- **Health check:** Ensure `EMAIL_ASSISTANT_SMTP_HOST`, `EMAIL_ASSISTANT_SMTP_USER`, `EMAIL_ASSISTANT_FROM_EMAIL` are set before enabling.

---

## Multi-Instruction Emails

You can send multiple instructions in one email. The assistant processes each and aggregates the results.

**Example:**
```
Create task: Call John tomorrow
Add note to John: left voicemail
What's my pipeline value?
```

**Response:** Three separate result blocks, one per instruction.

---

## Troubleshooting

- **"Only whitelisted addresses or admin users"** – Add your email to whitelisted senders or ensure your user has admin role.
- **"Q&A and instructions are disabled"** – Enable Q&A and/or Create task in Settings > Email Assistant.
- **"Contact not found"** – Use exact email or full name as stored in CRM.
- **"HUNTER_API_KEY not configured"** – Required for Verify email and for some Enrich contact flows.
- **"CLEARBIT_API_KEY or HUNTER_API_KEY"** – At least one required for Enrich contact.

---

## Related Documentation

- [Deployment](deployment.md) – Cron setup for IMAP fetch and daily digest
- [Third-Party Enrichment](third-party-enrichment.md) – API keys and enrichment sources
