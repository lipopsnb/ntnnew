<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'manager']);
$pdo = erp_db();

$errors = [];
$formData = [
    'id' => 0,
    'code' => '',
    'name' => '',
    'phone' => '',
    'contact_person' => '',
    'email' => '',
    'tax_code' => '',
    'is_active' => 1,
];
$activeTab = 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!erp_validate_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Phiên làm việc hết hạn. Vui lòng thử lại.';
    } else {
        try {
            if ($action === 'save_customer') {
                $activeTab = 'form';
                $formData = [
                    'id' => (int) ($_POST['id'] ?? 0),
                    'code' => trim((string) ($_POST['code'] ?? '')),
                    'name' => trim((string) ($_POST['name'] ?? '')),
                    'phone' => trim((string) ($_POST['phone'] ?? '')),
                    'contact_person' => trim((string) ($_POST['contact_person'] ?? '')),
                    'email' => trim((string) ($_POST['email'] ?? '')),
                    'tax_code' => trim((string) ($_POST['tax_code'] ?? '')),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                ];

                if ($formData['code'] === '' || $formData['name'] === '') {
                    $errors[] = 'Mã khách hàng và tên khách hàng là bắt buộc.';
                }

                if (!$errors) {
                    if ($formData['id'] > 0) {
                        $stmt = $pdo->prepare('UPDATE customers SET code = ?, name = ?, phone = ?, contact_person = ?, email = ?, tax_code = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
                        $stmt->execute([
                            $formData['code'],
                            $formData['name'],
                            $formData['phone'],
                            $formData['contact_person'],
                            $formData['email'],
                            $formData['tax_code'],
                            $formData['is_active'],
                            $formData['id'],
                        ]);
                        erp_flash('success', 'Đã cập nhật khách hàng.');
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO customers (code, name, phone, contact_person, email, tax_code, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                        $stmt->execute([
                            $formData['code'],
                            $formData['name'],
                            $formData['phone'],
                            $formData['contact_person'],
                            $formData['email'],
                            $formData['tax_code'],
                            $formData['is_active'],
                        ]);
                        erp_flash('success', 'Đã thêm khách hàng mới.');
                    }

                    erp_redirect(erp_url('modules/master/customers.php'));
                }
            }

            if ($action === 'toggle_customer') {
                $customerId = (int) ($_POST['customer_id'] ?? 0);
                $stmt = $pdo->prepare('UPDATE customers SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END, updated_at = NOW() WHERE id = ?');
                $stmt->execute([$customerId]);
                erp_flash('success', 'Đã cập nhật trạng thái khách hàng.');
                erp_redirect(erp_url('modules/master/customers.php'));
            }

            if ($action === 'delete_customer') {
                $customerId = (int) ($_POST['customer_id'] ?? 0);
                $stmt = $pdo->prepare('DELETE FROM customers WHERE id = ?');
                $stmt->execute([$customerId]);
                erp_flash('success', 'Đã xóa khách hàng.');
                erp_redirect(erp_url('modules/master/customers.php'));
            }
        } catch (PDOException $exception) {
            $errors[] = 'Không thể lưu dữ liệu khách hàng: ' . $exception->getMessage();
        }
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0 && $formData['id'] === 0) {
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$editId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($record) {
        $formData = array_merge($formData, $record, ['id' => (int) $record['id']]);
        $activeTab = 'form';
    }
}

$keyword = trim((string) ($_GET['keyword'] ?? ''));
$sql = 'SELECT c.*, (SELECT COUNT(*) FROM job_orders jo WHERE jo.customer_id = c.id) AS job_order_count FROM customers c WHERE 1=1';
$params = [];
if ($keyword !== '') {
    $sql .= ' AND (c.name LIKE ? OR c.code LIKE ?)';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
}
$sql .= ' ORDER BY c.name ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flashes = erp_pull_flashes();
$csrfToken = erp_csrf_token();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Sản xuất', 'url' => erp_url('modules/production/index.php')],
        ['label' => 'Danh mục KH'],
    ]); ?>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h1 class="h3 mb-1">Quản lý khách hàng</h1>
            <p class="text-muted mb-0">Theo dõi khách hàng, trạng thái hoạt động và lịch sử phiếu gia công.</p>
        </div>
        <a class="btn btn-primary" href="#customer-form-tab" data-bs-toggle="tab"><i class="fa-solid fa-plus me-2"></i>Thêm khách hàng</a>
    </div>

    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= erp_h($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= erp_h($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?>
                    <li><?= erp_h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-0 pt-3">
            <ul class="nav nav-tabs card-header-tabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab === 'list' ? 'active' : '' ?>" id="customer-list-tab" data-bs-toggle="tab" data-bs-target="#customer-list-pane" type="button">Danh sách KH</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?= $activeTab === 'form' ? 'active' : '' ?>" id="customer-form-tab" data-bs-toggle="tab" data-bs-target="#customer-form-pane" type="button">Thêm/Sửa</button>
                </li>
            </ul>
        </div>
        <div class="card-body tab-content">
            <div class="tab-pane fade <?= $activeTab === 'list' ? 'show active' : '' ?>" id="customer-list-pane">
                <form class="row g-3 mb-3" method="get">
                    <div class="col-md-5">
                        <label class="form-label">Tìm theo mã / tên khách hàng</label>
                        <input type="text" class="form-control" name="keyword" value="<?= erp_h($keyword) ?>" placeholder="VD: KH-001 hoặc NTN Vietnam">
                    </div>
                    <div class="col-md-3 align-self-end d-flex gap-2">
                        <button class="btn btn-outline-primary" type="submit"><i class="fa-solid fa-magnifying-glass me-2"></i>Lọc</button>
                        <a class="btn btn-outline-secondary" href="<?= erp_h(erp_url('modules/master/customers.php')) ?>">Đặt lại</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                        <tr>
                            <th>Mã KH</th>
                            <th>Tên khách hàng</th>
                            <th>Điện thoại</th>
                            <th>Người liên hệ</th>
                            <th>Email</th>
                            <th>MST</th>
                            <th>Trạng thái</th>
                            <th class="text-end">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$customers): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">Chưa có khách hàng.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($customers as $customer): ?>
                            <tr>
                                <td class="fw-semibold"><?= erp_h($customer['code']) ?></td>
                                <td>
                                    <div><?= erp_h($customer['name']) ?></div>
                                    <a class="small text-decoration-none" href="<?= erp_h(erp_url('modules/production/job_orders.php?customer_id=' . (int) $customer['id'])) ?>">Xem lịch sử phiếu GC (<?= (int) $customer['job_order_count'] ?>)</a>
                                </td>
                                <td><?= erp_h($customer['phone']) ?></td>
                                <td><?= erp_h($customer['contact_person']) ?></td>
                                <td><?= erp_h($customer['email']) ?></td>
                                <td><?= erp_h($customer['tax_code']) ?></td>
                                <td>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="toggle_customer">
                                        <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">
                                        <button type="submit" class="btn btn-sm <?= (int) $customer['is_active'] === 1 ? 'btn-outline-success' : 'btn-outline-secondary' ?>">
                                            <?= (int) $customer['is_active'] === 1 ? 'Đang hoạt động' : 'Ngưng hoạt động' ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a class="btn btn-outline-primary" href="<?= erp_h(erp_url('modules/master/customers.php?edit=' . (int) $customer['id'])) ?>"><i class="fa-regular fa-pen-to-square"></i></a>
                                        <form method="post" onsubmit="return confirm('Xóa khách hàng này?');">
                                            <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete_customer">
                                            <input type="hidden" name="customer_id" value="<?= (int) $customer['id'] ?>">
                                            <button class="btn btn-outline-danger" type="submit"><i class="fa-regular fa-trash-can"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="tab-pane fade <?= $activeTab === 'form' ? 'show active' : '' ?>" id="customer-form-pane">
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= erp_h($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_customer">
                    <input type="hidden" name="id" value="<?= (int) $formData['id'] ?>">
                    <div class="col-md-4">
                        <label class="form-label">Mã khách hàng <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="code" value="<?= erp_h($formData['code']) ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Tên khách hàng <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" value="<?= erp_h($formData['name']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Điện thoại</label>
                        <input type="text" class="form-control" name="phone" value="<?= erp_h($formData['phone']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Người liên hệ</label>
                        <input type="text" class="form-control" name="contact_person" value="<?= erp_h($formData['contact_person']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= erp_h($formData['email']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Mã số thuế</label>
                        <input type="text" class="form-control" name="tax_code" value="<?= erp_h($formData['tax_code']) ?>">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" <?= (int) $formData['is_active'] === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">Đang hoạt động</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Lưu khách hàng</button>
                        <a class="btn btn-outline-secondary" href="<?= erp_h(erp_url('modules/master/customers.php')) ?>">Làm mới</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
