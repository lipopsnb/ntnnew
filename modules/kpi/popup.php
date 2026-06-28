<?php
// Gọi file này trong header.php hoặc dashboard.php
// Chỉ hiện với role manager/director
$user = currentUser();
if (!$user || !in_array($user['role'] ?? '', ['manager', 'director'])) return;

$pdo   = getDBConnection();
$today = date('Y-m-d');
$hour  = (int)date('H');

// Chỉ hiện popup sau 17h
if ($hour < 17) return;

// Đếm nhân viên chưa nhập kết quả
$pending = $pdo->prepare("
    SELECT COUNT(*) FROM kpi_assignments ka
    LEFT JOIN kpi_results kr ON kr.kpi_assignment_id = ka.id
    WHERE ka.assign_date = ?
    AND kr.id IS NULL
");
$pending->execute([$today]);
$pendingCount = $pending->fetchColumn();

if ($pendingCount == 0) return;
?>
<!-- Popup cảnh báo KPI chưa nhập -->
<div class="modal fade" id="modalKpiWarning" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-warning border-2">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Chưa nhập kết quả KPI!
                </h5>
            </div>
            <div class="modal-body text-center py-4">
                <i class="fas fa-clipboard-list fa-4x text-warning mb-3"></i>
                <h5>Còn <span class="text-danger fw-bold"><?= $pendingCount ?></span>
                    nhân viên chưa có kết quả KPI hôm nay</h5>
                <p class="text-muted">
                    Vui lòng kiểm tra và nhập kết quả KPI
                    cho nhân viên sản xuất ngày
                    <strong><?= date('d/m/Y') ?></strong>
                </p>
            </div>
            <div class="modal-footer justify-content-center gap-2">
                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Để sau
                </button>
                <a href="/ntn_erp/modules/kpi/result.php?date=<?= $today ?>"
                   class="btn btn-warning fw-bold">
                    <i class="fas fa-clipboard-check me-1"></i>
                    Nhập kết quả KPI ngay
                </a>
            </div>
        </div>
    </div>
</div>
<script>
    window.addEventListener('load', () => {
        new bootstrap.Modal(document.getElementById('modalKpiWarning')).show();
    });
</script>