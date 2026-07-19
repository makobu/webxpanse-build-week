<?php
/**
 * Verify and add inbox management columns if missing
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || 
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Database;

try {
    $dbConfig = require __DIR__ . '/../../config/database.php';
    Database::init($dbConfig);
    
    $pdo = Database::getInstance();
    
    // Check which columns exist
    $columns = Database::query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = 'communications'"
    );
    
    $existingColumns = array_column($columns, 'COLUMN_NAME');
    
    echo "Existing columns: " . implode(', ', $existingColumns) . "\n\n";
    
    $columnsToAdd = [
        'archived_at' => 'DATETIME NULL',
        'deleted_at' => 'DATETIME NULL',
        'email_id' => 'INT NULL',
        'message_id' => 'VARCHAR(255) NULL',
        'in_reply_to' => 'VARCHAR(255) NULL',
        'from_email' => 'VARCHAR(255) NULL',
        'to_email' => 'VARCHAR(255) NULL',
    ];
    
    $added = [];
    foreach ($columnsToAdd as $column => $definition) {
        if (!in_array($column, $existingColumns)) {
            echo "Adding column: $column\n";
            try {
                $pdo->exec("ALTER TABLE communications ADD COLUMN $column $definition");
                $added[] = $column;
                echo "  ✓ Added $column\n";
            } catch (\PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate column') === false) {
                    echo "  ✗ Error adding $column: " . $e->getMessage() . "\n";
                } else {
                    echo "  - Column $column already exists\n";
                }
            }
        } else {
            echo "  - Column $column already exists\n";
        }
    }
    
    // Add indexes
    $indexes = [
        'idx_archived_at' => 'archived_at',
        'idx_deleted_at' => 'deleted_at',
        'idx_message_id' => 'message_id',
        'idx_email_id' => 'email_id',
        'idx_from_email' => 'from_email',
    ];
    
    echo "\nChecking indexes...\n";
    foreach ($indexes as $indexName => $column) {
        if (in_array($column, $existingColumns) || in_array($column, $added)) {
            try {
                $pdo->exec("CREATE INDEX $indexName ON communications($column)");
                echo "  ✓ Created index $indexName\n";
            } catch (\PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate key') === false) {
                    echo "  ✗ Error creating index $indexName: " . $e->getMessage() . "\n";
                } else {
                    echo "  - Index $indexName already exists\n";
                }
            }
        }
    }
    
    // Add foreign key (only if constraint does not already exist)
    if (in_array('email_id', $existingColumns) || in_array('email_id', $added)) {
        // Check if FK already exists
        $fkExists = Database::query(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'communications'
             AND CONSTRAINT_NAME = 'fk_communications_email_id' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        if (!empty($fkExists)) {
            echo "  - Foreign key fk_communications_email_id already exists\n";
        } else {
            // Clean orphaned email_id (required for FK): use LEFT JOIN to avoid subquery issues
            try {
                $cleaned = $pdo->exec(
                    "UPDATE communications c " .
                    "LEFT JOIN emails e ON e.id = c.email_id " .
                    "SET c.email_id = NULL " .
                    "WHERE c.email_id IS NOT NULL AND (c.email_id = 0 OR e.id IS NULL)"
                );
                if ($cleaned > 0) {
                    echo "  - Set email_id = NULL for $cleaned row(s) with invalid or missing email reference\n";
                }
            } catch (\PDOException $e) {
                echo "  - Could not clean orphaned email_id: " . $e->getMessage() . "\n";
            }
            // Ensure column type matches emails.id exactly (avoids errno 150)
            $colEmails = Database::queryOne(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS 
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'emails' AND COLUMN_NAME = 'id'"
            );
            if ($colEmails) {
                $refType = $colEmails['COLUMN_TYPE'];
            try {
                $pdo->exec("ALTER TABLE communications MODIFY COLUMN email_id $refType NULL");
                echo "  - Ensured communications.email_id type matches emails.id ($refType)\n";
            } catch (\PDOException $e) {
                echo "  - Note: could not align column type: " . $e->getMessage() . "\n";
            }
            // Recreate index so it is a proper B-tree leftmost index for the FK (can fix errno 150 on some MySQL versions)
            try {
                $pdo->exec("ALTER TABLE communications DROP INDEX idx_email_id");
            } catch (\PDOException $e) {
                /* ignore if index does not exist */
            }
            try {
                $pdo->exec("CREATE INDEX idx_email_id ON communications (email_id)");
            } catch (\PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate key') === false) {
                    echo "  - Note: could not recreate index: " . $e->getMessage() . "\n";
                }
            }
            try {
                $pdo->exec("ALTER TABLE communications ADD CONSTRAINT fk_communications_email_id FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE SET NULL");
                echo "  ✓ Added foreign key fk_communications_email_id\n";
            } catch (\PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate key') === false && strpos($e->getMessage(), 'already exists') === false) {
                    echo "  ✗ Error adding foreign key: " . $e->getMessage() . "\n";
                    echo "    (Application will work without this FK; referential integrity is not enforced for email_id.)\n";
                } else {
                    echo "  - Foreign key already exists\n";
                }
            }
            }
        }
    }
    
    // Check email_fetch_log table
    echo "\nChecking email_fetch_log table...\n";
    $tables = Database::query(
        "SELECT TABLE_NAME FROM information_schema.TABLES 
         WHERE TABLE_SCHEMA = DATABASE() 
         AND TABLE_NAME = 'email_fetch_log'"
    );
    
    if (empty($tables)) {
        echo "Creating email_fetch_log table...\n";
        $pdo->exec("
            CREATE TABLE email_fetch_log (
                id INT PRIMARY KEY AUTO_INCREMENT,
                last_uid INT DEFAULT NULL,
                last_fetch_at DATETIME DEFAULT NULL,
                emails_fetched INT DEFAULT 0,
                status ENUM('success','failed') DEFAULT 'success',
                error_message TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_last_fetch_at (last_fetch_at),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "  ✓ Created email_fetch_log table\n";
    } else {
        echo "  - email_fetch_log table already exists\n";
    }
    
    echo "\n✓ Verification complete!\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
