<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireRole('director', 'accountant');

$pdo = getDBConnection();
$search = trim($_GET['search'] ?? '');
$roleFilter = (int) ($_GET['role_id'] ?? 0);
$departmentFilter = (int) ($_GET['department_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR u.employee_code LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($roleFilter > 0) {
    $where[] = 'u.role_id = ?';
    $params[] = $roleFilter;
}
if ($departmentFilter > 0) {
    $where[] = 'u.department_id = ?';
    $params[] = $departmentFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT u.*, r.name AS role_name, r.display_name, d.name AS department_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE $whereSql
     ORDER BY u.full_name ASC
     LIMIT " . (int) $perPage . " OFFSET " . (int) $offset
);
$listStmt->execute($params);
$users = $listStmt->fetchAll();
$roles = $pdo->query("SELECT id, display_name FROM roles ORDER BY id ASC")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <?php showFlash(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h4 class="mb-1">Danh sách nhân viên</h4>
                <p class="text-muted mb-0">Tổng cộng <?= $totalRows ?> nhân viên</p>
            </div>
            <a class="btn btn-primary" href="/ntn_erp/modules/users/create.php">Thêm nhân viên</a>
        </div>
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-4"><label class="form-label">Tìm theo tên / mã NV</label><input class="form-control" type="text" name="search" value="<?= e($search) ?>"></div>
                    <div class="col-md-3"><label class="form-label">Vai trò</label><select class="form-select" name="role_id"><option value="0">Tất cả vai trò</option><?php foreach ($roles as $role): ?><option value="<?= (int) $role['id'] ?>" <?= $roleFilter === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['display_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label">Phòng ban</label><select class="form-select" name="department_id"><option value="0">Tất cả phòng ban</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= $departmentFilter === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Lọc</button></div>
                </form>
            </div>
        </div>
        <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Mã NV</th><th>Họ tên</th><th>Vai trò</th><th>Phòng ban</th><th>Điện thoại</th><th>Email</th><th>Trạng thái</th><th class="text-end">Liên kết</th></tr></thead>
                    <tbody>
                        <?php if (!$users): ?><tr><td colspan="8" class="text-center text-muted py-4">Không tìm thấy nhân viên phù hợp.</td></tr><?php endif; ?>
                        <?php foreach ($users as $row): ?>
                            <?php $badge = getRoleBadge($row['role_name']); ?>
                            <tr>
                                <td><?= e($row['employee_code']) ?></td>
                                <td><?= e($row['full_name']) ?></td>
                                <td><span class="badge bg-<?= e($badge['class']) ?>"><?= e($badge['label']) ?></span></td>
                                <td><?= e($row['department_name'] ?? 'Chưa có') ?></td>
                                <td><?= e($row['phone'] ?: '-') ?></td>
                                <td><?= e($row['email'] ?: '-') ?></td>
                                <td><span class="badge bg-<?= (int) $row['is_active'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $row['is_active'] === 1 ? 'Hoạt động' : 'Ngừng' ?></span></td>
                                <td class="text-end"><div class="btn-group btn-group-sm"><a class="btn btn-outline-primary" href="/ntn_erp/modules/users/edit.php?id=<?= (int) $row['id'] ?>">Sửa</a><a class="btn btn-outline-secondary" href="/ntn_erp/modules/users/profile.php?id=<?= (int) $row['id'] ?>">Hồ sơ</a></div></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white"><ul class="pagination pagination-sm mb-0 justify-content-end"><?php for ($p = 1; $p <= $totalPages; $p++): ?><li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= e(http_build_query(['search' => $search, 'role_id' => $roleFilter, 'department_id' => $departmentFilter, 'page' => $p])) ?>"><?= $p ?></a></li><?php endfor; ?></ul></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
