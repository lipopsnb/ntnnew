<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

requireRole('director', 'accountant', 'manager');
$pdo = getDBConnection();

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$formData = [
    'id' => 0,
    'customer_code' => '',
    'customer_name' => '',
    'address' => '',
    'contact_person' => '',
    'phone' => '',
    'email' => '',
    'is_active' => 1,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc đã hết hạn.');
        header('Location: /ntn_erp/modules/master/customers.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_customer') {
        $formData = [
            'id' => (int) ($_POST['id'] ?? 0),
            'customer_code' => trim((string) ($_POST['customer_code'] ?? '')),
            'customer_name' => trim((string) ($_POST['customer_name'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'contact_person' => trim((string) ($_POST['contact_person'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if ($formData['customer_code'] === '' || $formData['customer_name'] === '') {
            $errors[] = 'Vui lòng nhập mã và tên khách hàng.';
        }

        if ($formData['email'] !== '' && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email không hợp lệ.';
        }

        $dupStmt = $pdo->prepare('SELECT id FROM customers WHERE customer_code = ? AND id <> ? LIMIT 1');
        $dupStmt->execute([$formData['customer_code'], $formData['id']]);
        if ($dupStmt->fetch()) {
            $errors[] = 'Mã khách hàng đã tồn tại.';
        }

        if (!$errors) {
            $userId = (int) (currentUser()['id'] ?? 0);
            if ($formData['id'] > 0) {
                $stmt = $pdo->prepare('UPDATE customers SET customer_code = ?, customer_name = ?, address = ?, contact_person = ?, phone = ?, email = ?, is_active = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([
                    $formData['customer_code'],
                    $formData['customer_name'],
                    $formData['address'],
                    $formData['contact_person'],
                    $formData['phone'],
                    $formData['email'],
                    $formData['is_active'],
                    $formData['id'],
                ]);
                setFlash('success', 'Đã cập nhật khách hàng.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO customers (customer_code, customer_name, address, contact_person, phone, email, is_active, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                $stmt->execute([
                    $formData['customer_code'],
                    $formData['customer_name'],
                    $formData['address'],
                    $formData['contact_person'],
                    $formData['phone'],
                    $formData['email'],
                    $formData['is_active'],
                    $userId ?: null,
                ]);
                setFlash('success', 'Đã thêm khách hàng mới.');
            }

            header('Location: /ntn_erp/modules/master/customers.php');
            exit;
        }
    }

    if ($action === 'toggle_active') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE customers SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
        setFlash('success', 'Đã cập nhật trạng thái khách hàng.');
        header('Location: /ntn_erp/modules/master/customers.php');
        exit;
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0 && $formData['id'] === 0) {
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
    $stmt->execute([$editId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($record) {
        $formData = array_merge($formData, $record, ['id' => (int) $record['id']]);
    }
}

$keyword = trim((string) ($_GET['keyword'] ?? ''));
$sql = 'SELECT id, customer_code, customer_name, phone, address, contact_person, email, is_active FROM customers WHERE 1=1';
$params = [];
if ($keyword !== '') {
    $sql .= ' AND (customer_code LIKE ? OR customer_name LIKE ?)';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
}
$sql .= ' ORDER BY customer_name ASC, id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = getFlash();
$csrfToken = generateCSRF();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Danh mục khách hàng</h1>
            <p class="text-muted mb-0">Quản lý thông tin khách hàng theo mã và trạng thái hoạt động.</p>
        </div>
        <a href="/ntn_erp/modules/master/customers.php" class="btn btn-outline-secondary">Làm mới</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <form method="get" class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label">Tìm theo mã / tên khách hàng</label>
                            <input type="text" name="keyword" class="form-control" value="<?= e($keyword) ?>" placeholder="VD: KH001 hoặc Công ty ABC">
                        </div>
                        <div class="col-md-4 d-flex gap-2 align-items-end">
                            <button type="submit" class="btn btn-primary flex-fill">Tìm kiếm</button>
                            <a href="/ntn_erp/modules/master/customers.php" class="btn btn-outline-secondary">Reset</a>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Mã KH</th>
                                    <th>Tên khách hàng</th>
                                    <th>Điện thoại</th>
                                    <th>Địa chỉ</th>
                                    <th>Người liên hệ</th>
                                    <th>Email</th>
                                    <th>Trạng thái</th>
                                    <th class="text-end">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$customers): ?>
                                    <tr><td colspan="8" class="text-center text-muted py-4">Chưa có khách hàng.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= e($customer['customer_code']) ?></td>
                                        <td><?= e($customer['customer_name']) ?></td>
                                        <td><?= e($customer['phone']) ?></td>
                                        <td><?= e($customer['address']) ?></td>
                                        <td><?= e($customer['contact_person']) ?></td>
                                        <td><?= e($customer['email']) ?></td>
                                        <td>
                                            <span class="badge text-bg-<?= (int) $customer['is_active'] === 1 ? 'success' : 'secondary' ?>">
                                                <?= (int) $customer['is_active'] === 1 ? 'Đang hoạt động' : 'Ngưng hoạt động' ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <a href="/ntn_erp/modules/master/customers.php?edit=<?= (int) $customer['id'] ?>" class="btn btn-outline-primary">Sửa</a>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="toggle_active">
                                                    <input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-secondary">Bật/Tắt</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <h2 class="h5 mb-0"><?= $formData['id'] > 0 ? 'Cập nhật khách hàng' : 'Thêm khách hàng' ?></h2>
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_customer">
                        <input type="hidden" name="id" value="<?= (int) $formData['id'] ?>">

                        <div class="col-12">
                            <label class="form-label">Mã khách hàng <span class="text-danger">*</span></label>
                            <input type="text" name="customer_code" class="form-control" value="<?= e($formData['customer_code']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Tên khách hàng <span class="text-danger">*</span></label>
                            <input type="text" name="customer_name" class="form-control" value="<?= e($formData['customer_name']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Địa chỉ</label>
                            <textarea name="address" class="form-control" rows="2"><?= e($formData['address']) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Người liên hệ</label>
                            <input type="text" name="contact_person" class="form-control" value="<?= e($formData['contact_person']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Điện thoại</label>
                            <input type="text" name="phone" class="form-control" value="<?= e($formData['phone']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= e($formData['email']) ?>">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="customerActive" name="is_active" <?= (int) $formData['is_active'] === 1 ? 'checked' : '' ?>>
                                <label class="form-check-label" for="customerActive">Đang hoạt động</label>
                            </div>
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-fill">Lưu khách hàng</button>
                            <a href="/ntn_erp/modules/master/customers.php" class="btn btn-outline-secondary">Hủy</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
