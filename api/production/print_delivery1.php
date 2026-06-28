<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
requireLogin();

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) die('Không tìm thấy biên bản');

// ✅ Dùng đúng tên bảng delivery_notes
$delivery = $pdo->prepare("
    SELECT dn.*,
           c.customer_name, c.customer_code, c.address, c.phone
    FROM delivery_notes dn
    LEFT JOIN customers c ON dn.customer_id = c.id
    WHERE dn.id = ?
");
$delivery->execute([$id]);
$delivery = $delivery->fetch(PDO::FETCH_ASSOC);
if (!$delivery) die('Không tìm thấy biên bản');

// ✅ Dùng đúng tên bảng delivery_note_items + join production_outputs để lấy unit
$items = $pdo->prepare("
    SELECT dni.*,
           pc.product_code,
           pc.unit
    FROM delivery_note_items dni
    JOIN product_codes pc ON dni.product_code_id = pc.id
    WHERE dni.delivery_note_id = ?
    ORDER BY dni.id
");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

$totalQty    = array_sum(array_column($items, 'quantity'));
$totalAmount = array_sum(array_column($items, 'total_price'));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Biên bản giao hàng - <?= htmlspecialchars($delivery['delivery_no']) ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Times New Roman',serif; font-size:13px; padding:20mm; }
        h2 { text-align:center; font-size:18px; text-transform:uppercase; margin-bottom:4px; }
        .subtitle { text-align:center; font-size:13px; margin-bottom:16px; }
        .info-table { width:100%; margin-bottom:16px; }
        .info-table td { padding:3px 6px; vertical-align:top; }
        .info-table .label { font-weight:bold; width:140px; white-space:nowrap; }
        table.items { width:100%; border-collapse:collapse; margin-bottom:16px; }
        table.items th, table.items td { border:1px solid #333; padding:5px 8px; }
        table.items th { background:#f0f0f0; text-align:center; font-weight:bold; }
        .tr { text-align:right; }
        .tc { text-align:center; }
        .total-row td { font-weight:bold; background:#f9f9f9; }
        .sign-area { display:flex; justify-content:space-between; margin-top:30px; }
        .sign-box { text-align:center; width:30%; }
        .sign-box .title { font-weight:bold; margin-bottom:50px; }
        @media print { body { padding:10mm; } }
    </style>
</head>
<body onload="window.print()">
    <h2>Biên bản giao hàng</h2>
    <div class="subtitle">
        Số: <strong><?= htmlspecialchars($delivery['delivery_no']) ?></strong>
        &nbsp;|&nbsp;
        Ngày: <strong><?= date('d/m/Y', strtotime($delivery['delivery_date'])) ?></strong>
    </div>

    <table class="info-table">
        <tr>
            <td class="label">Khách hàng:</td>
            <td><strong><?= htmlspecialchars($delivery['customer_name'] ?? '—') ?></strong></td>
            <td class="label">Mã KH:</td>
            <td><?= htmlspecialchars($delivery['customer_code'] ?? '—') ?></td>
        </tr>
        <tr>
            <td class="label">Địa chỉ:</td>
            <td colspan="3"><?= htmlspecialchars($delivery['address'] ?? '—') ?></td>
        </tr>
        <tr>
            <td class="label">Điện thoại:</td>
            <td><?= htmlspecialchars($delivery['phone'] ?? '—') ?></td>
            <td class="label">Người giao:</td>
            <td><?= htmlspecialchars($delivery['sender_name'] ?? '—') ?></td>
        </tr>
        <tr>
            <td class="label">Biển số xe:</td>
            <td><?= htmlspecialchars($delivery['vehicle_plate'] ?? '—') ?></td>
            <td class="label">Tài xế:</td>
            <td><?= htmlspecialchars($delivery['driver_name'] ?? '—') ?></td>
        </tr>
        <?php if ($delivery['note']): ?>
        <tr>
            <td class="label">Ghi chú:</td>
            <td colspan="3"><?= htmlspecialchars($delivery['note']) ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th width="30">STT</th>
                <th width="100">Mã SP</th>
                <th>Mô tả / Tên hàng hoá</th>
                <th width="60">ĐVT</th>
                <th width="80">Số lượng</th>
                <th width="110">Đơn giá</th>
                <th width="120">Thành tiền</th>
            </tr>
        </thead>
        <tbody>
        <?php $i=1; foreach ($items as $it): ?>
        <tr>
            <td class="tc"><?= $i++ ?></td>
            <td class="tc"><?= htmlspecialchars($it['product_code']) ?></td>
            <td><?= htmlspecialchars($it['description']) ?></td>
            <td class="tc"><?= htmlspecialchars($it['unit']) ?></td>
            <td class="tr"><?= number_format($it['quantity']) ?></td>
            <td class="tr"><?= number_format($it['unit_price'] ?? 0) ?></td>
            <td class="tr"><?= number_format($it['total_price'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="4" class="tr">Cộng:</td>
                <td class="tr"><?= number_format($totalQty) ?></td>
                <td></td>
                <td class="tr"><?= number_format($totalAmount) ?> đ</td>
            </tr>
        </tfoot>
    </table>

    <div class="sign-area">
        <div class="sign-box">
            <div class="title">Người giao hàng</div>
            <div>(Ký, ghi rõ họ tên)</div>
        </div>
        <div class="sign-box">
            <div class="title">Người nhận hàng</div>
            <div>(Ký, ghi rõ họ tên)</div>
        </div>
        <div class="sign-box">
            <div class="title">Đại diện công ty</div>
            <div>(Ký, đóng dấu)</div>
        </div>
    </div>
</body>
</html>