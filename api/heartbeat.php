<?php
/**
 * Session Heartbeat - Keeps session alive
 * Called when user clicks "I'm Here" on timeout warning
 */
session_start();

// Update last activity timestamp
$_SESSION['last_activity'] = time();

// Return success
header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'timestamp' => time()]);
