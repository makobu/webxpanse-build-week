<?php
/**
 * Scheduled Email Processor
 *
 * Legacy compatibility wrapper. Scheduled emails already flow through the
 * workspace-safe email queue, so this delegates to the canonical one-shot
 * queue processor.
 */

require __DIR__ . '/process_pending_emails.php';
