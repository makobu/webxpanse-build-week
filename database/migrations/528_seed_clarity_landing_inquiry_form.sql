-- Seed the published inquiry and partnership form used by the public Clarity landing page.
-- The stable UUID lets the unauthenticated landing page embed the same form that
-- Platform Operations manages from the default workspace Forms section.

SET @clarity_default_workspace_id := (
    SELECT id
    FROM workspaces
    WHERE slug = 'default'
    ORDER BY id
    LIMIT 1
);

SET @clarity_form_owner_id := (
    SELECT wm.user_id
    FROM workspace_memberships wm
    WHERE wm.workspace_id = @clarity_default_workspace_id
      AND wm.membership_status = 'active'
    ORDER BY wm.is_owner DESC, wm.id ASC
    LIMIT 1
);

-- Match the forms.uuid collation explicitly. Some XAMPP installations keep
-- the server default at utf8mb4_general_ci while this database uses
-- utf8mb4_unicode_ci, which otherwise makes the idempotency check fail.
SET @clarity_inquiry_form_uuid := _utf8mb4'9c648567-448e-4a7e-a951-3c3fc9a0d4e6' COLLATE utf8mb4_unicode_ci;

SET @clarity_inquiry_fields := '[
  {"id":"first_name","type":"text","name":"first_name","label":"First name","placeholder":"Amina","required":true,"mapping":"first_name"},
  {"id":"last_name","type":"text","name":"last_name","label":"Last name","placeholder":"Kamau","required":false,"mapping":"last_name"},
  {"id":"email","type":"email","name":"email","label":"Work email","placeholder":"you@company.com","required":true,"mapping":"email"},
  {"id":"company","type":"text","name":"company","label":"Company or project","placeholder":"Your business or idea","required":false,"mapping":"company"},
  {"id":"inquiry_type","type":"select","name":"inquiry_type","label":"What would you like to explore?","required":true,"options":["Product inquiry","Partnership","Pilot or market test","Team or enterprise rollout","Integration or implementation","Media, research or ecosystem","Other"]},
  {"id":"message","type":"textarea","name":"message","label":"Tell us what you are building or considering","placeholder":"Share the context, opportunity, or question that would help us respond usefully.","required":true}
]';

SET @clarity_inquiry_document := '{
  "schema":"crm.form/v1",
  "theme":{
    "primary_color":"#60a5fa",
    "button_color":"#2563eb",
    "page_color":"#03070d",
    "surface_color":"#07111f",
    "text_color":"#f7fbff",
    "muted_color":"#a8b2c1",
    "radius":16,
    "density":"spacious",
    "logo_path":"",
    "header_image_path":""
  },
  "content":{
    "title":"Tell us what you are exploring",
    "description":"Product question, partnership, pilot, integration, or an idea that does not fit a standard box - share the context and we will route it to the right next step.",
    "submit_label":"Send inquiry",
    "success_message":"Thank you. Your inquiry is now in our workspace. We will review the context and respond with a useful next step.",
    "redirect_url":"",
    "gdpr_enabled":false,
    "gdpr_label":"I agree that webXpanse may use these details to respond to this inquiry."
  },
  "steps":[{"id":"step_1","title":"Inquiry","description":""}],
  "fields":[
    {"id":"first_name","type":"text","step_id":"step_1","name":"first_name","label":"First name","placeholder":"Amina","required":true,"mapping":"first_name","width":"half"},
    {"id":"last_name","type":"text","step_id":"step_1","name":"last_name","label":"Last name","placeholder":"Kamau","required":false,"mapping":"last_name","width":"half"},
    {"id":"email","type":"email","step_id":"step_1","name":"email","label":"Work email","placeholder":"you@company.com","required":true,"mapping":"email","width":"half"},
    {"id":"company","type":"text","step_id":"step_1","name":"company","label":"Company or project","placeholder":"Your business or idea","required":false,"mapping":"company","width":"half"},
    {"id":"inquiry_type","type":"select","step_id":"step_1","name":"inquiry_type","label":"What would you like to explore?","required":true,"options":["Product inquiry","Partnership","Pilot or market test","Team or enterprise rollout","Integration or implementation","Media, research or ecosystem","Other"],"width":"full"},
    {"id":"message","type":"textarea","step_id":"step_1","name":"message","label":"Tell us what you are building or considering","placeholder":"Share the context, opportunity, or question that would help us respond usefully.","required":true,"width":"full","validation":{"min_length":20,"max_length":4000}}
  ],
  "metadata":{"template_key":"clarity_landing_inquiry","template_version":"1.0.0"}
}';

INSERT INTO forms (
    workspace_id,
    uuid,
    name,
    fields,
    success_message,
    redirect_url,
    settings,
    design_schema_version,
    design_document_json,
    design_revision,
    design_validation_json,
    design_status,
    published_document_json,
    published_revision,
    preview_token,
    published_at,
    design_updated_at,
    created_by
)
SELECT
    @clarity_default_workspace_id,
    @clarity_inquiry_form_uuid,
    'Clarity landing inquiries and partnerships',
    @clarity_inquiry_fields,
    'Thank you. Your inquiry is now in our workspace. We will review the context and respond with a useful next step.',
    NULL,
    '{"primary_color":"#60a5fa","background_color":"#03070d","button_color":"#2563eb","text_color":"#f7fbff","layout":"spacious","border_radius":16}',
    'crm.form/v1',
    @clarity_inquiry_document,
    1,
    '{"valid":true,"score":100,"errors":[],"warnings":[],"schema":"crm.form/v1"}',
    'published',
    @clarity_inquiry_document,
    1,
    NULL,
    NOW(),
    NOW(),
    @clarity_form_owner_id
WHERE @clarity_default_workspace_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM forms
      WHERE uuid = @clarity_inquiry_form_uuid
  );
