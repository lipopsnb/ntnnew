<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
requireLogin();

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) die('Không tìm thấy biên bản');

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
            align-items: center;
            justify-content: space-between;
            border-bottom: 3px solid #1a6b3a;
            padding-bottom: 10px;
            margin-bottom: 6px;
        }
        .header-left {
            flex: 1;
        }
        .company-name {
            font-size: 15.5px;
            font-weight: bold;
            text-transform: uppercase;
            color: #1a6b3a;
            line-height: 1.45;
            margin-bottom: 3px;
        }
        .company-sub {
            font-size: 11.5px;
            color: #333;
            line-height: 1.65;
        }
        .company-sub b { color: #111; }

        .header-right {
            text-align: right;
            flex-shrink: 0;
            font-size: 11px;
            color: #555;
            line-height: 1.6;
        }

        /* ── TIÊU ĐỀ ── */
        .doc-title-wrap {
            text-align: center;
            margin: 14px 0 6px;
        }
        .doc-title {
            font-size: 20px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #111;
        }
        .doc-no-date {
            font-size: 12px;
            color: #444;
            margin-top: 3px;
        }
        .doc-no-date b { color: #c62828; }

        /* ── THÔNG TIN DẠNG DÒNG KẺ ── */
        .info-section {
            margin: 12px 0 0;
        }
        .info-line {
            display: flex;
            align-items: baseline;
            margin-bottom: 7px;
            font-size: 13px;
            gap: 0;
        }
        .info-line.two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 16px;
        }
        .info-field {
            display: flex;
            align-items: baseline;
            flex: 1;
        }
        .info-lbl {
            white-space: nowrap;
            font-weight: bold;
            margin-right: 4px;
            flex-shrink: 0;
        }
        .info-dots {
            flex: 1;
            border-bottom: 1px dotted #555;
            min-width: 40px;
            margin-bottom: 2px;
            /* value shown inline */
            padding: 0 4px;
            font-size: 13px;
            color: #111;
        }

        /* ── BẢNG ── */
        .table-wrap { margin-top: 14px; }

        table.items {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }
        table.items thead tr {
            background: #1a6b3a;
            color: #fff;
        }
        table.items th {
            padding: 6px 8px;
            text-align: center;
            font-weight: bold;
            border: 1px solid #1a6b3a;
        }
        table.items td {
            padding: 6px 8px;
            border: 1px solid #aaa;
            vertical-align: middle;
        }
        table.items tbody tr:nth-child(even) td { background: #f3faf5; }

        .tc { text-align: center; }
        .tr { text-align: right; }

        table.items tfoot td {
            border: 1px solid #888;
            padding: 6px 8px;
            font-weight: bold;
            background: #e8f5e9;
        }

        /* ── TỔNG TIỀN ── */
        .total-box {
            margin-top: 6px;
            padding: 5px 10px;
            background: #f3faf5;
            border-left: 4px solid #1a6b3a;
            font-size: 12.5px;
            font-style: italic;
            color: #222;
        }
        .total-box b { color: #c62828; font-style: normal; }

        /* ── GHI CHÚ KHÁCH ── */
        .customer-note {
            margin-top: 10px;
            padding: 6px 10px;
            border: 1px dashed #999;
            border-radius: 3px;
            font-size: 11.5px;
            color: #444;
        }
        .customer-note b { color: #111; }

        /* ── LỜI CẢM ƠN ── */
        .thank-note {
            margin-top: 10px;
            padding: 7px 12px;
            background: #fffde7;
            border: 1px solid #f9a825;
            border-radius: 3px;
            font-size: 12px;
            color: #333;
            font-style: italic;
            line-height: 1.65;
        }

        /* ── NGÀY KÝ ── */
        .sign-date {
            text-align: right;
            font-size: 12.5px;
            font-style: italic;
            margin-top: 18px;
            margin-bottom: 4px;
            color: #333;
        }

        /* ── KÝ TÊN 4 CỘT ── */
        .sign-area {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-top: 4px;
        }
        .sign-box {
            flex: 1;
            text-align: center;
        }
        .sign-box .sign-title {
            font-weight: bold;
            font-size: 12.5px;
            border-bottom: 1.5px solid #1a6b3a;
            padding-bottom: 3px;
            margin-bottom: 3px;
            color: #1a6b3a;
        }
        .sign-box .sign-sub {
            font-size: 10.5px;
            color: #888;
            font-style: italic;
        }
        .sign-box .sign-space { height: 55px; }
        .sign-box .sign-name {
            font-size: 12px;
            font-weight: bold;
            color: #111;
            border-top: 1px solid #ccc;
            padding-top: 2px;
            min-height: 18px;
        }

        /* ── FOOTER ── */
        .doc-footer {
            margin-top: 18px;
            padding-top: 6px;
            border-top: 1px solid #ccc;
            font-size: 10px;
            color: #aaa;
            text-align: center;
        }

        @media print {
            body { padding: 8mm 12mm 8mm; }
            table.items tbody tr:nth-child(even) td { background: #f3faf5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table.items thead tr { background: #1a6b3a !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .thank-note { background: #fffde7 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body onload="window.print()">

    <!-- ══ HEADER ══════════════════════════════════════════════════════ -->
    <div class="header">
        <div class="header-left">
            <div class="company-name">
                Công ty Cổ phần Sản xuất và Cung ứng NTN Việt Nam
            </div>
            <div class="company-sub">
                <b>MST:</b> 0111343796 &nbsp;|&nbsp;
                <b>Địa chỉ:</b> Số 36, Xóm Trại, Quan Âm, Xã Phúc Thịnh, TP. Hà Nội<br>
                <b>Đại diện:</b> Mr. Nam &nbsp;|&nbsp;
                <b>Tel:</b> 0966.459.663 &nbsp;–&nbsp; 0966.240.297
            </div>
        </div>
        <div class="header-right">
            Số: <b style="color:#c62828;font-size:13px"><?= htmlspecialchars($delivery['delivery_no']) ?></b><br>
            Ngày: <b><?= date('d/m/Y', strtotime($delivery['delivery_date'])) ?></b>
        </div>
    </div>

    <!-- ══ TIÊU ĐỀ ══════════════════════════════════════════════════════ -->
    <div class="doc-title-wrap">
        <div class="doc-title">Biên bản giao hàng</div>
    </div>

    <!-- ══ THÔNG TIN GIAO HÀNG ══════════════════════════════════════════ -->
    <div class="info-section">

        <div class="info-line">
            <div class="info-field">
                <span class="info-lbl">Tên khách hàng:</span>
                <span class="info-dots"><?= htmlspecialchars($delivery['customer_name'] ?? '') ?></span>
            </div>
        </div>

        <div class="info-line">
            <div class="info-field">
                <span class="info-lbl">Địa chỉ:</span>
                <span class="info-dots"><?= htmlspecialchars($delivery['address'] ?? '') ?></span>
            </div>
        </div>

        <div class="info-line two-col">
            <div class="info-field">
                <span class="info-lbl">Người nhận hàng:</span>
                <span class="info-dots"><?= htmlspecialchars($delivery['sender_name'] ?? '') ?></span>
            </div>
            <div class="info-field">
                <span class="info-lbl">Điện thoại:</span>
                <span class="info-dots"><?= htmlspecialchars($delivery['phone'] ?? '') ?></span>
            </div>
        </div>

        <div class="info-line two-col">
            <div class="info-field">
                <span class="info-lbl">Phương tiện:</span>
                <span class="info-dots"><?= htmlspecialchars($delivery['vehicle_plate'] ?? '') ?></span>
            </div>
            <div class="info-field">
                <span class="info-lbl">Tài xế:</span>
                <span class="info-dots"><?= htmlspecialchars($delivery['driver_name'] ?? '') ?></span>
            </div>
        </div>

        <?php if (!empty($delivery['note'])): ?>
        <div class="info-line">
            <div class="info-field">
                <span class="info-lbl">Ghi chú:</span>
                <span class="info-dots"><?= htmlspecialchars($delivery['note']) ?></span>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- ══ BẢNG HÀNG HOÁ ════════════════════════════════════════════════ -->
    <div class="table-wrap">
        <table class="items">
            <thead>
                <tr>
                    <th width="32">STT</th>
                    <th width="85">Mã SP</th>
                    <th>Mô tả / Tên hàng hoá</th>
                    <th width="50">ĐVT</th>
                    <th width="80">Số lượng</th>
                    <th width="105">Đơn giá (đ)</th>
                    <th width="115">Thành tiền (đ)</th>
                </tr>
            </thead>
            <tbody>
            <?php
            // Đảm bảo ít nhất 8 dòng để trống như mẫu
            $minRows = max(8, count($items));
            for ($i = 0; $i < $minRows; $i++):
                $it = $items[$i] ?? null;
            ?>
            <tr>
                <td class="tc"><?= $it ? $i + 1 : '' ?></td>
                <td class="tc"><?= $it ? htmlspecialchars($it['product_code']) : '' ?></td>
                <td><?= $it ? htmlspecialchars($it['description']) : '' ?></td>
                <td class="tc"><?= $it ? htmlspecialchars($it['unit']) : '' ?></td>
                <td class="tr"><?= $it ? number_format($it['quantity']) : '' ?></td>
                <td class="tr"><?= $it ? number_format($it['unit_price'] ?? 0) : '' ?></td>
                <td class="tr"><?= $it ? number_format($it['total_price'] ?? 0) : '' ?></td>
            </tr>
            <?php endfor; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="tr" style="color:#1a6b3a;">Tổng cộng:</td>
                    <td class="tr"><?= number_format($totalQty) ?></td>
                    <td></td>
                    <td class="tr" style="color:#c62828;"><?= number_format($totalAmount) ?> đ</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php if ($totalAmount > 0): ?>
    <div class="total-box">
        Tổng tiền thanh toán: <b><?= number_format($totalAmount) ?> đồng</b>
    </div>
    <?php endif; ?>

    <!-- ══ LỜI CẢM ƠN / CAM KẾT ════════════════════════════════════════ -->
    <div class="thank-note">
        Bằng việc ký vào biên bản giao nhận này, bạn đã xác nhận việc nhận đủ số lượng hàng hoá
        bên trên. Nếu trong quá trình giao nhận chúng tôi làm chưa tốt, bạn vui lòng ghi lại
        vào phiếu để chúng tôi cải thiện chất lượng và dịch vụ.
        <b>Xin chân thành cảm ơn!</b>
    </div>

    <!-- ══ NGÀY & KÝ TÊN ════════════════════════════════════════════════ -->
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
            <div class="sign-name">&nbsp;</div>
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
            <div class="sign-name"><?= htmlspecialchars($delivery['driver_name'] ?? '') ?></div>
        </div>
    </div>

    <!-- ══ FOOTER ═══════════════════════════════════════════════════════ -->
    <div class="doc-footer">
        Công ty Cổ phần SX &amp; Cung ứng NTN Việt Nam &nbsp;·&nbsp; MST: 0111343796
        &nbsp;·&nbsp; In ngày: <?= date('d/m/Y H:i') ?>
    </div>

</body>
</html>