<?php
declare(strict_types=1);

if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: getenv('NTN_DB_HOST') ?: '127.0.0.1');
    define('DB_PORT', getenv('DB_PORT') ?: getenv('NTN_DB_PORT') ?: '3306');
    define('DB_NAME', getenv('DB_NAME') ?: getenv('NTN_DB_NAME') ?: 'ntn_erp');
    define('DB_USER', getenv('DB_USER') ?: getenv('NTN_DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : (getenv('NTN_DB_PASS') !== false ? getenv('NTN_DB_PASS') : ''));
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException) {
        http_response_code(500);
        exit('Không thể kết nối cơ sở dữ liệu. Vui lòng kiểm tra cấu hình hệ thống.');
    }

    return $pdo;
}

function getPDO(): PDO
{
    return db();
}

$pdo = db();
