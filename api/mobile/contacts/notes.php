<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_serializers.php';
require_once dirname(__DIR__) . '/_conversation_resolver.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\Notes;
use CRM\Services\ContactIntelligenceService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mobileJson(['error' => 'Method not allowed.'], 405);
}

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$input = mobileRequestBody();

$contactId = (int) ($input['contact_id'] ?? 0);
if ($contactId <= 0) {
    mobileJson(['error' => 'contact_id is required.'], 422);
}

$contacts = new Contacts();
$contact = $contacts->getById($contactId);
if (!$contact || !mobileCanAccessContact($contact, $userId, $user)) {
    mobileJson(['error' => 'Contact not found or not accessible.'], 404);
}

$action = trim((string) ($input['action'] ?? ''));
if ($action === '') {
    mobileJson(['error' => 'action is required.'], 422);
}

$notes = new Notes();
$contactIntelligence = new ContactIntelligenceService();
$createdNote = null;
$deletedId = null;

try {
    if ($action === 'create') {
        $content = trim((string) ($input['content'] ?? ''));
        if ($content === '') {
            mobileJson(['error' => 'Note content is required.'], 422);
        }

        $noteId = $notes->create([
            'entity_type' => 'contact',
            'entity_id' => $contactId,
            'title' => trim((string) ($input['title'] ?? '')) ?: null,
            'content' => $content,
            'is_private' => !empty($input['is_private']) ? 1 : 0,
            'created_by' => $userId,
        ]);
        $createdNote = $notes->getById($noteId);
    } elseif ($action === 'reply') {
        $parentId = (int) ($input['parent_note_id'] ?? 0);
        $content = trim((string) ($input['content'] ?? ''));
        if ($parentId <= 0) {
            mobileJson(['error' => 'parent_note_id is required.'], 422);
        }
        if ($content === '') {
            mobileJson(['error' => 'Reply content is required.'], 422);
        }

        $parent = $notes->getById($parentId);
        if (!$parent || (string) ($parent['entity_type'] ?? '') !== 'contact' || (int) ($parent['entity_id'] ?? 0) !== $contactId) {
            mobileJson(['error' => 'Parent note not found.'], 404);
        }

        $noteId = $notes->addReply($parentId, $content, $userId);
        $createdNote = $notes->getById($noteId);
    } elseif ($action === 'delete') {
        $noteId = (int) ($input['note_id'] ?? 0);
        if ($noteId <= 0) {
            mobileJson(['error' => 'note_id is required.'], 422);
        }

        $note = $notes->getById($noteId);
        if (!$note || (string) ($note['entity_type'] ?? '') !== 'contact' || (int) ($note['entity_id'] ?? 0) !== $contactId) {
            mobileJson(['error' => 'Note not found.'], 404);
        }

        $canManageAllNotes = Authorization::can('notes.manage_all', $user);
        if ((int) ($note['created_by'] ?? 0) !== $userId && !$canManageAllNotes) {
            mobileJson(['error' => 'You do not have permission to delete this note.'], 403);
        }

        $notes->delete($noteId);
        $deletedId = $noteId;
    } else {
        mobileJson(['error' => 'Unsupported action.'], 422);
    }

    $intelligence = $contactIntelligence->computeAndPersist($contactId) ?? [];
    $freshNotes = $notes->getEntityNotes('contact', $contactId, false, $userId);

    mobileJson([
        'success' => true,
        'data' => [
            'action' => $action,
            'note' => $createdNote ? mobileNoteSummary($createdNote) : null,
            'deleted_note_id' => $deletedId,
            'notes' => array_map('mobileNoteSummary', $freshNotes),
            'timeline' => array_map(
                'mobileTimelineItemSummary',
                $contactIntelligence->buildUnifiedTimeline($contactId, 30)
            ),
            'relationship_summary' => mobileRelationshipSummary(
                is_array($intelligence['relationship_summary'] ?? null)
                    ? $intelligence['relationship_summary']
                    : []
            ),
            'data_quality' => mobileDataQualitySummary(
                is_array($intelligence['data_quality'] ?? null)
                    ? $intelligence['data_quality']
                    : []
            ),
            'generated_at' => (string) ($intelligence['generated_at'] ?? date(DATE_ATOM)),
        ],
    ]);
} catch (Throwable $e) {
    mobileJson(['error' => $e->getMessage()], 422);
}
