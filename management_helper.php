<?php
/**
 * Management module helpers: audit log, parts inventory, invoices, expenses.
 *
 * All functions are fail-open: they log errors and degrade gracefully so a
 * missing table or failed write never breaks a booking/payment flow.
 */

/**
 * Create the management tables if they do not exist (runs once per request).
 */
function ensure_management_tables($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;

    $ddl = [
        "CREATE TABLE IF NOT EXISTS parts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            sku VARCHAR(50) DEFAULT NULL,
            category VARCHAR(100) NOT NULL DEFAULT 'General',
            supplier VARCHAR(150) DEFAULT NULL,
            unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            selling_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stock_qty INT NOT NULL DEFAULT 0,
            reorder_level INT NOT NULL DEFAULT 5,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_parts_sku (sku),
            KEY idx_parts_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS booking_parts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            part_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bp_booking (booking_id),
            KEY idx_bp_part (part_id),
            CONSTRAINT fk_bp_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
            CONSTRAINT fk_bp_part FOREIGN KEY (part_id) REFERENCES parts(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_no VARCHAR(30) NOT NULL,
            booking_id INT NOT NULL,
            customer_id INT NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            parts_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('unpaid','partial','paid','void') NOT NULL DEFAULT 'unpaid',
            issued_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_invoice_no (invoice_no),
            UNIQUE KEY uq_invoice_booking (booking_id),
            KEY idx_invoice_customer (customer_id),
            CONSTRAINT fk_inv_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
            CONSTRAINT fk_inv_customer FOREIGN KEY (customer_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS invoice_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            item_type ENUM('service','package','part','other') NOT NULL DEFAULT 'service',
            description VARCHAR(255) NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            KEY idx_ii_invoice (invoice_id),
            CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            expense_date DATE NOT NULL,
            category VARCHAR(100) NOT NULL DEFAULT 'General',
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_expense_date (expense_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT NULL,
            username VARCHAR(100) NOT NULL DEFAULT '',
            role VARCHAR(30) NOT NULL DEFAULT '',
            action VARCHAR(100) NOT NULL,
            entity VARCHAR(50) NOT NULL DEFAULT '',
            entity_id INT DEFAULT NULL,
            details TEXT DEFAULT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_audit_action (action),
            KEY idx_audit_entity (entity, entity_id),
            KEY idx_audit_user (user_id),
            KEY idx_audit_time (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($ddl as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Exception $e) {
            error_log('management table init failed: ' . $e->getMessage());
        }
    }

    // booking_parts approval workflow — existing rows default to 'approved'
    try {
        if (!$pdo->query("SHOW COLUMNS FROM booking_parts LIKE 'status'")->fetch()) {
            $pdo->exec("ALTER TABLE booking_parts ADD COLUMN status ENUM('pending','approved','declined') NOT NULL DEFAULT 'approved' AFTER unit_price");
        }
    } catch (Exception $e) {
        error_log('booking_parts status column failed: ' . $e->getMessage());
    }

    // bookings.status enum may lack newer workflow statuses — add them
    try {
        $col = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($col && strpos($col['Type'], 'in_progress') === false) {
            $pdo->exec("ALTER TABLE bookings MODIFY COLUMN status ENUM('pending','deposit_submitted','assigned','accepted','in_progress','completed','rejected','deposit_rejected') NOT NULL DEFAULT 'pending'");
        }
    } catch (Exception $e) {
        error_log('bookings status enum failed: ' . $e->getMessage());
    }
}

/**
 * Write an entry to audit_log. Never throws.
 */
function log_audit($pdo, $action, $entity = '', $entity_id = null, $details = '') {
    try {
        ensure_management_tables($pdo);
        $stmt = $pdo->prepare(
            "INSERT INTO audit_log (user_id, username, role, action, entity, entity_id, details, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['username'] ?? '',
            $_SESSION['role'] ?? '',
            $action,
            $entity,
            $entity_id !== null ? (int)$entity_id : null,
            is_string($details) ? $details : json_encode($details),
            $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (Exception $e) {
        error_log('audit log failed: ' . $e->getMessage());
    }
}

/**
 * Resolve a booking's services/packages/parts into invoice line items.
 * @return array<int, array{type:string, description:string, quantity:int, unit_price:float, line_total:float}>
 */
function get_booking_line_items($pdo, array $booking) {
    $items = [];

    $service_ids = json_decode($booking['service_ids'] ?? '[]', true) ?: [];
    if (!empty($service_ids)) {
        $ph = rtrim(str_repeat('?,', count($service_ids)), ',');
        $stmt = $pdo->prepare("SELECT id, service_name, price FROM services WHERE id IN ($ph)");
        $stmt->execute($service_ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $items[] = [
                'type' => 'service',
                'description' => $s['service_name'],
                'quantity' => 1,
                'unit_price' => (float)$s['price'],
                'line_total' => (float)$s['price'],
            ];
        }
    }

    $package_ids = json_decode($booking['package_ids'] ?? '[]', true) ?: [];
    if (!empty($package_ids)) {
        $ph = rtrim(str_repeat('?,', count($package_ids)), ',');
        $stmt = $pdo->prepare("SELECT id, package_name, price FROM service_packages WHERE id IN ($ph)");
        $stmt->execute($package_ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $items[] = [
                'type' => 'package',
                'description' => $p['package_name'],
                'quantity' => 1,
                'unit_price' => (float)$p['price'],
                'line_total' => (float)$p['price'],
            ];
        }
    }

    $stmt = $pdo->prepare(
        "SELECT bp.quantity, bp.unit_price, p.name
         FROM booking_parts bp JOIN parts p ON p.id = bp.part_id
         WHERE bp.booking_id = ? AND bp.status = 'approved'"
    );
    $stmt->execute([$booking['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $part) {
        $items[] = [
            'type' => 'part',
            'description' => $part['name'],
            'quantity' => (int)$part['quantity'],
            'unit_price' => (float)$part['unit_price'],
            'line_total' => (float)$part['unit_price'] * (int)$part['quantity'],
        ];
    }

    return $items;
}

/**
 * Names of the services/packages booked (service_ids / package_ids may be JSON or CSV).
 */
function booked_service_names($pdo, array $booking) {
    $names = [];
    foreach (['service_ids' => 'services.service_name', 'package_ids' => 'service_packages.package_name'] as $field => $col) {
        [$table, $name_col] = explode('.', $col);
        $ids = json_decode($booking[$field] ?? '', true);
        if (!is_array($ids)) {
            $ids = array_filter(array_map('trim', explode(',', (string)($booking[$field] ?? ''))));
        }
        if (!$ids) continue;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT $name_col FROM $table WHERE id IN ($ph)");
        $stmt->execute($ids);
        $names = array_merge($names, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return $names;
}

/**
 * Suggest parts that typically go with the booked services/packages.
 * Keyword-matched against service and part names — a hint, not a rule.
 * @return array<int, array> subset of $parts_catalog
 */
function recommended_parts_for_services(array $service_names, array $parts_catalog) {
    static $map = [
        'major service'       => ['engine oil', 'oil filter', 'air filter', 'spark plug', 'brake pad', 'brake fluid', 'drive chain', 'sprocket'],
        'preventive maintenance' => ['engine oil', 'oil filter', 'air filter', 'spark plug', 'brake pad', 'chain lube'],
        'oil change'          => ['engine oil', 'oil filter'],
        'oil filter'          => ['oil filter'],
        'tune-up'             => ['spark plug', 'oil filter', 'engine flush'],
        'air filter'          => ['air filter'],
        'sprocket'            => ['drive chain', 'sprocket'],
        'chain'               => ['drive chain', 'sprocket', 'chain lube'],
        'clutch'              => ['clutch cable', 'clutch kit'],
        'tire'                => ['tire', 'tire valve'],
        'wheel'               => ['tire', 'tire valve'],
        'suspension'          => ['fork seal', 'fork oil'],
        'fork'                => ['fork seal', 'fork oil'],
        'brake'               => ['brake pad', 'brake shoe', 'brake fluid'],
        'battery'             => ['battery'],
        'electrical'          => ['bulb', 'fuse', 'spark plug'],
        'spark plug'          => ['spark plug'],
        'cooling'             => ['coolant'],
        'coolant'             => ['coolant'],
        'throttle'            => ['throttle cable'],
        'carburetor'          => ['carb cleaner'],
        'safety inspection'   => ['brake pad', 'bulb', 'tire'],
        'general motorcycle maintenance' => ['engine oil', 'chain lube', 'grease'],
    ];

    $keywords = [];
    foreach ($service_names as $name) {
        $n = strtolower($name);
        foreach ($map as $key => $words) {
            if (str_contains($n, $key)) {
                foreach ($words as $w) $keywords[$w] = true;
            }
        }
    }
    if (!$keywords) return [];

    $out = [];
    foreach ($parts_catalog as $p) {
        $pn = strtolower($p['name']);
        foreach ($keywords as $kw => $_) {
            if (str_contains($pn, $kw)) { $out[(int)$p['id']] = $p; break; }
        }
    }
    return array_values($out);
}

/**
 * Resolve the display image for a motorcycle record:
 * 1) the customer's uploaded photo, else
 * 2) a model-matched photo from the webroot (snip.png, nmax.png...), else
 * 3) the generic hero image.
 */
function resolve_moto_image($moto) {
    if (!empty($moto['image']) && file_exists($moto['image'])) {
        return $moto['image'];
    }
    $hay = strtolower(($moto['brand'] ?? '') . ' ' . ($moto['model'] ?? ''));
    $model_map = [
        'sniper' => 'snip.png', 'nmax' => 'nmax.png', 'pcx' => 'pcx.png',
        'adv'    => 'adv.png',  'xrm'  => 'xrm.png',  'mio' => 'mio.png',
        'raider' => 'rai.png',  'smash'=> 'sma.png',
        'click 160' => 'click160.png', 'click160' => 'click160.png',
        'click' => 'click125.png',
    ];
    foreach ($model_map as $kw => $file) {
        if (str_contains($hay, $kw) && file_exists($file)) return $file;
    }
    return 'moto_hero.jpg';
}

/**
 * Recompute invoice.amount_paid / status from verified rows in payments.
 */
function sync_invoice_payment($pdo, $invoice_id) {
    try {
        $stmt = $pdo->prepare("SELECT booking_id, total FROM invoices WHERE id = ?");
        $stmt->execute([$invoice_id]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) return;

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM payments WHERE booking_id = ? AND status = 'verified'"
        );
        $stmt->execute([$inv['booking_id']]);
        $paid = (float)$stmt->fetchColumn();

        if ($paid <= 0) {
            $status = 'unpaid';
        } elseif ($paid + 0.005 >= (float)$inv['total']) {
            $status = 'paid';
        } else {
            $status = 'partial';
        }

        $pdo->prepare("UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ? AND status != 'void'")
            ->execute([$paid, $status, $invoice_id]);
    } catch (Exception $e) {
        error_log('sync_invoice_payment failed: ' . $e->getMessage());
    }
}

/**
 * Generate an invoice (with line items) for a completed booking.
 * Idempotent: returns the existing invoice id if one already exists.
 * Safe to call inside an existing transaction.
 */
function generate_invoice_for_booking($pdo, $booking_id) {
    try {
        ensure_management_tables($pdo);

        $stmt = $pdo->prepare("SELECT id FROM invoices WHERE booking_id = ?");
        $stmt->execute([$booking_id]);
        if ($existing = $stmt->fetchColumn()) {
            return (int)$existing;
        }

        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) return false;

        // Parts the customer never approved are excluded from the bill and
        // release their reserved stock back to inventory.
        $pend = $pdo->prepare("SELECT id, part_id, quantity FROM booking_parts WHERE booking_id = ? AND status = 'pending'");
        $pend->execute([$booking_id]);
        $pend_rows = $pend->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pend_rows as $pr) {
            $pdo->prepare("UPDATE parts SET stock_qty = stock_qty + ? WHERE id = ?")
                ->execute([(int)$pr['quantity'], (int)$pr['part_id']]);
            $pdo->prepare("UPDATE booking_parts SET status = 'declined' WHERE id = ?")
                ->execute([(int)$pr['id']]);
        }
        if ($pend_rows) {
            log_audit($pdo, 'parts_auto_declined', 'booking', $booking_id, count($pend_rows) . ' pending part(s) excluded at invoicing');
        }

        $items = get_booking_line_items($pdo, $booking);
        $subtotal = 0.0;
        $parts_total = 0.0;
        foreach ($items as $it) {
            if ($it['type'] === 'part') $parts_total += $it['line_total'];
            else $subtotal += $it['line_total'];
        }

        // Fallback when no catalog rows resolved (e.g. archived services)
        if (empty($items)) {
            $subtotal = (float)$booking['total_price'];
            $items[] = [
                'type' => 'service',
                'description' => 'Service (booking total)',
                'quantity' => 1,
                'unit_price' => $subtotal,
                'line_total' => $subtotal,
            ];
        }

        $invoice_no = 'INV-' . date('Ymd') . '-' . str_pad($booking_id, 4, '0', STR_PAD_LEFT);
        $total = $subtotal + $parts_total;

        $own_tx = !$pdo->inTransaction();
        if ($own_tx) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO invoices (invoice_no, booking_id, customer_id, subtotal, parts_total, total, issued_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $invoice_no, $booking_id, $booking['user_id'],
                $subtotal, $parts_total, $total,
                $_SESSION['user_id'] ?? null,
            ]);
            $invoice_id = (int)$pdo->lastInsertId();

            $it = $pdo->prepare(
                "INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($items as $item) {
                $it->execute([
                    $invoice_id, $item['type'], $item['description'],
                    $item['quantity'], $item['unit_price'], $item['line_total'],
                ]);
            }
            if ($own_tx) $pdo->commit();
        } catch (Exception $e) {
            if ($own_tx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        sync_invoice_payment($pdo, $invoice_id);
        log_audit($pdo, 'invoice_generated', 'booking', $booking_id, "Invoice {$invoice_no} total ₱" . number_format($total, 2));
        return $invoice_id;
    } catch (Exception $e) {
        error_log('generate_invoice_for_booking failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * All parts recorded on a booking (any status), joined with part details.
 */
function get_booking_parts($pdo, $booking_id) {
    ensure_management_tables($pdo);
    try {
        $stmt = $pdo->prepare(
            "SELECT bp.*, p.name AS part_name, p.sku
             FROM booking_parts bp JOIN parts p ON p.id = bp.part_id
             WHERE bp.booking_id = ? ORDER BY bp.id"
        );
        $stmt->execute([(int)$booking_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Customer approves or declines a pending part on their own booking.
 * Declined parts release their reserved stock back to inventory.
 */
function customer_decide_part($pdo, $bp_id, $user_id, $decision) {
    ensure_management_tables($pdo);
    if (!in_array($decision, ['approved', 'declined'])) {
        return ['ok' => false, 'msg' => 'Invalid decision.'];
    }

    $own_tx = !$pdo->inTransaction();
    if ($own_tx) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT bp.*, b.user_id, b.status AS booking_status, p.name AS part_name
             FROM booking_parts bp
             JOIN bookings b ON b.id = bp.booking_id
             JOIN parts p ON p.id = bp.part_id
             WHERE bp.id = ? FOR UPDATE"
        );
        $stmt->execute([(int)$bp_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) throw new Exception('Record not found.');
        if ((int)$row['user_id'] !== (int)$user_id) throw new Exception('This is not your booking.');
        if (!in_array($row['booking_status'], ['pending', 'deposit_submitted', 'accepted', 'assigned', 'in_progress'])) {
            throw new Exception('This booking is already closed.');
        }
        if ($row['status'] !== 'pending') throw new Exception('This part was already decided.');

        if ($decision === 'declined') {
            $pdo->prepare("UPDATE parts SET stock_qty = stock_qty + ? WHERE id = ?")
                ->execute([(int)$row['quantity'], (int)$row['part_id']]);
        }
        $pdo->prepare("UPDATE booking_parts SET status = ? WHERE id = ?")
            ->execute([$decision, (int)$bp_id]);

        if ($own_tx) $pdo->commit();
        log_audit($pdo, 'part_' . $decision, 'booking', $row['booking_id'], "{$row['quantity']} × {$row['part_name']}");
        $label = $decision === 'approved' ? 'approved' : 'declined';
        return ['ok' => true, 'msg' => "Part {$label}: {$row['part_name']}."];
    } catch (Exception $e) {
        if ($own_tx && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

/**
 * Send one consolidated SMS to the customer listing all pending parts
 * on a booking that need their approval.
 */
function notify_customer_pending_parts($pdo, $booking_id) {
    ensure_management_tables($pdo);
    try {
        $pend = $pdo->prepare(
            "SELECT bp.quantity, bp.unit_price, p.name
             FROM booking_parts bp JOIN parts p ON p.id = bp.part_id
             WHERE bp.booking_id = ? AND bp.status = 'pending'"
        );
        $pend->execute([(int)$booking_id]);
        $rows = $pend->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return ['ok' => false, 'msg' => 'No parts awaiting approval.'];

        $stmt = $pdo->prepare(
            "SELECT u.id, u.phone, u.name FROM bookings b JOIN users u ON u.id = b.user_id WHERE b.id = ?"
        );
        $stmt->execute([(int)$booking_id]);
        $cust = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cust) return ['ok' => false, 'msg' => 'Customer not found.'];
        if (empty($cust['phone'])) return ['ok' => false, 'msg' => 'Customer has no phone number on file.'];

        $items = array_map(fn($r) => (int)$r['quantity'] . 'x ' . $r['name'], $rows);
        $list = implode(', ', $items);
        if (strlen($list) > 100) $list = substr($list, 0, 97) . '...';
        $total = array_sum(array_map(fn($r) => $r['quantity'] * $r['unit_price'], $rows));

        $cname = trim((string)($cust['name'] ?? ''));
        $greet = $cname !== '' ? "Dear {$cname}, " : 'Dear Customer, ';
        $message = $greet . "this is Mindanao Eversure Motor Maintenance. Parts added to your Booking #{$booking_id} need your approval: {$list} (total ₱" . number_format($total, 2) . "). Please check My Bookings to approve or decline. Thank you.";

        if (!file_exists(__DIR__ . '/sms_helper.php')) return ['ok' => false, 'msg' => 'SMS not configured.'];
        require_once __DIR__ . '/sms_helper.php';
        $res = (new SMSHelper())->sendSMS($cust['phone'], $message, 'PART_APPROVAL', [
            'booking_id' => (int)$booking_id,
            'user_id' => (int)$cust['id'],
        ]);

        log_audit($pdo, 'parts_notify_sent', 'booking', $booking_id, count($rows) . ' pending part(s), ₱' . number_format($total, 2));
        return $res['success']
            ? ['ok' => true, 'msg' => 'Customer notified via SMS — ' . count($rows) . ' part(s) awaiting approval.']
            : ['ok' => false, 'msg' => 'SMS not sent: ' . ($res['message'] ?? 'unknown error')];
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => 'SMS failed: ' . $e->getMessage()];
    }
}

/**
 * Record parts used on a booking; deducts stock atomically.
 * New parts start as 'pending' — the customer must approve them before billing.
 * @return array{ok:bool, msg:string}
 */
function record_booking_part($pdo, $booking_id, $part_id, $qty) {
    ensure_management_tables($pdo);
    $qty = (int)$qty;
    $part_id = (int)$part_id;
    $booking_id = (int)$booking_id;

    if ($qty < 1) return ['ok' => false, 'msg' => 'Quantity must be at least 1.'];

    $own_tx = !$pdo->inTransaction();
    if ($own_tx) $pdo->beginTransaction();
    try {
        $b = $pdo->prepare("SELECT status FROM bookings WHERE id = ? FOR UPDATE");
        $b->execute([$booking_id]);
        $status = $b->fetchColumn();
        if (!in_array($status, ['assigned', 'accepted', 'in_progress', 'pending', 'deposit_submitted'])) {
            throw new Exception('Parts can only be added to an open booking.');
        }

        $p = $pdo->prepare("SELECT name, selling_price, stock_qty FROM parts WHERE id = ? FOR UPDATE");
        $p->execute([$part_id]);
        $part = $p->fetch(PDO::FETCH_ASSOC);
        if (!$part) throw new Exception('Part not found.');
        if ((int)$part['stock_qty'] < $qty) {
            throw new Exception("Not enough stock for {$part['name']} (have {$part['stock_qty']}).");
        }

        $ex = $pdo->prepare("SELECT id, status FROM booking_parts WHERE booking_id = ? AND part_id = ? AND status != 'declined'");
        $ex->execute([$booking_id, $part_id]);
        if ($bp_id = $ex->fetchColumn()) {
            // Quantity changed → the row goes back to pending customer approval
            $pdo->prepare("UPDATE booking_parts SET quantity = quantity + ?, status = 'pending' WHERE id = ?")
                ->execute([$qty, $bp_id]);
        } else {
            $pdo->prepare(
                "INSERT INTO booking_parts (booking_id, part_id, quantity, unit_price, status) VALUES (?, ?, ?, ?, 'pending')"
            )->execute([$booking_id, $part_id, $qty, $part['selling_price']]);
        }

        $pdo->prepare("UPDATE parts SET stock_qty = stock_qty - ? WHERE id = ?")
            ->execute([$qty, $part_id]);

        if ($own_tx) $pdo->commit();
        log_audit($pdo, 'part_used', 'booking', $booking_id, "{$qty} × {$part['name']}");
        return ['ok' => true, 'msg' => "Added {$qty} × {$part['name']}."];
    } catch (Exception $e) {
        if ($own_tx && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

/**
 * Remove a recorded part usage and restore stock.
 */
function remove_booking_part($pdo, $booking_part_id) {
    ensure_management_tables($pdo);
    $own_tx = !$pdo->inTransaction();
    if ($own_tx) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT bp.*, b.status AS booking_status, p.name AS part_name
             FROM booking_parts bp
             JOIN bookings b ON b.id = bp.booking_id
             JOIN parts p ON p.id = bp.part_id
             WHERE bp.id = ? FOR UPDATE"
        );
        $stmt->execute([$booking_part_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Record not found.');
        if (!in_array($row['booking_status'], ['assigned', 'accepted', 'in_progress', 'pending', 'deposit_submitted'])) {
            throw new Exception('Cannot remove parts from a closed booking.');
        }

        // Declined parts already returned their stock — don't restore twice
        if (($row['status'] ?? 'approved') !== 'declined') {
            $pdo->prepare("UPDATE parts SET stock_qty = stock_qty + ? WHERE id = ?")
                ->execute([$row['quantity'], $row['part_id']]);
        }
        $pdo->prepare("DELETE FROM booking_parts WHERE id = ?")->execute([$booking_part_id]);

        if ($own_tx) $pdo->commit();
        log_audit($pdo, 'part_removed', 'booking', $row['booking_id'], "{$row['quantity']} × {$row['part_name']}");
        return ['ok' => true, 'msg' => 'Part removed, stock restored.'];
    } catch (Exception $e) {
        if ($own_tx && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}
