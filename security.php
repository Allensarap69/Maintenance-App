<?php
/**
 * Security Helper Functions
 * 
 * Provides CSRF protection, rate limiting, and security headers
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Secure session settings
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

/**
 * Generate a CSRF token
 * @return string CSRF token
 */
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from form submission
 * @return bool True if valid
 */
function verify_csrf_token() {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    
    // Check token age (expire after 1 hour)
    if (time() - $_SESSION['csrf_token_time'] > 3600) {
        unset($_SESSION['csrf_token']);
        unset($_SESSION['csrf_token_time']);
        return false;
    }
    
    return true;
}

/**
 * Output CSRF token field for forms
 * @return string HTML input field
 */
function csrf_field() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Client IP used for throttling. REMOTE_ADDR only — X-Forwarded-For
 * is client-supplied and would let attackers rotate IPs at will.
 */
function client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Lazily create the login_attempts table. Fail-open: if DDL fails we
 * log and continue without throttling (same as before this existed).
 */
function ensure_login_attempts_table($pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            attempted_at INT UNSIGNED NOT NULL,
            INDEX idx_email (email),
            INDEX idx_ip (ip),
            INDEX idx_time (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('login_attempts table init failed: ' . $e->getMessage());
    }
}

/**
 * Progressive Login Delay — server-side, keyed on email + IP.
 * Clearing cookies does NOT reset it (unlike the old session version).
 *
 * Schedule within a 15-min window:
 *   fails 1-2 : no delay
 *   fail  3   : 10s, fail 4 : 30s, fail 5 : 60s
 *   fail  6+  : locked until 15 min after the last attempt
 *
 * @return array ['allowed' => bool, 'delay' => int, 'attempts' => int,
 *                'locked' => bool, 'remaining' => int, 'message' => ?string]
 */
function check_login_delay($pdo = null, $email = '') {
    $allowed = [
        'allowed' => true,
        'delay' => 0,
        'attempts' => 0,
        'locked' => false,
        'remaining' => 0,
        'message' => null
    ];
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    if (!$pdo) return $allowed;

    try {
        ensure_login_attempts_table($pdo);
        $ip = client_ip();
        $window = 900; // 15 minutes
        $cutoff = time() - $window;
        $email = strtolower(trim((string)$email));

        $stmt = $pdo->prepare(
            "SELECT COUNT(*), MAX(attempted_at) FROM login_attempts
             WHERE attempted_at > ? AND (email = ? OR ip = ?)"
        );
        $stmt->execute([$cutoff, $email, $ip]);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $attempts = (int)($row[0] ?? 0);
        $last = (int)($row[1] ?? 0);

        if ($attempts >= 6) {
            $remaining = $last + $window - time();
            if ($remaining > 0) {
                return [
                    'allowed' => false,
                    'delay' => 0,
                    'attempts' => $attempts,
                    'locked' => true,
                    'remaining' => $remaining,
                    'message' => 'Too many failed attempts. Try again in ' . format_wait_time($remaining) . '.'
                ];
            }
        }

        $delays = [3 => 10, 4 => 30, 5 => 60];
        if (isset($delays[$attempts])) {
            $wait = $delays[$attempts] - (time() - $last);
            if ($wait > 0) {
                return [
                    'allowed' => false,
                    'delay' => $wait,
                    'attempts' => $attempts,
                    'locked' => false,
                    'remaining' => $wait,
                    'message' => 'Please wait ' . format_wait_time($wait) . ' before trying again.'
                ];
            }
        }
    } catch (Exception $e) {
        error_log('check_login_delay failed: ' . $e->getMessage());
    }
    return $allowed;
}

/**
 * Format wait time in human-readable format
 * @param int $seconds Seconds to format
 * @return string Human-readable time
 */
function format_wait_time($seconds) {
    if ($seconds < 60) {
        return $seconds . " seconds";
    } elseif ($seconds < 3600) {
        $mins = ceil($seconds / 60);
        return $mins . " minute" . ($mins > 1 ? "s" : "");
    } else {
        $hours = floor($seconds / 3600);
        $mins = ceil(($seconds % 3600) / 60);
        if ($mins > 0) {
            return $hours . " hour" . ($hours > 1 ? "s" : "") . " and " . $mins . " minute" . ($mins > 1 ? "s" : "");
        }
        return $hours . " hour" . ($hours > 1 ? "s" : "");
    }
}

/**
 * Enforce login delay with JavaScript countdown
 * @param int $delay_seconds Delay in seconds
 * @return string HTML/JS for countdown
 */
function render_login_delay($delay_info) {
    $delay = $delay_info['delay'];
    $attempts = $delay_info['attempts'];
    
    if ($delay <= 0) return '';
    
    $delay_js = $delay * 1000; // Convert to milliseconds
    
    return "
    <div id='loginDelayOverlay' style='
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0, 0, 0, 0.85);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        backdrop-filter: blur(10px);
    '>
        <div style='
            background: linear-gradient(135deg, #1e293b, #0f172a);
            padding: 40px 60px;
            border-radius: 20px;
            text-align: center;
            border: 2px solid rgba(245, 158, 11, 0.3);
            box-shadow: 0 25px 50px rgba(0,0,0,0.5);
            max-width: 400px;
        '>
            <i class='bi bi-shield-lock' style='font-size: 3rem; color: #f59e0b; margin-bottom: 20px; display: block;'></i>
            <h3 style='color: white; margin-bottom: 15px;'>Security Delay</h3>
            <p style='color: rgba(255,255,255,0.7); margin-bottom: 25px;'>
                Too many failed attempts.<br>
                Attempt {$attempts} of 5
            </p>
            <div style='
                background: rgba(0,0,0,0.3);
                padding: 20px;
                border-radius: 12px;
                margin-bottom: 20px;
            '>
                <div style='font-size: 2.5rem; font-weight: 700; color: #f59e0b;' id='countdownTimer'>
                    " . format_wait_time($delay) . "
                </div>
                <div style='color: rgba(255,255,255,0.5); font-size: 0.9rem; margin-top: 5px;'>remaining</div>
            </div>
            <p style='color: rgba(255,255,255,0.5); font-size: 0.85rem;'>
                <i class='bi bi-info-circle'></i> This helps protect your account from unauthorized access.
            </p>
        </div>
    </div>
    <script>
        (function() {
            let timeLeft = {$delay};
            const timerDisplay = document.getElementById('countdownTimer');
            const overlay = document.getElementById('loginDelayOverlay');
            
            function formatTime(seconds) {
                if (seconds < 60) return seconds + 's';
                const mins = Math.floor(seconds / 60);
                const secs = seconds % 60;
                return mins + 'm ' + (secs < 10 ? '0' : '') + secs + 's';
            }
            
            const countdown = setInterval(function() {
                timeLeft--;
                if (timeLeft <= 0) {
                    clearInterval(countdown);
                    overlay.style.opacity = '0';
                    overlay.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => overlay.remove(), 500);
                } else {
                    timerDisplay.textContent = formatTime(timeLeft);
                }
            }, 1000);
        })();
    </script>
    ";
}

/**
 * Record a failed login attempt (server-side, survives cookie clears).
 * ~5% of calls also purge attempts older than 24h.
 */
function record_failed_login($pdo = null, $email = '') {
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    if (!$pdo) return;
    try {
        ensure_login_attempts_table($pdo);
        $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)");
        $stmt->execute([strtolower(trim((string)$email)), client_ip(), time()]);
        if (random_int(1, 20) === 1) {
            $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < " . (time() - 86400));
        }
    } catch (Exception $e) {
        error_log('record_failed_login failed: ' . $e->getMessage());
    }
}

/**
 * Clear login rate limit after successful login
 */
function clear_login_rate_limit($pdo = null, $email = '') {
    $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
    if (!$pdo) return;
    try {
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE email = ? OR ip = ?");
        $stmt->execute([strtolower(trim((string)$email)), client_ip()]);
    } catch (Exception $e) {
        error_log('clear_login_rate_limit failed: ' . $e->getMessage());
    }
}

/**
 * Set security headers
 */
function set_security_headers() {
    // Prevent clickjacking
    header('X-Frame-Options: DENY');
    
    // Prevent XSS
    header('X-XSS-Protection: 1; mode=block');
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Basic Content Security Policy
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com https://unpkg.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self';");
}

/**
 * Sanitize email input
 * @param string $email Email to sanitize
 * @return string|false Sanitized email or false if invalid
 */
function sanitize_email($email) {
    $email = trim($email);
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    return $email;
}

/**
 * Sanitize string input
 * @param string $data Input data
 * @param int $max_length Maximum allowed length
 * @return string Sanitized string
 */
function sanitize_string($data, $max_length = 255) {
    $data = trim($data);
    $data = substr($data, 0, $max_length);
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

/**
 * Validate and sanitize phone number
 * @param string $phone Phone number
 * @return string|false Sanitized phone or false if invalid
 */
function sanitize_phone($phone) {
    $phone = preg_replace('/[^0-9+\-\s\(\)]/', '', $phone);
    if (strlen($phone) < 7 || strlen($phone) > 20) {
        return false;
    }
    return $phone;
}

/**
 * Output security headers on all pages
 */
set_security_headers();
