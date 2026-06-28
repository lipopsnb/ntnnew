<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/functions.php';

requireLogin();

$pdo = getDBConnection();
$user = currentUser();
$today = date('Y-m-d');

$stmt = $pdo->prepare(
    'SELECT ka.kpi_target, ka.over_bonus_pct, kr.actual_qty, kr.salary_per_day, kr.salary_actual, kr.is_deducted, kr.reason, kr.confirmed_at
     FROM kpi_assignments ka
     INNER JOIN kpi_results kr ON kr.kpi_assignment_id = ka.id
     WHERE ka.user_id = ? AND ka.assign_date = ?
     LIMIT 1'
);
$stmt->execute([$user['user_id'], $today]);
$kpiResult = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$kpiResult) {
    return;
}

$popupKey = 'ntn_kpi_popup_' . $today;
?>
<div class="modal fade" id="kpiTodayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fa-solid fa-chart-line me-2"></i>Kết quả KPI hôm nay</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Đóng"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Kết quả KPI ngày <strong><?= e(formatDate($today)) ?></strong> của bạn đã được cập nhật.</p>
                <div class="row g-3 small">
                    <div class="col-6">
                        <div class="kpi-summary-box">
                            <div class="text-muted">Mục tiêu</div>
                            <div class="fw-bold fs-5"><?= e($kpiResult['kpi_target']) ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="kpi-summary-box">
                            <div class="text-muted">Thực tế</div>
                            <div class="fw-bold fs-5"><?= e($kpiResult['actual_qty']) ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="kpi-summary-box">
                            <div class="text-muted">Lương/ngày</div>
                            <div class="fw-bold"><?= e(formatMoney($kpiResult['salary_per_day'])) ?> VNĐ</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="kpi-summary-box">
                            <div class="text-muted">Thực nhận KPI</div>
                            <div class="fw-bold text-success"><?= e(formatMoney($kpiResult['salary_actual'])) ?> VNĐ</div>
                        </div>
                    </div>
                </div>
                <div class="mt-3 small">
                    <div><strong>Thưởng vượt:</strong> <?= e($kpiResult['over_bonus_pct']) ?>%</div>
                    <div><strong>Khấu trừ:</strong> <?= !empty($kpiResult['is_deducted']) ? 'Có' : 'Không' ?></div>
                    <?php if (!empty($kpiResult['reason'])): ?>
                        <div><strong>Ghi chú:</strong> <?= e($kpiResult['reason']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const storageKey = <?= json_encode($popupKey) ?>;
    const modalElement = document.getElementById('kpiTodayModal');
    if (!modalElement || localStorage.getItem(storageKey) === 'dismissed') {
        return;
    }

    const modal = new bootstrap.Modal(modalElement);
    modal.show();
    modalElement.addEventListener('hidden.bs.modal', function () {
        localStorage.setItem(storageKey, 'dismissed');
    }, { once: true });
});
</script>
