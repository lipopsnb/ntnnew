<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

$pdo = getDBConnection();
$user = currentUser();
$slipStmt = $pdo->prepare(
    "SELECT ps.*, pp.period_month, pp.period_year, pp.period_from, pp.period_to
     FROM payroll_slips ps
     INNER JOIN payroll_periods pp ON pp.id = ps.period_id
     WHERE ps.user_id = ?
     ORDER BY pp.period_year DESC, pp.period_month DESC"
);
$slipStmt->execute([(int) $user['id']]);
$slips = $slipStmt->fetchAll();
$detailId = (int) ($_GET['id'] ?? ($slips[0]['id'] ?? 0));
$detail = null;
foreach ($slips as $row) {
    if ((int) $row['id'] === $detailId) {
        $detail = $row;
        break;
    }
}

function money($value): string {
    return number_format((float) $value, 0, ',', '.');
}

$detailGroups = [
    'Thông tin công và lương cơ bản' => ['basic_salary' => 'Lương cơ bản', 'working_days_standard' => 'Ngày công chuẩn', 'salary_per_day' => 'Lương/ngày', 'salary_per_hour' => 'Lương/giờ', 'actual_workdays' => 'Ngày công thực tế', 'paid_leave_days' => 'Nghỉ phép hưởng lương', 'other_paid_leave_days' => 'Nghỉ khác hưởng lương', 'unpaid_leave_days' => 'Nghỉ không lương', 'late_early_hours' => 'Giờ trễ/về sớm', 'late_early_deduction' => 'Khấu trừ trễ/về sớm', 'total_paid_days' => 'Tổng ngày được trả', 'basic_salary_received' => 'Lương cơ bản thực nhận'],
    'Phụ cấp và thưởng' => ['meal_allowance' => 'Phụ cấp ăn', 'meal_received' => 'Tiền ăn thực nhận', 'clothes_allowance' => 'Phụ cấp quần áo', 'clothes_received' => 'Quần áo thực nhận', 'phone_allowance' => 'Phụ cấp điện thoại', 'phone_received' => 'Điện thoại thực nhận', 'transport_allowance' => 'Phụ cấp đi lại', 'housing_allowance' => 'Phụ cấp nhà ở', 'transport_received' => 'Đi lại thực nhận', 'housing_received' => 'Nhà ở thực nhận', 'performance_bonus' => 'Thưởng hiệu suất', 'kpi_bonus' => 'Thưởng KPI', 'kpi_over_days' => 'Ngày vượt KPI', 'kpi_under_days' => 'Ngày dưới KPI', 'other_income' => 'Thu nhập khác', 'adjustment' => 'Điều chỉnh', 'other_bonus' => 'Thưởng khác', 'attendance_bonus' => 'Thưởng chuyên cần'],
    'Tăng ca' => ['basic_salary_per_hour' => 'Lương cơ bản/giờ', 'ot_weekday_hours' => 'Giờ OT ngày thường', 'ot_weekend_hours' => 'Giờ OT cuối tuần', 'ot_holiday_hours' => 'Giờ OT ngày lễ', 'ot_weekday_amount' => 'Tiền OT ngày thường', 'ot_weekend_amount' => 'Tiền OT cuối tuần', 'ot_holiday_amount' => 'Tiền OT ngày lễ', 'total_ot_amount' => 'Tổng tiền OT', 'ot_meal_days' => 'Ngày hỗ trợ bữa OT', 'ot_meal_bonus' => 'Tiền ăn OT'],
    'Phép năm và bảo hiểm' => ['annual_leave_total' => 'Phép năm được cấp', 'annual_leave_used' => 'Phép đã dùng', 'annual_leave_remaining' => 'Phép còn lại', 'annual_leave_payout' => 'Thanh toán phép', 'has_social_insurance' => 'Tham gia BHXH', 'si_employee' => 'BHXH nhân viên', 'si_company' => 'BHXH công ty', 'dependants' => 'Người phụ thuộc', 'personal_deduction' => 'Giảm trừ bản thân', 'dependant_deduction' => 'Giảm trừ người phụ thuộc', 'ot_exclude_pit' => 'OT miễn PIT'],
    'Thuế và lương thực nhận' => ['taxable_income' => 'Thu nhập chịu thuế', 'pit_amount' => 'Thuế TNCN', 'late_deduction' => 'Khấu trừ đi trễ', 'kpi_deduction' => 'Khấu trừ KPI', 'gross_salary' => 'Tổng lương', 'advance_payment' => 'Tạm ứng', 'pit_adjustment' => 'Điều chỉnh PIT', 'net_salary' => 'Lương thực nhận', 'bank_transfer' => 'Chuyển khoản'],
    'Ghi chú khác' => ['remark' => 'Ghi chú', 'is_late_warning' => 'Cảnh báo đi trễ', 'late_warning_note' => 'Ghi chú cảnh báo', 'manually_adjusted' => 'Điều chỉnh tay', 'created_at' => 'Ngày tạo', 'updated_at' => 'Ngày cập nhật'],
];

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <?php showFlash(); ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white fw-semibold">Phiếu lương của tôi</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Kỳ lương</th><th class="text-end">Lương cơ bản</th><th class="text-end">Tổng OT</th><th class="text-end">BHXH NV</th><th class="text-end">Tổng lương</th><th class="text-end">Thực nhận</th><th class="text-end">Chi tiết</th></tr></thead>
                    <tbody>
                        <?php if (!$slips): ?><tr><td colspan="7" class="text-center text-muted py-4">Chưa có phiếu lương.</td></tr><?php endif; ?>
                        <?php foreach ($slips as $slip): ?>
                            <tr>
                                <td><?= e($slip['period_month'] . '/' . $slip['period_year']) ?></td>
                                <td class="text-end"><?= money($slip['basic_salary']) ?></td>
                                <td class="text-end"><?= money($slip['total_ot_amount']) ?></td>
                                <td class="text-end"><?= money($slip['si_employee']) ?></td>
                                <td class="text-end"><?= money($slip['gross_salary']) ?></td>
                                <td class="text-end fw-semibold text-success"><?= money($slip['net_salary']) ?></td>
                                <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="?id=<?= (int) $slip['id'] ?>">Xem</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($detail): ?>
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white">Chi tiết phiếu lương kỳ <?= e($detail['period_month'] . '/' . $detail['period_year']) ?></div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="text-muted small">Kỳ lương</div><div class="fw-semibold"><?= e(formatDate($detail['period_from']) . ' - ' . formatDate($detail['period_to'])) ?></div></div></div>
                        <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="text-muted small">Lương thực nhận</div><div class="fw-semibold text-success"><?= money($detail['net_salary']) ?></div></div></div>
                        <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="text-muted small">Tổng OT</div><div class="fw-semibold"><?= money($detail['total_ot_amount']) ?></div></div></div>
                        <div class="col-md-3"><div class="border rounded p-3 h-100"><div class="text-muted small">Tổng lương</div><div class="fw-semibold"><?= money($detail['gross_salary']) ?></div></div></div>
                    </div>
                    <?php foreach ($detailGroups as $groupTitle => $fields): ?>
                        <div class="card border mb-3">
                            <div class="card-header bg-light fw-semibold"><?= e($groupTitle) ?></div>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <tbody>
                                        <?php foreach ($fields as $field => $label): ?>
                                            <tr>
                                                <th style="width: 40%;"><?= e($label) ?></th>
                                                <td>
                                                    <?php if (in_array($field, ['created_at', 'updated_at'], true)): ?>
                                                        <?= e(formatDateTime($detail[$field] ?? null)) ?>
                                                    <?php elseif (in_array($field, ['is_late_warning', 'manually_adjusted', 'has_social_insurance', 'attendance_bonus_eligible'], true)): ?>
                                                        <?= !empty($detail[$field]) ? 'Có' : 'Không' ?>
                                                    <?php elseif (in_array($field, ['remark', 'late_warning_note'], true)): ?>
                                                        <?= e($detail[$field] ?? '') ?>
                                                    <?php else: ?>
                                                        <?= money($detail[$field] ?? 0) ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>
