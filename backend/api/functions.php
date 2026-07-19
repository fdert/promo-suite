<?php
// Thin endpoint to ensure /api/functions.php routes to unified index router even if mod_rewrite is disabled
$_GET['service'] = 'functions';
include __DIR__ . '/index.php';
