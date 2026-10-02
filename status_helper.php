<?php
/**
 * Canonical status -> visual token map + badge renderer.
 *
 * Use status_badge() for any NEW status markup. The color tokens
 * live in assets/css/ui.css (also normalizes legacy .status-*
 * class names to the same palette).
 *
 * Canonical keys: pending | progress | success | danger | warning | neutral
 */

if (!function_exists('status_key')) {
    function status_key($status) {
        $s = strtolower(trim((string)$status));
        $s = str_replace([' ', '-'], '_', $s);

        static $map = [
            // Waiting on someone / not yet confirmed
            'pending' => 'pending', 'unassigned' => 'pending',
            'awaiting_deposit' => 'pending', 'awaiting_payment' => 'pending',
            'deposit_submitted' => 'pending', 'for_verification' => 'pending',

            // Confirmed / in progress
            'accepted' => 'progress', 'approved' => 'progress',
            'assigned' => 'progress', 'confirmed' => 'progress',
            'in_progress' => 'progress', 'ongoing' => 'progress',
            'processing' => 'progress', 'scheduled' => 'progress',
            'rescheduled' => 'progress', 'low' => 'progress',

            // Positive terminal states
            'completed' => 'success', 'complete' => 'success',
            'active' => 'success', 'available' => 'success',
            'paid' => 'success', 'success' => 'success',
            'good' => 'success', 'excellent' => 'success',
            'claimed' => 'success',

            // Negative
            'rejected' => 'danger', 'deposit_rejected' => 'danger',
            'cancelled' => 'danger', 'canceled' => 'danger',
            'declined' => 'danger', 'failed' => 'danger',
            'poor' => 'danger', 'critical' => 'danger',
            'danger' => 'danger', 'emergency' => 'danger',
            'overdue' => 'danger',

            // Caution
            'warning' => 'warning', 'attention' => 'warning',
            'needs_attention' => 'warning', 'fair' => 'warning',
            'medium' => 'warning', 'high' => 'warning',
            'busy' => 'warning', 'for_approval' => 'warning',

            // Neutral / archived
            'expired' => 'neutral', 'inactive' => 'neutral',
            'archived' => 'neutral', 'draft' => 'neutral',
            'void' => 'neutral', 'closed' => 'neutral',
        ];

        return $map[$s] ?? 'neutral';
    }
}

if (!function_exists('status_label')) {
    function status_label($status) {
        return ucwords(str_replace('_', ' ', strtolower(trim((string)$status))));
    }
}

if (!function_exists('status_badge')) {
    /**
     * Render a canonical status badge.
     * @param string      $status      Raw status string from DB
     * @param string|null $label       Override display text
     * @param string      $extra_class Extra CSS classes
     */
    function status_badge($status, $label = null, $extra_class = '') {
        $key  = status_key($status);
        $text = $label !== null ? $label : status_label($status);
        $cls  = 'st-badge st-' . $key . ($extra_class !== '' ? ' ' . $extra_class : '');
        return '<span class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">'
             . '<span class="st-dot"></span>'
             . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
             . '</span>';
    }
}
