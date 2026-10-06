<?php
/**
 * CLI setup: creates management tables (parts, booking_parts, invoices,
 * invoice_items, expenses, audit_log).
 *
 * Run:  php scripts/setup_management_modules.php
 */
require __DIR__ . '/../db.php';
require __DIR__ . '/../management_helper.php';

if (php_sapi_name() !== 'cli') {
    die("Run this from the command line: php scripts/setup_management_modules.php\n");
}

echo "Management modules setup\n";
echo str_repeat('-', 40) . "\n";

ensure_management_tables($pdo);

foreach (['parts', 'booking_parts', 'invoices', 'invoice_items', 'expenses', 'audit_log'] as $t) {
    try {
        $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
        echo "[OK] $t\n";
    } catch (PDOException $e) {
        echo "[FAIL] $t — " . $e->getMessage() . "\n";
    }
}

echo "Done.\n";
