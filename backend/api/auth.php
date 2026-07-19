<?php
// Thin endpoint to ensure /api/auth.php routes to unified index router even if mod_rewrite is disabled
$_GET['service'] = 'auth';
include __DIR__ . '/index.php';
