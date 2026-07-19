<?php
// Thin endpoint to ensure /api/storage.php routes to unified index router even if mod_rewrite is disabled
$_GET['service'] = 'storage';
include __DIR__ . '/index.php';
