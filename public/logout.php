<?php

declare(strict_types=1);

use DbAdmin\Session;

$config = require dirname(__DIR__).'/bootstrap.php';

db_admin_headers();

$session = new Session($config);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $session->grant() !== null && $session->verifyCsrf($_POST['csrf'] ?? null)) {
    $session->signOut();
}

header('Location: ./');
