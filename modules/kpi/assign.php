<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'manager', 'warehouse', 'production');

$pdo   = getDBConnection();
$user  = currentUser();
$today = date('Y-m-d');
$canEditDelete = hasRole('director', 'accountant');

// Lấy danh sách nhân viên (chỉ employee)
$staffList = $pdo->query("
    SELECT u.id, u.full_name, u.employee_code
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE r.name = 'employee'
    AND u.is_active = 1
    ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

// Lấy KPI đã phân bổ hôm nay
$stmt = $pdo->prepare("
    SELECT ka.*, u.full_name, u.employee_code,
           kr.id AS result_id
    FROM kpi_assignments ka
    JOIN users u ON ka.user_id = u.id
    LEFT JOIN kpi_results kr ON kr.kpi_assignment_id = ka.id
    WHERE ka.assign_date = ?
    ORDER BY u.full_name
");
$stmt->execute([$today]);
$assigned = $stmt->fetchAll(PDO::FETCH_ASSOC);

$assignedUserIds = array_column($assigned, 'user_id');
$csrf = generateCSRF();

include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/sidebar.php';
?>
<div class="main-content">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/warehouse_nav.php'; ?>
<div class="container-fluid py-4">

    <?php showFlash(); ?>

    <div class="d-flex align-items-center mb-4">
        <div>
            <h4 class="mb-1">
                <i class="fas fa-tasks me-2 text-primary"></i>Phân bổ KPI
                <span class="badge bg-secondary fs-6 ms-2"><?= date('d/m/Y') ?></span>
            </h4>
            <small class="text-muted">Phân bổ KPI buổi sáng cho nhân viên sản xuất</small>
        </div>
    </div>

    <div class="row g-3">
        <!-- Form phân bổ -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-plus-circle me-2 text-success"></i>
                    Phân bổ KPI hôm nay
                </div>
                <div class="card-body">
                    <?php if (empty($staffList)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Chưa có nhân viên nào trong hệ thống.
                    </div>
                    <?php else: ?>
                    <form id="formAssign">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="assign_date" value="<?= $today ?>">

                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" id="checkAll">
                                        </th>
                                        <th>Nhân viên</th>
                                        <th width="160">KPI mục tiêu (SP)</th>
                                        <?php if ($canEditDelete): ?>
                                        <th width="100" class="text-center">Thao tác</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($staffList as $s): ?>
                                <?php $isAssigned = in_array($s['id'], $assignedUserIds); ?>
                                <?php
                                    $kpiData = null;
                                    if ($isAssigned) {
                                        $tmp = array_filter($assigned, fn($a) => $a['user_id'] == $s['id']);
                                        $kpiData = reset($tmp);
                                    }
                                ?>
                                <tr class="<?= $isAssigned ? 'table-success' : '' ?>">
                                    <td class="text-center">
                                        <?php if (!$isAssigned): ?>
                                        <input type="checkbox" name="users[]"
                                               value="<?= $s['id'] ?>" class="chk-user">
                                        <?php else: ?>
                                        <i class="fas fa-check-circle text-success"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($s['full_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($s['employee_code']) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!$isAssigned): ?>
                                        <input type="number"
                                               name="kpi[<?= $s['id'] ?>]"
                                               class="form-control form-control-sm text-center"
                                               placeholder="VD: 300" min="1">
                                        <?php else: ?>
                                        <span class="badge bg-success fs-6"
                                              id="kpiLabel_<?= $kpiData['id'] ?>">
                                            <?= number_format($kpiData['kpi_target']) ?> SP
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($canEditDelete): ?>
                                    <td class="text-center">
                                        <?php if ($isAssigned): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-warning me-1"
                                                onclick="openEditModal(<?= $kpiData['id'] ?>, '<?= htmlspecialchars($s['full_name']) ?>', <?= $kpiData['kpi_target'] ?>)"
                                                title="Sửa KPI">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-danger"
                                                onclick="confirmDelete(<?= $kpiData['id'] ?>, '<?= htmlspecialchars($s['full_name']) ?>', <?= $kpiData['result_id'] ? 1 : 0 ?>)"
                                                title="Xoá KPI">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Đã phân bổ: <strong><?= count($assigned) ?></strong> /
                                <?= count($staffList) ?> nhân viên
                            </small>
                            <button type="button" class="btn btn-primary" id="btnSaveAssign">
                                <i class="fas fa-save me-1"></i>Lưu phân bổ KPI
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Danh sách đã phân bổ hôm nay -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-list me-2 text-primary"></i>
                    Đã phân bổ hôm nay
                    <span class="badge bg-primary ms-2"><?= count($assigned) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($assigned)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                        Chưa phân bổ KPI hôm nay
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Nhân viên</th>
                                    <th class="text-center">KPI</th>
                                    <th class="text-center">Kết quả</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($assigned as $a):
                                $res = $pdo->prepare("SELECT * FROM kpi_results WHERE kpi_assignment_id = ?");
                                $res->execute([$a['id']]);
                                $res = $res->fetch(PDO::FETCH_ASSOC);
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($a['full_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($a['employee_code']) ?></small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary">
                                        <?= number_format($a['kpi_target']) ?> SP
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($res):
                                        $pct = round($res['actual_qty'] / $a['kpi_target'] * 100);
                                    ?>
                                    <span class="badge bg-<?= $pct >= 100 ? 'success' : 'warning' ?>">
                                        <?= number_format($res['actual_qty']) ?> SP (<?= $pct ?>%)
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Chưa nhập</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($assigned)): ?>
                <div class="card-footer bg-white">
                    <a href="/ntn_erp/modules/kpi/result.php?date=<?= $today ?>"
                       class="btn btn-success btn-sm w-100">
                        <i class="fas fa-clipboard-check me-1"></i>
                        Nhập kết quả buổi chiều
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Modal Sửa KPI -->
<div class="modal fade" id="modalEditKpi" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-edit me-2"></i>Sửa KPI
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Nhân viên: <strong id="editEmployeeName"></strong></p>
                <label class="form-label fw-semibold">KPI mục tiêu mới (SP):</label>
                <input type="number" id="editKpiTarget"
                       class="form-control" min="1" placeholder="Nhập số sản phẩm">
                <input type="hidden" id="editAssignId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Huỷ
                </button>
                <button type="button" class="btn btn-warning fw-bold" id="btnConfirmEdit">
                    <i class="fas fa-save me-1"></i>Lưu thay đổi
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Xác nhận Xoá KPI -->
<div class="modal fade" id="modalDeleteKpi" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-trash me-2"></i>Xác nhận xoá KPI
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalDeleteBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Huỷ
                </button>
                <button type="button" class="btn btn-danger fw-bold" id="btnConfirmDelete">
                    <i class="fas fa-trash me-1"></i>Xoá KPI
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ===== CHECK ALL =====
document.getElementById('checkAll').addEventListener('change', function () {
    document.querySelectorAll('.chk-user').forEach(c => c.checked = this.checked);
});

// ===== LƯU PHÂN BỔ =====
document.getElementById('btnSaveAssign').addEventListener('click', () => {
    const checked = document.querySelectorAll('.chk-user:checked');
    if (checked.length === 0) {
        alert('Vui lòng chọn ít nhất 1 nhân viên!'); return;
    }
    let valid = true;
    checked.forEach(c => {
        const uid   = c.value;
        const input = document.querySelector(`input[name="kpi[${uid}]"]`);
        if (!input || !input.value || parseInt(input.value) < 1) {
            alert('Vui lòng nhập KPI mục tiêu cho tất cả nhân viên đã chọn!');
            valid = false;
        }
    });
    if (!valid) return;

    const btn = document.getElementById('btnSaveAssign');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';

    fetch('/ntn_erp/api/kpi/save_assign.php', {
        method: 'POST',
        body  : new FormData(document.getElementById('formAssign'))
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) location.reload();
        else alert('Lỗi: ' + res.msg);
    })
    .catch(() => alert('Có lỗi xảy ra, vui lòng thử lại!'))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu phân bổ KPI';
    });
});

// ===== SỬA KPI =====
function openEditModal(assignId, name, currentTarget) {
    document.getElementById('editAssignId').value   = assignId;
    document.getElementById('editEmployeeName').textContent = name;
    document.getElementById('editKpiTarget').value  = currentTarget;
    bootstrap.Modal.getOrCreateInstance(
        document.getElementById('modalEditKpi')
    ).show();
}

document.getElementById('btnConfirmEdit').addEventListener('click', () => {
    const assignId = document.getElementById('editAssignId').value;
    const target   = parseInt(document.getElementById('editKpiTarget').value);
    if (!target || target < 1) {
        alert('KPI phải l��n hơn 0!'); return;
    }

    const btn = document.getElementById('btnConfirmEdit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';

    const fd = new FormData();
    fd.append('csrf_token', '<?= $csrf ?>');
    fd.append('assign_id',  assignId);
    fd.append('kpi_target', target);

    fetch('/ntn_erp/api/kpi/edit_assign.php', {
        method: 'POST', body: fd
    })
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

// ===== XOÁ KPI =====
function confirmDelete(assignId, name, hasResult) {
    document.getElementById('btnConfirmDelete').dataset.id = assignId;
    document.getElementById('modalDeleteBody').innerHTML = `
        <div class="alert alert-danger mb-0">
            <p class="mb-1">Bạn có chắc ch��n muốn xoá KPI của:</p>
            <strong class="fs-6">${name}</strong>
            ${hasResult ? `<div class="mt-2 text-danger fw-bold">
                <i class="fas fa-exclamation-triangle me-1"></i>
                Nhân viên này đã có kết quả KPI — xoá sẽ mất cả kết quả!
            </div>` : ''}
        </div>
    `;
    bootstrap.Modal.getOrCreateInstance(
        document.getElementById('modalDeleteKpi')
    ).show();
}

document.getElementById('btnConfirmDelete').addEventListener('click', function () {
    const assignId = this.dataset.id;
    const btn      = this;
    btn.disabled   = true;
    btn.innerHTML  = '<i class="fas fa-spinner fa-spin me-1"></i>Đang xoá...';

    const fd = new FormData();
    fd.append('csrf_token', '<?= $csrf ?>');
    fd.append('assign_id',  assignId);

    fetch('/ntn_erp/api/kpi/delete_assign.php', {
        method: 'POST', body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.ok) location.reload();
        else alert('Lỗi: ' + res.msg);
    })
    .catch(() => alert('Có lỗi xảy ra!'))
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash me-1"></i>Xoá KPI';
    });
});
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/includes/footer.php'; ?>