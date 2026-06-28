<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

requireRole('director', 'accountant', 'manager', 'production', 'warehouse');
$pdo = getDBConnection();

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . ' đ';
    }
}
if (!function_exists('formatQty')) {
    function formatQty($value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }
}
if (!function_exists('docStatusBadge')) {
    function docStatusBadge(string $status): string
    {
        return match ($status) {
            'confirmed' => 'success',
            'invoiced' => 'primary',
            default => 'secondary',
        };
    }
}
if (!function_exists('docStatusLabel')) {
    function docStatusLabel(string $status): string
    {
        return match ($status) {
            'confirmed' => 'Đã xác nhận',
            'invoiced' => 'Đã xuất HĐ',
            default => 'Nháp',
        };
    }
}
if (!function_exists('nextDocCode')) {
    function nextDocCode(PDO $pdo, string $prefix, ?string $docDate = null): string
    {
        if ($docDate !== null && date('Y-m-d', strtotime($docDate)) === date('Y-m-d') && function_exists('generateDocCode')) {
            return generateDocCode($pdo, $prefix);
        }
        $docDate = $docDate ?: date('Y-m-d');
        $dateKey = date('Y-m-d', strtotime($docDate));
        $stmt = $pdo->prepare('SELECT id, last_seq FROM document_sequences WHERE doc_type = ? AND doc_date = ? FOR UPDATE');
        $stmt->execute([$prefix, $dateKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $next = $row ? ((int) $row['last_seq'] + 1) : 1;
        if ($row) {
            $pdo->prepare('UPDATE document_sequences SET last_seq = ? WHERE id = ?')->execute([$next, $row['id']]);
        } else {
            $pdo->prepare('INSERT INTO document_sequences (doc_type, doc_date, last_seq) VALUES (?, ?, ?)')->execute([$prefix, $dateKey, $next]);
        }
        return sprintf('%s-%s-%03d', $prefix, date('Ymd', strtotime($dateKey)), $next);
    }
}
if (!function_exists('adjustWarehouseCompletedStock')) {
    function adjustWarehouseCompletedStock(PDO $pdo, int $productId, float $delta): void
    {
        $stmt = $pdo->prepare('SELECT id, qty_completed FROM warehouse_stock WHERE product_code_id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pdo->prepare('UPDATE warehouse_stock SET qty_completed = ?, updated_at = NOW() WHERE id = ?')->execute([max(0, (float) $row['qty_completed'] + $delta), $row['id']]);
        } else {
            $pdo->prepare('INSERT INTO warehouse_stock (product_code_id, qty_pending, qty_completed, qty_defect, updated_at) VALUES (?, 0, ?, 0, NOW())')->execute([$productId, max(0, $delta)]);
        }
    }
}
if (!function_exists('addWarehouseStockLog')) {
    function addWarehouseStockLog(PDO $pdo, int $productId, string $date, string $txnType, string $stockType, float $qtyChange, string $refTable, int $refId, string $note, ?int $createdBy): void
    {
        $stmt = $pdo->prepare('INSERT INTO warehouse_stock_log (product_code_id, log_date, txn_type, stock_type, qty_change, ref_table, ref_id, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$productId, $date, $txnType, $stockType, $qtyChange, $refTable, $refId, $note, $createdBy]);
    }
}

$errors = [];
$flash = getFlash();
$csrfToken = generateCSRF();
$userId = (int) (currentUser()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Phiên làm việc đã hết hạn.');
        header('Location: /ntn_erp/modules/production/delivery.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'confirm_delivery') {
        $deliveryId = (int) ($_POST['delivery_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE delivery_notes SET status = 'confirmed', updated_at = NOW() WHERE id = ? AND status = 'draft'");
        $stmt->execute([$deliveryId]);
        setFlash('success', 'Đã xác nhận phiếu giao hàng.');
        header('Location: /ntn_erp/modules/production/delivery.php');
        exit;
    }

    if ($action === 'create_delivery') {
        $deliveryDate = trim((string) ($_POST['delivery_date'] ?? date('Y-m-d')));
        $customerId = (int) ($_POST['customer_id'] ?? 0);
        $senderName = trim((string) ($_POST['sender_name'] ?? ''));
        $senderPhone = trim((string) ($_POST['sender_phone'] ?? ''));
        $vehiclePlate = trim((string) ($_POST['vehicle_plate'] ?? ''));
        $driverName = trim((string) ($_POST['driver_name'] ?? ''));
        $driverPhone = trim((string) ($_POST['driver_phone'] ?? ''));
        $receiverName = trim((string) ($_POST['receiver_name'] ?? ''));
        $receiverPhone = trim((string) ($_POST['receiver_phone'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        $outputIds = $_POST['production_output_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $unitPrices = $_POST['unit_price'] ?? [];
        $descriptions = $_POST['description'] ?? [];
        $units = $_POST['unit'] ?? [];

        if ($customerId <= 0 || $deliveryDate === '') {
            $errors[] = 'Vui lòng chọn khách hàng và ngày giao hàng.';
        }

        $availableOutputRows = $pdo->query("SELECT po.id, po.product_code_id, po.description, po.quantity_completed, po.quantity_delivered, pc.product_code, pc.description AS product_name, pc.unit, (po.quantity_completed - po.quantity_delivered) AS qty_available, (SELECT pp.unit_price FROM product_prices pp WHERE pp.product_code_id = po.product_code_id ORDER BY pp.effective_from DESC, pp.id DESC LIMIT 1) AS latest_price FROM production_outputs po INNER JOIN product_codes pc ON pc.id = po.product_code_id WHERE po.quantity_completed > po.quantity_delivered ORDER BY po.output_date DESC, po.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $availableMap = [];
        foreach ($availableOutputRows as $row) {
            $availableMap[$row['id']] = $row;
        }

        $items = [];
        foreach ($outputIds as $index => $outputId) {
            $outputId = (int) $outputId;
            $qty = (float) ($quantities[$index] ?? 0);
            $unitPrice = (float) ($unitPrices[$index] ?? 0);
            if ($outputId <= 0 || $qty <= 0) {
                continue;
            }
            if (!isset($availableMap[$outputId])) {
                $errors[] = 'Có dòng hàng không còn khả dụng để giao.';
                break;
            }
            if ($qty > (float) $availableMap[$outputId]['qty_available'] + 0.00001) {
                $errors[] = 'Số lượng giao vượt quá tồn khả dụng.';
                break;
            }
            $items[] = [
                'production_output_id' => $outputId,
                'product_code_id' => (int) $availableMap[$outputId]['product_code_id'],
                'description' => trim((string) ($descriptions[$index] ?? $availableMap[$outputId]['description'] ?? $availableMap[$outputId]['product_name'])),
                'unit' => trim((string) ($units[$index] ?? $availableMap[$outputId]['unit'] ?? '')),
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'total_price' => $qty * $unitPrice,
            ];
        }

        if (!$items) {
            $errors[] = 'Vui lòng nhập ít nhất một dòng hàng giao hợp lệ.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                foreach ($items as $item) {
                    $check = $pdo->prepare('SELECT id, product_code_id, quantity_completed, quantity_delivered FROM production_outputs WHERE id = ? FOR UPDATE');
                    $check->execute([$item['production_output_id']]);
                    $output = $check->fetch(PDO::FETCH_ASSOC);
                    if (!$output) {
                        throw new RuntimeException('Phiếu xuất thành phẩm không tồn tại.');
                    }
                    $remaining = (float) $output['quantity_completed'] - (float) $output['quantity_delivered'];
                    if ($item['quantity'] > $remaining + 0.00001) {
                        throw new RuntimeException('Số lượng giao đã thay đổi, vui lòng tải lại trang.');
                    }
                }

                $deliveryNo = nextDocCode($pdo, 'DN', $deliveryDate);
                $totalAmount = array_sum(array_column($items, 'total_price'));
                $insert = $pdo->prepare("INSERT INTO delivery_notes (delivery_no, delivery_date, sender_name, sender_phone, vehicle_plate, driver_name, driver_phone, customer_id, total_amount, status, note, created_by, created_at, updated_at, receiver_name, receiver_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, NOW(), NOW(), ?, ?)");
                $insert->execute([$deliveryNo, $deliveryDate, $senderName, $senderPhone, $vehiclePlate, $driverName, $driverPhone, $customerId, $totalAmount, $note, $userId ?: null, $receiverName, $receiverPhone]);
                $deliveryId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare('INSERT INTO delivery_note_items (delivery_note_id, production_output_id, product_code_id, description, unit, quantity, unit_price, total_price, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($items as $item) {
                    $itemStmt->execute([$deliveryId, $item['production_output_id'], $item['product_code_id'], $item['description'], $item['unit'], $item['quantity'], $item['unit_price'], $item['total_price'], null]);
                    $pdo->prepare('UPDATE production_outputs SET quantity_delivered = quantity_delivered + ?, updated_at = NOW() WHERE id = ?')->execute([$item['quantity'], $item['production_output_id']]);
                    adjustWarehouseCompletedStock($pdo, $item['product_code_id'], -$item['quantity']);
                    addWarehouseStockLog($pdo, $item['product_code_id'], $deliveryDate, 'delivery', 'completed', -$item['quantity'], 'delivery_notes', $deliveryId, 'Xuất giao khách hàng', $userId ?: null);
                }
                $pdo->commit();
                setFlash('success', 'Đã tạo phiếu giao hàng ' . $deliveryNo . '.');
                header('Location: /ntn_erp/modules/production/delivery.php?view=' . $deliveryId);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $e->getMessage();
            }
        }
    }
}

$customers = $pdo->query('SELECT id, customer_code, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name ASC')->fetchAll(PDO::FETCH_ASSOC);
$availableOutputs = $pdo->query("SELECT po.id, po.output_no, po.output_date, po.product_code_id, po.description, po.quantity_completed, po.quantity_delivered, pc.product_code, pc.description AS product_name, pc.unit, (po.quantity_completed - po.quantity_delivered) AS qty_available, COALESCE((SELECT pp.unit_price FROM product_prices pp WHERE pp.product_code_id = po.product_code_id ORDER BY pp.effective_from DESC, pp.id DESC LIMIT 1), 0) AS latest_price FROM production_outputs po INNER JOIN product_codes pc ON pc.id = po.product_code_id WHERE po.quantity_completed > po.quantity_delivered ORDER BY po.output_date DESC, po.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$outputMap = [];
foreach ($availableOutputs as $row) {
    $outputMap[$row['id']] = $row;
}
$deliveryNotes = $pdo->query("SELECT dn.*, c.customer_code, c.customer_name, COUNT(dni.id) AS item_count FROM delivery_notes dn INNER JOIN customers c ON c.id = dn.customer_id LEFT JOIN delivery_note_items dni ON dni.delivery_note_id = dn.id GROUP BY dn.id ORDER BY dn.delivery_date DESC, dn.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$viewId = (int) ($_GET['view'] ?? 0);
$viewDelivery = null;
$viewItems = [];
if ($viewId > 0) {
    $stmt = $pdo->prepare('SELECT dn.*, c.customer_code, c.customer_name, c.address, c.phone, c.contact_person FROM delivery_notes dn INNER JOIN customers c ON c.id = dn.customer_id WHERE dn.id = ? LIMIT 1');
    $stmt->execute([$viewId]);
    $viewDelivery = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($viewDelivery) {
        $itemStmt = $pdo->prepare('SELECT dni.*, pc.product_code FROM delivery_note_items dni INNER JOIN product_codes pc ON pc.id = dni.product_code_id WHERE dni.delivery_note_id = ? ORDER BY dni.id ASC');
        $itemStmt->execute([$viewId]);
        $viewItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<style>
@media print {
    nav, .sidebar, .btn, .no-print, .card-header .btn-group, .card-header .btn, .card-footer, .print-hidden { display: none !important; }
    .container-fluid { margin: 0 !important; padding: 0 !important; }
    .print-card { border: none !important; box-shadow: none !important; }
    body { background: #fff; }
    @page { size: A4; margin: 12mm; }
}
</style>
<div class="container-fluid py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 no-print">
        <div>
            <h1 class="h3 mb-1">Phiếu giao hàng</h1>
            <p class="text-muted mb-0">Lập phiếu giao hàng, xác nhận giao và in phiếu A4.</p>
        </div>
        <a href="/ntn_erp/modules/production/delivery.php" class="btn btn-outline-secondary">Làm mới</a>
    </div>

    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show no-print" role="alert"><?= e($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger no-print"><ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <?php if ($viewDelivery): ?>
        <div class="card shadow-sm border-0 mb-4 print-card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center no-print">
                <h2 class="h5 mb-0">Xem / In phiếu <?= e($viewDelivery['delivery_no']) ?></h2>
                <div class="btn-group btn-group-sm">
                    <?php if ($viewDelivery['status'] === 'draft'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="action" value="confirm_delivery">
                            <input type="hidden" name="delivery_id" value="<?= (int) $viewDelivery['id'] ?>">
                            <button type="submit" class="btn btn-success">Xác nhận giao</button>
                        </form>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-primary" onclick="window.print()">In phiếu</button>
                </div>
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <h5 class="mb-1 fw-bold">CÔNG TY CỔ PHẦN SẢN XUẤT VÀ CUNG ỨNG NTN VIỆT NAM</h5>
                    <div>Số 36, Xóm Trại, Quan Âm, Xã Phúc Thịnh, Thành phố Hà Nội</div>
                    <div>MST: 0111343796 | Hotline: 0966240297 - 0562033333</div>
                    <h4 class="mt-3 mb-1">PHIẾU GIAO HÀNG</h4>
                    <div>Số phiếu: <strong><?= e($viewDelivery['delivery_no']) ?></strong></div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6"><strong>Khách hàng:</strong> <?= e($viewDelivery['customer_code'] . ' - ' . $viewDelivery['customer_name']) ?></div>
                    <div class="col-md-6"><strong>Ngày giao:</strong> <?= e(date('d/m/Y', strtotime($viewDelivery['delivery_date']))) ?></div>
                    <div class="col-md-6"><strong>Người gửi:</strong> <?= e($viewDelivery['sender_name']) ?> - <?= e($viewDelivery['sender_phone']) ?></div>
                    <div class="col-md-6"><strong>Người nhận:</strong> <?= e($viewDelivery['receiver_name']) ?> - <?= e($viewDelivery['receiver_phone']) ?></div>
                    <div class="col-md-6"><strong>Tài xế:</strong> <?= e($viewDelivery['driver_name']) ?> - <?= e($viewDelivery['driver_phone']) ?></div>
                    <div class="col-md-6"><strong>Biển số xe:</strong> <?= e($viewDelivery['vehicle_plate']) ?></div>
                    <div class="col-12"><strong>Địa chỉ:</strong> <?= e($viewDelivery['address']) ?></div>
                    <div class="col-12"><strong>Ghi chú:</strong> <?= nl2br(e($viewDelivery['note'])) ?></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>STT</th>
                                <th>Mã SP</th>
                                <th>Mô tả</th>
                                <th>ĐVT</th>
                                <th class="text-end">Số lượng</th>
                                <th class="text-end">Đơn giá</th>
                                <th class="text-end">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($viewItems as $index => $item): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= e($item['product_code']) ?></td>
                                    <td><?= e($item['description']) ?></td>
                                    <td><?= e($item['unit']) ?></td>
                                    <td class="text-end"><?= e(formatQty($item['quantity'])) ?></td>
                                    <td class="text-end"><?= e(formatCurrency($item['unit_price'])) ?></td>
                                    <td class="text-end"><?= e(formatCurrency($item['total_price'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="6" class="text-end">Tổng cộng</th>
                                <th class="text-end"><?= e(formatCurrency($viewDelivery['total_amount'])) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4 no-print">
        <div class="col-xl-8">
            <div class="card shadow-sm border-0">
                <div class="card-body table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Mã phiếu</th>
                                <th>Ngày giao</th>
                                <th>Khách hàng</th>
                                <th>Tổng tiền</th>
                                <th>Trạng thái</th>
                                <th>Số dòng</th>
                                <th class="text-end">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$deliveryNotes): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Chưa có phiếu giao hàng.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($deliveryNotes as $note): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($note['delivery_no']) ?></td>
                                    <td><?= e(date('d/m/Y', strtotime($note['delivery_date']))) ?></td>
                                    <td><?= e($note['customer_code'] . ' - ' . $note['customer_name']) ?></td>
                                    <td><?= e(formatCurrency($note['total_amount'])) ?></td>
                                    <td><span class="badge text-bg-<?= docStatusBadge($note['status']) ?>"><?= e(docStatusLabel($note['status'])) ?></span></td>
                                    <td><?= (int) $note['item_count'] ?></td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <a href="/ntn_erp/modules/production/delivery.php?view=<?= (int) $note['id'] ?>" class="btn btn-outline-primary">Xem</a>
                                            <?php if ($note['status'] === 'draft'): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                    <input type="hidden" name="action" value="confirm_delivery">
                                                    <input type="hidden" name="delivery_id" value="<?= (int) $note['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-success">Xác nhận</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white"><h2 class="h5 mb-0">Tạo phiếu giao</h2></div>
                <div class="card-body">
                    <form method="post" id="deliveryForm" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="create_delivery">
                        <div class="col-12"><label class="form-label">Ngày giao <span class="text-danger">*</span></label><input type="date" name="delivery_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required></div>
                        <div class="col-12"><label class="form-label">Khách hàng <span class="text-danger">*</span></label><select name="customer_id" class="form-select" required><option value="">Chọn khách hàng</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id'] ?>"><?= e($customer['customer_code'] . ' - ' . $customer['customer_name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label">Người gửi</label><input type="text" name="sender_name" class="form-control" value="<?= e(currentUser()['full_name'] ?? '') ?>"></div>
                        <div class="col-md-6"><label class="form-label">SĐT người gửi</label><input type="text" name="sender_phone" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Biển số xe</label><input type="text" name="vehicle_plate" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Tài xế</label><input type="text" name="driver_name" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">SĐT tài xế</label><input type="text" name="driver_phone" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Người nhận</label><input type="text" name="receiver_name" class="form-control"></div>
                        <div class="col-12"><label class="form-label">SĐT người nhận</label><input type="text" name="receiver_phone" class="form-control"></div>
                        <div class="col-12"><label class="form-label">Ghi chú</label><textarea name="note" class="form-control" rows="2"></textarea></div>
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0">Chi tiết hàng giao</label>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="addDeliveryRow">Thêm dòng</button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light"><tr><th>Phiếu OUT</th><th>SL</th><th>Đơn giá</th><th></th></tr></thead>
                                    <tbody id="deliveryItemsBody"></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-12 text-end">
                            <div class="text-muted small">Tổng tiền</div>
                            <div class="h5 mb-0" id="deliveryTotal">0 đ</div>
                        </div>
                        <div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-primary flex-fill">Lưu phiếu giao</button><a href="/ntn_erp/modules/production/delivery.php" class="btn btn-outline-secondary">Hủy</a></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
const outputMap = <?= json_encode($outputMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const deliveryItemsBody = document.getElementById('deliveryItemsBody');

function formatMoney(value) {
    return new Intl.NumberFormat('vi-VN').format(Number(value || 0)) + ' đ';
}
function outputOptions(selected = '') {
    return '<option value="">Chọn phiếu OUT</option>' + Object.values(outputMap).map(item => `<option value="${item.id}" ${String(item.id) === String(selected) ? 'selected' : ''}>${item.output_no} - ${item.product_code} - Còn ${Number(item.qty_available).toLocaleString('vi-VN')}</option>`).join('');
}
function recalcTotal() {
    let total = 0;
    document.querySelectorAll('#deliveryItemsBody tr').forEach(row => {
        const qty = Number(row.querySelector('.delivery-qty')?.value || 0);
        const unitPrice = Number(row.querySelector('.delivery-price')?.value || 0);
        const lineTotal = qty * unitPrice;
        total += lineTotal;
        row.querySelector('.delivery-line-total').textContent = formatMoney(lineTotal);
    });
    document.getElementById('deliveryTotal').textContent = formatMoney(total);
}
function syncRow(row) {
    const select = row.querySelector('.delivery-output');
    const item = outputMap[select.value] || null;
    row.querySelector('.delivery-desc').value = item ? (item.description || item.product_name) : '';
    row.querySelector('.delivery-unit').value = item ? (item.unit || '') : '';
    row.querySelector('.delivery-qty').max = item ? item.qty_available : '';
    if (item && !Number(row.querySelector('.delivery-price').value)) {
        row.querySelector('.delivery-price').value = item.latest_price || 0;
    }
    row.querySelector('.delivery-meta').textContent = item ? `${item.product_code} - ${item.product_name} | Còn ${Number(item.qty_available).toLocaleString('vi-VN')}` : '';
    recalcTotal();
}
function addRow() {
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <select name="production_output_id[]" class="form-select form-select-sm delivery-output" required>${outputOptions()}</select>
            <div class="small text-muted mt-1 delivery-meta"></div>
            <input type="hidden" name="description[]" class="delivery-desc">
            <input type="hidden" name="unit[]" class="delivery-unit">
        </td>
        <td><input type="number" min="0" step="0.01" name="quantity[]" class="form-control form-control-sm delivery-qty" value="0" required></td>
        <td>
            <input type="number" min="0" step="0.01" name="unit_price[]" class="form-control form-control-sm delivery-price" value="0">
            <div class="small text-end mt-1 delivery-line-total">0 đ</div>
        </td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-row">×</button></td>`;
    deliveryItemsBody.appendChild(row);
    row.querySelector('.delivery-output').addEventListener('change', () => syncRow(row));
    row.querySelector('.delivery-qty').addEventListener('input', recalcTotal);
    row.querySelector('.delivery-price').addEventListener('input', recalcTotal);
    row.querySelector('.remove-row').addEventListener('click', () => { row.remove(); recalcTotal(); });
}
document.getElementById('addDeliveryRow').addEventListener('click', addRow);
addRow();
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
