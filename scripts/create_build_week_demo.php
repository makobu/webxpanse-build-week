<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['presenter-email:', 'ttl-hours::', 'json', 'help']);
if (isset($options['help'])) {
    echo "Create the isolated WebXpanse OpenAI Build Week presentation workspace.\n\n";
    echo "Usage:\n";
    echo "  php scripts/create_build_week_demo.php --presenter-email=owner@example.com [--ttl-hours=24] [--json]\n";
    exit(0);
}

$presenterEmail = strtolower(trim((string) ($options['presenter-email'] ?? '')));
if ($presenterEmail === '' || filter_var($presenterEmail, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "A valid --presenter-email is required. Run with --help for usage.\n");
    exit(2);
}

$ttlHours = min(72, max(1, (int) ($options['ttl-hours'] ?? 24)));
$jsonOutput = array_key_exists('json', $options);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';

loadEnvFile(__DIR__ . '/../.env');
require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\PresentationSessionService;

try {
    Database::init(require __DIR__ . '/../config/database.php');

    foreach (['presentation_sessions', 'presentation_access_tokens', 'workspace_skill_installs'] as $requiredTable) {
        if (!Database::tableExists($requiredTable)) {
            throw new RuntimeException(
                'Required table ' . $requiredTable . ' is missing. Run the database migrations before creating the demo workspace.'
            );
        }
    }

    $presenter = Database::queryOne(
        'SELECT id, email FROM users WHERE LOWER(email) = ? LIMIT 1',
        [$presenterEmail]
    ) ?: [];
    $presenterUserId = (int) ($presenter['id'] ?? 0);
    if ($presenterUserId <= 0) {
        throw new RuntimeException('No user was found for presenter email ' . $presenterEmail . '.');
    }

    $result = (new PresentationSessionService())->create([
        'name' => 'OpenAI Build Week Judge',
        'email' => 'judge@webxpanse.example',
        'phone' => '+254700000001',
        'company' => 'WebXpanse',
        'pitch_title' => 'WebXpanse: The Solo Founder Operating Rhythm',
        'audience_key' => 'solo_founders',
        'seed_pack_key' => 'solo_founder',
        'ttl_hours' => $ttlHours,
        'delivery_mode' => 'simulated_first',
        'feature_emphasis' => ['ai_coach', 'founder_command_center', 'evidence', 'commitments'],
    ], $presenterUserId);

    $workspace = (array) ($result['workspace'] ?? []);
    $session = (array) ($result['session'] ?? []);
    $temporaryLogin = (array) ($result['temporary_login'] ?? []);
    $payload = [
        'success' => true,
        'workspace_id' => (int) ($workspace['id'] ?? 0),
        'session_id' => (int) ($session['id'] ?? 0),
        'mode' => 'simulated_first',
        'magic_login_url' => (string) ($temporaryLogin['magic_login_url'] ?? ''),
        'temporary_email' => (string) ($temporaryLogin['email'] ?? ''),
        'temporary_password' => (string) ($temporaryLogin['password'] ?? ''),
        'expires_at' => (string) ($temporaryLogin['expires_at'] ?? ''),
    ];

    if ($jsonOutput) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    echo "Build Week presentation workspace created.\n";
    echo "Mode: simulated first (live delivery is not armed)\n";
    echo 'Magic login: ' . $payload['magic_login_url'] . "\n";
    echo 'Temporary email: ' . $payload['temporary_email'] . "\n";
    echo 'Temporary password: ' . $payload['temporary_password'] . "\n";
    echo 'Expires: ' . $payload['expires_at'] . "\n";
} catch (Throwable $e) {
    if ($jsonOutput) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        fwrite(STDERR, 'Unable to create the Build Week workspace: ' . $e->getMessage() . "\n");
    }
    exit(1);
}
