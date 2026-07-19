<?php
/**
 * WhatsApp Cloud API Migration Page
 * 
 * Admin interface for migrating WhatsApp Business numbers from On-Premises API to Cloud API
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Services\WhatsAppMigrationService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

// Require permission
$user = Auth::user();
if (!Authorization::can('feature.whatsapp_migration', $user)) {
    header('Location: dashboard.php');
    exit;
}

// Get migration status
$migrationService = new WhatsAppMigrationService();
$migrationStatus = $migrationService->getMigrationStatus();

$pageTitle = 'WhatsApp Cloud API Migration - ' . brandProductName();
ob_start();
?>

<div style="margin-bottom: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">WhatsApp Cloud API Registration</h1>
    <p style="color: var(--charcoal-grey);">Register your phone number with WhatsApp Cloud API or migrate from On-Premises API</p>
    
    <div style="background: #fff3cd; border: 1px solid #ffc107; padding: var(--spacing-md); border-radius: 4px; margin-top: var(--spacing-md);">
        <p style="color: #856404; font-size: 14px; margin-bottom: var(--spacing-sm); font-weight: 600;">
            <i class="fas fa-lightbulb" style="margin-right: 6px;"></i>Two Use Cases:
        </p>
        <ul style="color: #856404; font-size: 13px; margin: 0; padding-left: 20px;">
            <li style="margin-bottom: var(--spacing-xs);">
                <strong>Simple Registration:</strong> Register a phone number that's in "Pending" status in WhatsApp Manager. 
                <span style="color: #856404;">Skip Step 2</span> and go directly to Step 3 with just your PIN.
            </li>
            <li>
                <strong>On-Premises Migration:</strong> Migrate from On-Premises API to Cloud API while preserving your Official Business Account (OBA) status. 
                <span style="color: #856404;">Complete all steps</span> including Step 2 (metadata generation).
            </li>
        </ul>
    </div>
</div>

<!-- Migration Status -->
<div id="migration-status" style="background: white; border: 1px solid var(--border-color); border-radius: 8px; padding: var(--spacing-lg); margin-bottom: var(--spacing-lg);">
    <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 1.25rem;">
        <i class="fas fa-info-circle" style="margin-right: 8px; color: var(--accent-blue);"></i>Current Migration Status
    </h2>
    <div id="status-content">
        <?php if ($migrationStatus): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--spacing-md);">
                <div>
                    <strong style="color: var(--charcoal-grey);">Latest Step:</strong>
                    <div style="color: var(--midnight-black); font-weight: 500;">
                        <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $migrationStatus['latest']['step']))); ?>
                    </div>
                </div>
                <div>
                    <strong style="color: var(--charcoal-grey);">Status:</strong>
                    <div style="color: <?php echo $migrationStatus['latest']['success'] ? '#3c3' : '#c33'; ?>; font-weight: 500;">
                        <?php echo $migrationStatus['latest']['success'] ? '✓ Success' : '✗ Failed'; ?>
                    </div>
                </div>
                <div>
                    <strong style="color: var(--charcoal-grey);">Last Updated:</strong>
                    <div style="color: var(--midnight-black);">
                        <?php echo date('Y-m-d H:i:s', strtotime($migrationStatus['latest']['created_at'])); ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <p style="color: var(--charcoal-grey);">No migration steps have been performed yet.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Messages -->
<div id="message-container" style="margin-bottom: var(--spacing-lg);"></div>

<!-- Step 2: Generate Metadata -->
<div style="background: white; border: 1px solid var(--border-color); border-radius: 8px; padding: var(--spacing-lg); margin-bottom: var(--spacing-lg);">
    <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 1.25rem;">
        <i class="fas fa-key" style="margin-right: 8px; color: var(--accent-blue);"></i>Step 2: Generate Phone Number Metadata <span style="color: var(--charcoal-grey); font-size: 14px; font-weight: normal;">(Optional)</span>
    </h2>
    <div style="background: #e7f3ff; border: 1px solid #0066cc; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
        <p style="color: var(--midnight-black); font-size: 13px; margin: 0; font-weight: 600;">
            <i class="fas fa-info-circle" style="margin-right: 6px;"></i>When is this needed?
        </p>
        <p style="color: var(--charcoal-grey); font-size: 12px; margin: var(--spacing-xs) 0 0 0;">
            <strong>Skip this step</strong> if you're just registering a pending phone number (not migrating from On-Premises API).<br>
            <strong>Use this step</strong> only if you're migrating from On-Premises API and want to preserve your Official Business Account (OBA) status.
        </p>
    </div>
    <p style="color: var(--charcoal-grey); margin-bottom: var(--spacing-md);">
        Generate metadata from your On-Premises API. This metadata is required to preserve your Official Business Account (OBA) status during migration.
    </p>
    
    <form id="generate-metadata-form" style="display: flex; flex-direction: column; gap: var(--spacing-md); max-width: 600px;">
        <div>
            <label for="metadata-password" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">
                Secure Password <span style="color: #c33;">*</span>
            </label>
            <input 
                type="password" 
                id="metadata-password" 
                name="password" 
                required
                minlength="8"
                placeholder="Enter a secure password (min 8 characters)"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
            <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                This password will be used to encode the metadata. Save it securely - you'll need it in Step 3.
            </small>
        </div>
        
        <div style="display: flex; gap: var(--spacing-md);">
            <button 
                type="submit" 
                id="generate-metadata-btn"
                style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
            >
                <i class="fas fa-magic" style="margin-right: 6px;"></i>Generate Metadata
            </button>
        </div>
    </form>
    
    <div id="metadata-result" style="margin-top: var(--spacing-md); display: none;">
        <div style="background: #f5f5f5; border: 1px solid var(--border-color); border-radius: 4px; padding: var(--spacing-md);">
            <strong style="color: var(--midnight-black); display: block; margin-bottom: var(--spacing-sm);">Generated Metadata:</strong>
            <textarea 
                id="generated-metadata" 
                readonly 
                style="width: 100%; min-height: 100px; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-family: monospace; font-size: 12px;"
            ></textarea>
            <small style="color: var(--charcoal-grey); display: block; margin-top: var(--spacing-xs);">
                <i class="fas fa-exclamation-triangle" style="color: #f90;"></i>
                <strong>Important:</strong> Copy this metadata string and the password you used. You'll need both in Step 3.
            </small>
        </div>
    </div>
</div>

<!-- Step 3: Register Number -->
<div style="background: white; border: 1px solid var(--border-color); border-radius: 8px; padding: var(--spacing-lg); margin-bottom: var(--spacing-lg);">
    <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 1.25rem;">
        <i class="fas fa-phone-alt" style="margin-right: 8px; color: var(--accent-blue);"></i>Step 3: Register Number with Cloud API
    </h2>
    <p style="color: var(--charcoal-grey); margin-bottom: var(--spacing-md);">
        Register your phone number with Cloud API. This will activate a phone number that's currently in "Pending" status in WhatsApp Manager.
    </p>
    <p style="color: var(--charcoal-grey); margin-bottom: var(--spacing-md); font-size: 13px;">
        <strong>Note:</strong> Metadata (from Step 2) is only required if you're migrating from On-Premises API to preserve your Official Business Account (OBA) status. For simple registration of pending phone numbers, you can skip Step 2 and leave metadata empty.
    </p>
    
    <form id="register-number-form" style="display: flex; flex-direction: column; gap: var(--spacing-md); max-width: 600px;">
        <div>
            <label for="register-pin" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">
                Phone Number PIN (6 digits) <span style="color: #c33;">*</span>
            </label>
            <input 
                type="text" 
                id="register-pin" 
                name="pin" 
                required
                pattern="[0-9]{6}"
                maxlength="6"
                placeholder="000000"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
            <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                The 6-digit PIN for your WhatsApp Business phone number's two-step verification.
            </small>
        </div>
        
        <div>
            <label for="register-password" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">
                Password (from Step 2) <span style="color: var(--charcoal-grey); font-weight: normal;">(Optional)</span>
            </label>
            <input 
                type="password" 
                id="register-password" 
                name="password" 
                placeholder="Enter the password you used in Step 2 (only if migrating from On-Premises API)"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
            <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                Only required if you generated metadata in Step 2 (On-Premises migration). Leave empty for simple registration.
            </small>
        </div>
        
        <div>
            <label for="register-metadata" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">
                Metadata (from Step 2) <span style="color: var(--charcoal-grey); font-weight: normal;">(Optional)</span>
            </label>
            <textarea 
                id="register-metadata" 
                name="metadata" 
                placeholder="Paste the metadata string from Step 2 (only if migrating from On-Premises API to preserve OBA status)"
                style="width: 100%; min-height: 100px; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-family: monospace; font-size: 12px;"
            ></textarea>
            <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                <strong>When to use:</strong> Only if you're migrating from On-Premises API and want to preserve your Official Business Account (OBA) status. For simple registration of pending phone numbers, leave this empty.
            </small>
        </div>
        
        <div>
            <label for="data-localization-region" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">
                Data Localization Region (Optional)
            </label>
            <select 
                id="data-localization-region" 
                name="data_localization_region"
                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
            >
                <option value="">None (Global)</option>
                <optgroup label="APAC">
                    <option value="AU">Australia (AU)</option>
                    <option value="ID">Indonesia (ID)</option>
                    <option value="IN">India (IN)</option>
                    <option value="JP">Japan (JP)</option>
                    <option value="SG">Singapore (SG)</option>
                    <option value="KR">South Korea (KR)</option>
                </optgroup>
                <optgroup label="Europe">
                    <option value="DE">EU - Germany (DE)</option>
                    <option value="CH">Switzerland (CH)</option>
                    <option value="GB">United Kingdom (GB)</option>
                </optgroup>
                <optgroup label="LATAM">
                    <option value="BR">Brazil (BR)</option>
                </optgroup>
                <optgroup label="MEA">
                    <option value="BH">Bahrain (BH)</option>
                    <option value="ZA">South Africa (ZA)</option>
                    <option value="AE">United Arab Emirates (AE)</option>
                </optgroup>
                <optgroup label="NORAM">
                    <option value="CA">Canada (CA)</option>
                </optgroup>
            </select>
            <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                Enable local storage for data-at-rest. Once enabled, cannot be disabled or changed directly.
            </small>
        </div>
        
        <div style="display: flex; gap: var(--spacing-md);">
            <button 
                type="submit" 
                id="register-number-btn"
                style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
            >
                <i class="fas fa-check-circle" style="margin-right: 6px;"></i>Register Number
            </button>
        </div>
    </form>
</div>

<!-- Step 4: Health Check -->
<div style="background: white; border: 1px solid var(--border-color); border-radius: 8px; padding: var(--spacing-lg); margin-bottom: var(--spacing-lg);">
    <h2 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 1.25rem;">
        <i class="fas fa-heartbeat" style="margin-right: 8px; color: var(--accent-blue);"></i>Step 4: Check Messaging Health Status
    </h2>
    <p style="color: var(--charcoal-grey); margin-bottom: var(--spacing-md);">
        Verify that your phone number is healthy and ready to send messages.
    </p>
    
    <button 
        type="button" 
        id="check-health-btn"
        onclick="checkHealthStatus()"
        style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
    >
        <i class="fas fa-stethoscope" style="margin-right: 6px;"></i>Check Health Status
    </button>
    
    <div id="health-result" style="margin-top: var(--spacing-md); display: none;">
        <div id="health-status-box" style="padding: var(--spacing-md); border-radius: 4px; border: 2px solid;">
            <div id="health-status-content"></div>
        </div>
    </div>
</div>

<!-- Deregister Phone Number -->
<div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; padding: var(--spacing-lg); margin-bottom: var(--spacing-lg);">
    <h2 style="color: #856404; margin-bottom: var(--spacing-md); font-size: 1.25rem;">
        <i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i>Deregister Phone Number
    </h2>
    <p style="color: #856404; margin-bottom: var(--spacing-md);">
        <strong>Warning:</strong> Deregistering your phone number makes it unusable with Cloud API and disables local storage (if enabled). 
        This action does NOT delete the number or its message history.
    </p>
    <p style="color: #856404; margin-bottom: var(--spacing-md);">
        <strong>Limitations:</strong>
    </p>
    <ul style="color: #856404; margin-left: var(--spacing-lg); margin-bottom: var(--spacing-md);">
        <li>Cannot deregister if number is in use with both Cloud API and WhatsApp Business app</li>
        <li>Limited to 10 requests per number in a 72-hour window</li>
        <li>If you exceed the limit, you'll be blocked for 72 hours (error code 133016)</li>
    </ul>
    
    <form id="deregister-form" style="display: flex; flex-direction: column; gap: var(--spacing-md); max-width: 600px;">
        <div>
            <label style="display: flex; align-items: center; gap: var(--spacing-sm);">
                <input 
                    type="checkbox" 
                    id="deregister-confirm" 
                    required
                    style="width: 18px; height: 18px;"
                >
                <span style="color: #856404; font-weight: 500;">
                    I understand the consequences and want to deregister this phone number
                </span>
            </label>
        </div>
        
        <div style="display: flex; gap: var(--spacing-md);">
            <button 
                type="submit" 
                id="deregister-btn"
                style="background: #dc3545; color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
            >
                <i class="fas fa-times-circle" style="margin-right: 6px;"></i>Deregister Phone Number
            </button>
        </div>
    </form>
</div>

<script>
// Get CSRF token
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

// Show message
function showMessage(message, type = 'success') {
    const container = document.getElementById('message-container');
    const bgColor = type === 'success' ? '#efe' : '#fee';
    const borderColor = type === 'success' ? '#cfc' : '#fcc';
    const textColor = type === 'success' ? '#3c3' : '#c33';
    
    container.innerHTML = `
        <div style="background: ${bgColor}; border: 1px solid ${borderColor}; color: ${textColor}; padding: var(--spacing-md); border-radius: 4px;">
            ${message}
        </div>
    `;
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        container.innerHTML = '';
    }, 5000);
}

// Generate Metadata
document.getElementById('generate-metadata-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const btn = document.getElementById('generate-metadata-btn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right: 6px;"></i>Generating...';
    
    const password = document.getElementById('metadata-password').value;
    
    try {
        const response = await fetch('../api/whatsapp/migrate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                action: 'generate_metadata',
                password: password,
                csrf_token: csrfToken
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('generated-metadata').value = data.metadata;
            document.getElementById('metadata-result').style.display = 'block';
            showMessage('Metadata generated successfully! Copy the metadata string and password.', 'success');
            
            // Auto-fill register form
            document.getElementById('register-password').value = password;
            document.getElementById('register-metadata').value = data.metadata;
        } else {
            showMessage('Error: ' + (data.error || 'Failed to generate metadata'), 'error');
        }
    } catch (error) {
        showMessage('Error: ' + error.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
});

// Register Number
document.getElementById('register-number-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const btn = document.getElementById('register-number-btn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right: 6px;"></i>Registering...';
    
    const pin = document.getElementById('register-pin').value;
    const password = document.getElementById('register-password').value || null;
    const metadata = document.getElementById('register-metadata').value || null;
    const dataLocalizationRegion = document.getElementById('data-localization-region').value || null;
    
    // Build request body - only include non-empty optional fields
    const requestBody = {
        action: 'register_number',
        pin: pin,
        csrf_token: csrfToken
    };
    
    if (password) {
        requestBody.password = password;
    }
    
    if (metadata) {
        requestBody.metadata = metadata;
    }
    
    if (dataLocalizationRegion) {
        requestBody.data_localization_region = dataLocalizationRegion;
    }
    
    try {
        const response = await fetch('../api/whatsapp/migrate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(requestBody)
        });
        
        const data = await response.json();
        
        if (data.success) {
            showMessage('Phone number registered successfully with Cloud API!', 'success');
            // Refresh status
            loadMigrationStatus();
        } else {
            showMessage('Error: ' + (data.error || 'Failed to register number'), 'error');
        }
    } catch (error) {
        showMessage('Error: ' + error.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
});

// Check Health Status
async function checkHealthStatus() {
    const btn = document.getElementById('check-health-btn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right: 6px;"></i>Checking...';
    
    try {
        const response = await fetch('../api/whatsapp/migrate.php?health=1', {
            headers: {
                'X-CSRF-Token': csrfToken
            }
        });
        
        const data = await response.json();
        
        if (data.success && data.health) {
            const health = data.health;
            const statusColor = health.status_color || 'UNKNOWN';
            const bgColor = statusColor === 'GREEN' ? '#d4edda' : (statusColor === 'YELLOW' ? '#fff3cd' : '#f8d7da');
            const borderColor = statusColor === 'GREEN' ? '#28a745' : (statusColor === 'YELLOW' ? '#ffc107' : '#dc3545');
            const textColor = statusColor === 'GREEN' ? '#155724' : (statusColor === 'YELLOW' ? '#856404' : '#721c24');
            
            document.getElementById('health-status-box').style.background = bgColor;
            document.getElementById('health-status-box').style.borderColor = borderColor;
            document.getElementById('health-status-content').innerHTML = `
                <div style="color: ${textColor};">
                    <strong style="font-size: 1.1rem;">Health Status: ${statusColor}</strong>
                    <div style="margin-top: var(--spacing-sm);">
                        <div>Can Send Messages: ${health.can_send_message ? 'Yes ✓' : 'No ✗'}</div>
                        <div>Status: ${health.health_status || 'Unknown'}</div>
                    </div>
                </div>
            `;
            document.getElementById('health-result').style.display = 'block';
        } else {
            showMessage('Error: ' + (data.error || 'Failed to check health status'), 'error');
        }
    } catch (error) {
        showMessage('Error: ' + error.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

// Deregister Number
document.getElementById('deregister-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    if (!confirm('Are you sure you want to deregister this phone number? This action cannot be easily undone.')) {
        return;
    }
    
    const btn = document.getElementById('deregister-btn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right: 6px;"></i>Deregistering...';
    
    try {
        const response = await fetch('../api/whatsapp/migrate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                action: 'deregister_number',
                confirm: true,
                csrf_token: csrfToken
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showMessage('Phone number deregistered successfully.', 'success');
            // Refresh status
            loadMigrationStatus();
        } else {
            showMessage('Error: ' + (data.error || 'Failed to deregister number'), 'error');
        }
    } catch (error) {
        showMessage('Error: ' + error.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
});

// Load Migration Status
async function loadMigrationStatus() {
    try {
        const response = await fetch('../api/whatsapp/migrate.php?status=1', {
            headers: {
                'X-CSRF-Token': csrfToken
            }
        });
        
        const data = await response.json();
        
        if (data.success && data.status) {
            const status = data.status;
            const statusHtml = `
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--spacing-md);">
                    <div>
                        <strong style="color: var(--charcoal-grey);">Latest Step:</strong>
                        <div style="color: var(--midnight-black); font-weight: 500;">
                            ${status.latest ? status.latest.step.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) : 'None'}
                        </div>
                    </div>
                    <div>
                        <strong style="color: var(--charcoal-grey);">Status:</strong>
                        <div style="color: ${status.latest && status.latest.success ? '#3c3' : '#c33'}; font-weight: 500;">
                            ${status.latest && status.latest.success ? '✓ Success' : '✗ Failed'}
                        </div>
                    </div>
                    <div>
                        <strong style="color: var(--charcoal-grey);">Last Updated:</strong>
                        <div style="color: var(--midnight-black);">
                            ${status.latest ? new Date(status.latest.created_at).toLocaleString() : 'Never'}
                        </div>
                    </div>
                </div>
            `;
            document.getElementById('status-content').innerHTML = statusHtml;
        }
    } catch (error) {
        console.error('Failed to load migration status:', error);
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
