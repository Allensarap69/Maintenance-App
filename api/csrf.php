<?php
// Returns the session-bound CSRF token for the React app's auth forms.
require __DIR__ . '/../db.php';
require __DIR__ . '/../security.php';

header('Content-Type: application/json');
echo json_encode(['csrf_token' => generate_csrf_token()]);
