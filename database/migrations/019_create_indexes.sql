-- Performance Indexes
-- Additional indexes for performance optimization
-- Note: IF NOT EXISTS is not supported for CREATE INDEX in MySQL, but migration script handles duplicates

CREATE INDEX idx_contacts_created ON contacts(created_at DESC);
CREATE INDEX idx_activities_created ON activities(created_at DESC);
CREATE INDEX idx_emails_created ON emails(created_at DESC);
CREATE INDEX idx_communications_created ON communications(created_at DESC);
