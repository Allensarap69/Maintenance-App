<?php
// Lightweight discovery endpoint used by the mobile app to locate this server
// on the local network. Must return exactly this marker.
header('Content-Type: text/plain');
echo 'EVERSURE_PONG';
