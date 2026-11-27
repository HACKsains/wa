<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (wa_is_admin_logged_in()) {
    header('Location: ' . BASE_URL . '/dashboard/index.php');
    exit;
}

header('Location: ' . BASE_URL . '/login.php');
exit;
