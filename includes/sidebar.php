<?php
$currentPage = $_SERVER['REQUEST_URI'];
function isActive($path) {
    global $currentPage;
    return strpos($currentPage, $path) !== false ? 'active' : '';
}
$sidebarUser = currentUser();
?>
<div class="sidebar" id="sidebar">
    <ul class="nav flex-column pt-2">

        <!-- TỔNG QUAN -->
        <li class="nav-item">
            <a class="nav-link <?= isActive('/dashboard') ?>" href="/ntn_erp/dashboard.php">
                <i class="fas fa-home"></i> <span>Tổng quan</span>
            </a>
        </li>

        <!-- ==================== CÁ NHÂN (tất cả đều thấy) ==================== -->
        <li class="nav-section">CÁ NHÂN</li>

        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/users/profile') ?>"
               href="/ntn_erp/modules/users/profile.php?id=<?= $sidebarUser['id'] ?>">
                <i class="fas fa-id-card"></i> <span>Hồ sơ của tôi</span>
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/users/change_password') ?>"
               href="/ntn_erp/modules/users/change_password.php?id=<?= $sidebarUser['id'] ?>">
                <i class="fas fa-key"></i> <span>Đổi mật khẩu</span>
            </a>
        </li>

        <!-- ==================== CHẤM CÔNG ==================== -->
        <li class="nav-section">CHẤM CÔNG</li>

        <li class="nav-item">
            <a class="nav-link <?= isActive('/attendance/index') ?>"
               href="/ntn_erp/modules/attendance/index.php">
                <i class="fas fa-calendar-check"></i> <span>Lịch chấm công</span>
            </a>
        </li>

        <?php if (hasRole('employee', 'production', 'warehouse')): ?>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/leave_request') ?>"
               href="/ntn_erp/modules/attendance/leave_request.php">
                <i class="fas fa-calendar-minus"></i> <span>Xin nghỉ phép</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/ot_request') ?>"
               href="/ntn_erp/modules/attendance/ot_request.php">
                <i class="fas fa-clock"></i> <span>Đăng ký OT</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasRole('production', 'manager', 'director', 'accountant')): ?>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/all_attendance') ?>"
               href="/ntn_erp/modules/attendance/all_attendance.php">
                <i class="fas fa-table"></i> <span>Bảng chấm công</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/leave_manage') ?>"
               href="/ntn_erp/modules/attendance/leave_manage.php">
                <i class="fas fa-clipboard-check"></i>
                <span>Duyệt nghỉ phép</span>
                <span class="badge bg-warning text-dark ms-1" id="sidebarLeaveCount"></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/ot_manage') ?>"
               href="/ntn_erp/modules/attendance/ot_manage.php">
                <i class="fas fa-user-clock"></i>
                <span>Duyệt OT</span>
                <span class="badge bg-info ms-1" id="sidebarOTCount"></span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasRole('director', 'accountant', 'manager', 'production')): ?>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/shift_schedule') ?>"
               href="/ntn_erp/modules/attendance/shift_schedule.php">
                <i class="fas fa-calendar-alt"></i> <span>Lịch ca tháng</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/shift_assign') ?>"
               href="/ntn_erp/modules/attendance/shift_assign.php">
                <i class="fas fa-users-cog"></i> <span>Phân công ca</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (hasRole('director', 'accountant', 'manager')): ?>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/shift_setup') ?>"
               href="/ntn_erp/modules/attendance/shift_setup.php">
                <i class="fas fa-sliders-h"></i> <span>Setup ca làm việc</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ==================== BẢNG LƯƠNG ==================== -->
        <?php if (hasRole('director', 'accountant', 'manager')): ?>
        <li class="nav-section">BẢNG LƯƠNG</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/payroll/index') ?>"
               href="/ntn_erp/modules/payroll/index.php">
                <i class="fas fa-money-check-alt"></i> <span>Quản lý kỳ lương</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/payroll/holidays') ?>"
               href="/ntn_erp/modules/payroll/holidays.php">
                <i class="fas fa-calendar-times"></i> <span>Ngày lễ</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (!hasRole('director', 'accountant', 'manager')): ?>
        <li class="nav-section">BẢNG LƯƠNG</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/payroll/my_payroll') ?>"
               href="/ntn_erp/modules/payroll/my_payroll.php">
                <i class="fas fa-file-invoice-dollar"></i> <span>Phiếu lương của tôi</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ==================== KHO & SẢN XUẤT ==================== -->
        <?php if (hasRole('director','accountant','warehouse','production','manager')): ?>
        <li class="nav-section">KHO & SẢN XUẤT</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/warehouse/') ?>"
               href="/ntn_erp/modules/warehouse/index.php">
                <i class="fas fa-boxes"></i> <span>Quản lý kho</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/warehouse/receipt') ?>"
               href="/ntn_erp/modules/warehouse/receipt.php">
                <i class="fas fa-arrow-down"></i> <span>Nhập kho</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/warehouse/output') ?>"
               href="/ntn_erp/modules/warehouse/output.php">
                <i class="fas fa-arrow-up"></i> <span>Xuất kho</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/production/') ?>"
               href="/ntn_erp/modules/production/index.php">
                <i class="fas fa-industry"></i> <span>Sản xuất</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/delivery/') ?>"
               href="/ntn_erp/modules/delivery/index.php">
                <i class="fas fa-truck"></i> <span>Giao hàng</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ==================== HÓA ĐƠN & CÔNG NỢ ==================== -->
        <?php if (hasRole('director','accountant','manager')): ?>
        <li class="nav-section">HÓA ĐƠN & CÔNG NỢ</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/invoice/') ?>"
               href="/ntn_erp/modules/invoice/index.php">
                <i class="fas fa-file-invoice"></i> <span>Hóa đơn</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/invoice/payments') ?>"
               href="/ntn_erp/modules/invoice/payments.php">
                <i class="fas fa-hand-holding-usd"></i> <span>Thu tiền</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ==================== DANH MỤC ==================== -->
        <?php if (hasRole('director','accountant','manager')): ?>
        <li class="nav-section">DANH MỤC</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/master/customers') ?>"
               href="/ntn_erp/modules/master/customers.php">
                <i class="fas fa-building"></i> <span>Khách hàng</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/master/product_codes') ?>"
               href="/ntn_erp/modules/master/product_codes.php">
                <i class="fas fa-barcode"></i> <span>Mã sản phẩm</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/master/prices') ?>"
               href="/ntn_erp/modules/master/prices.php">
                <i class="fas fa-tags"></i> <span>Bảng giá</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ==================== KPI SẢN XUẤT ==================== -->
<?php if (hasRole('director', 'accountant', 'manager', 'warehouse', 'production')): ?>
<li class="nav-section">KPI SẢN XUẤT</li>
<li class="nav-item">
    <a class="nav-link <?= isActive('/modules/kpi/assign') ?>"
       href="/ntn_erp/modules/kpi/assign.php">
        <i class="fas fa-tasks"></i> <span>Phân bổ KPI</span>
    </a>
</li>
<li class="nav-item">
    <a class="nav-link <?= isActive('/modules/kpi/result') ?>"
       href="/ntn_erp/modules/kpi/result.php">
        <i class="fas fa-clipboard-check"></i> <span>Kết quả KPI</span>
        <span class="badge bg-warning text-dark ms-1" id="sidebarKpiCount"></span>
    </a>
</li>
<?php endif; ?>

        <!-- ==================== QUẢN LÝ HỆ THỐNG ==================== -->
        <?php if (hasRole('director', 'accountant')): ?>
        <li class="nav-section">QUẢN LÝ HỆ THỐNG</li>
        <li class="nav-item">
            <a class="nav-link <?= isActive('/modules/users/index') ?>"
               href="/ntn_erp/modules/users/index.php">
                <i class="fas fa-users-cog"></i> <span>Quản lý tài khoản</span>
            </a>
        </li>
        <?php endif; ?>

    </ul>
</div>