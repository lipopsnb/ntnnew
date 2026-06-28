<?php
declare(strict_types=1);

function adminGroupLabel(string $group): string
{
    return match ($group) {
        'fixed_asset' => 'Tài sản cố định',
        'consumable' => 'Vật tư tiêu hao',
        'vehicle' => 'Xe cộ',
        default => ucfirst($group),
    };
}

function adminAssetStatusLabel(string $status): string
{
    return match ($status) {
        'active' => 'Đang sử dụng',
        'maintenance' => 'Bảo trì',
        'broken' => 'Hỏng',
        'disposed' => 'Thanh lý',
        default => ucfirst($status),
    };
}

function adminAssetStatusBadgeClass(string $status): string
{
    return match ($status) {
        'active' => 'success',
        'maintenance' => 'warning text-dark',
        'broken' => 'danger',
        'disposed' => 'secondary',
        default => 'light text-dark',
    };
}

function adminMaintenanceTypeLabel(string $type): string
{
    return match ($type) {
        'preventive' => 'Phòng ngừa',
        'corrective' => 'Khắc phục',
        default => ucfirst($type),
    };
}

function adminMaintenanceTypeBadgeClass(string $type): string
{
    return match ($type) {
        'preventive' => 'primary',
        'corrective' => 'warning text-dark',
        default => 'secondary',
    };
}

function adminVehicleExpenseTypeLabel(string $type): string
{
    return match ($type) {
        'xang' => 'Xăng',
        'dau' => 'Dầu',
        'bao_duong' => 'Bảo dưỡng',
        'sua_chua' => 'Sửa chữa',
        'dangkiem' => 'Đăng kiểm',
        'baohiem' => 'Bảo hiểm',
        'khac' => 'Khác',
        default => ucfirst($type),
    };
}

function adminVehicleExpenseTypes(): array
{
    return [
        'xang' => 'Xăng',
        'dau' => 'Dầu',
        'bao_duong' => 'Bảo dưỡng',
        'sua_chua' => 'Sửa chữa',
        'dangkiem' => 'Đăng kiểm',
        'baohiem' => 'Bảo hiểm',
        'khac' => 'Khác',
    ];
}

function adminExpenseStatusLabel(string $status): string
{
    return match ($status) {
        'draft' => 'Nháp',
        'submitted' => 'Đã nộp',
        'approved' => 'Đã duyệt',
        'rejected' => 'Từ chối',
        'paid' => 'Đã thanh toán',
        'refunded' => 'Hoàn ứng',
        default => ucfirst($status),
    };
}

function adminExpenseStatusBadgeClass(string $status): string
{
    return match ($status) {
        'draft' => 'secondary',
        'submitted' => 'warning text-dark',
        'approved' => 'info text-dark',
        'rejected' => 'danger',
        'paid' => 'success',
        'refunded' => 'primary',
        default => 'light text-dark',
    };
}

function adminExpenseTimeline(string $status): string
{
    $baseSteps = [
        'draft' => 'Nháp',
        'submitted' => 'Nộp',
        'approved' => 'Duyệt',
        'paid' => 'Thanh toán',
    ];
    $rank = ['draft' => 1, 'submitted' => 2, 'approved' => 3, 'paid' => 4, 'refunded' => 5];
    $currentRank = $rank[$status] ?? 0;
    $html = '<div class="d-flex flex-wrap gap-1">';
    foreach ($baseSteps as $key => $label) {
        $stepRank = $rank[$key] ?? 0;
        $class = $currentRank >= $stepRank ? 'text-bg-success' : 'text-bg-light';
        if ($status === 'rejected' && $key === 'approved') {
            $class = 'text-bg-light';
        }
        $html .= '<span class="badge ' . $class . '">' . e($label) . '</span>';
    }
    if ($status === 'rejected') {
        $html .= '<span class="badge text-bg-danger">Từ chối</span>';
    }
    if ($status === 'refunded') {
        $html .= '<span class="badge text-bg-primary">Hoàn ứng</span>';
    }
    return $html . '</div>';
}

function adminGenerateAssetCode(PDO $pdo, string $groupType = 'fixed_asset'): string
{
    $prefix = match ($groupType) {
        'consumable' => 'VT',
        'vehicle' => 'XE',
        default => 'TS',
    };

    $rows = fetchAllSafe(
        $pdo,
        'SELECT asset_code FROM assets WHERE asset_code LIKE :prefix ORDER BY id DESC LIMIT 200',
        ['prefix' => $prefix . '%']
    );

    $max = 0;
    foreach ($rows as $row) {
        $code = (string) ($row['asset_code'] ?? '');
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $code, $matches)) {
            $max = max($max, (int) $matches[1]);
        }
    }

    return $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}

function adminGenerateExpenseRequestCode(PDO $pdo): string
{
    $prefix = 'CP' . date('Ymd');
    $rows = fetchAllSafe(
        $pdo,
        'SELECT request_code FROM expense_requests WHERE request_code LIKE :prefix ORDER BY id DESC LIMIT 200',
        ['prefix' => $prefix . '%']
    );

    $max = 0;
    foreach ($rows as $row) {
        $code = (string) ($row['request_code'] ?? '');
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $code, $matches)) {
            $max = max($max, (int) $matches[1]);
        }
    }

    return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}

function adminAvatarHtml(?string $name, ?string $avatar): string
{
    $name = trim((string) $name);
    $avatar = trim((string) $avatar);

    if ($avatar !== '') {
        $src = preg_match('#^(https?:)?//#i', $avatar) || str_starts_with($avatar, '/')
            ? $avatar
            : basePath(ltrim($avatar, '/'));

        return '<img src="' . e($src) . '" alt="' . e($name) . '" class="rounded-circle border" style="width:40px;height:40px;object-fit:cover;">';
    }

    return '<span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-semibold text-primary bg-primary-subtle border" style="width:40px;height:40px;">' . e(getInitials($name !== '' ? $name : 'NTN')) . '</span>';
}
