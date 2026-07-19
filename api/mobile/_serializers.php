<?php

function mobileContactSummary(array $contact): array
{
    return [
        'id' => (int) ($contact['id'] ?? 0),
        'uuid' => (string) ($contact['uuid'] ?? ''),
        'first_name' => (string) ($contact['first_name'] ?? ''),
        'last_name' => (string) ($contact['last_name'] ?? ''),
        'full_name' => trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))),
        'email' => (string) ($contact['email'] ?? ''),
        'phone' => (string) ($contact['phone'] ?? ''),
        'company' => (string) ($contact['company'] ?? ''),
        'company_id' => !empty($contact['company_id']) ? (int) $contact['company_id'] : null,
        'linked_company_name' => (string) ($contact['linked_company_name'] ?? ''),
        'stage' => (string) ($contact['stage'] ?? ''),
        'lead_source' => (string) ($contact['lead_source'] ?? ''),
        'assigned_to' => !empty($contact['assigned_to']) ? (int) $contact['assigned_to'] : null,
        'created_by' => !empty($contact['created_by']) ? (int) $contact['created_by'] : null,
        'job_title' => (string) ($contact['job_title'] ?? ''),
        'location' => (string) ($contact['location'] ?? ''),
        'company_website' => (string) ($contact['company_website'] ?? ''),
        'linkedin_url' => (string) ($contact['linkedin_url'] ?? ''),
        'twitter_url' => (string) ($contact['twitter_url'] ?? ''),
        'timezone' => (string) ($contact['timezone'] ?? ''),
        'address' => (string) ($contact['address'] ?? ''),
        'created_at' => (string) ($contact['created_at'] ?? ''),
    ];
}

function mobileCompanySummary(array $company): array
{
    $primaryContactName = trim((string) (($company['primary_contact_first_name'] ?? '') . ' ' . ($company['primary_contact_last_name'] ?? '')));

    return [
        'id' => (int) ($company['id'] ?? 0),
        'uuid' => (string) ($company['uuid'] ?? ''),
        'name' => (string) ($company['name'] ?? ''),
        'website' => (string) ($company['website'] ?? ''),
        'phone' => (string) ($company['phone'] ?? ''),
        'industry' => (string) ($company['industry'] ?? ''),
        'size' => (string) ($company['size'] ?? ''),
        'address' => (string) ($company['address'] ?? ''),
        'assigned_to' => !empty($company['assigned_to']) ? (int) $company['assigned_to'] : null,
        'assigned_to_email' => (string) ($company['assigned_to_email'] ?? ''),
        'primary_contact_id' => !empty($company['primary_contact_id']) ? (int) $company['primary_contact_id'] : null,
        'primary_contact_name' => $primaryContactName,
        'primary_contact_email' => (string) ($company['primary_contact_email'] ?? ''),
        'created_at' => (string) ($company['created_at'] ?? ''),
        'updated_at' => (string) ($company['updated_at'] ?? ''),
    ];
}

function mobileTaskSummary(array $task): array
{
    return [
        'id' => (int) ($task['id'] ?? 0),
        'title' => (string) ($task['title'] ?? ''),
        'description' => (string) ($task['description'] ?? ''),
        'status' => (string) ($task['status'] ?? ''),
        'priority' => (string) ($task['priority'] ?? ''),
        'due_date' => (string) ($task['due_date'] ?? ''),
        'completed_at' => (string) ($task['completed_at'] ?? ''),
        'assigned_to' => !empty($task['assigned_to']) ? (int) $task['assigned_to'] : null,
        'created_by' => !empty($task['created_by']) ? (int) $task['created_by'] : null,
        'contact' => [
            'id' => !empty($task['contact_id']) ? (int) $task['contact_id'] : null,
            'name' => trim((string) (($task['contact_first_name'] ?? '') . ' ' . ($task['contact_last_name'] ?? ''))),
            'email' => (string) ($task['contact_email'] ?? ''),
        ],
        'target' => [
            'id' => !empty($task['target_id']) ? (int) $task['target_id'] : null,
            'title' => (string) ($task['target_title'] ?? ''),
            'deadline' => (string) ($task['target_deadline'] ?? ''),
        ],
        'created_at' => (string) ($task['created_at'] ?? ''),
    ];
}

function mobileDealSummary(array $deal): array
{
    return [
        'id' => (int) ($deal['id'] ?? 0),
        'title' => (string) ($deal['title'] ?? ''),
        'description' => (string) ($deal['description'] ?? ''),
        'stage' => (string) ($deal['stage'] ?? ''),
        'value' => (float) ($deal['value'] ?? 0),
        'probability' => (int) ($deal['probability'] ?? 0),
        'expected_close_date' => (string) ($deal['expected_close_date'] ?? ''),
        'actual_close_date' => (string) ($deal['actual_close_date'] ?? ''),
        'currency' => (string) ($deal['currency'] ?? ''),
        'assigned_to' => !empty($deal['assigned_to']) ? (int) $deal['assigned_to'] : null,
        'created_by' => !empty($deal['created_by']) ? (int) $deal['created_by'] : null,
        'company_id' => !empty($deal['company_id']) ? (int) $deal['company_id'] : null,
        'company_name' => (string) ($deal['company_name'] ?? ''),
        'contact' => [
            'id' => !empty($deal['contact_id']) ? (int) $deal['contact_id'] : null,
            'name' => trim((string) (($deal['contact_first_name'] ?? '') . ' ' . ($deal['contact_last_name'] ?? ''))),
            'email' => (string) ($deal['contact_email'] ?? ''),
        ],
        'created_at' => (string) ($deal['created_at'] ?? ''),
    ];
}

function mobileEventSummary(array $event): array
{
    $contactName = trim((string) (($event['contact_first_name'] ?? '') . ' ' . ($event['contact_last_name'] ?? '')));
    $location = trim((string) ($event['location'] ?? ''));
    $joinUrl = '';
    $mapsUrl = '';

    if ($location !== '') {
        if (preg_match('#https?://\S+#i', $location, $matches)) {
            $joinUrl = (string) $matches[0];
        } else {
            $mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($location);
        }
    }

    return [
        'id' => (int) ($event['id'] ?? 0),
        'lock_version' => (int) ($event['lock_version'] ?? 0),
        'title' => (string) ($event['title'] ?? ''),
        'description' => (string) ($event['description'] ?? ''),
        'event_type' => (string) ($event['event_type'] ?? ''),
        'status' => (string) ($event['status'] ?? ''),
        'start_time' => (string) ($event['start_time'] ?? ''),
        'end_time' => (string) ($event['end_time'] ?? ''),
        'location' => $location,
        'is_all_day' => !empty($event['is_all_day']),
        'reminder_minutes' => !empty($event['reminder_minutes']) ? (int) $event['reminder_minutes'] : null,
        'assigned_to' => !empty($event['assigned_to']) ? (int) $event['assigned_to'] : null,
        'assigned_to_email' => (string) ($event['assigned_to_email'] ?? ''),
        'created_by' => !empty($event['created_by']) ? (int) $event['created_by'] : null,
        'created_by_email' => (string) ($event['created_by_email'] ?? ''),
        'contact_id' => !empty($event['contact_id']) ? (int) $event['contact_id'] : null,
        'contact_name' => $contactName,
        'contact_email' => (string) ($event['contact_email'] ?? ''),
        'join_url' => $joinUrl,
        'maps_url' => $mapsUrl,
        'created_at' => (string) ($event['created_at'] ?? ''),
    ];
}

function mobileInvoiceLineItemSummary(array $lineItem): array
{
    return [
        'id' => (int) ($lineItem['id'] ?? 0),
        'product_id' => !empty($lineItem['product_id']) ? (int) $lineItem['product_id'] : null,
        'product_name' => (string) ($lineItem['product_name'] ?? ''),
        'description' => (string) ($lineItem['description'] ?? ''),
        'quantity' => (float) ($lineItem['quantity'] ?? 0),
        'unit_price' => (float) ($lineItem['unit_price'] ?? 0),
        'discount_percent' => (float) ($lineItem['discount_percent'] ?? 0),
        'discount_amount' => (float) ($lineItem['discount_amount'] ?? 0),
        'tax_percent' => (float) ($lineItem['tax_percent'] ?? 0),
        'tax_amount' => (float) ($lineItem['tax_amount'] ?? 0),
        'line_total' => (float) ($lineItem['line_total'] ?? 0),
    ];
}

function mobileInvoiceSummary(array $invoice): array
{
    $contactName = trim((string) (($invoice['contact_first_name'] ?? '') . ' ' . ($invoice['contact_last_name'] ?? '')));

    return [
        'id' => (int) ($invoice['id'] ?? 0),
        'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
        'document_type' => (string) ($invoice['document_type'] ?? ''),
        'status' => (string) ($invoice['status'] ?? ''),
        'title' => (string) ($invoice['title'] ?? ''),
        'currency' => (string) ($invoice['currency'] ?? ''),
        'subtotal' => (float) ($invoice['subtotal'] ?? 0),
        'discount_total' => (float) ($invoice['discount_total'] ?? 0),
        'tax_total' => (float) ($invoice['tax_total'] ?? 0),
        'grand_total' => (float) ($invoice['grand_total'] ?? 0),
        'amount_paid' => (float) ($invoice['amount_paid'] ?? 0),
        'balance_due' => (float) ($invoice['balance_due'] ?? 0),
        'issue_date' => (string) ($invoice['issue_date'] ?? ''),
        'due_date' => (string) ($invoice['due_date'] ?? ''),
        'valid_until' => (string) ($invoice['valid_until'] ?? ''),
        'assigned_to' => !empty($invoice['assigned_to']) ? (int) ($invoice['assigned_to']) : null,
        'assigned_to_email' => (string) ($invoice['assigned_to_email'] ?? ''),
        'contact_id' => !empty($invoice['contact_id']) ? (int) $invoice['contact_id'] : null,
        'contact_name' => $contactName,
        'contact_email' => (string) ($invoice['contact_email'] ?? ''),
        'company_id' => !empty($invoice['company_id']) ? (int) $invoice['company_id'] : null,
        'company_name' => (string) ($invoice['company_name'] ?? ''),
        'deal_id' => !empty($invoice['deal_id']) ? (int) $invoice['deal_id'] : null,
        'deal_title' => (string) ($invoice['deal_title'] ?? ''),
        'billing_name' => (string) ($invoice['billing_name'] ?? ''),
        'billing_email' => (string) ($invoice['billing_email'] ?? ''),
        'created_at' => (string) ($invoice['created_at'] ?? ''),
        'updated_at' => (string) ($invoice['updated_at'] ?? ''),
        'web_url' => '/public/invoice_view.php?id=' . (int) ($invoice['id'] ?? 0),
        'pdf_url' => '/public/invoice_pdf.php?id=' . (int) ($invoice['id'] ?? 0),
    ];
}

function mobileDecodeJsonArrayField($value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function mobileConversationSummary(array $row): array
{
    $contactName = trim((string) ($row['contact_name'] ?? ''));
    $threadChannels = [];
    $rawThreadChannels = $row['thread_channels'] ?? [];
    if (is_string($rawThreadChannels) && trim($rawThreadChannels) !== '') {
        $threadChannels = array_values(array_filter(array_map('trim', explode(',', $rawThreadChannels))));
    } elseif (is_array($rawThreadChannels)) {
        $threadChannels = array_values(array_filter(array_map(static fn($item): string => trim((string) $item), $rawThreadChannels)));
    }
    $triageReasonCodes = mobileDecodeJsonArrayField($row['triage_reason_codes'] ?? null);
    $threadMetadata = mobileDecodeJsonArrayField($row['thread_metadata_json'] ?? null);

    return [
        'id' => (int) ($row['id'] ?? 0),
        'latest_communication_id' => !empty($row['latest_communication_id']) ? (int) $row['latest_communication_id'] : (int) ($row['id'] ?? 0),
        'group_type' => (string) ($row['group_type'] ?? (!empty($row['contact_id']) ? 'contact' : 'thread')),
        'group_id' => !empty($row['group_id'])
            ? (int) $row['group_id']
            : (!empty($row['contact_id']) ? (int) $row['contact_id'] : (int) ($row['thread_id'] ?? $row['id'] ?? 0)),
        'owner_id' => !empty($row['owner_id'])
            ? (int) $row['owner_id']
            : (!empty($row['thread_owner_id']) ? (int) $row['thread_owner_id'] : null),
        'latest_thread_id' => !empty($row['latest_thread_id'])
            ? (int) $row['latest_thread_id']
            : (!empty($row['thread_id']) ? (int) $row['thread_id'] : null),
        'contact_id' => !empty($row['contact_id']) ? (int) $row['contact_id'] : null,
        'channel' => (string) ($row['channel'] ?? ''),
        'direction' => (string) ($row['direction'] ?? ''),
        'subject' => (string) ($row['subject'] ?? ''),
        'from_email' => (string) ($row['from_email'] ?? ''),
        'body_preview' => (string) ($row['body_preview'] ?? ''),
        'body_length' => (int) ($row['body_length'] ?? 0),
        'read_at' => (string) ($row['read_at'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'contact_name' => $contactName,
        'contact_email' => (string) ($row['contact_email'] ?? ''),
        'thread_key' => (string) ($row['thread_key'] ?? ''),
        'triage_priority' => (string) ($row['triage_priority'] ?? ''),
        'triage_score' => isset($row['triage_score']) ? (float) $row['triage_score'] : null,
        'triage_confidence' => isset($row['triage_confidence']) ? (float) $row['triage_confidence'] : null,
        'triage_status' => (string) ($row['triage_status'] ?? ''),
        'triage_owner_id' => !empty($row['triage_owner_id']) ? (int) $row['triage_owner_id'] : null,
        'triage_task_id' => !empty($row['triage_task_id']) ? (int) $row['triage_task_id'] : null,
        'triage_reason_codes' => $triageReasonCodes,
        'triage_decided_at' => (string) ($row['triage_decided_at'] ?? ''),
        'thread_status' => (string) ($row['thread_status'] ?? ''),
        'thread_owner_id' => !empty($row['thread_owner_id']) ? (int) $row['thread_owner_id'] : null,
        'thread_priority' => (string) ($row['thread_priority'] ?? ''),
        'thread_response_due_at' => (string) ($row['thread_response_due_at'] ?? ''),
        'thread_unresolved_item_count' => (int) ($row['thread_unresolved_item_count'] ?? 0),
        'thread_escalation_status' => (string) ($row['thread_escalation_status'] ?? ''),
        'thread_metadata_json' => $threadMetadata,
        'unread_count' => (int) ($row['thread_unread_count'] ?? 0),
        'message_count' => (int) ($row['thread_message_count'] ?? 0),
        'channels' => $threadChannels,
        'thread' => [
            'id' => !empty($row['thread_id']) ? (int) $row['thread_id'] : null,
            'thread_key' => (string) ($row['thread_key'] ?? ''),
            'status' => (string) ($row['thread_status'] ?? ''),
            'owner_id' => !empty($row['thread_owner_id']) ? (int) $row['thread_owner_id'] : null,
            'priority' => (string) ($row['thread_priority'] ?? ''),
            'response_due_at' => (string) ($row['thread_response_due_at'] ?? ''),
            'unresolved_item_count' => (int) ($row['thread_unresolved_item_count'] ?? 0),
            'escalation_status' => (string) ($row['thread_escalation_status'] ?? ''),
            'metadata' => $threadMetadata,
            'unread_count' => (int) ($row['thread_unread_count'] ?? 0),
            'message_count' => (int) ($row['thread_message_count'] ?? 0),
            'latest_communication_id' => !empty($row['latest_communication_id']) ? (int) $row['latest_communication_id'] : (int) ($row['id'] ?? 0),
            'channels' => $threadChannels,
        ],
    ];
}

function mobileNoteSummary(array $note): array
{
    $content = trim((string) ($note['content'] ?? ''));
    $plain = trim(html_entity_decode(strip_tags($content), ENT_QUOTES, 'UTF-8'));

    return [
        'id' => (int) ($note['id'] ?? 0),
        'title' => (string) ($note['title'] ?? ''),
        'content' => $content,
        'content_plain' => $plain,
        'is_private' => !empty($note['is_private']),
        'created_by' => !empty($note['created_by']) ? (int) $note['created_by'] : null,
        'created_by_email' => (string) ($note['created_by_email'] ?? ''),
        'created_at' => (string) ($note['created_at'] ?? ''),
        'replies' => array_map(
            'mobileNoteSummary',
            is_array($note['replies'] ?? null) ? $note['replies'] : []
        ),
    ];
}

function mobileTimelineItemSummary(array $item): array
{
    return [
        'type' => (string) ($item['type'] ?? ''),
        'filter' => (string) ($item['filter'] ?? 'all'),
        'title' => (string) ($item['title'] ?? ''),
        'detail' => (string) ($item['detail'] ?? ''),
        'created_at' => (string) ($item['created_at'] ?? ''),
    ];
}

function mobileRelationshipSummaryEntry(?array $item): ?array
{
    if (!is_array($item) || $item === []) {
        return null;
    }

    return [
        'id' => (int) ($item['id'] ?? 0),
        'title' => (string) ($item['title'] ?? ''),
        'created_at' => (string) ($item['created_at'] ?? ''),
        'status' => isset($item['status']) ? (string) $item['status'] : null,
        'channel' => isset($item['channel']) ? (string) $item['channel'] : null,
        'due_date' => isset($item['due_date']) ? (string) $item['due_date'] : null,
    ];
}

function mobileRelationshipSummary(array $summary): array
{
    return [
        'last_inbound_reply' => mobileRelationshipSummaryEntry($summary['last_inbound_reply'] ?? null),
        'last_outbound_reply' => mobileRelationshipSummaryEntry($summary['last_outbound_reply'] ?? null),
        'last_meeting' => mobileRelationshipSummaryEntry($summary['last_meeting'] ?? null),
        'last_quote_or_invoice' => mobileRelationshipSummaryEntry($summary['last_quote_or_invoice'] ?? null),
        'last_note' => mobileRelationshipSummaryEntry($summary['last_note'] ?? null),
        'last_open_task' => mobileRelationshipSummaryEntry($summary['last_open_task'] ?? null),
    ];
}

function mobileSharedTaskSummary(array $task): array
{
    return [
        'id' => (int) ($task['id'] ?? 0),
        'title' => (string) ($task['title'] ?? ''),
        'status' => (string) ($task['status'] ?? ''),
        'contact_id' => !empty($task['contact_id']) ? (int) $task['contact_id'] : null,
        'created_at' => (string) ($task['created_at'] ?? ''),
    ];
}

function mobileSharedInvoiceSummary(array $invoice): array
{
    return [
        'id' => (int) ($invoice['id'] ?? 0),
        'invoice_number' => (string) ($invoice['invoice_number'] ?? ''),
        'document_type' => (string) ($invoice['document_type'] ?? ''),
        'status' => (string) ($invoice['status'] ?? ''),
        'contact_id' => !empty($invoice['contact_id']) ? (int) $invoice['contact_id'] : null,
        'created_at' => (string) ($invoice['created_at'] ?? ''),
    ];
}

function mobileAccountContextSummary(array $context): array
{
    $sharedDeals = is_array($context['shared_deals'] ?? null) ? $context['shared_deals'] : [];
    $sharedTasks = is_array($context['shared_tasks'] ?? null) ? $context['shared_tasks'] : [];
    $sharedInvoices = is_array($context['shared_invoices'] ?? null) ? $context['shared_invoices'] : [];

    return [
        'company_name' => (string) ($context['company_name'] ?? ''),
        'email_domain' => (string) ($context['email_domain'] ?? ''),
        'related_contacts' => array_map(
            'mobileContactSummary',
            is_array($context['related_contacts'] ?? null) ? $context['related_contacts'] : []
        ),
        'shared_deals' => array_map('mobileDealSummary', $sharedDeals),
        'shared_tasks' => array_map('mobileSharedTaskSummary', $sharedTasks),
        'shared_invoices' => array_map('mobileSharedInvoiceSummary', $sharedInvoices),
        'shared_deals_count' => count($sharedDeals),
        'shared_tasks_count' => count($sharedTasks),
        'shared_invoices_count' => count($sharedInvoices),
    ];
}

function mobileDuplicateContactSummary(array $contact): array
{
    return [
        'id' => (int) ($contact['id'] ?? 0),
        'full_name' => trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))),
        'email' => (string) ($contact['email'] ?? ''),
        'phone' => (string) ($contact['phone'] ?? ''),
        'company' => (string) ($contact['company'] ?? ''),
        'duplicate_score' => (float) ($contact['duplicate_score'] ?? 0),
    ];
}

function mobileDataQualitySummary(array $quality): array
{
    return [
        'missing_critical_fields' => array_values(array_map(
            static fn($item): string => (string) $item,
            is_array($quality['missing_critical_fields'] ?? null) ? $quality['missing_critical_fields'] : []
        )),
        'is_incomplete' => !empty($quality['is_incomplete']),
        'is_stale' => !empty($quality['is_stale']),
        'stale_days' => isset($quality['stale_days']) ? (int) $quality['stale_days'] : null,
        'has_engagement_evidence' => !empty($quality['has_engagement_evidence']),
        'last_touch_at' => !empty($quality['last_touch_at']) ? (string) $quality['last_touch_at'] : null,
        'last_touch_source' => !empty($quality['last_touch_source']) ? (string) $quality['last_touch_source'] : null,
        'awaiting_response_since' => !empty($quality['awaiting_response_since']) ? (string) $quality['awaiting_response_since'] : null,
        'likely_duplicates' => array_map(
            'mobileDuplicateContactSummary',
            is_array($quality['likely_duplicates'] ?? null) ? $quality['likely_duplicates'] : []
        ),
    ];
}

function mobileNotificationSummary(array $row): array
{
    $link = (string) ($row['link'] ?? '');
    $entityType = (string) ($row['entity_type'] ?? '');
    $entityId = !empty($row['entity_id']) ? (int) $row['entity_id'] : null;
    $route = null;

    if ($entityId !== null) {
        $route = match ($entityType) {
            'deal' => '/deals/' . $entityId,
            'task' => '/tasks/' . $entityId,
            'contact' => '/contacts/' . $entityId,
            'company' => '/companies/' . $entityId,
            'event' => '/calendar/' . $entityId,
            'invoice' => '/invoices/' . $entityId,
            'target' => '/targets/' . $entityId,
            'activity' => '/activities',
            'form' => '/forms/' . $entityId . '/submissions',
            'form_submission' => '/forms',
            'meeting_booking', 'booking' => '/bookings',
            'organization_risk', 'organization_task' => '/organization',
            'communication', 'conversation' => '/inbox/' . $entityId,
            default => null,
        };
    }

    if ($route === null && $link !== '') {
        if (preg_match('/deal_view\.php\?id=(\d+)/i', $link, $matches)) {
            $route = '/deals/' . (int) $matches[1];
        } elseif (preg_match('/task_view\.php\?id=(\d+)/i', $link, $matches)) {
            $route = '/tasks/' . (int) $matches[1];
        } elseif (preg_match('/contact_view\.php\?id=(\d+)/i', $link, $matches)) {
            $route = '/contacts/' . (int) $matches[1];
        } elseif (preg_match('/company_view\.php\?id=(\d+)/i', $link, $matches)) {
            $route = '/companies/' . (int) $matches[1];
        } elseif (preg_match('/event_view\.php\?id=(\d+)/i', $link, $matches)) {
            $route = '/calendar/' . (int) $matches[1];
        } elseif (preg_match('/invoice_view\.php\?id=(\d+)/i', $link, $matches)) {
            $route = '/invoices/' . (int) $matches[1];
        } elseif (preg_match('/conversation\.php\?id=(\d+)/i', $link, $matches)) {
            $route = '/inbox/' . (int) $matches[1];
        } elseif (preg_match('/target_view\.php\?id=(\d+)/i', $link, $matches)) {
            $route = '/targets/' . (int) $matches[1];
        } elseif (preg_match('/meeting_bookings\.php/i', $link)) {
            $route = '/bookings';
        } elseif (preg_match('/form_submissions\.php\?(?:.*&)?form_id=(\d+)/i', $link, $matches)) {
            $route = '/forms/' . (int) $matches[1] . '/submissions';
        } elseif (preg_match('/hr_analytics\.php|organization_intelligence/i', $link)) {
            $route = '/organization';
        }
    }

    $isAi = str_starts_with((string) ($row['type'] ?? ''), 'ai_')
        || (string) ($row['type'] ?? '') === 'ai_coach_nudge';

    return [
        'id' => (int) ($row['id'] ?? 0),
        'type' => (string) ($row['type'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'message' => (string) ($row['message'] ?? ''),
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'link' => $link,
        'route' => $route,
        'severity' => (string) ($row['severity'] ?? ''),
        'ai_type' => $isAi ? (string) ($row['type'] ?? '') : null,
        'ai_priority' => $isAi ? (((string) ($row['severity'] ?? '')) !== '' ? (string) ($row['severity'] ?? '') : 'normal') : null,
        'ai_route' => $isAi ? ($route ?? '/ai') : null,
        'ai_action_preview' => $isAi ? (string) ($row['message'] ?? '') : null,
        'is_read' => !empty($row['is_read']),
        'read_at' => (string) ($row['read_at'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function mobileDocumentSummary(array $document): array
{
    return [
        'id' => (int) ($document['id'] ?? 0),
        'entity_type' => (string) ($document['entity_type'] ?? ''),
        'entity_id' => !empty($document['entity_id']) ? (int) $document['entity_id'] : null,
        'original_name' => (string) ($document['original_name'] ?? ''),
        'description' => (string) ($document['description'] ?? ''),
        'mime_type' => (string) ($document['mime_type'] ?? ''),
        'file_size' => !empty($document['file_size']) ? (int) $document['file_size'] : 0,
        'uploaded_by' => !empty($document['uploaded_by']) ? (int) $document['uploaded_by'] : null,
        'uploaded_by_email' => (string) ($document['uploaded_by_email'] ?? ''),
        'created_at' => (string) ($document['created_at'] ?? ''),
        'preview_url' => '/api/mobile/documents/file.php?id=' . (int) ($document['id'] ?? 0) . '&mode=preview',
        'download_url' => '/api/mobile/documents/file.php?id=' . (int) ($document['id'] ?? 0) . '&mode=download',
        'preview_available' => !empty($document['mime_type']) && (
            str_starts_with((string) $document['mime_type'], 'image/')
            || str_contains((string) $document['mime_type'], 'pdf')
        ),
    ];
}

function mobileTaskDetail(array $task): array
{
    $summary = mobileTaskSummary($task);
    $metadata = [];
    if (is_array($task['metadata_json'] ?? null)) {
        $metadata = $task['metadata_json'];
    } elseif (is_string($task['metadata_json'] ?? null) && trim((string) $task['metadata_json']) !== '') {
        $decoded = json_decode((string) $task['metadata_json'], true);
        $metadata = is_array($decoded) ? $decoded : [];
    }

    $tasks = new \CRM\Modules\Tasks();
    $summary['source'] = [
        'origin_type' => (string) ($task['origin_type'] ?? 'manual'),
        'surface' => (string) ($task['source_surface'] ?? $metadata['source_surface'] ?? ''),
        'skill_key' => (string) ($task['source_skill_key'] ?? $metadata['source_skill_key'] ?? $metadata['marketplace_skill_key'] ?? ''),
        'run_id' => (string) ($task['source_run_id'] ?? $metadata['source_run_id'] ?? ''),
    ];
    $summary['subtasks'] = array_map(static function (array $subtask): array {
        return [
            'id' => (int) ($subtask['id'] ?? 0),
            'title' => (string) ($subtask['title'] ?? ''),
            'description' => (string) ($subtask['description'] ?? ''),
            'completed' => !empty($subtask['completed']),
            'order' => (int) ($subtask['order'] ?? 0),
        ];
    }, $tasks->getSubtasks((int) ($task['id'] ?? 0)));
    $summary['completion'] = (new \CRM\Services\TaskCompletionCoordinator($tasks))->completionPayload(
        $task,
        (int) (\CRM\Auth::user()['id'] ?? 0)
    );

    return $summary;
}

function mobileStringList($value): array
{
    if (!is_array($value)) {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn($item): string => trim((string) $item),
        $value
    )));
}

function mobileTargetSummary(array $target): array
{
    $id = (int) ($target['id'] ?? 0);

    return [
        'id' => $id,
        'title' => (string) ($target['title'] ?? ''),
        'description' => (string) ($target['description'] ?? ''),
        'target_type' => (string) ($target['target_type'] ?? ''),
        'status' => (string) ($target['status'] ?? ''),
        'scope' => (string) ($target['scope'] ?? ''),
        'progress_mode' => (string) ($target['progress_mode'] ?? ''),
        'target_value' => (float) ($target['target_value'] ?? 0),
        'current_value' => (float) ($target['current_value'] ?? 0),
        'unit' => (string) ($target['unit'] ?? ''),
        'progress_percentage' => (float) ($target['progress_percentage'] ?? 0),
        'days_remaining' => isset($target['days_remaining']) ? (int) $target['days_remaining'] : null,
        'is_on_track' => array_key_exists('is_on_track', $target) ? (bool) $target['is_on_track'] : null,
        'status_category' => (string) ($target['status_category'] ?? ''),
        'start_date' => (string) ($target['start_date'] ?? ''),
        'target_date' => (string) ($target['target_date'] ?? ''),
        'completed_at' => (string) ($target['completed_at'] ?? ''),
        'reminder_frequency' => (string) ($target['reminder_frequency'] ?? ''),
        'user_id' => !empty($target['user_id']) ? (int) $target['user_id'] : null,
        'user_email' => (string) ($target['user_email'] ?? ''),
        'source_skill_key' => (string) ($target['source_skill_key'] ?? ''),
        'source_capability_key' => (string) ($target['source_capability_key'] ?? ''),
        'origin_type' => (string) ($target['origin_type'] ?? 'manual'),
        'automation_mode' => (string) ($target['automation_mode'] ?? 'manual'),
        'rollup_source' => (string) ($target['rollup_source'] ?? ''),
        'rollup_metric' => (string) ($target['rollup_metric'] ?? ''),
        'rollup_window' => (string) ($target['rollup_window'] ?? 'target_period'),
        'currency_code' => (string) ($target['currency_code'] ?? ''),
        'state_version' => (int) ($target['state_version'] ?? 1),
        'measurement' => (array) ($target['measurement'] ?? []),
        'created_at' => (string) ($target['created_at'] ?? ''),
        'updated_at' => (string) ($target['updated_at'] ?? ''),
        'route' => $id > 0 ? '/targets/' . $id : '/targets',
    ];
}

function mobileActivitySummary(array $activity): array
{
    $contactName = trim((string) (($activity['first_name'] ?? $activity['contact_first_name'] ?? '') . ' ' . ($activity['last_name'] ?? $activity['contact_last_name'] ?? '')));

    return [
        'id' => (int) ($activity['id'] ?? 0),
        'contact_id' => !empty($activity['contact_id']) ? (int) $activity['contact_id'] : null,
        'contact_name' => $contactName,
        'contact_email' => (string) ($activity['contact_email'] ?? $activity['email'] ?? ''),
        'user_id' => !empty($activity['user_id']) ? (int) $activity['user_id'] : null,
        'user_email' => (string) ($activity['user_email'] ?? ''),
        'activity_type' => (string) ($activity['activity_type'] ?? ''),
        'activity_label' => class_exists('\CRM\Modules\Activities')
            ? \CRM\Modules\Activities::formatTypeLabel((string) ($activity['activity_type'] ?? ''))
            : ucwords(str_replace('_', ' ', (string) ($activity['activity_type'] ?? 'Activity'))),
        'description' => (string) ($activity['description'] ?? ''),
        'metadata' => mobileDecodeJsonArrayField($activity['metadata'] ?? null),
        'created_at' => (string) ($activity['created_at'] ?? ''),
        'route' => !empty($activity['contact_id']) ? '/contacts/' . (int) $activity['contact_id'] : '/activities',
    ];
}

function mobileMeetingBookingSummary(array $booking): array
{
    $contactName = trim((string) (($booking['contact_first_name'] ?? '') . ' ' . ($booking['contact_last_name'] ?? '')));

    return [
        'id' => (int) ($booking['id'] ?? $booking['booking_id'] ?? 0),
        'status' => (string) ($booking['status'] ?? ''),
        'requester_name' => (string) ($booking['requester_name'] ?? ''),
        'requester_email' => (string) ($booking['requester_email'] ?? ''),
        'requester_phone' => (string) ($booking['requester_phone'] ?? ''),
        'requester_organization' => (string) ($booking['requester_organization'] ?? ''),
        'inquiry_type' => (string) ($booking['inquiry_type'] ?? ''),
        'inquiry_description' => (string) ($booking['inquiry_description'] ?? ''),
        'scheduled_start' => (string) ($booking['scheduled_start'] ?? ''),
        'scheduled_end' => (string) ($booking['scheduled_end'] ?? ''),
        'duration_minutes' => (int) ($booking['duration_minutes'] ?? 0),
        'timezone' => (string) ($booking['timezone'] ?? $booking['profile_timezone'] ?? ''),
        'meeting_format' => (string) ($booking['meeting_format'] ?? ''),
        'profile_id' => !empty($booking['profile_id']) ? (int) $booking['profile_id'] : null,
        'profile_title' => (string) ($booking['profile_title'] ?? ''),
        'booking_mode' => (string) ($booking['booking_mode'] ?? ''),
        'assigned_host_user_id' => !empty($booking['assigned_host_user_id']) ? (int) $booking['assigned_host_user_id'] : null,
        'assigned_host_email' => (string) ($booking['assigned_host_email'] ?? ''),
        'event_id' => !empty($booking['event_id']) ? (int) $booking['event_id'] : null,
        'event_title' => (string) ($booking['event_title'] ?? ''),
        'contact_id' => !empty($booking['contact_id']) ? (int) $booking['contact_id'] : null,
        'contact_name' => $contactName,
        'deal_id' => !empty($booking['deal_id']) ? (int) $booking['deal_id'] : null,
        'deal_title' => (string) ($booking['deal_title'] ?? ''),
        'meeting_bot_status' => (string) ($booking['meeting_bot_status'] ?? ''),
        'meeting_note_apply_status' => (string) ($booking['meeting_note_apply_status'] ?? ''),
        'created_at' => (string) ($booking['created_at'] ?? ''),
        'updated_at' => (string) ($booking['updated_at'] ?? ''),
        'route' => '/bookings',
    ];
}

function mobileCalendarShareSummary(array $share): array
{
    $contactName = trim((string) (($share['contact_first_name'] ?? '') . ' ' . ($share['contact_last_name'] ?? '')));

    return [
        'id' => (int) ($share['id'] ?? 0),
        'status' => (string) ($share['status'] ?? ''),
        'token' => (string) ($share['token'] ?? ''),
        'booking_url' => (string) ($share['booking_url'] ?? ''),
        'profile_id' => !empty($share['profile_id']) ? (int) $share['profile_id'] : null,
        'profile_title' => (string) ($share['profile_title'] ?? ''),
        'contact_id' => !empty($share['contact_id']) ? (int) $share['contact_id'] : null,
        'contact_name' => $contactName,
        'contact_email' => (string) ($share['contact_email'] ?? ''),
        'deal_id' => !empty($share['deal_id']) ? (int) $share['deal_id'] : null,
        'deal_title' => (string) ($share['deal_title'] ?? ''),
        'meeting_purpose' => (string) ($share['meeting_purpose'] ?? ''),
        'custom_purpose' => (string) ($share['custom_purpose'] ?? ''),
        'duration_minutes' => (int) ($share['duration_minutes'] ?? 0),
        'date_from' => (string) ($share['date_from'] ?? ''),
        'date_to' => (string) ($share['date_to'] ?? ''),
        'suggested_slots' => is_array($share['suggested_slots'] ?? null) ? array_values($share['suggested_slots']) : [],
        'expires_at' => (string) ($share['expires_at'] ?? ''),
        'sent_at' => (string) ($share['sent_at'] ?? ''),
        'viewed_at' => (string) ($share['viewed_at'] ?? ''),
        'booked_at' => (string) ($share['booked_at'] ?? ''),
        'copied_at' => (string) ($share['copied_at'] ?? ''),
        'booking_id' => !empty($share['booking_id']) ? (int) $share['booking_id'] : null,
        'created_at' => (string) ($share['created_at'] ?? ''),
        'updated_at' => (string) ($share['updated_at'] ?? ''),
        'route' => '/calendar/share',
    ];
}

function mobileFormPublicUrl(array $form): string
{
    $uuid = trim((string) ($form['uuid'] ?? ''));
    if ($uuid === '') {
        return '';
    }

    $base = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
    $path = function_exists('publicUrl') ? publicUrl('form.php?uuid=' . rawurlencode($uuid)) : '/public/form.php?uuid=' . rawurlencode($uuid);

    return $base !== '' ? $base . $path : $path;
}

function mobileFormSummary(array $form): array
{
    $fields = is_array($form['fields'] ?? null) ? $form['fields'] : mobileDecodeJsonArrayField($form['fields'] ?? null);
    $id = (int) ($form['id'] ?? 0);

    return [
        'id' => $id,
        'uuid' => (string) ($form['uuid'] ?? ''),
        'name' => (string) ($form['name'] ?? ''),
        'field_count' => count($fields),
        'submission_count' => (int) ($form['submission_count'] ?? 0),
        'latest_submission_at' => (string) ($form['latest_submission_at'] ?? ''),
        'public_url' => mobileFormPublicUrl($form),
        'preview_url' => mobileFormPublicUrl($form),
        'created_by_email' => (string) ($form['created_by_email'] ?? ''),
        'created_at' => (string) ($form['created_at'] ?? ''),
        'updated_at' => (string) ($form['updated_at'] ?? ''),
        'submissions_route' => $id > 0 ? '/forms/' . $id . '/submissions' : '/forms',
    ];
}

function mobileFormSubmissionSummary(array $submission): array
{
    $fields = mobileDecodeJsonArrayField($submission['data'] ?? $submission['form_data'] ?? $submission['submission_data'] ?? null);
    $contactName = trim((string) (($submission['first_name'] ?? $submission['contact_first_name'] ?? '') . ' ' . ($submission['last_name'] ?? $submission['contact_last_name'] ?? '')));

    return [
        'id' => (int) ($submission['id'] ?? 0),
        'form_id' => (string) ($submission['form_id'] ?? ''),
        'form_definition_id' => !empty($submission['form_definition_id']) ? (int) $submission['form_definition_id'] : null,
        'contact_id' => !empty($submission['contact_id']) ? (int) $submission['contact_id'] : null,
        'contact_name' => $contactName,
        'contact_email' => (string) ($submission['contact_email'] ?? $submission['email'] ?? ''),
        'fields' => $fields,
        'ai_insight' => (string) ($submission['ai_insight'] ?? $submission['analysis_summary'] ?? ''),
        'source' => (string) ($submission['source'] ?? ''),
        'submitted_at' => (string) ($submission['submitted_at'] ?? $submission['created_at'] ?? ''),
        'created_at' => (string) ($submission['created_at'] ?? ''),
        'contact_route' => !empty($submission['contact_id']) ? '/contacts/' . (int) $submission['contact_id'] : null,
    ];
}

function mobileEmailTemplateSummary(array $template): array
{
    return [
        'id' => (int) ($template['id'] ?? 0),
        'name' => (string) ($template['name'] ?? ''),
        'slug' => (string) ($template['slug'] ?? ''),
        'subject' => (string) ($template['subject'] ?? ''),
        'category' => (string) ($template['category'] ?? ''),
        'description' => (string) ($template['description'] ?? ''),
        'variables' => mobileDecodeJsonArrayField($template['variables'] ?? null),
        'tags' => mobileDecodeJsonArrayField($template['tags'] ?? null),
        'is_ai_generated' => !empty($template['is_ai_generated']),
        'created_at' => (string) ($template['created_at'] ?? ''),
        'updated_at' => (string) ($template['updated_at'] ?? ''),
    ];
}

function mobileEmailSignatureSummary(array $signature): array
{
    return [
        'id' => (int) ($signature['id'] ?? 0),
        'name' => (string) ($signature['name'] ?? ''),
        'content_html' => (string) ($signature['content_html'] ?? ''),
        'content_text' => (string) ($signature['content_text'] ?? ''),
        'is_default' => !empty($signature['is_default']),
        'created_at' => (string) ($signature['created_at'] ?? ''),
        'updated_at' => (string) ($signature['updated_at'] ?? ''),
    ];
}

function mobileWhatsAppTemplateSummary(array $template): array
{
    return [
        'id' => (int) ($template['id'] ?? 0),
        'name' => (string) ($template['name'] ?? $template['template_name'] ?? ''),
        'template_name' => (string) ($template['template_name'] ?? $template['name'] ?? ''),
        'language_code' => (string) ($template['language_code'] ?? $template['language'] ?? ''),
        'category' => (string) ($template['category'] ?? ''),
        'status' => (string) ($template['status'] ?? 'approved'),
        'body_text' => (string) ($template['body_text'] ?? $template['body'] ?? ''),
        'header_text' => (string) ($template['header_text'] ?? ''),
        'footer_text' => (string) ($template['footer_text'] ?? ''),
        'variables' => mobileDecodeJsonArrayField($template['variables_json'] ?? $template['variables'] ?? null),
        'sample_values' => mobileDecodeJsonArrayField($template['sample_values_json'] ?? $template['sample_values'] ?? null),
        'updated_at' => (string) ($template['updated_at'] ?? ''),
    ];
}

function mobileWhatsAppMessageSummary(array $message): array
{
    $contactName = trim((string) (($message['first_name'] ?? $message['contact_first_name'] ?? '') . ' ' . ($message['last_name'] ?? $message['contact_last_name'] ?? '')));
    $body = (string) ($message['message_body'] ?? $message['message'] ?? $message['body'] ?? $message['body_text'] ?? '');

    return [
        'id' => (int) ($message['id'] ?? 0),
        'direction' => (string) ($message['direction'] ?? ''),
        'status' => (string) ($message['status'] ?? ''),
        'message_type' => (string) ($message['message_type'] ?? $message['type'] ?? ''),
        'message' => $body,
        'preview' => substr(trim($body), 0, 180),
        'phone' => (string) ($message['phone'] ?? $message['to_number'] ?? $message['from_number'] ?? $message['to_phone'] ?? $message['from_phone'] ?? $message['contact_phone'] ?? ''),
        'contact_id' => !empty($message['contact_id']) ? (int) $message['contact_id'] : null,
        'contact_name' => $contactName,
        'contact_phone' => (string) ($message['contact_phone'] ?? ''),
        'user_id' => !empty($message['user_id']) ? (int) $message['user_id'] : null,
        'user_email' => (string) ($message['user_email'] ?? ''),
        'provider_message_id' => (string) ($message['message_id'] ?? $message['provider_message_id'] ?? ''),
        'error_message' => (string) ($message['error_message'] ?? ''),
        'created_at' => (string) ($message['created_at'] ?? ''),
        'sent_at' => (string) ($message['sent_at'] ?? ''),
        'contact_route' => !empty($message['contact_id']) ? '/contacts/' . (int) $message['contact_id'] : null,
    ];
}

function mobileCampaignReviewSummary(array $item): array
{
    $preview = (string) ($item['preview'] ?? $item['message'] ?? $item['subject'] ?? '');

    return [
        'id' => (int) ($item['id'] ?? 0),
        'channel' => (string) ($item['channel'] ?? ''),
        'status' => (string) ($item['status'] ?? ''),
        'subject' => (string) ($item['subject'] ?? ''),
        'preview' => substr(trim($preview), 0, 180),
        'contact_id' => !empty($item['contact_id']) ? (int) $item['contact_id'] : null,
        'contact_name' => (string) ($item['contact_name'] ?? ''),
        'recipient' => (string) ($item['recipient'] ?? ''),
        'sent_at' => (string) ($item['sent_at'] ?? ''),
        'created_at' => (string) ($item['created_at'] ?? ''),
        'route' => !empty($item['contact_id']) ? '/contacts/' . (int) $item['contact_id'] : null,
    ];
}

function mobileOrganizationRecord(array $item, string $kind = 'attention'): array
{
    $firstString = static function (array $keys) use ($item): string {
        foreach ($keys as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    };

    $title = $firstString(['title', 'headline', 'label', 'risk', 'action', 'name', 'summary']);
    $detail = $firstString(['detail', 'description', 'message', 'reason', 'evidence', 'summary', 'next_move']);
    if ($detail === $title) {
        $detail = '';
    }
    $severity = strtolower($firstString(['severity', 'priority', 'risk_level', 'status_category', 'status']));
    if (!in_array($severity, ['urgent', 'critical', 'high', 'medium', 'moderate', 'low'], true)) {
        $severity = $kind === 'person' && !empty($item['overloaded']) ? 'high' : 'medium';
    }
    $userId = (int) ($item['target_user_id'] ?? $item['user_id'] ?? $item['employee_id'] ?? $item['id'] ?? 0);
    $userName = $firstString(['user_name', 'employee_name', 'full_name', 'name', 'email', 'user_email']);
    $evidenceSource = $item['evidence_notes'] ?? $item['evidence'] ?? $item['signals'] ?? [];
    $evidence = [];
    foreach (is_array($evidenceSource) ? $evidenceSource : [$evidenceSource] as $evidenceItem) {
        if (is_scalar($evidenceItem)) {
            $value = trim((string) $evidenceItem);
        } elseif (is_array($evidenceItem)) {
            $value = trim((string) ($evidenceItem['title'] ?? $evidenceItem['detail'] ?? $evidenceItem['message'] ?? ''));
        } else {
            $value = '';
        }
        if ($value !== '') {
            $evidence[] = $value;
        }
    }

    $record = [
        'title' => $title !== '' ? $title : ($userName !== '' ? $userName : 'Leadership attention'),
        'detail' => $detail,
        'severity' => $severity,
        'user_id' => $userId > 0 ? $userId : null,
        'user_name' => $userName,
        'workload_score' => (float) ($item['workload_score'] ?? $item['load_score'] ?? 0),
        'open_task_count' => (int) ($item['open_task_count'] ?? $item['open_tasks'] ?? 0),
        'overdue_count' => (int) ($item['overdue_count'] ?? $item['overdue_open_tasks'] ?? 0),
        'evidence' => $evidence,
    ];

    if ($kind === 'candidate') {
        $record += [
            'candidate_key' => (string) ($item['key'] ?? ''),
            'priority' => in_array($severity, ['urgent', 'high', 'medium', 'low'], true) ? $severity : 'medium',
            'due_date_offset' => max(1, min(90, (int) ($item['due_date_offset'] ?? 7))),
            'source_scope' => (string) ($item['source_scope'] ?? 'Organization'),
            'source_risk_type' => (string) ($item['source_risk_type'] ?? 'organization_action_plan'),
        ];
    }

    return $record;
}

function mobileOrganizationIntelligencePayload(array $dashboard, array $actionPlanDraft = []): array
{
    $founderBrief = (array) ($dashboard['founder_brief'] ?? []);
    $summary = (array) ($dashboard['summary'] ?? []);
    $evidence = (array) ($dashboard['evidence_confidence'] ?? []);
    $dataQuality = (array) ($dashboard['data_quality'] ?? []);
    $employees = array_values((array) ($dashboard['employees'] ?? []));
    $thresholds = (array) ($dashboard['settings']['thresholds'] ?? []);
    $overloadThreshold = max(1, (int) ($thresholds['overloaded_task_count'] ?? 7));

    $topPriorities = array_map(
        static fn($item): array => mobileOrganizationRecord(is_array($item) ? $item : ['title' => (string) $item]),
        array_slice((array) ($founderBrief['top_priorities'] ?? $dashboard['leadership_attention'] ?? []), 0, 5)
    );

    $overloadedUsers = array_values(array_filter(
        $employees,
        static function (array $employee) use ($overloadThreshold): bool {
            $open = (int) ($employee['open_tasks'] ?? 0);
            $overdue = (int) ($employee['overdue_open_tasks'] ?? 0);
            $balance = $employee['metrics']['workload_balance'] ?? null;
            return $overdue > 0 || $open >= $overloadThreshold || ($balance !== null && (float) $balance <= 25);
        }
    ));

    return [
        'schema_version' => 2,
        'calculation_version' => (string) ($dashboard['calculation_version'] ?? 'oi-score-v2'),
        'generated_at' => gmdate('c'),
        'filters' => (array) ($dashboard['filters'] ?? []),
        'organization_profile' => (array) ($dashboard['organization_profile'] ?? []),
        'data_quality' => (array) ($dashboard['data_quality'] ?? []),
        'score_eligibility' => [
            'eligible_people' => (int) ($dataQuality['eligible_people_count'] ?? 0),
            'total_people' => (int) ($dataQuality['total_people_count'] ?? count($employees)),
            'coverage' => round((float) ($dataQuality['eligible_ratio'] ?? 0) * 100, 1),
        ],
        'structural_readiness' => (array) ($dashboard['structural_readiness'] ?? []),
        'snapshot_metadata' => (array) ($dashboard['snapshot_metadata'] ?? []),
        'trends' => (array) ($dashboard['operating_trends'] ?? []),
        'function_coverage' => (array) ($dashboard['function_coverage'] ?? []),
        'founder_load' => (array) ($dashboard['founder_load'] ?? []),
        'workload_distribution' => array_map(static fn(array $employee): array => [
            'user_id' => (int) ($employee['id'] ?? 0),
            'name' => (string) ($employee['name'] ?? 'User'),
            'open_tasks' => (int) ($employee['open_tasks'] ?? 0),
            'overdue_open_tasks' => (int) ($employee['overdue_open_tasks'] ?? 0),
            'workload_balance' => ($employee['metrics']['workload_balance'] ?? null) !== null
                ? (float) $employee['metrics']['workload_balance']
                : null,
            'score' => $employee['score'] ?? null,
            'score_status' => (string) ($employee['score_status'] ?? 'insufficient_evidence'),
            'evidence_coverage' => (float) ($employee['evidence_coverage'] ?? 0),
        ], $employees),
        'department_summary' => array_values((array) ($dashboard['department_summaries'] ?? [])),
        'executive_brief' => [
            'headline' => (string) ($founderBrief['headline'] ?? 'Organization brief is ready.'),
            'summary' => (string) ($founderBrief['overall_readout'] ?? $founderBrief['summary'] ?? ''),
            'overall_readout' => (string) ($founderBrief['overall_readout'] ?? $founderBrief['summary'] ?? ''),
            'top_priorities' => $topPriorities,
            'confidence' => $evidence,
            'evidence_notes' => mobileStringList($evidence['notes'] ?? []),
            'employee_count' => (int) ($summary['employee_count'] ?? $summary['total_employees'] ?? count((array) ($dashboard['employees'] ?? []))),
        ],
        'organization_health' => (array) ($dashboard['organization_health'] ?? []),
        'leadership_attention' => array_map(
            static fn($item): array => mobileOrganizationRecord(is_array($item) ? $item : ['title' => (string) $item]),
            array_slice((array) ($dashboard['leadership_attention'] ?? []), 0, 8)
        ),
        'people_risks' => array_map(
            static fn($item): array => mobileOrganizationRecord(is_array($item) ? $item : ['title' => (string) $item], 'risk'),
            array_slice((array) ($dashboard['people_risks'] ?? []), 0, 12)
        ),
        'overloaded_users' => array_map(
            static function (array $employee): array {
                $workloadBalance = $employee['metrics']['workload_balance'] ?? null;
                if ($workloadBalance !== null) {
                    $employee['load_score'] = round(100 - (float) $workloadBalance, 1);
                }
                return mobileOrganizationRecord($employee, 'person');
            },
            $overloadedUsers
        ),
        'action_plan_candidates' => array_map(
            static fn($item): array => mobileOrganizationRecord(is_array($item) ? $item : ['title' => (string) $item], 'candidate'),
            array_slice((array) ($actionPlanDraft['task_candidates'] ?? []), 0, 8)
        ),
        'action_plan' => $actionPlanDraft,
        'recent_coaching_tasks' => [],
    ];
}
