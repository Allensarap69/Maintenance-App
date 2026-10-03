<?php
// sms_helper.php — reusable IPROG SMS helper with history logging

require_once __DIR__ . '/db.php';
require_once 'sms_config.php';

// Fallback endpoint: sends via the IPROGREMIND sender name, which supports ALL
// networks including Smart/TNT (shared sender names are rejected by Smart/TNT).
// Can be overridden by defining SMS_REMINDERS_URL in sms_config.php.
if (!defined('SMS_REMINDERS_URL')) {
    define('SMS_REMINDERS_URL', 'https://www.iprogsms.com/api/v1/message-reminders');
}

class SMSHelper {
    private $api_key;
    private $sender_name;
    private $api_url;

    public function __construct($api_key = null, $sender_name = null) {
        $this->api_key = $api_key ?: SMS_API_KEY;
        $this->sender_name = $sender_name ?: SMS_SENDER_NAME;
        $this->api_url = SMS_API_URL;
    }

    /**
     * Normalize a Philippine mobile number into 63XXXXXXXXXX format.
     */
    public function normalizePhone($number) {
        if (empty($number)) return false;
        $clean = preg_replace('/\D/', '', $number);

        if (strlen($clean) === 11 && substr($clean, 0, 2) === '09') {
            return '63' . substr($clean, 1);
        }
        if (strlen($clean) === 12 && substr($clean, 0, 2) === '63') {
            return $clean;
        }
        if (strlen($clean) === 13 && substr($clean, 0, 3) === '639') {
            return $clean;
        }
        return false;
    }

    /**
     * Check if an SMS has already been sent for a specific notification key.
     */
    public function isAlreadySent($notificationKey) {
        if (empty($notificationKey)) return false;
        global $pdo;
        if (empty($pdo)) return false;

        $stmt = $pdo->prepare("
            SELECT sms_id FROM sms_history
            WHERE notification_key = ? AND sent_status = 'SENT'
            LIMIT 1
        ");
        $stmt->execute([$notificationKey]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Send an SMS via IPROG SMS and log the result.
     *
     * @param string $to_number
     * @param string $message
     * @param string $notificationType  e.g. APPOINTMENT_CONFIRMATION
     * @param array  $metadata          booking_id, motorcycle_id, warranty_id, user_id,
     *                                  customer_id, notification_key, reference_id
     */
    public function sendSMS($to_number, $message, $notificationType = 'SMS', $metadata = []) {
        $result = [
            'success' => false,
            'message' => '',
            'provider_message_id' => '',
            'provider_status' => '',
            'error_code' => '',
            'http_code' => 0
        ];

        if (!SMS_ENABLED) {
            $result['message'] = 'SMS is disabled in configuration.';
            $result['error_code'] = 'DISABLED';
            return $result;
        }

        $phone = $this->normalizePhone($to_number);
        if ($phone === false) {
            $result['message'] = 'Invalid phone number: ' . $to_number;
            $result['error_code'] = 'INVALID_PHONE';
            $this->logSMS($to_number, $message, $notificationType, 'FAILED', $result, $metadata);
            return $result;
        }

        $notificationKey = $metadata['notification_key'] ?? '';
        if (!empty($notificationKey) && $this->isAlreadySent($notificationKey)) {
            $result['message'] = 'Duplicate: already sent for key ' . $notificationKey;
            $result['error_code'] = 'DUPLICATE';
            $result['success'] = true;
            return $result;
        }

        $payload = [
            'api_token'    => $this->api_key,
            'phone_number' => $phone,
            'message'      => $message,
            'sms_provider' => IPROG_SMS_PROVIDER
        ];

        $ch = curl_init($this->api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $result['http_code'] = $httpCode;

        if ($curlError) {
            $result['message'] = 'cURL error: ' . $curlError;
            $result['error_code'] = 'CURL_ERROR';
            $this->logSMS($phone, $message, $notificationType, 'FAILED', $result, $metadata);
            return $result;
        }

        $apiResponse = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && is_array($apiResponse) && isset($apiResponse['status'])) {
            if ($apiResponse['status'] == 200) {
                $result['success'] = true;
                $result['message'] = is_array($apiResponse['message'] ?? '') ? json_encode($apiResponse['message']) : (string) ($apiResponse['message'] ?? 'SMS queued');
                $result['provider_message_id'] = $apiResponse['message_id'] ?? '';
                $result['provider_status'] = (string) $apiResponse['status'];
                $this->logSMS($phone, $message, $notificationType, 'SENT', $result, $metadata, $response);
                return $result;
            }
            $result['message'] = is_array($apiResponse['message'] ?? '') ? json_encode($apiResponse['message']) : (string) ($apiResponse['message'] ?? 'IPROG API error ' . $apiResponse['status']);
            $result['error_code'] = (string) ($apiResponse['status'] ?? 'API_ERROR');
            $result['provider_status'] = (string) ($apiResponse['status'] ?? '');

            // Smart/TNT reject shared sender names - retry via IPROGREMIND (works on all networks)
            if (stripos($result['message'], 'sender name') !== false) {
                return $this->sendViaReminder($phone, $message, $notificationType, $result, $metadata);
            }

            $this->logSMS($phone, $message, $notificationType, 'FAILED', $result, $metadata, $response);
            return $result;
        }

        $result['message'] = 'Unexpected response (HTTP ' . $httpCode . '): ' . $response;
        $result['error_code'] = 'HTTP_' . $httpCode;

        if (stripos($result['message'], 'sender name') !== false) {
            return $this->sendViaReminder($phone, $message, $notificationType, $result, $metadata);
        }

        $this->logSMS($phone, $message, $notificationType, 'FAILED', $result, $metadata, $response);
        return $result;
    }

    /**
     * Fallback sender: schedule the message via IPROG's Message Reminders endpoint.
     * Reminders are delivered under the IPROGREMIND sender name, which is approved
     * for all networks (Globe/TM, DITO, Smart/TNT). Used when the shared sender
     * name is rejected. The message is scheduled ~2 minutes ahead, i.e. near-immediate.
     */
    private function sendViaReminder($phone, $message, $notificationType, $result, $metadata) {
        $scheduledAt = (new DateTime('now', new DateTimeZone('Asia/Manila')))
            ->modify('+2 minutes')
            ->format('Y-m-d h:iA');

        $payload = [
            'api_token'    => $this->api_key,
            'phone_number' => $phone,
            'message'      => $message,
            'scheduled_at' => $scheduledAt
        ];

        $ch = curl_init(SMS_REMINDERS_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $result['message'] .= ' | Reminder fallback cURL error: ' . $curlError;
            $this->logSMS($phone, $message, $notificationType, 'FAILED', $result, $metadata);
            return $result;
        }

        $apiResponse = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300 && is_array($apiResponse) && ($apiResponse['status'] ?? '') === 'success') {
            $result['success'] = true;
            $result['message'] = 'Sent via IPROGREMIND (all-network sender), scheduled at ' . $scheduledAt;
            $result['provider_message_id'] = 'reminder-' . ($apiResponse['data']['id'] ?? '');
            $result['provider_status'] = 'scheduled';
            $result['error_code'] = '';
            $this->logSMS($phone, $message, $notificationType, 'SENT', $result, $metadata, $response);
            return $result;
        }

        $errMsg = is_array($apiResponse) && isset($apiResponse['message'])
            ? (is_array($apiResponse['message']) ? json_encode($apiResponse['message']) : $apiResponse['message'])
            : (string) $response;
        $result['message'] .= ' | Reminder fallback failed: ' . $errMsg;
        $this->logSMS($phone, $message, $notificationType, 'FAILED', $result, $metadata, $response);
        return $result;
    }

    /**
     * Save an SMS record to sms_history.
     */
    private function logSMS($phone, $message, $notificationType, $sentStatus, $result, $metadata, $providerResponse = null) {
        global $pdo;
        if (empty($pdo)) return;

        try {
            // Make sure required columns exist in case the migration was not run
            $required = ['motorcycle_id', 'warranty_id', 'notification_key', 'failure_reason', 'provider_status', 'provider_response'];
            $existing = [];
            $cols = $pdo->query("DESCRIBE sms_history")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($required as $c) {
                if (in_array($c, $cols)) $existing[] = $c;
            }

            $fields = ['user_id', 'mechanic_id', 'booking_id', 'recipient_phone', 'message_body', 'message_type', 'sent_status', 'api_message_id', 'sent_timestamp'];
            $values = [
                ':user_id' => $metadata['user_id'] ?? $metadata['customer_id'] ?? null,
                ':mechanic_id' => $metadata['mechanic_id'] ?? null,
                ':booking_id' => $metadata['booking_id'] ?? null,
                ':recipient_phone' => $phone,
                ':message_body' => $message,
                ':message_type' => $notificationType,
                ':sent_status' => $sentStatus,
                ':api_message_id' => $result['provider_message_id'] ?? '',
                ':sent_timestamp' => date('Y-m-d H:i:s')
            ];

            foreach ($existing as $c) {
                if ($c === 'motorcycle_id') { $fields[] = 'motorcycle_id'; $values[':motorcycle_id'] = $metadata['motorcycle_id'] ?? null; }
                if ($c === 'warranty_id') { $fields[] = 'warranty_id'; $values[':warranty_id'] = $metadata['warranty_id'] ?? null; }
                if ($c === 'notification_key') { $fields[] = 'notification_key'; $values[':notification_key'] = $metadata['notification_key'] ?? null; }
                if ($c === 'failure_reason') { $fields[] = 'failure_reason'; $values[':failure_reason'] = $result['message'] ?? ''; }
                if ($c === 'provider_status') { $fields[] = 'provider_status'; $values[':provider_status'] = $result['provider_status'] ?? ''; }
                if ($c === 'provider_response') { $fields[] = 'provider_response'; $values[':provider_response'] = $providerResponse; }
            }

            $sql = "INSERT INTO sms_history (" . implode(', ', $fields) . ") VALUES (" . implode(', ', array_keys($values)) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
        } catch (PDOException $e) {
            error_log('SMS history log error: ' . $e->getMessage());
        }

        if (SMS_DEBUG) {
            $log = sprintf(
                "[SMS] %s | Type: %s | To: %s | Status: %s | Error: %s | ProviderID: %s",
                date('Y-m-d H:i:s'),
                $notificationType,
                $phone,
                $sentStatus,
                $result['error_code'] ?? '',
                $result['provider_message_id'] ?? ''
            );
            error_log($log);
        }
    }

    /**
     * Check current IPROG SMS credits.
     */
    public function getCredits() {
        if (empty($this->api_key)) {
            return ['success' => false, 'message' => 'API token not configured'];
        }
        $url = SMS_CREDITS_URL . '?api_token=' . urlencode($this->api_key);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $apiResponse = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($apiResponse['data']['load_balance'])) {
            return ['success' => true, 'credits' => $apiResponse['data']['load_balance']];
        }
        return ['success' => false, 'message' => 'Failed to load credits', 'http_code' => $httpCode, 'response' => $response];
    }
}
