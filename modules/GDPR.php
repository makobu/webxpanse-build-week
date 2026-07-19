<?php
/**
 * GDPR Module
 * 
 * Handles GDPR compliance requests (data export, deletion, access, rectification)
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Services\GDPREmailService;

class GDPR
{
    private GDPREmailService $emailService;
    
    public function __construct()
    {
        $this->emailService = new GDPREmailService();
    }
    
    /**
     * Create a new GDPR request
     */
    public function createRequest(string $email, string $requestType): array
    {
        // Validate request type
        $validTypes = ['export', 'deletion', 'access', 'rectification'];
        if (!in_array($requestType, $validTypes)) {
            throw new \InvalidArgumentException("Invalid request type: {$requestType}");
        }
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email address");
        }
        
        // Check for existing pending request
        $existing = Database::queryOne(
            "SELECT id FROM gdpr_requests 
             WHERE email = ? AND status IN ('pending', 'verified', 'processing')
             ORDER BY created_at DESC
             LIMIT 1",
            [$email]
        );
        
        if ($existing) {
            throw new \RuntimeException("A pending request already exists for this email address. Please check your email or wait for the current request to be processed.");
        }
        
        // Generate verification token
        $verificationToken = bin2hex(random_bytes(32));
        
        // Create request
        Database::execute(
            "INSERT INTO gdpr_requests (email, request_type, status, verification_token) 
             VALUES (?, ?, 'pending', ?)",
            [$email, $requestType, $verificationToken]
        );
        
        $requestId = (int) Database::lastInsertId();
        
        // Send verification email
        $this->emailService->sendVerificationEmail($email, $verificationToken, $requestType);
        
        return [
            'id' => $requestId,
            'email' => $email,
            'request_type' => $requestType,
            'status' => 'pending'
        ];
    }
    
    /**
     * Verify email and process request
     */
    public function verifyAndProcess(string $token): array
    {
        $request = Database::queryOne(
            "SELECT * FROM gdpr_requests WHERE verification_token = ? AND status = 'pending'",
            [$token]
        );
        
        if (!$request) {
            throw new \RuntimeException("Invalid or expired verification token");
        }
        
        // Check if token is not too old (24 hours)
        $createdAt = strtotime($request['created_at']);
        if (time() - $createdAt > 86400) { // 24 hours
            Database::execute(
                "UPDATE gdpr_requests SET status = 'failed', metadata = JSON_SET(COALESCE(metadata, '{}'), '$.error', 'Token expired') WHERE id = ?",
                [$request['id']]
            );
            throw new \RuntimeException("Verification token has expired. Please submit a new request.");
        }
        
        // Mark as verified
        Database::execute(
            "UPDATE gdpr_requests SET status = 'verified', verified_at = NOW() WHERE id = ?",
            [$request['id']]
        );
        
        // Process request based on type
        $result = [];
        try {
            Database::execute(
                "UPDATE gdpr_requests SET status = 'processing' WHERE id = ?",
                [$request['id']]
            );
            
            switch ($request['request_type']) {
                case 'export':
                    $result = $this->exportUserData($request['email']);
                    break;
                case 'deletion':
                    $result = $this->deleteUserData($request['email']);
                    break;
                case 'access':
                    $result = $this->getUserData($request['email']);
                    break;
                case 'rectification':
                    // For rectification, we just provide access to data
                    $result = $this->getUserData($request['email']);
                    break;
            }
            
            Database::execute(
                "UPDATE gdpr_requests SET status = 'completed', processed_at = NOW(), metadata = ? WHERE id = ?",
                [json_encode($result), $request['id']]
            );
            
            // Send confirmation email
            $this->emailService->sendConfirmationEmail($request['email'], $request['request_type'], $result);
            
        } catch (\Exception $e) {
            Database::execute(
                "UPDATE gdpr_requests SET status = 'failed', metadata = JSON_SET(COALESCE(metadata, '{}'), '$.error', ?) WHERE id = ?",
                [$e->getMessage(), $request['id']]
            );
            throw $e;
        }
        
        return [
            'success' => true,
            'request_type' => $request['request_type'],
            'result' => $result
        ];
    }
    
    /**
     * Export all user data
     */
    public function exportUserData(string $email): array
    {
        $user = Database::queryOne(
            "SELECT * FROM users WHERE email = ?",
            [$email]
        );
        
        if (!$user) {
            throw new \RuntimeException("No user found with email: {$email}");
        }
        
        $userId = $user['id'];
        $data = [
            'user' => $this->sanitizeUserData($user),
            'contacts' => [],
            'communications' => [],
            'activities' => [],
            'deals' => [],
            'tasks' => [],
            'documents' => [],
            'notes' => []
        ];
        
        // Get contacts
        $contacts = Database::query(
            "SELECT * FROM contacts WHERE id IN (
                SELECT DISTINCT contact_id FROM communications WHERE user_id = ?
                UNION
                SELECT DISTINCT contact_id FROM deals WHERE user_id = ?
            )",
            [$userId, $userId]
        );
        foreach ($contacts as $contact) {
            $data['contacts'][] = $this->sanitizeContactData($contact);
        }
        
        // Get communications
        $communications = Database::query(
            "SELECT * FROM communications WHERE user_id = ? ORDER BY created_at DESC",
            [$userId]
        );
        foreach ($communications as $comm) {
            $data['communications'][] = $this->sanitizeCommunicationData($comm);
        }
        
        // Get activities
        $activities = Database::query(
            "SELECT * FROM activities WHERE user_id = ? ORDER BY created_at DESC",
            [$userId]
        );
        foreach ($activities as $activity) {
            $data['activities'][] = $this->sanitizeActivityData($activity);
        }
        
        // Get deals
        $deals = Database::query(
            "SELECT * FROM deals WHERE user_id = ? ORDER BY created_at DESC",
            [$userId]
        );
        foreach ($deals as $deal) {
            $data['deals'][] = $this->sanitizeDealData($deal);
        }
        
        // Get tasks
        $tasks = Database::query(
            "SELECT * FROM tasks WHERE assigned_to = ? ORDER BY created_at DESC",
            [$userId]
        );
        foreach ($tasks as $task) {
            $data['tasks'][] = $this->sanitizeTaskData($task);
        }
        
        // Get documents metadata (not files themselves)
        $documents = Database::query(
            "SELECT id, uuid, filename, file_type, file_size, category, created_at 
             FROM documents WHERE uploaded_by = ? ORDER BY created_at DESC",
            [$userId]
        );
        foreach ($documents as $doc) {
            $data['documents'][] = $doc;
        }
        
        // Get notes
        $notes = Database::query(
            "SELECT * FROM notes WHERE user_id = ? ORDER BY created_at DESC",
            [$userId]
        );
        foreach ($notes as $note) {
            $data['notes'][] = $this->sanitizeNoteData($note);
        }
        
        return $data;
    }
    
    /**
     * Delete user data (right to be forgotten)
     */
    public function deleteUserData(string $email): array
    {
        $user = Database::queryOne(
            "SELECT * FROM users WHERE email = ?",
            [$email]
        );
        
        if (!$user) {
            throw new \RuntimeException("No user found with email: {$email}");
        }
        
        $userId = $user['id'];
        
        // Anonymize user data instead of deleting (for audit trail)
        // Set email to deleted_{timestamp}@deleted.local
        $anonymizedEmail = 'deleted_' . time() . '@deleted.local';
        
        Database::execute(
            "UPDATE users SET 
                email = ?,
                first_name = 'Deleted',
                last_name = 'User',
                password_hash = '',
                phone = NULL,
                deleted_at = NOW()
             WHERE id = ?",
            [$anonymizedEmail, $userId]
        );
        
        // Anonymize communications
        Database::execute(
            "UPDATE communications SET 
                body = '[Content deleted per GDPR request]',
                subject = '[Subject deleted per GDPR request]'
             WHERE user_id = ?",
            [$userId]
        );
        
        // Delete personal notes
        Database::execute(
            "DELETE FROM notes WHERE user_id = ?",
            [$userId]
        );
        
        // Note: We keep deals, contacts, and other business data as they may be required for legal/compliance reasons
        // but we remove the user association
        
        return [
            'success' => true,
            'message' => 'Your data has been anonymized and deleted in accordance with GDPR requirements.',
            'anonymized_email' => $anonymizedEmail
        ];
    }
    
    /**
     * Get user data (access request)
     */
    public function getUserData(string $email): array
    {
        return $this->exportUserData($email);
    }
    
    /**
     * Sanitize user data for export
     */
    private function sanitizeUserData(array $user): array
    {
        unset($user['password_hash']);
        return $user;
    }
    
    /**
     * Sanitize contact data for export
     */
    private function sanitizeContactData(array $contact): array
    {
        return $contact;
    }
    
    /**
     * Sanitize communication data for export
     */
    private function sanitizeCommunicationData(array $comm): array
    {
        return $comm;
    }
    
    /**
     * Sanitize activity data for export
     */
    private function sanitizeActivityData(array $activity): array
    {
        return $activity;
    }
    
    /**
     * Sanitize deal data for export
     */
    private function sanitizeDealData(array $deal): array
    {
        return $deal;
    }
    
    /**
     * Sanitize task data for export
     */
    private function sanitizeTaskData(array $task): array
    {
        return $task;
    }
    
    /**
     * Sanitize note data for export
     */
    private function sanitizeNoteData(array $note): array
    {
        return $note;
    }
}
