<?php
declare(strict_types=1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';

requireRole(['director', 'accountant', 'manager']);
ensurePostCsrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deactivate') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        setFlash('danger', 'Nhân viên không hợp lệ.');
        redirect('modules/users/index.php');
    }

    $statement = $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :id');
    $statement->execute(['id' => $userId]);
    setFlash('success', 'Đã vô hiệu hóa tài khoản nhân viên.');
    redirect('modules/users/index.php');
}

$search = trim((string) ($_GET['search'] ?? ''));
$departmentFilter = trim((string) ($_GET['department_id'] ?? ''));
$roleFilter = trim((string) ($_GET['role_id'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(u.employee_code LIKE :search OR u.full_name LIKE :search OR u.phone LIKE :search OR u.email LIKE :search)';
    $params['search'] = '%' . $search . '%';
}
if ($departmentFilter !== '' && ctype_digit($departmentFilter)) {
    $where[] = 'u.department_id = :department_id';
    $params['department_id'] = (int) $departmentFilter;
}
if ($roleFilter !== '' && ctype_digit($roleFilter)) {
    $where[] = 'u.role_id = :role_id';
    $params['role_id'] = (int) $roleFilter;
}
if ($statusFilter === 'active') {
    $where[] = 'u.is_active = :active_status';
    $params['active_status'] = 1;
} elseif ($statusFilter === 'inactive') {
    $where[] = 'u.is_active = :inactive_status';
    $params['inactive_status'] = 0;
}

$whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
$countSql = "SELECT COUNT(*) FROM users u {$whereSql}";
$countStatement = $pdo->prepare($countSql);
$countStatement->execute($params);
$totalRows = (int) $countStatement->fetchColumn();
$totalPages = (int) max(1, ceil($totalRows / $perPage));

$listSql = "SELECT u.id, u.employee_code, u.full_name, u.phone, u.email, u.hire_date, u.is_active,
                   r.name AS role_name, r.display_name AS role_display_name, d.name AS department_name
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            LEFT JOIN departments d ON d.id = u.department_id
            {$whereSql}
            ORDER BY u.created_at DESC
            LIMIT :limit OFFSET :offset";
$listStatement = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStatement->bindValue(':' . $key, $value);
}
$listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStatement->execute();
$users = $listStatement->fetchAll();

$departments = fetchAllSafe($pdo, 'SELECT id, name FROM departments ORDER BY name ASC');
$roles = fetchAllSafe($pdo, 'SELECT id, name, display_name FROM roles ORDER BY display_name ASC');

$pageTitle = 'Danh sách nhân viên';
$breadcrumbs = [
    ['label' => 'Tổng quan', 'url' => 'dashboard.php'],
    ['label' => 'Nhân sự'],
    ['label' => 'Danh sách nhân viên'],
];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/../../includes/sidebar.php';
?>
<div class="card content-card mb-4"><div class="card-body p-4"><div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h1 class="h4 mb-1">Danh sách nhân viên</h1><p class="text-muted mb-0">Quản lý hồ sơ, trạng thái và phân quyền nội bộ.</p></div><a href="<?= e(basePath('modules/users/create.php')) ?>" class="btn btn-primary"><i class="fa-solid fa-user-plus me-2"></i>Thêm nhân viên</a></div><form method="get" class="row g-3 align-items-end"><div class="col-lg-4"><label class="form-label">Tìm kiếm</label><input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="Mã NV, họ tên, SĐT, email"></div><div class="col-lg-2"><label class="form-label">Phòng ban</label><select name="department_id" class="form-select"><option value="">Tất cả</option><?php foreach ($departments as $department): ?><option value="<?= e((string) $department['id']) ?>" <?= $departmentFilter === (string) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div><div class="col-lg-2"><label class="form-label">Chức vụ</label><select name="role_id" class="form-select"><option value="">Tất cả</option><?php foreach ($roles as $role): ?><option value="<?= e((string) $role['id']) ?>" <?= $roleFilter === (string) $role['id'] ? 'selected' : '' ?>><?= e($role['display_name']) ?></option><?php endforeach; ?></select></div><div class="col-lg-2"><label class="form-label">Trạng thái</label><select name="status" class="form-select"><option value="">Tất cả</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Đang làm việc</option><option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Vô hiệu hóa</option></select></div><div class="col-lg-2 d-grid"><button type="submit" class="btn btn-outline-primary"><i class="fa-solid fa-filter me-2"></i>Lọc dữ liệu</button></div></form></div></div>

<div class="card content-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Mã NV</th><th>Họ tên</th><th>Chức vụ</th><th>Phòng ban</th><th>SĐT</th><th>Email</th><th>Ngày vào làm</th><th>Trạng thái</th><th class="text-end">Actions</th></tr></thead><tbody><?php if ($users === []): ?><tr><td colspan="9" class="text-center py-4 text-muted">Không tìm thấy nhân viên phù hợp.</td></tr><?php else: ?><?php foreach ($users as $user): ?><tr><td class="fw-semibold"><?= e($user['employee_code'] ?? '—') ?></td><td><?= e($user['full_name'] ?? '—') ?></td><td><span class="badge text-bg-<?= e(roleBadgeClass((string) ($user['role_name'] ?? 'employee'))) ?>"><?= e($user['role_display_name'] ?? roleLabel((string) ($user['role_name'] ?? 'employee'))) ?></span></td><td><?= e($user['department_name'] ?? '—') ?></td><td><?= e($user['phone'] ?? '—') ?></td><td><?= e($user['email'] ?? '—') ?></td><td><?= e(formatDate($user['hire_date'] ?? null)) ?></td><td><?php if ((int) ($user['is_active'] ?? 0) === 1): ?><span class="badge text-bg-success">Đang làm việc</span><?php else: ?><span class="badge text-bg-secondary">Vô hiệu hóa</span><?php endif; ?></td><td class="text-end"><div class="btn-group"><a href="<?= e(basePath('modules/users/profile.php')) ?>?user_id=<?= e((string) $user['id']) ?>" class="btn btn-sm btn-outline-info">Xem</a><a href="<?= e(basePath('modules/users/edit.php')) ?>?id=<?= e((string) $user['id']) ?>" class="btn btn-sm btn-outline-primary">Sửa</a><?php if ((int) ($user['is_active'] ?? 0) === 1): ?><form method="post" onsubmit="return confirm('Bạn chắc chắn muốn vô hiệu hóa nhân viên này?');"><?= csrf_input() ?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="user_id" value="<?= e((string) $user['id']) ?>"><button type="submit" class="btn btn-sm btn-outline-danger">Vô hiệu hóa</button></form><?php endif; ?></div></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div></div><div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-3"><div class="text-muted">Hiển thị <?= count($users) ?> / <?= number_format($totalRows) ?> nhân viên</div><?= paginationLinks($page, $totalPages, 'modules/users/index.php', ['search' => $search, 'department_id' => $departmentFilter, 'role_id' => $roleFilter, 'status' => $statusFilter]) ?></div></div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
