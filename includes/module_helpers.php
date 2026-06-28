<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('ERP_BASE_URL')) {
    define('ERP_BASE_URL', '/ntn_erp');
}

if (!function_exists('erp_db')) {
    function erp_db(): PDO
    {
        foreach (['pdo', 'conn', 'db', 'database'] as $key) {
            if (($GLOBALS[$key] ?? null) instanceof PDO) {
                return $GLOBALS[$key];
            }
        }

        foreach (['getPDO', 'getConnection', 'db_connect', 'getDBConnection'] as $callable) {
            if (function_exists($callable)) {
                $pdo = $callable();
                if ($pdo instanceof PDO) {
                    return $pdo;
                }
            }
        }

        throw new RuntimeException('PDO connection not found.');
    }
}

if (!function_exists('erp_h')) {
    function erp_h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('erp_url')) {
    function erp_url(string $path = ''): string
    {
        return rtrim(ERP_BASE_URL, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('erp_post_value')) {
    function erp_post_value(string $key, mixed $default = ''): mixed
    {
        return $_POST[$key] ?? $default;
    }
}

if (!function_exists('erp_array_value')) {
    function erp_array_value(array $array, string|int $key, mixed $default = null): mixed
    {
        return $array[$key] ?? $default;
    }
}

if (!function_exists('erp_flash')) {
    function erp_flash(string $type, string $message): void
    {
        $_SESSION['erp_flash'][] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('erp_pull_flashes')) {
    function erp_pull_flashes(): array
    {
        $flashes = $_SESSION['erp_flash'] ?? [];
        unset($_SESSION['erp_flash']);

        return $flashes;
    }
}

if (!function_exists('erp_current_user')) {
    function erp_current_user(): array
    {
        foreach (['user', 'auth_user', 'current_user'] as $key) {
            if (isset($_SESSION[$key]) && is_array($_SESSION[$key])) {
                return $_SESSION[$key];
            }
        }

        return [];
    }
}

if (!function_exists('erp_current_username')) {
    function erp_current_username(): string
    {
        $user = erp_current_user();
        foreach (['full_name', 'name', 'username', 'email'] as $key) {
            if (!empty($user[$key])) {
                return (string) $user[$key];
            }
        }

        return (string) ($_SESSION['username'] ?? 'system');
    }
}

if (!function_exists('erp_current_user_id')) {
    function erp_current_user_id(): int
    {
        $user = erp_current_user();
        foreach (['id', 'user_id'] as $key) {
            if (!empty($user[$key])) {
                return (int) $user[$key];
            }
        }

        return (int) ($_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('erp_user_roles')) {
    function erp_user_roles(): array
    {
        $user = erp_current_user();
        $roles = [];

        if (isset($user['roles']) && is_array($user['roles'])) {
            $roles = array_merge($roles, $user['roles']);
        }

        foreach (['role', 'user_role', 'position'] as $key) {
            if (!empty($user[$key])) {
                $roles[] = $user[$key];
            }
        }

        foreach (['role', 'roles'] as $key) {
            if (!empty($_SESSION[$key])) {
                if (is_array($_SESSION[$key])) {
                    $roles = array_merge($roles, $_SESSION[$key]);
                } else {
                    $roles[] = $_SESSION[$key];
                }
            }
        }

        return array_values(array_unique(array_filter(array_map('strval', $roles))));
    }
}

if (!function_exists('erp_has_any_role')) {
    function erp_has_any_role(array $roles): bool
    {
        return [] !== array_intersect($roles, erp_user_roles());
    }
}

if (!function_exists('erp_csrf_token')) {
    function erp_csrf_token(): string
    {
        foreach (['generateCSRFToken', 'csrf_token'] as $callable) {
            if (function_exists($callable)) {
                $token = $callable();
                if (is_string($token) && $token !== '') {
                    return $token;
                }
            }
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('erp_validate_csrf')) {
    function erp_validate_csrf(?string $token): bool
    {
        foreach (['validateCSRFToken', 'verifyCSRFToken', 'check_csrf_token'] as $callable) {
            if (function_exists($callable)) {
                return (bool) $callable($token);
            }
        }

        return is_string($token)
            && isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('erp_redirect')) {
    function erp_redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }
}

if (!function_exists('erp_format_vnd')) {
    function erp_format_vnd(float|int|string $amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' đ';
    }
}

if (!function_exists('erp_format_date')) {
    function erp_format_date(?string $date, string $format = 'd/m/Y'): string
    {
        if (!$date) {
            return '';
        }

        $time = strtotime($date);
        return $time ? date($format, $time) : (string) $date;
    }
}

if (!function_exists('erp_to_decimal')) {
    function erp_to_decimal(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_replace(['.', ',', ' '], ['', '.', ''], (string) $value);
        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }
}

if (!function_exists('erp_generate_code')) {
    function erp_generate_code(PDO $pdo, string $table, string $column, string $prefix): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new InvalidArgumentException('Invalid table or column name.');
        }

        $year = date('Y');
        $like = sprintf('%s-%s-%%', $prefix, $year);
        $sql = "SELECT {$column} FROM {$table} WHERE {$column} LIKE ? ORDER BY {$column} DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$like]);
        $lastCode = (string) ($stmt->fetchColumn() ?: '');

        $nextNumber = 1;
        if ($lastCode !== '' && preg_match('/(\d+)$/', $lastCode, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        }

        return sprintf('%s-%s-%03d', $prefix, $year, $nextNumber);
    }
}

if (!function_exists('erp_generate_daily_code')) {
    /**
     * Generate a document code in the format PREFIX-YYYY-MM-DD-XXX.
     * Uses SELECT … FOR UPDATE via a transaction for safe concurrent generation.
     */
    function erp_generate_daily_code(PDO $pdo, string $table, string $column, string $prefix, ?string $date = null): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            throw new InvalidArgumentException('Invalid table or column name.');
        }

        $ts = $date ? strtotime($date) : false;
        $dateStr = $ts !== false ? date('Y-m-d', $ts) : date('Y-m-d');
        $like = sprintf('%s-%s-%%', $prefix, $dateStr);
        $sql = "SELECT {$column} FROM {$table} WHERE {$column} LIKE ? ORDER BY {$column} DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$like]);
        $lastCode = (string) ($stmt->fetchColumn() ?: '');

        $nextNumber = 1;
        if ($lastCode !== '' && preg_match('/(\d+)$/', $lastCode, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        }

        return sprintf('%s-%s-%03d', $prefix, $dateStr, $nextNumber);
    }
}

if (!function_exists('erp_status_badge_class')) {
    function erp_status_badge_class(string $status): string
    {
        return match ($status) {
            'draft' => 'secondary',
            'in_progress' => 'primary',
            'done' => 'success',
            'delivered' => 'info',
            'cancelled' => 'danger',
            'partial', 'pending' => 'warning',
            'paid' => 'success',
            'overdue' => 'danger',
            default => 'secondary',
        };
    }
}

if (!function_exists('erp_status_label')) {
    function erp_status_label(string $status): string
    {
        return match ($status) {
            'draft' => 'Nháp',
            'in_progress' => 'Đang xử lý',
            'done' => 'Hoàn thành',
            'delivered' => 'Đã giao',
            'cancelled' => 'Đã hủy',
            'pending' => 'Chờ xử lý',
            'partial' => 'Thanh toán một phần',
            'paid' => 'Đã thanh toán',
            'overdue' => 'Quá hạn',
            'unpaid' => 'Chưa thanh toán',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}

if (!function_exists('erp_table_exists')) {
    function erp_table_exists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('erp_render_breadcrumb')) {
    function erp_render_breadcrumb(array $items): void
    {
        echo '<nav aria-label="breadcrumb"><ol class="breadcrumb mb-3">';
        $lastIndex = array_key_last($items);
        foreach ($items as $index => $item) {
            $label = erp_h($item['label'] ?? '');
            $url = $item['url'] ?? null;
            if ($index === $lastIndex || !$url) {
                echo '<li class="breadcrumb-item active" aria-current="page">' . $label . '</li>';
                continue;
            }
            echo '<li class="breadcrumb-item"><a href="' . erp_h($url) . '">' . $label . '</a></li>';
        }
        echo '</ol></nav>';
    }
}
