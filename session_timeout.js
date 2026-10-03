/**
 * Idle Session Timeout Manager
 * Auto-logout after inactivity with warning popup
 */

(function() {
    'use strict';

    // Configuration
    const CONFIG = {
        IDLE_TIMEOUT: 90000,      // 1.5 minutes (90000ms) - Total idle time before logout
        WARNING_TIME: 30000,      // 30 seconds (30000ms) - Warning before logout
        CHECK_INTERVAL: 1000,     // Check every 1 second
        LOGOUT_URL: 'logout.php'  // Redirect URL
    };

    // Calculate warning threshold
    const WARNING_THRESHOLD = CONFIG.IDLE_TIMEOUT - CONFIG.WARNING_TIME; // 60 seconds

    // State
    let lastActivity = Date.now();
    let warningShown = false;
    let countdownInterval = null;
    let checkInterval = null;

    // DOM Elements
    let overlay = null;
    let countdownEl = null;

    /**
     * Update last activity timestamp
     */
    function updateActivity() {
        lastActivity = Date.now();
        if (warningShown) {
            hideWarning();
        }
    }

    /**
     * Check idle status
     */
    function checkIdle() {
        const idleTime = Date.now() - lastActivity;

        // If idle for total timeout, logout
        if (idleTime >= CONFIG.IDLE_TIMEOUT) {
            logout();
            return;
        }

        // If idle for warning threshold and warning not shown yet
        if (idleTime >= WARNING_THRESHOLD && !warningShown) {
            showWarning(CONFIG.WARNING_TIME - (idleTime - WARNING_THRESHOLD));
        }
    }

    /**
     * Show warning popup with countdown
     */
    function showWarning(remainingTime) {
        // Prevent creating multiple popups
        if (warningShown && overlay && document.getElementById('sessionTimeoutWarning')) {
            return; // Already showing, don't recreate
        }
        warningShown = true;
        createWarningOverlay(remainingTime);
    }

    /**
     * Create warning overlay HTML
     */
    function createWarningOverlay(remainingTime) {
        // Don't remove existing - prevents flicker if called multiple times

        // Create overlay
        overlay = document.createElement('div');
        overlay.id = 'sessionTimeoutWarning';
        overlay.innerHTML = `
            <div class="session-warning-content">
                <div class="session-warning-icon">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
                <h3>Session Timeout Warning</h3>
                <p>Your session is about to expire due to inactivity.</p>
                <div class="session-countdown">
                    <span class="countdown-number" id="sessionCountdown">${Math.ceil(remainingTime / 1000)}</span>
                    <span class="countdown-label">seconds remaining</span>
                </div>
                <div class="session-warning-actions">
                    <button class="btn-stay-active" onclick="sessionTimeout.stayActive()">
                        <i class="bi bi-hand-index-thumb-fill"></i> I'm Here
                    </button>
                </div>
            </div>
        `;

        // Add styles
        const styles = document.createElement('style');
        styles.textContent = `
            #sessionTimeoutWarning {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(15, 23, 42, 0.85);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 100000;
                backdrop-filter: blur(20px) saturate(180%);
                -webkit-backdrop-filter: blur(20px) saturate(180%);
                animation: fadeIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            }
            
            @keyframes fadeIn {
                from { 
                    opacity: 0;
                    backdrop-filter: blur(0px) saturate(100%);
                }
                to { 
                    opacity: 1;
                    backdrop-filter: blur(20px) saturate(180%);
                }
            }
            
            @keyframes fadeOut {
                from { 
                    opacity: 1;
                    backdrop-filter: blur(20px) saturate(180%);
                }
                to { 
                    opacity: 0;
                    backdrop-filter: blur(0px) saturate(100%);
                }
            }
            
            .session-warning-content {
                background: linear-gradient(145deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.95));
                padding: 45px 55px;
                border-radius: 28px;
                text-align: center;
                border: 1px solid rgba(245, 158, 11, 0.3);
                box-shadow: 
                    0 32px 64px rgba(0,0,0,0.4),
                    0 0 0 1px rgba(245, 158, 11, 0.15),
                    inset 0 1px 0 rgba(255,255,255,0.1);
                max-width: 420px;
                width: 90%;
                animation: popIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
                transform-origin: center center;
            }
            
            @keyframes popIn {
                0% { 
                    opacity: 0; 
                    transform: scale(0.7) translateY(40px);
                    filter: blur(10px);
                }
                70% {
                    transform: scale(1.05) translateY(-5px);
                }
                100% { 
                    opacity: 1; 
                    transform: scale(1) translateY(0);
                    filter: blur(0);
                }
            }
            
            @keyframes popOut {
                0% { 
                    opacity: 1; 
                    transform: scale(1) translateY(0);
                    filter: blur(0);
                }
                100% { 
                    opacity: 0; 
                    transform: scale(0.9) translateY(-20px);
                    filter: blur(5px);
                }
            }
            
            .session-warning-icon {
                font-size: 3.5rem;
                color: #f59e0b;
                margin-bottom: 20px;
                animation: iconPulse 2s ease-in-out infinite;
                display: inline-block;
            }
            
            @keyframes iconPulse {
                0%, 100% { 
                    transform: scale(1); 
                    filter: drop-shadow(0 0 10px rgba(245, 158, 11, 0.5));
                }
                50% { 
                    transform: scale(1.15); 
                    filter: drop-shadow(0 0 20px rgba(245, 158, 11, 0.8));
                }
            }
            
            .session-warning-content h3 {
                color: #fff;
                font-size: 1.6rem;
                font-weight: 700;
                margin-bottom: 12px;
                letter-spacing: -0.5px;
            }
            
            .session-warning-content p {
                color: rgba(255,255,255,0.65);
                font-size: 1rem;
                margin-bottom: 25px;
                line-height: 1.5;
            }
            
            .session-countdown {
                background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(245, 158, 11, 0.05));
                border: 1px solid rgba(245, 158, 11, 0.25);
                border-radius: 20px;
                padding: 22px 35px;
                margin-bottom: 25px;
                position: relative;
                overflow: hidden;
            }
            
            .session-countdown::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 2px;
                background: linear-gradient(90deg, transparent, #f59e0b, transparent);
                animation: shimmer 2s infinite;
            }
            
            @keyframes shimmer {
                0% { transform: translateX(-100%); }
                100% { transform: translateX(100%); }
            }
            
            .countdown-number {
                display: block;
                font-size: 3rem;
                font-weight: 800;
                color: #f59e0b;
                line-height: 1;
                text-shadow: 0 0 30px rgba(245, 158, 11, 0.4);
                font-variant-numeric: tabular-nums;
            }
            
            .countdown-label {
                display: block;
                color: rgba(255,255,255,0.5);
                font-size: 0.85rem;
                margin-top: 8px;
                text-transform: uppercase;
                letter-spacing: 2px;
                font-weight: 500;
            }
            
            .btn-stay-active {
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                border: none;
                color: white;
                font-weight: 600;
                padding: 14px 36px;
                border-radius: 50px;
                font-size: 1.05rem;
                cursor: pointer;
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1), visibility 0.3s cubic-bezier(0.4, 0, 0.2, 1), max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: 
                    0 4px 15px rgba(245, 158, 11, 0.3),
                    0 0 0 1px rgba(245, 158, 11, 0.2),
                    inset 0 1px 0 rgba(255,255,255,0.2);
                position: relative;
                overflow: hidden;
            }
            
            .btn-stay-active::before {
                content: '';
                position: absolute;
                top: 0;
                left: -100%;
                width: 100%;
                height: 100%;
                background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
                transition: left 0.5s ease;
            }
            
            .btn-stay-active:hover {
                transform: translateY(-3px) scale(1.02);
                box-shadow: 
                    0 8px 25px rgba(245, 158, 11, 0.4),
                    0 0 0 1px rgba(245, 158, 11, 0.3),
                    inset 0 1px 0 rgba(255,255,255,0.2);
            }
            
            .btn-stay-active:hover::before {
                left: 100%;
            }
            
            .btn-stay-active:active {
                transform: translateY(-1px) scale(0.98);
            }
            
            .btn-stay-active i {
                margin-right: 10px;
                font-size: 1.1em;
            }
        `;

        document.head.appendChild(styles);
        document.body.appendChild(overlay);

        // Start countdown
        let remaining = Math.ceil(remainingTime / 1000);
        countdownEl = document.getElementById('sessionCountdown');
        
        countdownInterval = setInterval(() => {
            remaining--;
            if (countdownEl) {
                countdownEl.textContent = remaining;
            }
            if (remaining <= 0) {
                clearInterval(countdownInterval);
            }
        }, 1000);
    }

    /**
     * Hide warning and reset
     */
    function hideWarning() {
        warningShown = false;
        if (countdownInterval) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
        if (overlay && overlay.parentNode) {
            // Apply fade out animation to overlay
            overlay.style.animation = 'fadeOut 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
            
            // Apply pop-out animation to content
            const content = overlay.querySelector('.session-warning-content');
            if (content) {
                content.style.animation = 'popOut 0.35s cubic-bezier(0.4, 0, 1, 1) forwards';
            }
            
            // Remove after animation completes
            setTimeout(() => {
                if (overlay && overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
                overlay = null;
            }, 400);
        }
    }

    /**
     * Logout user
     */
    function logout() {
        window.location.href = CONFIG.LOGOUT_URL;
    }

    /**
     * User wants to stay active
     */
    function stayActive() {
        updateActivity();
        hideWarning();
        
        // Send heartbeat to server to keep session alive
        fetch('api/heartbeat.php', { 
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).catch(() => {}); // Silently fail if not implemented
    }

    /**
     * Track user activity events
     */
    function setupEventListeners() {
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        
        events.forEach(event => {
            document.addEventListener(event, updateActivity, true);
        });
    }

    /**
     * Initialize
     */
    function init() {
        // Only run if user is logged in (check for session indicator)
        if (document.body.classList.contains('logged-out')) {
            return;
        }

        setupEventListeners();
        
        // Start idle checker
        checkInterval = setInterval(checkIdle, CONFIG.CHECK_INTERVAL);

        console.log('Session timeout manager initialized (1.5 min idle, 30 sec warning)');
    }

    // Public API
    window.sessionTimeout = {
        stayActive: stayActive,
        updateActivity: updateActivity,
        logout: logout
    };

    // Auto-init when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
