<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireRole(['director', 'accountant']);
$pdo = erp_db();
$filters = [
    'customer_id' => (int) ($_GET['customer_id'] ?? 0),
    'date_from' => trim((string) ($_GET['date_from'] ?? date('Y-m-01'))),
    'date_to' => trim((string) ($_GET['date_to'] ?? date('Y-m-t'))),
];
$where = ['1=1'];
$params = [];
if ($filters['customer_id'] > 0) { $where[] = 'i.customer_id = ?'; $params[] = $filters['customer_id']; }
if ($filters['date_from'] !== '') { $where[] = 'ip.payment_date >= ?'; $params[] = $filters['date_from']; }
if ($filters['date_to'] !== '') { $where[] = 'ip.payment_date <= ?'; $params[] = $filters['date_to']; }

$sql = 'SELECT ip.*, i.invoice_code, c.name AS customer_name
    FROM invoice_payments ip
    INNER JOIN invoices i ON i.id = ip.invoice_id
    INNER JOIN customers c ON c.id = i.customer_id
    WHERE ' . implode(' AND ', $where) . ' ORDER BY ip.payment_date DESC, ip.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$customers = $pdo->query('SELECT id, code, name FROM customers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$summaryStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE DATE_FORMAT(payment_date, '%Y-%m') = ?");
$summaryStmt->execute([date('Y-m')]);
$totalCollectedThisMonth = (float) $summaryStmt->fetchColumn();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="container-fluid py-4">
    <?php erp_render_breadcrumb([
        ['label' => 'Tổng quan', 'url' => erp_url('dashboard.php')],
        ['label' => 'Hóa đơn', 'url' => erp_url('modules/invoice/index.php')],
        ['label' => 'Lịch sử thanh toán'],
    ]); ?>

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3"><div><h1 class="h3 mb-1">Lịch sử thanh toán / Công nợ</h1><p class="text-muted mb-0">Tra cứu lịch sử thu tiền, tham chiếu thanh toán và công nợ theo khách hàng.</p></div><button class="btn btn-outline-secondary" type="button" onclick="window.print()"><i class="fa-solid fa-print me-2"></i>Export / Print</button></div>

    <div class="card shadow-sm border-0 mb-4"><div class="card-body"><div class="text-muted small">Tổng thu tháng này</div><div class="h4 mb-0 text-success"><?= erp_h(erp_format_vnd($totalCollectedThisMonth)) ?></div></div></div>

    <div class="card shadow-sm border-0 mb-4"><div class="card-body"><form class="row g-3" method="get"><div class="col-md-4"><label class="form-label">Khách hàng</label><select class="form-select" name="customer_id"><option value="0">Tất cả</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id'] ?>" <?= $filters['customer_id'] === (int) $customer['id'] ? 'selected' : '' ?>><?= erp_h($customer['code'] . ' - ' . $customer['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Từ ngày</label><input type="date" class="form-control" name="date_from" value="<?= erp_h($filters['date_from']) ?>"></div><div class="col-md-3"><label class="form-label">Đến ngày</label><input type="date" class="form-control" name="date_to" value="<?= erp_h($filters['date_to']) ?>"></div><div class="col-md-2 align-self-end d-flex gap-2"><button class="btn btn-outline-primary flex-fill" type="submit">Lọc</button><a class="btn btn-outline-secondary" href="<?= erp_h(erp_url('modules/invoice/payments.php')) ?>">Reset</a></div></form></div></div>

    <div class="card shadow-sm border-0"><div class="card-body table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Mã HĐ</th><th>Khách hàng</th><th>Ngày thanh toán</th><th>Số tiền</th><th>Phương thức</th><th>Tham chiếu</th><th>Người thu</th></tr></thead><tbody><?php if (!$payments): ?><tr><td colspan="7" class="text-center text-muted py-4">Chưa có giao dịch thanh toán.</td></tr><?php endif; ?><?php foreach ($payments as $payment): ?><tr><td class="fw-semibold"><?= erp_h($payment['invoice_code']) ?></td><td><?= erp_h($payment['customer_name']) ?></td><td><?= erp_h(erp_format_date($payment['payment_date'])) ?></td><td class="text-nowrap"><?= erp_h(erp_format_vnd($payment['amount'])) ?></td><td><?= erp_h($payment['method']) ?></td><td><?= erp_h($payment['reference']) ?></td><td><?= erp_h($payment['paid_by']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
