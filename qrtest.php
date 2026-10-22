<?php
// TEMPORARY test page — renders the amount-locked GCash QR exactly like confirm_payment.php
require 'db.php';

$gcash_qr_path = 'g.png.jpg';
$gcash_emv_base_payload = '00020101021127830012com.p2pqrpay0111GXCHPHM2XXX02089996440303152170200000006560417DWQM4TK3JDO7CBK0B5204601653036085802PH5910NO****A G.6007Glamang61041234630489FE';
$deposit_amount = 575.00; // sample deposit
$gcash_name = '';
$gcash_number = '';

try {
    $row = $pdo->query("SELECT gcash_name, gcash_number, qr_image_path, emv_payload FROM payment_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if (!empty($row['qr_image_path'])) $gcash_qr_path = $row['qr_image_path'];
        $gcash_emv_base_payload = $row['emv_payload'];
        $gcash_name = $row['gcash_name'] ?? '';
        $gcash_number = $row['gcash_number'] ?? '';
    }
} catch (PDOException $e) {
    error_log('qrtest settings lookup failed: ' . $e->getMessage());
}

function gcash_locked_amount_payload(string $base_payload, string $amount): string {
    $body = substr($base_payload, 0, -8);
    $poi_pos = strpos($body, '010211');
    if ($poi_pos !== false) {
        $body = substr_replace($body, '010212', $poi_pos, 6);
    }
    $field54 = '54' . str_pad((string) strlen($amount), 2, '0', STR_PAD_LEFT) . $amount;
    $pos = 0;
    $insert_at = strlen($body);
    while ($pos + 4 <= strlen($body)) {
        $tag = substr($body, $pos, 2);
        $pos += 4 + (int) substr($body, $pos + 2, 2);
        if ($tag === '53') { $insert_at = $pos; break; }
    }
    $body = substr($body, 0, $insert_at) . $field54 . substr($body, $insert_at);
    $data = $body . '6304';
    $crc = 0xFFFF;
    for ($i = 0, $n = strlen($data); $i < $n; $i++) {
        $crc ^= ord($data[$i]) << 8;
        for ($b = 0; $b < 8; $b++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
        }
    }
    return $body . '6304' . strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

$gcash_locked_payload = !empty($gcash_emv_base_payload)
    ? gcash_locked_amount_payload($gcash_emv_base_payload, number_format($deposit_amount, 2, '.', ''))
    : null;
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>QR amount-lock test</title></head>
<body>
<p>Pay to: <strong><?= htmlspecialchars(trim($gcash_name . ' ' . $gcash_number)) ?: '—' ?></strong></p>
<p>Payload: <code><?= htmlspecialchars($gcash_locked_payload ?? '(none — static QR shown)') ?></code></p>
<p>Locked amount: P<?= number_format($deposit_amount, 2) ?></p>
<div id="gcash_qr_code" class="d-inline-block">
    <img id="gcash_qr_fallback" src="<?= htmlspecialchars($gcash_qr_path) ?>" alt="GCash QR Code">
</div>
<script src="assets/js/qrcode.min.js"></script>
<script>
    const gcashQrPayload = <?= json_encode($gcash_locked_payload) ?>;
    const gcashQrContainer = document.getElementById('gcash_qr_code');
    if (gcashQrContainer && gcashQrPayload && typeof QRCode !== 'undefined') {
        try {
            new QRCode(gcashQrContainer, {
                text: gcashQrPayload,
                width: 150,
                height: 150,
                correctLevel: QRCode.CorrectLevel.M
            });
            if (gcashQrContainer.querySelector('canvas')) {
                const fallbackImg = document.getElementById('gcash_qr_fallback');
                if (fallbackImg) fallbackImg.remove();
            }
        } catch (e) {
            document.body.insertAdjacentHTML('beforeend', '<p style="color:red">QR lib failed: ' + e.message + '</p>');
        }
    }
</script>
</body>
</html>
