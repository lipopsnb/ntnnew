# 🏭 NTN ERP System

Hệ thống ERP cho CÔNG TY CỔ PHẦN SẢN XUẤT VÀ CUNG ỨNG NTN VIỆT NAM

## Thông tin
- Địa chỉ: Số 36, Xóm Trại, Quan Âm, Xã Phúc Thịnh, Hà Nội
- MST: 0111343796 | Hotline: 0966240297

## Yêu cầu hệ thống
- PHP 7.4+ | MySQL 5.7+ | XAMPP | Bootstrap 5.3

## Cài đặt
1. Clone repo vào `htdocs/ntn_erp/`
2. Import `database/ntn_erp.sql` vào phpMyAdmin
3. Truy cập: http://localhost/ntn_erp/
4. Đăng nhập: `admin` / `Admin@123`

## Cấu trúc modules
- Module 1: Nhân sự (users, attendance, payroll)
- Module 2: Sản xuất (master, production, delivery, invoice)
- Module 3: Hành chính (assets, maintenance, vehicles, expenses)

## Phân quyền
| Role | Mô tả |
|------|-------|
| director | Giám đốc - toàn quyền |
| accountant | Kế toán |
| manager | Quản lý sản xuất |
| production | Nhân viên sản xuất |
| warehouse | Thủ kho |
| employee | Nhân viên |
