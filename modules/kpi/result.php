<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/modules/payroll/engine/PayrollEngine.php';
requireLogin();
requireRole('director', 'accountant', 'manager', 'warehouse', 'production');

$pdo  = getDBConnection();
$user = currentUser();
$date = $_GET['date'] ?? date('Y-m-d');
$canEditDelete = hasRole('director', 'accountant');

// ── Lấy working_days từ kỳ lương hiện tại ────────────────────
$periodRow = $pdo->prepare("
    SELECT working_days FROM payroll_periods
    WHERE status != 'locked'
      AND period_from <= ? AND period_to >= ?
    ORDER BY period_from DESC LIMIT 1
");
$periodRow->execute([$date, $date]);
$periodRow = $periodRow->fetch(PDO::FETCH_ASSOC);

if (!$periodRow) {
    $periodRow = $pdo->query("
        SELECT working_days FROM payroll_periods
        WHERE status != 'locked'
        ORDER BY period_from DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
}
$workingDays = $periodRow ? (int)$periodRow['working_days'] : 26;

// ── Lấy assignments ───────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT ka.*, u.full_name, u.employee_code,
           kr.id            AS result_id,
           kr.actual_qty,
           kr.is_deducted,
           kr.reason,
           kr.salary_per_day,
           kr.salary_actual
    FROM kpi_assignments ka
    JOIN users u ON ka.user_id = u.id
    LEFT JOIN kpi_results kr ON kr.kpi_assignment_id = ka.id
    WHERE ka.assign_date = ?
    ORDER BY u.full_name
");
$stmt->execute([$date]);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Tính salary_per_day cho từng user ─────────────────────────
foreach ($assignments as &$a) {
    $salaryStmt = $pdo->prepare("
        SELECT COALESCE(SUM(es.amount), 0) AS total
        FROM employee_salaries es
        JOIN salary_components sc ON es.component_id = sc.id
        WHERE es.user_id = ?
          AND es.is_active = 1
          AND sc.component_type IN ('earning', 'bonus')
          AND sc.component_code != 'attendance_bonus'
    ");
    $salaryStmt->execute([$a['user_id']]);
    $totalSalary = (float)$salaryStmt->fetchColumn();
    $a['salary_per_day_calc'] = $workingDays > 0
        ? round($totalSalary / $workingDays) : 0;
}
unset($a);

$csrf = generateCSRF();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/warehouse_nav.php'; ?>
<div class="container-fluid py-4">

    <?php showFlash(); ?>

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <a href="/ntn_erp/modules/kpi/assign.php" class="btn btn-sm btn-outline-secondary me-2">
                <i class="fas fa-arrow-left"></i>
            </a>
            <span class="fs-5 fw-bold">
                <i class="fas fa-clipboard-check me-2 text-success"></i>
                Nhập kết quả KPI —
                <span class="text-primary"><?= date('d/m/Y', strtotime($date)) ?></span>
            </span>
        </div>
        <input type="date" class="form-control w-auto"
               value="<?= $date ?>"
               onchange="location.href='/ntn_erp/modules/kpi/result.php?date='+this.value">
    </div>

    <?php if (empty($assignments)): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Chưa có KPI được phân bổ ngày <?= date('d/m/Y', strtotime($date)) ?> —
        <a href="/ntn_erp/modules/kpi/assign.php">Phân bổ ngay</a>
    </div>
    <?php else: ?>

    <div class="alert alert-info small py-2 mb-3">
        <i class="fas fa-info-circle me-1"></i>
        <strong>Lương áp KPI</strong> = Lương ngày chuẩn × (SP thực tế ÷ KPI mục tiêu)
        — Vượt KPI được thưởng thêm, không đạt bị trừ theo tỷ lệ (nếu chọn trừ).
        Ngày công chuẩn: <strong><?= $workingDays ?> ngày</strong>
    </div>

    <form id="formResult">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="assign_date" value="<?= $date ?>">

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold">
                <i class="fas fa-list me-2 text-warning"></i>
                Kết quả KPI nhân viên
                <span class="badge bg-secondary ms-2"><?= count($assignments) ?> nhân viên</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Nhân viên</th>
                                <th class="text-center">KPI mục tiêu</th>
                                <th class="text-center" width="130">SP thực tế</th>
                                <th class="text-center">Tỷ lệ</th>
                                <th class="text-center">Lương ngày chuẩn</th>
                                <th class="text-center">Lương áp KPI</th>
                                <th class="text-center">Trạng thái</th>
                                <?php if ($canEditDelete): ?>
                                <th class="text-center" width="90">Thao tác</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($assignments as $a):
                            $salaryDay    = $a['salary_per_day_calc'];
                            $isDone       = $a['result_id'] !== null;
                            $isOver       = $isDone && (float)$a['actual_qty'] >= (float)$a['kpi_target'];
                            $isDeducted   = $isDone && (int)$a['is_deducted'] === 1;
                            $pct          = $isDone && $a['kpi_target'] > 0
                                            ? round($a['actual_qty'] / $a['kpi_target'] * 100, 1) : 0;

                            // ✅ Tính salary_actual đúng: luôn theo tỷ lệ qty/target
                            if ($isDone) {
                                if ($isDeducted) {
                                    // Trừ lương → tỷ lệ
                                    $salaryActual = round($salaryDay * $a['actual_qty'] / $a['kpi_target']);
                                } elseif ($a['salary_actual'] > 0) {
                                    // Đã lưu (bao gồm vượt hoặc giữ nguyên)
                                    $salaryActual = (float)$a['salary_actual'];
                                } else {
                                    $salaryActual = $salaryDay;
                                }
                            } else {
                                $salaryActual = $salaryDay;
                            }

                            $diff = $salaryActual - $salaryDay;
                        ?>
                        <tr id="row_<?= $a['id'] ?>">
                            <td>
                                <input type="hidden" name="items[<?= $a['id'] ?>][assignment_id]"
                                       value="<?= $a['id'] ?>">
                                <input type="hidden" name="items[<?= $a['id'] ?>][salary_per_day]"
                                       value="<?= $salaryDay ?>">
                                <input type="hidden" name="items[<?= $a['id'] ?>][kpi_target]"
                                       value="<?= $a['kpi_target'] ?>">
                                <div class="fw-semibold"><?= htmlspecialchars($a['full_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($a['employee_code']) ?></small>
                            </td>

                            <td class="text-center">
                                <span class="badge bg-primary fs-6">
                                    <?= number_format($a['kpi_target']) ?> SP
                                </span>
                            </td>

                            <td class="text-center">
                                <input type="number"
                                       name="items[<?= $a['id'] ?>][actual_qty]"
                                       class="form-control form-control-sm text-center inp-actual"
                                       data-id="<?= $a['id'] ?>"
                                       data-target="<?= $a['kpi_target'] ?>"
                                       data-salary="<?= $salaryDay ?>"
                                       value="<?= $isDone ? htmlspecialchars($a['actual_qty']) : '' ?>"
                                       min="0" placeholder="Nhập SP"
                                       <?= $isDone ? 'readonly' : '' ?>>
                            </td>

                            <td class="text-center">
                                <?php if ($isDone): ?>
                                <span class="badge fs-6 bg-<?= $pct>=100?'success':($pct>=75?'warning':'danger') ?>">
                                    <?= $pct ?>%
                                </span>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Cột: Lương ngày chuẩn -->
                            <td class="text-center text-muted">
                                <?= number_format($salaryDay) ?> đ
                            </td>

                            <!-- Cột: Lương áp KPI -->
                            <td class="text-center">
                                <?php if ($isDone): ?>
                                    <div class="fw-bold <?= $diff > 0 ? 'text-success' : ($diff < 0 ? 'text-danger' : 'text-dark') ?>">
                                        <?= number_format($salaryActual) ?> đ
                                    </div>
                                    <?php if ($diff > 0): ?>
                                    <small class="text-success">
                                        <i class="fas fa-arrow-up me-1"></i>+<?= number_format($diff) ?> đ
                                    </small>
                                    <?php elseif ($diff < 0): ?>
                                    <small class="text-danger">
                                        <i class="fas fa-arrow-down me-1"></i><?= number_format($diff) ?> đ
                                    </small>
                                    <?php else: ?>
                                    <small class="text-muted">Không thay đổi</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                <div class="text-muted small">Chưa nhập</div>
                                <?php endif; ?>
                            </td>

                            <!-- Cột: Trạng thái -->
                            <td class="text-center">
                                <?php if ($isDone): ?>
                                    <?php if ($isOver): ?>
                                    <span class="badge bg-success">
                                        <i class="fas fa-trophy me-1"></i>Vượt <?= round($pct-100, 1) ?>%
                                    </span>
                                    <?php elseif ($isDeducted): ?>
                                    <span class="badge bg-danger">
                                        <i class="fas fa-minus-circle me-1"></i>Trừ lương
                                    </span>
                                    <div class="small text-danger mt-1"><?= $pct ?>% KPI</div>
                                    <?php else: ?>
                                    <span class="badge bg-warning text-dark">
                                        <i class="fas fa-shield-alt me-1"></i>Giữ nguyên
                                    </span>
                                    <?php if ($a['reason']): ?>
                                    <div class="small text-muted mt-1"
                                         title="<?= htmlspecialchars($a['reason']) ?>">
                                        <i class="fas fa-comment me-1"></i>
                                        <?= mb_strimwidth(htmlspecialchars($a['reason']), 0, 25, '...') ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                <span class="badge bg-secondary">Chưa nhập</span>
                                <?php endif; ?>
                            </td>

                            <?php if ($canEditDelete): ?>
                            <td class="text-center">
                                <?php if ($isDone): ?>
                                <button type="button" class="btn btn-sm btn-warning me-1"
                                        onclick="openEditResult(
                                            <?= $a['result_id'] ?>,
                                            '<?= htmlspecialchars($a['full_name'], ENT_QUOTES) ?>',
                                            <?= (float)$a['actual_qty'] ?>,
                                            <?= (float)$a['kpi_target'] ?>,
                                            <?= $salaryDay ?>,
                                            <?= (int)$a['is_deducted'] ?>,
                                            '<?= htmlspecialchars($a['reason'] ?? '', ENT_QUOTES) ?>'
                                        )" title="Sửa kết quả">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-danger"
                                        onclick="confirmDeleteResult(
                                            <?= $a['result_id'] ?>,
                                            '<?= htmlspecialchars($a['full_name'], ENT_QUOTES) ?>'
                                        )" title="Xoá kết quả">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white d-flex justify-content-end gap-2">
                <a href="/ntn_erp/modules/kpi/assign.php" class="btn btn-secondary">
                    <i class="fas fa-times me-1"></i>Huỷ
                </a>
                <button type="button" class="btn btn-success" id="btnSaveResult">
                    <i class="fas fa-save me-1"></i>Lưu kết quả KPI
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>
</div>

<!-- Modal hỏi trừ lương -->
<div class="modal fade" id="modalDeduct" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-exclamation-triangle me-2"></i>Chưa hoàn thành KPI
                </h5>
            </div>
            <div class="modal-body" id="modalDeductBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger fw-bold" id="btnDeductYes">
                    <i class="fas fa-check me-1"></i>Có — Trừ lương theo tỷ lệ
                </button>
                <button type="button" class="btn btn-success" id="btnDeductNo">
                    <i class="fas fa-times me-1"></i>Không — Vẫn tính đủ ngày công
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal nhập lý do -->
<div class="modal fade" id="modalReason" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Nhập lý do</h5>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-2">Vui lòng nhập lý do không trừ lương:</p>
                <textarea id="inputReason" class="form-control" rows="3"
                          placeholder="Nhập lý do..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="btnReasonOk">
                    <i class="fas fa-check me-1"></i>Xác nhận
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal sửa kết quả -->
<div class="modal fade" id="modalEditResult" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-edit me-2"></i>Sửa kết quả KPI
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Nhân viên: <strong id="editResultName"></strong></p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">SP thực tế:</label>
                    <input type="number" id="editActualQty" class="form-control" min="0">
                </div>
                <div id="editDeductSection" class="mb-3" style="display:none;">
                    <label class="form-label fw-semibold">Xử lý lương:</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="editDeduct"
                                   id="editDeductYes" value="1">
                            <label class="form-check-label text-danger fw-semibold"
                                   for="editDeductYes">Trừ lương theo tỷ lệ</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="editDeduct"
                                   id="editDeductNo" value="0">
                            <label class="form-check-label text-success fw-semibold"
                                   for="editDeductNo">Tính đủ ngày công</label>
                        </div>
                    </div>
                </div>
                <div id="editReasonSection" class="mb-3" style="display:none;">
                    <label class="form-label fw-semibold">Lý do không trừ lương:</label>
                    <textarea id="editReason" class="form-control" rows="2"></textarea>
                </div>
                <div class="card bg-light border-0">
                    <div class="card-body py-2 small">
                        <div class="d-flex justify-content-between mb-1">
                            <span>Lương ngày chuẩn:</span>
                            <strong id="previewFullSalary">—</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span>Lương áp KPI:</span>
                            <strong id="previewActualSalary" class="text-primary">—</strong>
                        </div>
                        <div class="d-flex justify-content-between border-top pt-1">
                            <span>Chênh lệch:</span>
                            <strong id="previewDiff">—</strong>
                        </div>
                    </div>
                </div>
                <input type="hidden" id="editResultId">
                <input type="hidden" id="editKpiTarget">
                <input type="hidden" id="editSalaryPerDay">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-warning fw-bold" id="btnConfirmEditResult">
                    <i class="fas fa-save me-1"></i>Lưu thay đổi
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal xoá kết quả -->
<div class="modal fade" id="modalDeleteResult" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-trash me-2"></i>Xác nhận xoá kết quả KPI
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger mb-0">
                    <p class="mb-1">Bạn có chắc chắn muốn xoá kết quả KPI của:</p>
                    <strong id="deleteResultName" class="fs-6"></strong>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-danger fw-bold" id="btnConfirmDeleteResult">
                    <i class="fas fa-trash me-1"></i>Xoá kết quả
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF = '<?= $csrf ?>';
let pendingItems = [], currentItemIdx = 0, processedItems = {};

// ===== LƯU KẾT QUẢ MỚI =====
document.getElementById('btnSaveResult').addEventListener('click', () => {
    const inputs = document.querySelectorAll('.inp-actual:not([readonly])');
    if (inputs.length === 0) { alert('Tất cả đã được nhập kết quả rồi!'); return; }

    let hasEmpty = false;
    inputs.forEach(inp => { if (!inp.value) hasEmpty = true; });
    if (hasEmpty) {
        if (!confirm('Một số nhân viên chưa nhập SP thực tế. Tiếp tục?')) return;
    }

    let toProcess = [];
    processedItems = {};

    inputs.forEach(inp => {
        const qty    = parseInt(inp.value) || 0;
        const target = parseInt(inp.dataset.target);
        const salary = parseFloat(inp.dataset.salary);
        const id     = inp.dataset.id;
        if (!inp.value) return;

        if (qty < target) {
            // Không đạt → hỏi có trừ không
            toProcess.push({
                id, qty, target, salary,
                name: inp.closest('tr').querySelector('.fw-semibold').textContent.trim()
            });
        } else {
            // ✅ Đạt hoặc vượt → tính theo tỷ lệ (vượt được thưởng thêm)
            const salaryActual = Math.round(salary * qty / target);
            processedItems[id] = { is_deducted: 0, reason: '', salary_actual: salaryActual };
        }
    });

    if (toProcess.length > 0) {
        pendingItems = toProcess;
        currentItemIdx = 0;
        showDeductModal();
    } else {
        submitResult();
    }
});

function showDeductModal() {
    if (currentItemIdx >= pendingItems.length) { submitResult(); return; }

    const item         = pendingItems[currentItemIdx];
    const pct          = Math.round(item.qty / item.target * 100);
    const salaryActual = Math.round(item.salary * item.qty / item.target);
    const salaryLoss   = item.salary - salaryActual;

    document.getElementById('modalDeductBody').innerHTML = `
        <div class="alert alert-warning mb-3">
            <strong class="fs-6">${item.name}</strong><br>
            KPI mục tiêu: <strong>${item.target.toLocaleString('vi-VN')} SP</strong><br>
            Thực tế: <strong class="text-danger">${item.qty.toLocaleString('vi-VN')} SP</strong>
            <span class="badge bg-danger ms-1">${pct}%</span><br>
            Còn thiếu: <strong class="text-danger">${(item.target - item.qty).toLocaleString('vi-VN')} SP</strong>
        </div>
        <div class="card bg-light border-0 mb-3">
            <div class="card-body py-2 small">
                <div class="d-flex justify-content-between mb-1">
                    <span>Lương ngày chuẩn:</span>
                    <strong>${item.salary.toLocaleString('vi-VN')} đ</strong>
                </div>
                <div class="d-flex justify-content-between text-danger mb-1">
                    <span>Lương nếu trừ (${pct}%):</span>
                    <strong>${salaryActual.toLocaleString('vi-VN')} đ</strong>
                </div>
                <div class="d-flex justify-content-between text-danger border-top pt-1">
                    <span>Bị trừ:</span>
                    <strong>−${salaryLoss.toLocaleString('vi-VN')} đ</strong>
                </div>
            </div>
        </div>
        <p class="mb-0 fw-bold text-danger">
            <i class="fas fa-question-circle me-1"></i>Có trừ lương ngày này không?
        </p>`;

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDeduct'));
    modal.show();

    document.getElementById('btnDeductYes').onclick = () => {
        processedItems[item.id] = { is_deducted: 1, reason: '', salary_actual: salaryActual };
        modal.hide();
        currentItemIdx++;
        setTimeout(showDeductModal, 350);
    };

    document.getElementById('btnDeductNo').onclick = () => {
        modal.hide();
        setTimeout(() => {
            document.getElementById('inputReason').value = '';
            const reasonModal = bootstrap.Modal.getOrCreateInstance(
                document.getElementById('modalReason')
            );
            reasonModal.show();
            document.getElementById('btnReasonOk').onclick = () => {
                const reason = document.getElementById('inputReason').value.trim();
                if (!reason) { alert('Vui lòng nhập lý do!'); return; }
                // Không trừ → giữ nguyên lương chuẩn
                processedItems[item.id] = { is_deducted: 0, reason, salary_actual: item.salary };
                reasonModal.hide();
                currentItemIdx++;
                setTimeout(showDeductModal, 350);
            };
        }, 350);
    };
}

function submitResult() {
    const formData = new FormData(document.getElementById('formResult'));
    formData.append('processed', JSON.stringify(processedItems));
    const btn = document.getElementById('btnSaveResult');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';
    fetch('/ntn_erp/api/kpi/save_result.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.ok) location.reload();
        else alert('Lỗi: ' + res.msg);
    })
    .catch(() => alert('Có lỗi xảy ra!'))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu kết quả KPI';
    });
}

// ===== SỬA KẾT QUẢ =====
function openEditResult(resultId, name, actualQty, kpiTarget, salaryDay, isDeducted, reason) {
    document.getElementById('editResultId').value         = resultId;
    document.getElementById('editResultName').textContent = name;
    document.getElementById('editActualQty').value        = actualQty;
    document.getElementById('editKpiTarget').value        = kpiTarget;
    document.getElementById('editSalaryPerDay').value     = salaryDay;
    document.getElementById('previewFullSalary').textContent =
        salaryDay.toLocaleString('vi-VN') + ' đ';
    document.getElementById('editDeductYes').checked = (isDeducted === 1);
    document.getElementById('editDeductNo').checked  = (isDeducted === 0);
    document.getElementById('editReason').value      = reason || '';
    updateEditSections();
    updateSalaryPreview();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditResult')).show();
}

function updateEditSections() {
    const qty    = parseInt(document.getElementById('editActualQty').value) || 0;
    const target = parseInt(document.getElementById('editKpiTarget').value) || 1;
    const deductSec = document.getElementById('editDeductSection');
    const reasonSec = document.getElementById('editReasonSection');
    if (qty < target) {
        deductSec.style.display = 'block';
        reasonSec.style.display = document.getElementById('editDeductNo').checked ? 'block' : 'none';
    } else {
        // Đạt hoặc vượt → không cần chọn
        deductSec.style.display = 'none';
        reasonSec.style.display = 'none';
    }
}

function updateSalaryPreview() {
    const qty       = parseInt(document.getElementById('editActualQty').value) || 0;
    const target    = parseInt(document.getElementById('editKpiTarget').value) || 1;
    const salaryDay = parseFloat(document.getElementById('editSalaryPerDay').value) || 0;
    const isDeduct  = document.getElementById('editDeductYes').checked;
    const preview   = document.getElementById('previewActualSalary');
    const diffEl    = document.getElementById('previewDiff');

    let salaryActual;
    if (qty >= target) {
        // ✅ Vượt hoặc đúng → tính theo tỷ lệ (thưởng thêm nếu vượt)
        salaryActual = Math.round(salaryDay * qty / target);
        preview.className = qty > target ? 'text-success fw-bold' : 'text-dark fw-bold';
    } else if (isDeduct) {
        // Không đạt + trừ → tỷ lệ
        salaryActual = Math.round(salaryDay * qty / target);
        preview.className = 'text-danger fw-bold';
    } else {
        // Không đạt + không trừ → giữ nguyên
        salaryActual = salaryDay;
        preview.className = 'text-warning fw-bold';
    }

    preview.textContent = salaryActual.toLocaleString('vi-VN') + ' đ';

    const diff = salaryActual - salaryDay;
    if (diff > 0) {
        diffEl.textContent = '+' + diff.toLocaleString('vi-VN') + ' đ';
        diffEl.className = 'text-success fw-bold';
    } else if (diff < 0) {
        diffEl.textContent = diff.toLocaleString('vi-VN') + ' đ';
        diffEl.className = 'text-danger fw-bold';
    } else {
        diffEl.textContent = '0 đ';
        diffEl.className = 'text-muted fw-bold';
    }
}

document.getElementById('editActualQty').addEventListener('input', () => {
    updateEditSections();
    updateSalaryPreview();
});
document.querySelectorAll('input[name="editDeduct"]').forEach(r => {
    r.addEventListener('change', () => {
        updateEditSections();
        updateSalaryPreview();
    });
});

document.getElementById('btnConfirmEditResult').addEventListener('click', () => {
    const resultId  = document.getElementById('editResultId').value;
    const actualQty = parseInt(document.getElementById('editActualQty').value);
    const kpiTarget = parseInt(document.getElementById('editKpiTarget').value);
    const salaryDay = parseFloat(document.getElementById('editSalaryPerDay').value);

    if (isNaN(actualQty) || actualQty < 0) { alert('SP thực tế không hợp lệ!'); return; }

    let isDeducted = 0, reason = '', salaryActual;

    if (actualQty >= kpiTarget) {
        // ✅ Đạt/vượt → tính theo tỷ lệ
        isDeducted   = 0;
        salaryActual = Math.round(salaryDay * actualQty / kpiTarget);
    } else {
        isDeducted = document.getElementById('editDeductYes').checked ? 1 : 0;
        if (isDeducted === 0) {
            reason = document.getElementById('editReason').value.trim();
            if (!reason) { alert('Vui lòng nhập lý do!'); return; }
            salaryActual = salaryDay; // Giữ nguyên
        } else {
            salaryActual = Math.round(salaryDay * actualQty / kpiTarget);
        }
    }

    const btn = document.getElementById('btnConfirmEditResult');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';

    const fd = new FormData();
    fd.append('csrf_token',    CSRF);
    fd.append('result_id',     resultId);
    fd.append('actual_qty',    actualQty);
    fd.append('is_deducted',   isDeducted);
    fd.append('reason',        reason);
    fd.append('salary_actual', salaryActual);
    fd.append('salary_per_day', salaryDay);

    fetch('/ntn_erp/api/kpi/edit_result.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.ok) location.reload();
        else alert('Lỗi: ' + res.msg);
    })
    .catch(() => alert('Có lỗi xảy ra!'))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu thay đổi';
    });
});

// ===== XOÁ KẾT QUẢ =====
function confirmDeleteResult(resultId, name) {
    document.getElementById('deleteResultName').textContent = name;
    document.getElementById('btnConfirmDeleteResult').dataset.id = resultId;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDeleteResult')).show();
}

document.getElementById('btnConfirmDeleteResult').addEventListener('click', function() {
    const resultId = this.dataset.id;
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang xoá...';
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('result_id',  resultId);
    fetch('/ntn_erp/api/kpi/delete_result.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.ok) location.reload();
        else alert('Lỗi: ' + res.msg);
    })
    .catch(() => alert('Có lỗi xảy ra!'))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash me-1"></i>Xoá kết quả';
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>