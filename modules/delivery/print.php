<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/module_helpers.php';

requireLogin();
$pdo = erp_db();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(404);
    exit('Không tìm thấy phiếu giao hàng.');
}

$stmt = $pdo->prepare("
    SELECT d.*,
           c.name AS customer_name, c.code AS customer_code,
           c.address AS customer_address, c.phone AS customer_phone,
           c.tax_code AS customer_tax_code,
           jo.job_code
    FROM deliveries d
    INNER JOIN customers c ON c.id = d.customer_id
    INNER JOIN job_orders jo ON jo.id = d.job_order_id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$delivery = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$delivery) {
    http_response_code(404);
    exit('Không tìm thấy phiếu giao hàng.');
}

$itemsStmt = $pdo->prepare("
    SELECT di.qty_delivered, di.unit_price, di.amount,
           pc.code AS product_code, pc.name AS product_name, pc.unit
    FROM delivery_items di
    INNER JOIN product_codes pc ON pc.id = di.product_code_id
    WHERE di.delivery_id = ?
    ORDER BY di.id ASC
");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalQty    = array_sum(array_column($items, 'qty_delivered'));
$totalAmount = array_sum(array_column($items, 'amount'));

$autoPrint = !isset($_GET['auto_print']) || $_GET['auto_print'] !== '0';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phiếu giao hàng – <?= htmlspecialchars($delivery['delivery_code'], ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 13px;
            color: #111;
            background: #fff;
            padding: 14mm 18mm 12mm;
        }

        /* ── HEADER ── */
        .header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            border-bottom: 3px solid #1a6b3a;
            padding-bottom: 10px;
            margin-bottom: 8px;
        }
        .company-name {
            font-size: 15px;
            font-weight: bold;
            text-transform: uppercase;
            color: #1a6b3a;
            line-height: 1.45;
            margin-bottom: 3px;
        }
        .company-sub { font-size: 11.5px; color: #333; line-height: 1.65; }
        .company-sub b { color: #111; }
        .header-right { text-align: right; flex-shrink: 0; font-size: 11px; color: #555; line-height: 1.65; }

        /* ── TIÊU ĐỀ ── */
        .doc-title-wrap { text-align: center; margin: 14px 0 6px; }
        .doc-title { font-size: 20px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; }
        .doc-sub { font-size: 12px; color: #444; margin-top: 2px; }
        .doc-sub b { color: #c62828; }

        /* ── THÔNG TIN ── */
        .info-section { margin: 12px 0 0; }
        .info-line { display: flex; align-items: baseline; margin-bottom: 6px; }
        .info-line.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
        .info-field { display: flex; align-items: baseline; flex: 1; }
        .info-lbl { white-space: nowrap; font-weight: bold; margin-right: 4px; flex-shrink: 0; }
        .info-val { flex: 1; border-bottom: 1px dotted #666; padding: 0 4px 1px; min-width: 40px; }

        /* ── BẢNG ── */
        .table-wrap { margin-top: 14px; }
        table.items { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        table.items thead tr { background: #1a6b3a; color: #fff; }
        table.items th { padding: 6px 8px; text-align: center; border: 1px solid #1a6b3a; }
        table.items td { padding: 6px 8px; border: 1px solid #aaa; vertical-align: middle; }
        table.items tbody tr:nth-child(even) td { background: #f4faf6; }
        table.items tfoot td { border: 1px solid #888; padding: 6px 8px; font-weight: bold; background: #e8f5e9; }
        .tc { text-align: center; }
        .tr { text-align: right; }

        /* ── TỔNG TIỀN ── */
        .total-box {
            margin-top: 6px; padding: 5px 10px;
            background: #f3faf5; border-left: 4px solid #1a6b3a;
            font-size: 12.5px; font-style: italic; color: #222;
        }
        .total-box b { color: #c62828; font-style: normal; }

        /* ── KÝ TÊN ── */
        .sign-date { text-align: right; font-size: 12px; font-style: italic; margin-top: 18px; color: #333; }
        .sign-area { display: flex; justify-content: space-between; gap: 8px; margin-top: 6px; }
        .sign-box { flex: 1; text-align: center; }
        .sign-box .sign-title { font-weight: bold; font-size: 12.5px; border-bottom: 1.5px solid #1a6b3a; padding-bottom: 3px; color: #1a6b3a; }
        .sign-box .sign-sub { font-size: 10.5px; color: #888; font-style: italic; }
        .sign-box .sign-space { height: 55px; }
        .sign-box .sign-name { font-size: 12px; font-weight: bold; border-top: 1px solid #ccc; padding-top: 2px; min-height: 18px; }

        /* ── FOOTER ── */
        .doc-footer { margin-top: 18px; padding-top: 6px; border-top: 1px solid #ccc; font-size: 10px; color: #aaa; text-align: center; }

        /* ── PRINT BUTTON (ẩn khi in) ── */
        .no-print { margin-bottom: 16px; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 8mm 12mm 8mm; }
            table.items tbody tr:nth-child(even) td { background: #f4faf6 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table.items thead tr { background: #1a6b3a !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body<?php if ($autoPrint): ?> onload="window.print()"<?php endif; ?>>

    <!-- Nút in (ẩn khi in thực) -->
    <div class="no-print">
        <button onclick="window.print()" style="padding:6px 18px;background:#1a6b3a;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:14px;">
            🖨️ In phiếu
        </button>
        <a href="<?= htmlspecialchars(erp_url('modules/delivery/index.php'), ENT_QUOTES, 'UTF-8') ?>"
           style="margin-left:12px;font-size:13px;color:#555;text-decoration:none;">← Quay lại</a>
    </div>

    <!-- ══ HEADER ══ -->
    <div class="header">
        <div>
            <div class="company-name">Công ty Cổ phần Sản xuất và Cung ứng NTN Việt Nam</div>
            <div class="company-sub">
                <b>MST:</b> 0111343796 &nbsp;|&nbsp;
                <b>Địa chỉ:</b> Số 36, Xóm Trại, Quan Âm, Xã Phúc Thịnh, TP. Hà Nội<br>
                <b>Đại diện:</b> Mr. Nam &nbsp;|&nbsp;
                <b>Tel:</b> 0966.459.663 &nbsp;–&nbsp; 0966.240.297
            </div>
        </div>
        <div class="header-right">
            Số: <b style="color:#c62828;font-size:13px"><?= htmlspecialchars($delivery['delivery_code'], ENT_QUOTES, 'UTF-8') ?></b><br>
            Phiếu GC: <b><?= htmlspecialchars($delivery['job_code'], ENT_QUOTES, 'UTF-8') ?></b><br>
            Ngày: <b><?= date('d/m/Y', strtotime($delivery['delivery_date'])) ?></b>
        </div>
    </div>

    <!-- ══ TIÊU ĐỀ ══ -->
    <div class="doc-title-wrap">
        <div class="doc-title">Phiếu giao hàng</div>
        <div class="doc-sub">Số: <b><?= htmlspecialchars($delivery['delivery_code'], ENT_QUOTES, 'UTF-8') ?></b></div>
    </div>

    <!-- ══ THÔNG TIN ══ -->
    <div class="info-section">
        <div class="info-line">
            <div class="info-field">
                <span class="info-lbl">Khách hàng:</span>
                <span class="info-val"><?= htmlspecialchars($delivery['customer_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <div class="info-line">
            <div class="info-field">
                <span class="info-lbl">Địa chỉ:</span>
                <span class="info-val"><?= htmlspecialchars($delivery['customer_address'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <div class="info-line two-col">
            <div class="info-field">
                <span class="info-lbl">Người nhận:</span>
                <span class="info-val"><?= htmlspecialchars($delivery['recipient_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="info-field">
                <span class="info-lbl">Điện thoại:</span>
                <span class="info-val"><?= htmlspecialchars($delivery['customer_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <div class="info-line two-col">
            <div class="info-field">
                <span class="info-lbl">Người giao:</span>
                <span class="info-val"><?= htmlspecialchars($delivery['driver'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="info-field">
                <span class="info-lbl">Mã số thuế KH:</span>
                <span class="info-val"><?= htmlspecialchars($delivery['customer_tax_code'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <?php if (!empty($delivery['note'])): ?>
        <div class="info-line">
            <div class="info-field">
                <span class="info-lbl">Ghi chú:</span>
                <span class="info-val"><?= htmlspecialchars($delivery['note'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ BẢNG HÀNG HOÁ ══ -->
    <div class="table-wrap">
        <table class="items">
            <thead>
                <tr>
                    <th width="32">STT</th>
                    <th width="85">Mã SP</th>
                    <th>Tên hàng hoá</th>
                    <th width="50">ĐVT</th>
                    <th width="80">Số lượng</th>
                    <th width="110">Đơn giá (đ)</th>
                    <th width="120">Thành tiền (đ)</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $minRows = max(8, count($items));
            for ($i = 0; $i < $minRows; $i++):
                $it = $items[$i] ?? null;
            ?>
            <tr>
                <td class="tc"><?= $it ? $i + 1 : '' ?></td>
                <td class="tc"><?= $it ? htmlspecialchars($it['product_code'], ENT_QUOTES, 'UTF-8') : '' ?></td>
                <td><?= $it ? htmlspecialchars($it['product_name'], ENT_QUOTES, 'UTF-8') : '' ?></td>
                <td class="tc"><?= $it ? htmlspecialchars($it['unit'], ENT_QUOTES, 'UTF-8') : '' ?></td>
                <td class="tr"><?= $it ? number_format((float) $it['qty_delivered']) : '' ?></td>
                <td class="tr"><?= $it && (float) $it['unit_price'] > 0 ? number_format((float) $it['unit_price']) : '' ?></td>
                <td class="tr"><?= $it && (float) $it['amount'] > 0 ? number_format((float) $it['amount']) : '' ?></td>
            </tr>
            <?php endfor; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="tr" style="color:#1a6b3a;">Tổng cộng:</td>
                    <td class="tr"><?= number_format($totalQty) ?></td>
                    <td></td>
                    <td class="tr" style="color:#c62828;"><?= $totalAmount > 0 ? number_format($totalAmount) . ' đ' : '' ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php if ($totalAmount > 0): ?>
    <div class="total-box">
        Tổng tiền thanh toán: <b><?= number_format($totalAmount) ?> đồng</b>
    </div>
    <?php endif; ?>

    <!-- ══ KÝ TÊN ══ -->
    <div class="sign-date">
        Ngày&nbsp;......&nbsp;tháng&nbsp;......&nbsp;năm&nbsp;..........
    </div>
    <div class="sign-area">
        <div class="sign-box">
            <div class="sign-title">Người lập phiếu</div>
            <div class="sign-sub">(Ký, ghi rõ họ tên)</div>
            <div class="sign-space"></div>
            <div class="sign-name">&nbsp;</div>
        </div>
        <div class="sign-box">
            <div class="sign-title">Người nhận hàng</div>
            <div class="sign-sub">(Ký, ghi rõ họ tên)</div>
            <div class="sign-space"></div>
            <div class="sign-name"><?= htmlspecialchars($delivery['recipient_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="sign-box">
            <div class="sign-title">Thủ kho</div>
            <div class="sign-sub">(Ký, ghi rõ họ tên)</div>
            <div class="sign-space"></div>
            <div class="sign-name">&nbsp;</div>
        </div>
        <div class="sign-box">
            <div class="sign-title">Người giao hàng</div>
            <div class="sign-sub">(Ký, ghi rõ họ tên)</div>
            <div class="sign-space"></div>
            <div class="sign-name"><?= htmlspecialchars($delivery['driver'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>

    <!-- ══ FOOTER ══ -->
    <div class="doc-footer">
        Công ty Cổ phần SX &amp; Cung ứng NTN Việt Nam &nbsp;·&nbsp; MST: 0111343796
        &nbsp;·&nbsp; In ngày: <?= date('d/m/Y H:i') ?>
    </div>

</body>
</html>
