<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

$_SESSION = [];
session_destroy();

header('Location: /ntn_erp/login.php');
exit;
