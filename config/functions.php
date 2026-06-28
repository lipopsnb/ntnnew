<?php
declare(strict_types=1);

if (!defined('ERP_BASE_PATH')) {
    define('ERP_BASE_PATH', '/ntn_erp');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function basePath(string $path = ''): string
{
    $path = ltrim($path, '/');
    return ERP_BASE_PATH . ($path !== '' ? '/' . $path : '');
}

function redirect(string $path): void
{
    $location = preg_match('#^https?://#i', $path) ? $path : basePath($path);
    header('Location: ' . $location);
    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_token(): string
{
    return csrfToken();
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function csrf_input(): string
{
    return csrfField();
}

function verifyCsrfToken(?string $token): bool
{
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function validateCsrfOrAbort(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Phiên làm việc đã hết hạn, vui lòng tải lại trang và thử lại.');
    }
}

function ensurePostCsrf(): void
{
    validateCsrfOrAbort();
}

function setFlashMessage(string $type, string $message): void
{
    $_SESSION['flash_messages'][] = ['type' => $type, 'message' => $message];
}

function setFlash(string $type, string $message): void
{
    setFlashMessage($type, $message);
}

function getFlashMessages(): array
{
    $messages = $_SESSION['flash_messages'] ?? [];
    unset($_SESSION['flash_messages']);
    return is_array($messages) ? $messages : [];
}

function getFlashMessage(): ?array
{
    $messages = getFlashMessages();
    return $messages[0] ?? null;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function formatDate(?string $date, string $format = 'd/m/Y'): string
{
    if (empty($date)) {
        return '—';
    }

    try {
        return (new DateTime($date))->format($format);
    } catch (Exception) {
        return '—';
    }
}

function formatDateVN(?string $date, string $format = 'd/m/Y'): string
{
    return formatDate($date, $format);
}

function formatDateTime(?string $dateTime, string $format = 'd/m/Y H:i'): string
{
    if (empty($dateTime)) {
        return '—';
    }

    try {
        return (new DateTime($dateTime))->format($format);
    } catch (Exception) {
        return '—';
    }
}

function formatDateTimeVN(?string $dateTime, string $format = 'd/m/Y H:i'): string
{
    return formatDateTime($dateTime, $format);
}

function formatCurrency(float|int|string|null $amount): string
{
    return number_format((float) $amount, 0, ',', '.') . ' ₫';
}

function getInitials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $letters = [];
    foreach ($parts as $part) {
        if ($part !== '') {
            $letters[] = mb_strtoupper(mb_substr($part, 0, 1));
        }
    }
    return implode('', array_slice($letters, 0, 2));
}

function roleLabel(string $role): string
{
    return [
        'director' => 'Giám đốc',
        'accountant' => 'Kế toán',
        'manager' => 'Quản lý',
        'production' => 'Sản xuất',
        'warehouse' => 'Kho',
        'employee' => 'Nhân viên',
    ][$role] ?? ucfirst($role);
}

function roleBadgeClass(string $role): string
{
    return match ($role) {
        'director' => 'danger',
        'accountant' => 'success',
        'manager' => 'primary',
        'production' => 'warning text-dark',
        'warehouse' => 'info text-dark',
        default => 'secondary',
    };
}

function activeMenu(string $needle): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return str_contains($uri, $needle) ? 'active' : '';
}

function renderBreadcrumb(array $items): void
{
    echo '<nav aria-label="breadcrumb" class="mb-3">';
    echo '<ol class="breadcrumb bg-white rounded-3 px-3 py-2 border shadow-sm mb-0">';
    $lastIndex = array_key_last($items);
    foreach ($items as $index => $item) {
        $label = e($item['label'] ?? '');
        $url = $item['url'] ?? '';
        $isLast = $index === $lastIndex;
        if (!$isLast && $url !== '') {
            echo '<li class="breadcrumb-item"><a href="' . e(basePath((string) $url)) . '">' . $label . '</a></li>';
        } else {
            echo '<li class="breadcrumb-item' . ($isLast ? ' active' : '') . '"' . ($isLast ? ' aria-current="page"' : '') . '>' . $label . '</li>';
        }
    }
    echo '</ol></nav>';
}

function fetchScalarSafe(PDO $pdo, string $sql, array $params = [], mixed $default = 0): mixed
{
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        return $value === false ? $default : $value;
    } catch (PDOException) {
        return $default;
    }
}

function fetchOneSafe(PDO $pdo, string $sql, array $params = [], ?array $default = null): ?array
{
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $result = $statement->fetch();
        return $result ?: $default;
    } catch (PDOException) {
        return $default;
    }
}

function fetchAllSafe(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    } catch (PDOException) {
        return [];
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $statement = $pdo->prepare('SHOW TABLES LIKE :table');
        $statement->execute(['table' => $table]);
        return $cache[$table] = (bool) $statement->fetchColumn();
    } catch (PDOException) {
        return $cache[$table] = false;
    }
}

function getTableColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!tableExists($pdo, $table)) {
        return $cache[$table] = [];
    }

    try {
        $rows = $pdo->query('DESCRIBE `' . str_replace('`', '``', $table) . '`')->fetchAll();
    } catch (PDOException) {
        return $cache[$table] = [];
    }

    $columns = [];
    foreach ($rows as $row) {
        $columns[] = $row['Field'];
    }

    return $cache[$table] = $columns;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, getTableColumns($pdo, $table), true);
}

function pickColumn(PDO $pdo, string $table, array $candidates): ?string
{
    $columns = getTableColumns($pdo, $table);
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function getEmployeeSourceTable(PDO $pdo): ?string
{
    foreach (['users', 'employees'] as $table) {
        if (tableExists($pdo, $table)) {
            return $table;
        }
    }
    return null;
}

function generateEmployeeCode(PDO $pdo): string
{
    $sql = "SELECT employee_code FROM users WHERE employee_code REGEXP '^NV[0-9]+$' ORDER BY CAST(SUBSTRING(employee_code, 3) AS UNSIGNED) DESC LIMIT 1";
    $latest = (string) fetchScalarSafe($pdo, $sql, [], '');
    if ($latest === '') {
        return 'NV0001';
    }
    $number = (int) preg_replace('/\D+/', '', $latest);
    return 'NV' . str_pad((string) ($number + 1), 4, '0', STR_PAD_LEFT);
}

function getLeaveBalance(PDO $pdo, int $userId, int $annualAllowance = 12): float
{
    $allowance = (float) fetchScalarSafe(
        $pdo,
        'SELECT COALESCE(max_days_per_year, :fallback) FROM leave_types WHERE name = :name LIMIT 1',
        ['fallback' => $annualAllowance, 'name' => 'Nghỉ phép năm'],
        $annualAllowance
    );

    $used = (float) fetchScalarSafe(
        $pdo,
        'SELECT COALESCE(SUM(lr.days), 0)
         FROM leave_requests lr
         INNER JOIN leave_types lt ON lt.id = lr.leave_type_id
         WHERE lr.user_id = :user_id AND lr.status = :status AND lt.name = :name AND YEAR(lr.date_from) = YEAR(CURDATE())',
        ['user_id' => $userId, 'status' => 'approved', 'name' => 'Nghỉ phép năm'],
        0
    );

    return max(0, $allowance - $used);
}

function paginationLinks(int $currentPage, int $totalPages, string $baseUrl, array $query = []): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $html = '<nav><ul class="pagination mb-0">';
    for ($page = 1; $page <= $totalPages; $page++) {
        $query['page'] = $page;
        $active = $page === $currentPage ? ' active' : '';
        $html .= '<li class="page-item' . $active . '">';
        $html .= '<a class="page-link" href="' . e(basePath($baseUrl) . '?' . http_build_query($query)) . '">' . $page . '</a>';
        $html .= '</li>';
    }
    return $html . '</ul></nav>';
}
