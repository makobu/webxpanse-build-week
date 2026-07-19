# Forms Page

Client-facing product promo and feature summary

Prepared: June 25, 2026

## Product Positioning

The Clarity CRM Forms page is the lead capture front door for the business. It helps a company create public forms, collect useful information from interested people, review every response, and move the best opportunities into CRM follow-up.

The client is not buying a form builder. The client is buying a better way to catch interest before it disappears.

Forms helps answer one practical question: when someone shows interest, how do we collect the right details, understand what they need, and follow up before the moment goes cold?

For clients, the Forms page turns anonymous website visitors, event prospects, referral leads, and campaign responses into visible CRM records. It connects public capture, contact creation, submissions, AI suggestions, and human-controlled follow-up into one simple workflow.

## Executive Summary

The Forms page gives users a clear place to create, manage, preview, embed, and review lead capture forms. A form can be used on a website, landing page, campaign page, event page, service request page, quote request page, booking request page, or any other place where the business needs to collect information from people.

The page is built around a practical flow. First, the team creates a form. Then the team chooses the fields it needs. Then the team customizes the look, confirmation message, redirect behavior, consent message, and public embed code. After the form is live, new submissions are stored, counted, reviewed, and connected to contacts when an email address is present.

The Forms experience is not only about collecting answers. It is about starting the next business move. A submitted form can create or identify a contact, record a touchpoint, preserve form data, show the latest responses, and give the team AI suggestions for tags, sales stage, and follow-up.

The intention is simple: help the business turn interest into action.

## Product At A Glance

- Operating role: Public lead capture and CRM response routing surface.
- Primary audience: Founders, sales teams, service teams, marketing teams, event teams, administrators, and implementation partners.
- Core promise: Capture interest, collect useful details, and move submissions into CRM follow-up.
- Business result: More leads become visible, organized, and ready for action.
- Source page: `http://localhost/crm/public/forms.php`

## Plain English Pitch

Most businesses lose leads in quiet ways. A person visits the website, thinks about asking a question, and leaves. Someone sees a campaign, wants a quote, but never gets entered into the system. A referral sends a name through a message, but the details stay incomplete. A small team gets busy and forgets to follow up.

The Forms page helps solve that problem by giving the business a simple place to build capture experiences. Instead of waiting for people to call, message, or explain everything from scratch, the business can ask the right questions up front.

When a person submits a form, the system stores the response and can connect it to a contact. The team can then review the submission, see the captured details, ask AI for follow-up suggestions, and decide what to do next.

The result is not just a form. The result is a cleaner path from interest to conversation.

## What The Client Is Really Buying

Clients are not shopping for another website widget. They are shopping for a way to stop losing warm opportunities.

They want:

- A simple way to capture new leads from websites and campaigns.
- A way to ask the right questions before the first call.
- A place where every response is stored and easy to review.
- A path from form submission to contact record.
- A way to identify which responses need attention.
- A follow-up process that does not depend only on memory.
- A form that can match the brand and fit inside a website.
- A safer way to collect files, details, and consent when needed.
- AI assistance that suggests tags, stages, and next steps without taking control away from the team.

The Forms page supports that result by turning public interest into organized CRM action.

## What The Page Is Built To Do

The Forms page is intended to help teams:

- Create new lead capture forms quickly.
- Manage every form from one Form Library.
- See how many forms, fields, submissions, and recent updates exist.
- Preview a public version of each form.
- Edit form details, fields, appearance, and success behavior.
- Embed forms into external website pages.
- Review the latest submissions for each form.
- Link submissions to contacts when the response includes an email address.
- Request AI analysis for tags, suggested stage, and follow-up.
- Apply suggested tags to linked contacts when the human user approves.
- Support secure file uploads when the business needs documents, images, or supporting evidence.
- Protect public forms with validation and rate limiting.
- Give the business a cleaner route from "someone is interested" to "someone is responsible for follow-up."

## Salient Features

### Form Library

The main Forms page acts as the library for all capture experiences. Users can see each form by name, view its unique ID, check how many fields it contains, see how many submissions it has received, review the last updated date, and choose actions such as Preview, Edit, or Delete.

This gives the business one organized place to manage public intake instead of scattering forms across different tools.

### Summary Cards

The page opens with simple summary cards:

- Forms: how many active capture experiences exist.
- Fields: how many fields exist across all forms.
- Submissions: how many stored lead responses have been captured.
- Recent updates: how many forms were edited recently and when the latest update happened.

These cards help a client quickly understand whether their lead capture system is active, growing, and being maintained.

### New Form Creation

The New Form button starts a focused builder. A new form begins with practical default fields such as Name and Email, which helps users start with the basics instead of facing an empty setup screen.

This is important for small teams. They can create something useful first, then improve it as they learn what information they need from real leads.

### Form Details

Each form can have:

- A clear internal form name.
- A success message shown after submission.
- An optional redirect URL for sending people to a thank-you page, booking page, payment page, or next step.

This allows the business to control both the data collection and the visitor experience after the form is submitted.

### Field Builder

The builder lets the team add, remove, and configure the fields that matter. Supported field types include text, email, phone, textarea, dropdown, checkbox, date, number, and file upload.

Each field can have a label, field name, placeholder, required setting, and options where needed. Dropdown fields can include one option per line. File upload fields can define accepted file types and maximum size.

This helps the business collect the right information without overcomplicating the first interaction.

### Required Fields

The form builder supports required fields so the business can make sure important information is captured before a visitor submits. For example, email can be required for follow-up, phone can be required for service calls, and consent can be required where appropriate.

The goal is simple: collect enough information to act.

### Appearance Controls

Forms can be styled to match the page where they will appear. Users can control primary color, background color, button color, text color, layout density, and border radius.

This matters because a lead form should feel like part of the business, not like a random external tool pasted onto a website.

### Logo And Header Image

After a form is saved, users can upload a logo and header image. These assets are served through a controlled asset route tied to the form.

For clients, this means public forms can carry a brand signal. A visitor sees a form that feels connected to the business they trust.

### GDPR Consent

The form builder includes an optional GDPR consent setting and editable consent label. When enabled, the public form requires the visitor to confirm consent before submitting.

This supports businesses that need a clear consent step for privacy, marketing permission, or other intake requirements.

### Public Form Page

Each form has a public version that can be opened in a browser. The public page renders the configured fields, styling, logo, header image, required indicators, error messages, success message, and submit button.

This public form is the customer-facing capture experience. It is where interest becomes data the business can act on.

### Embed Code

Saved forms include iframe embed code. A user can copy the code and place the form on a website page.

This makes the form useful beyond the CRM itself. It can live on service pages, landing pages, partner pages, product pages, or campaign pages while still feeding responses back into the CRM.

### Preview

Users can preview each public form before or after sharing it. This helps the team check the visitor experience, review the questions, confirm the branding, and make sure the form feels ready.

Preview protects the business from publishing a form that is confusing, incomplete, or off-brand.

### Submission Storage

Every successful submission is stored with the form data, form ID, public form UUID, page path, visitor ID, and submission time. The system can show the latest 200 submissions for a form.

This gives the business a record of what people asked for and when they asked for it.

### Contact Linking

When a submitted form includes a valid email address, the tracking layer can identify an existing contact or create a new one. The new contact can include name, email, phone, company, and a lead source of form.

This is one of the most important business results. The form does not just collect a response. It can help turn that response into a CRM contact ready for follow-up.

### Touchpoint Tracking

When a submission is linked to a contact, the system can record a form submission touchpoint and log activity on the contact. This helps the team understand the relationship history.

Instead of seeing a contact with no context, the team can see that the person submitted a specific form and supplied specific information.

### Submission Dashboard

Each form has a submissions page. It shows the number of submissions, linked contacts, latest submission time, captured fields, response rows, contact links, and actions.

This page gives users a practical inbox for form responses. It makes it easier to review leads without opening spreadsheets or searching through email notifications.

### AI Submission Analysis

From the submissions page, users can ask AI to analyze a response. The AI can suggest tags, a possible CRM stage, and a follow-up suggestion.

This helps users understand what the response may mean. For example, a form submission could be tagged as urgent, quote request, support need, event inquiry, partnership interest, or high intent depending on the information provided.

### Human-Controlled AI Actions

The system does not blindly apply AI decisions. A user can review the AI suggestion and then apply suggested tags to a linked contact.

This matches the larger product philosophy: AI helps the team think and move faster, while humans stay in control of what gets changed.

### File Upload Support

Forms can include file upload fields. The public form validates uploaded files by size, MIME type, and image integrity where relevant. Supported file categories include common images, PDFs, text files, CSV files, Word documents, and Excel files.

This is valuable for businesses that need resumes, documents, images, receipts, project files, quote attachments, or service evidence.

### Validation

The public form checks required fields, validates email format, checks number fields, normalizes date fields, checks dropdown selections, and sanitizes submitted text.

This helps protect data quality. Cleaner submissions create cleaner CRM follow-up.

### Rate Limiting

Public form submissions are protected by rate limiting. If too many submissions are made in the configured time window, the visitor is asked to wait.

This protects the business from abuse and reduces noisy submissions.

### Safe Asset Handling

Logo and header image uploads are limited to images, checked by file size, validated as real images, and served through a route that checks the path belongs to the expected form upload area.

This keeps public form branding practical while still respecting security boundaries.

### Delete Protection

When a user deletes a form, the delete screen explains that the form and its stored submissions will be removed. It shows the form name, field count, submission count, and updated date before the user confirms.

This makes destructive actions clearer and helps prevent accidental removal of valuable response history.

### Page Guide

The Forms page can show a Forms page guide button when an explainer video is available. This helps users learn the page in context instead of needing separate training.

For a client, this makes adoption easier. The team can learn the feature while using it.

### Workspace And Marketing Fit

Forms fit naturally with marketing pages, landing pages, campaigns, websites, and CRM follow-up. They can be used wherever the business needs a clear way to collect interest and start a relationship.

This makes Forms more than a standalone utility. It becomes part of a larger growth system.

## Product Intentions

The Forms page is designed with four main intentions.

First, it is meant to reduce missed opportunities. If people are interested, they need a simple way to raise their hand.

Second, it is meant to collect better information. A good form asks focused questions so the team can respond with context.

Third, it is meant to connect capture with follow-up. A form submission should not sit alone. It should become a contact, a touchpoint, a review item, and a next action.

Fourth, it is meant to keep humans in control. AI can suggest tags, stages, and follow-up, but the user decides what to apply.

## The Result The Page Sells

The page sells a practical result: no more invisible interest.

When Forms is used well, a business can:

- Catch more inquiries from the website.
- Collect the details needed to qualify a lead.
- Reduce back-and-forth by asking the right questions early.
- Give visitors a professional way to request help.
- Turn form responses into contact records.
- See new responses in one organized place.
- Use AI to understand the response faster.
- Follow up with more confidence and consistency.

The value is not the number of field types. The value is that a person who was interested becomes someone the business can serve.

## Why This Matters For A Growing Business

A small business usually starts with manual follow-up. The owner remembers who asked for what. A salesperson checks messages. A support person writes down requests. That can work when the business is small.

As the business grows, memory becomes a weak system. People forget. Details get lost. Leads stay in inboxes. Follow-up becomes uneven.

The Forms page gives the business a more dependable intake process. It helps the team create the same first step for every interested person.

For a high school level entrepreneur, the idea is simple: if people want what you sell, make it easy for them to tell you. Then make sure the business knows what to do next.

## AI Automation, CRM Follow-Up, And Human Control

The Forms page supports the larger WebXpanse promise.

AI automation saves time and money by helping interpret form responses, suggest tags, suggest a stage, and recommend a follow-up direction.

CRM follow-up creates consistency because form submissions can become contacts, activities, and reviewable response records instead of loose messages.

Human control keeps the business safe because AI analysis is presented for review. The system learns and suggests before it acts.

That balance matters. The business gets help without handing away judgment.

## Best-Fit Use Cases

The Forms page is useful for:

- Contact us forms.
- Quote request forms.
- Service request forms.
- Consultation request forms.
- Event registration forms.
- Waitlist forms.
- Partnership inquiry forms.
- Product interest forms.
- Support intake forms.
- Job application or candidate intake forms.
- Document collection forms.
- Landing page lead forms.
- Campaign response forms.

Any time a business needs to ask questions and create follow-up, Forms can be the starting point.

## Example Client Stories

### Service Business

A service company places a quote request form on its website. The form asks for name, email, phone, service type, location, budget, and preferred date. When someone submits, the response appears in the CRM, the person becomes a contact, and the team can review the details before calling.

The result is faster quoting and fewer missed inquiries.

### Event Organizer

An event team creates a registration form with attendee details, organization, session interest, and file upload for proof of payment or supporting documents. Submissions are stored in one place and can be reviewed by the team.

The result is cleaner event intake.

### Campaign Team

A marketing team embeds a form on a campaign landing page. The form collects interest and lets AI suggest tags and follow-up. The sales team can then focus on the responses that look most ready.

The result is better campaign follow-through.

### Founder-Led Business

A founder uses a simple contact form to stop losing interested people who visit the website after hours. Every morning, the founder can review the submissions instead of wondering who slipped away.

The result is less guesswork and more action.

## Client-Facing Value Proposition

Forms gives your business a simple way to capture interest and turn it into follow-up.

It helps you ask the right questions, collect the right details, keep responses organized, and move promising people into your CRM process. Instead of letting website visitors disappear, Forms gives them a clear path to raise their hand.

The system is practical enough for a small team and strong enough for a growing operation. You can create the form, style it for your brand, embed it on your website, review the responses, and use AI suggestions to decide what should happen next.

## What Makes It Different

The difference is not that the system can create fields. Many tools can create fields.

The difference is where the form lives in the business process.

In Clarity CRM, a form is connected to contacts, submissions, visitor tracking, touchpoints, AI analysis, tags, and follow-up. That means the form is not the end of the journey. It is the beginning of a relationship.

## Sales Message

Use Forms when you want every interested person to have a clear way into your business.

It gives you a public lead capture experience, a simple form builder, brand controls, embed code, submission review, contact linking, and AI-assisted follow-up suggestions.

The promise is simple: capture the lead, understand the need, and move the conversation forward.

## Short Website Blurb

Forms turns interest into action. Build branded lead capture forms, embed them on your website, review every submission, connect responses to contacts, and use AI suggestions to plan the next follow-up.

## Sales One-Liner

Forms helps your business stop losing interested people by turning website and campaign responses into organized CRM follow-up.

## Client Conversation Script

If a prospect asks what the Forms page does, say:

"Forms gives your business a simple way to collect interest from people who visit your website, respond to a campaign, or request help. You can build the form, choose the questions, match the style to your brand, embed it online, and review every response in the CRM. When a person submits their details, the system can connect them to a contact and help suggest tags or follow-up, while you stay in control of what gets applied."

## Client Readiness Checklist

Before launching Forms with a client, confirm:

- What type of lead or request the form should capture.
- Which fields are truly needed for follow-up.
- Whether email should be required.
- Whether phone, company, budget, date, or service type should be included.
- Whether the form needs file uploads.
- Whether the form needs a consent checkbox.
- What the visitor should see after submission.
- Whether the visitor should be redirected to a thank-you page.
- Where the form will be embedded.
- Who on the team will review submissions.
- What tags or stages may be useful for follow-up.

This keeps the form focused on results instead of collecting too much information too early.

## Recommended Pairings

Forms becomes more valuable when paired with:

- Contacts, so submissions can become customer records.
- Tasks, so follow-up work can be assigned.
- Workflows, so common next steps can become repeatable.
- Email, so the team can follow up from the CRM.
- WhatsApp, where fast messaging is part of the sales or service process.
- AI Coach, so the business can decide what to do with incoming interest.
- Marketplace skills, so Forms can be positioned as part of a bigger growth system.
- Landing pages, so campaigns have a clear place to collect responses.

## Final Product Message

The Forms page gives a growing business a dependable way to capture new interest.

It is built for the real moment when someone is ready to ask, buy, book, apply, register, or request help. Instead of letting that moment scatter across inboxes, chats, and memory, Forms brings it into the CRM where the team can see it, understand it, and respond.

Forms turns anonymous interest into visible CRM follow-up.
