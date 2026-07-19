<?php
// Thin endpoint to ensure /api/db.php routes to unified index router even if mod_rewrite is disabled
$_GET['service'] = 'db';
include __DIR__ . '/index.php';
