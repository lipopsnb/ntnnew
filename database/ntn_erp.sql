CREATE DATABASE IF NOT EXISTS ntn_erp
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
USE ntn_erp;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    permissions_json JSON DEFAULT NULL,
    UNIQUE KEY uk_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uk_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(30) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    hire_date DATE DEFAULT NULL,
    salary_base DECIMAL(15,2) NOT NULL DEFAULT 0,
    bank_account VARCHAR(50) DEFAULT NULL,
    bank_name VARCHAR(150) DEFAULT NULL,
    avatar VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_users_employee_code (employee_code),
    UNIQUE KEY uk_users_username (username),
    UNIQUE KEY uk_users_email (email),
    KEY idx_users_role_id (role_id),
    KEY idx_users_department_id (department_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shifts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    time_start TIME NOT NULL,
    time_end TIME NOT NULL,
    break_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    working_hours DECIMAL(4,2) NOT NULL DEFAULT 0,
    color VARCHAR(20) DEFAULT '#0f3460',
    UNIQUE KEY uk_shifts_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shift_assigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    shift_id INT UNSIGNED NOT NULL,
    assign_date DATE NOT NULL,
    UNIQUE KEY uk_shift_assign_user_date (user_id, assign_date),
    KEY idx_shift_assign_shift_id (shift_id),
    CONSTRAINT fk_shift_assign_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_shift_assign_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    check_in DATETIME DEFAULT NULL,
    check_out DATETIME DEFAULT NULL,
    shift_id INT UNSIGNED DEFAULT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'present',
    note VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uk_attendance_user_date (user_id, work_date),
    KEY idx_attendance_shift_id (shift_id),
    CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_attendance_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    max_days_per_year DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_paid TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uk_leave_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    leave_type_id INT UNSIGNED NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    days DECIMAL(5,2) NOT NULL DEFAULT 0,
    reason TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    KEY idx_leave_requests_user_id (user_id),
    KEY idx_leave_requests_leave_type_id (leave_type_id),
    KEY idx_leave_requests_status (status),
    KEY idx_leave_requests_approved_by (approved_by),
    CONSTRAINT fk_leave_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_leave_requests_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_leave_requests_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS overtime_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ot_date DATE NOT NULL,
    time_start TIME NOT NULL,
    time_end TIME NOT NULL,
    hours DECIMAL(5,2) NOT NULL DEFAULT 0,
    reason TEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    KEY idx_overtime_requests_user_id (user_id),
    KEY idx_overtime_requests_status (status),
    KEY idx_overtime_requests_approved_by (approved_by),
    CONSTRAINT fk_overtime_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_overtime_requests_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS holidays (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    date DATE NOT NULL,
    is_recurring TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uk_holidays_date_name (date, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_periods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_month TINYINT UNSIGNED NOT NULL,
    period_year SMALLINT UNSIGNED NOT NULL,
    working_days DECIMAL(5,2) NOT NULL DEFAULT 0,
    status ENUM('draft','submitted','approved','paid') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED DEFAULT NULL,
    submitted_at DATETIME DEFAULT NULL,
    approved_by INT UNSIGNED DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    UNIQUE KEY uk_payroll_period (period_month, period_year),
    KEY idx_payroll_periods_created_by (created_by),
    KEY idx_payroll_periods_approved_by (approved_by),
    CONSTRAINT fk_payroll_periods_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_payroll_periods_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_slips (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    period_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
    working_days_actual DECIMAL(5,2) NOT NULL DEFAULT 0,
    ot_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
    ot_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    allowances DECIMAL(15,2) NOT NULL DEFAULT 0,
    deductions DECIMAL(15,2) NOT NULL DEFAULT 0,
    net_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uk_payroll_slip_period_user (period_id, user_id),
    KEY idx_payroll_slips_user_id (user_id),
    CONSTRAINT fk_payroll_slips_period FOREIGN KEY (period_id) REFERENCES payroll_periods(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_payroll_slips_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    contact_person VARCHAR(100) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    tax_code VARCHAR(50) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_customers_code (code),
    UNIQUE KEY uk_customers_tax_code (tax_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    unit VARCHAR(30) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_product_codes_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    product_code_id INT UNSIGNED NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    effective_date DATE NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uk_prices_customer_product_date (customer_id, product_code_id, effective_date),
    CONSTRAINT fk_prices_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_prices_product_code FOREIGN KEY (product_code_id) REFERENCES product_codes(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_code VARCHAR(30) NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    received_date DATE NOT NULL,
    due_date DATE DEFAULT NULL,
    note TEXT DEFAULT NULL,
    status ENUM('draft','in_progress','done','delivered','cancelled') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_job_orders_job_code (job_code),
    KEY idx_job_orders_customer_id (customer_id),
    KEY idx_job_orders_created_by (created_by),
    CONSTRAINT fk_job_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_job_orders_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_order_id BIGINT UNSIGNED NOT NULL,
    product_code_id INT UNSIGNED NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    qty_received DECIMAL(12,2) NOT NULL DEFAULT 0,
    qty_ok DECIMAL(12,2) NOT NULL DEFAULT 0,
    qty_ng DECIMAL(12,2) NOT NULL DEFAULT 0,
    qty_returned DECIMAL(12,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL,
    KEY idx_job_order_items_job_order_id (job_order_id),
    KEY idx_job_order_items_product_code_id (product_code_id),
    CONSTRAINT fk_job_order_items_job_order FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_job_order_items_product_code FOREIGN KEY (product_code_id) REFERENCES product_codes(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouse_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_code VARCHAR(30) NOT NULL,
    job_order_id BIGINT UNSIGNED NOT NULL,
    receipt_date DATE NOT NULL,
    received_by INT UNSIGNED DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_warehouse_receipts_code (receipt_code),
    KEY idx_warehouse_receipts_job_order_id (job_order_id),
    KEY idx_warehouse_receipts_received_by (received_by),
    CONSTRAINT fk_warehouse_receipts_job_order FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_warehouse_receipts_received_by FOREIGN KEY (received_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouse_receipt_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_id BIGINT UNSIGNED NOT NULL,
    product_code_id INT UNSIGNED NOT NULL,
    qty DECIMAL(12,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL,
    KEY idx_warehouse_receipt_items_receipt_id (receipt_id),
    KEY idx_warehouse_receipt_items_product_code_id (product_code_id),
    CONSTRAINT fk_warehouse_receipt_items_receipt FOREIGN KEY (receipt_id) REFERENCES warehouse_receipts(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_warehouse_receipt_items_product_code FOREIGN KEY (product_code_id) REFERENCES product_codes(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouse_outputs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    output_code VARCHAR(30) NOT NULL,
    job_order_id BIGINT UNSIGNED NOT NULL,
    output_date DATE NOT NULL,
    output_by INT UNSIGNED DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_warehouse_outputs_code (output_code),
    KEY idx_warehouse_outputs_job_order_id (job_order_id),
    KEY idx_warehouse_outputs_output_by (output_by),
    CONSTRAINT fk_warehouse_outputs_job_order FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_warehouse_outputs_output_by FOREIGN KEY (output_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warehouse_output_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    output_id BIGINT UNSIGNED NOT NULL,
    job_order_item_id BIGINT UNSIGNED DEFAULT NULL,
    product_code_id INT UNSIGNED NOT NULL,
    qty_ok DECIMAL(12,2) NOT NULL DEFAULT 0,
    qty_ng DECIMAL(12,2) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL,
    KEY idx_warehouse_output_items_output_id (output_id),
    KEY idx_warehouse_output_items_job_order_item_id (job_order_item_id),
    KEY idx_warehouse_output_items_product_code_id (product_code_id),
    CONSTRAINT fk_warehouse_output_items_output FOREIGN KEY (output_id) REFERENCES warehouse_outputs(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_warehouse_output_items_job_order_item FOREIGN KEY (job_order_item_id) REFERENCES job_order_items(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_warehouse_output_items_product_code FOREIGN KEY (product_code_id) REFERENCES product_codes(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_code VARCHAR(30) NOT NULL,
    job_order_id BIGINT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    delivery_date DATE NOT NULL,
    recipient_name VARCHAR(150) DEFAULT NULL,
    driver VARCHAR(100) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    photo_url VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_deliveries_code (delivery_code),
    KEY idx_deliveries_job_order_id (job_order_id),
    KEY idx_deliveries_customer_id (customer_id),
    KEY idx_deliveries_created_by (created_by),
    CONSTRAINT fk_deliveries_job_order FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_deliveries_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_deliveries_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NOT NULL,
    job_order_item_id BIGINT UNSIGNED DEFAULT NULL,
    product_code_id INT UNSIGNED NOT NULL,
    qty_delivered DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    KEY idx_delivery_items_delivery_id (delivery_id),
    KEY idx_delivery_items_job_order_item_id (job_order_item_id),
    KEY idx_delivery_items_product_code_id (product_code_id),
    CONSTRAINT fk_delivery_items_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_delivery_items_job_order_item FOREIGN KEY (job_order_item_id) REFERENCES job_order_items(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_delivery_items_product_code FOREIGN KEY (product_code_id) REFERENCES product_codes(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_code VARCHAR(30) NOT NULL,
    delivery_id BIGINT UNSIGNED DEFAULT NULL,
    customer_id INT UNSIGNED NOT NULL,
    invoice_date DATE NOT NULL,
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
    due_date DATE DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_invoices_code (invoice_code),
    KEY idx_invoices_delivery_id (delivery_id),
    KEY idx_invoices_customer_id (customer_id),
    KEY idx_invoices_created_by (created_by),
    CONSTRAINT fk_invoices_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_invoices_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    method ENUM('tien_mat','chuyen_khoan') NOT NULL,
    reference VARCHAR(100) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_invoice_payments_invoice_id (invoice_id),
    KEY idx_invoice_payments_created_by (created_by),
    CONSTRAINT fk_invoice_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_invoice_payments_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_code VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    group_type ENUM('fixed_asset','consumable','vehicle') NOT NULL,
    category VARCHAR(100) DEFAULT NULL,
    unit VARCHAR(30) DEFAULT NULL,
    purchase_date DATE DEFAULT NULL,
    purchase_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    supplier VARCHAR(150) DEFAULT NULL,
    location VARCHAR(150) DEFAULT NULL,
    status ENUM('active','maintenance','broken','disposed') NOT NULL DEFAULT 'active',
    qty_current DECIMAL(12,2) NOT NULL DEFAULT 0,
    qty_min DECIMAL(12,2) NOT NULL DEFAULT 0,
    license_plate VARCHAR(30) DEFAULT NULL,
    km_current INT UNSIGNED DEFAULT NULL,
    registration_exp DATE DEFAULT NULL,
    insurance_exp DATE DEFAULT NULL,
    next_maintenance DATE DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_assets_asset_code (asset_code),
    UNIQUE KEY uk_assets_license_plate (license_plate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    assigned_date DATE NOT NULL,
    returned_date DATE DEFAULT NULL,
    condition_out VARCHAR(255) DEFAULT NULL,
    condition_in VARCHAR(255) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    KEY idx_asset_assignments_asset_id (asset_id),
    KEY idx_asset_assignments_user_id (user_id),
    KEY idx_asset_assignments_created_by (created_by),
    CONSTRAINT fk_asset_assignments_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_asset_assignments_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_asset_assignments_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asset_inventory (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_date DATE NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    actual_status ENUM('good','damaged','missing') NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    checked_by INT UNSIGNED DEFAULT NULL,
    KEY idx_asset_inventory_asset_id (asset_id),
    KEY idx_asset_inventory_checked_by (checked_by),
    CONSTRAINT fk_asset_inventory_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_asset_inventory_checked_by FOREIGN KEY (checked_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id BIGINT UNSIGNED NOT NULL,
    maintenance_date DATE NOT NULL,
    type ENUM('preventive','corrective') NOT NULL,
    description TEXT NOT NULL,
    cost DECIMAL(15,2) NOT NULL DEFAULT 0,
    performed_by VARCHAR(150) DEFAULT NULL,
    next_date DATE DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_maintenance_logs_asset_id (asset_id),
    KEY idx_maintenance_logs_created_by (created_by),
    CONSTRAINT fk_maintenance_logs_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_maintenance_logs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consumable_in (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id BIGINT UNSIGNED NOT NULL,
    in_date DATE NOT NULL,
    qty DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    supplier VARCHAR(150) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_consumable_in_asset_id (asset_id),
    KEY idx_consumable_in_created_by (created_by),
    CONSTRAINT fk_consumable_in_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_consumable_in_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consumable_out (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id BIGINT UNSIGNED NOT NULL,
    out_date DATE NOT NULL,
    qty DECIMAL(12,2) NOT NULL DEFAULT 0,
    purpose VARCHAR(255) DEFAULT NULL,
    used_by INT UNSIGNED DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_consumable_out_asset_id (asset_id),
    KEY idx_consumable_out_used_by (used_by),
    KEY idx_consumable_out_created_by (created_by),
    CONSTRAINT fk_consumable_out_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_consumable_out_used_by FOREIGN KEY (used_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_consumable_out_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id BIGINT UNSIGNED NOT NULL,
    use_date DATE NOT NULL,
    km_start INT UNSIGNED NOT NULL,
    km_end INT UNSIGNED NOT NULL,
    destination VARCHAR(255) DEFAULT NULL,
    driver_id INT UNSIGNED DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    KEY idx_vehicle_logs_asset_id (asset_id),
    KEY idx_vehicle_logs_driver_id (driver_id),
    KEY idx_vehicle_logs_created_by (created_by),
    CONSTRAINT fk_vehicle_logs_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_logs_driver FOREIGN KEY (driver_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_vehicle_logs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_vehicle_logs_km CHECK (km_end >= km_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_expenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id BIGINT UNSIGNED NOT NULL,
    expense_date DATE NOT NULL,
    type ENUM('xang','dau','bao_duong','sua_chua','dangkiem','baohiem','khac') NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    km_current INT UNSIGNED DEFAULT NULL,
    vendor VARCHAR(150) DEFAULT NULL,
    receipt_photo VARCHAR(255) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_vehicle_expenses_asset_id (asset_id),
    KEY idx_vehicle_expenses_created_by (created_by),
    CONSTRAINT fk_vehicle_expenses_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_expenses_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uk_expense_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(30) NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    requester_id INT UNSIGNED NOT NULL,
    request_date DATE NOT NULL,
    amount_requested DECIMAL(15,2) NOT NULL DEFAULT 0,
    purpose TEXT DEFAULT NULL,
    attachment VARCHAR(255) DEFAULT NULL,
    status ENUM('draft','submitted','approved','rejected','paid','refunded') NOT NULL DEFAULT 'draft',
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_expense_requests_request_code (request_code),
    KEY idx_expense_requests_category_id (category_id),
    KEY idx_expense_requests_requester_id (requester_id),
    CONSTRAINT fk_expense_requests_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_expense_requests_requester FOREIGN KEY (requester_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_id BIGINT UNSIGNED NOT NULL,
    approver_id INT UNSIGNED NOT NULL,
    approved_at DATETIME DEFAULT NULL,
    decision ENUM('approved','rejected') NOT NULL,
    comment VARCHAR(255) DEFAULT NULL,
    KEY idx_expense_approvals_expense_id (expense_id),
    KEY idx_expense_approvals_approver_id (approver_id),
    CONSTRAINT fk_expense_approvals_expense FOREIGN KEY (expense_id) REFERENCES expense_requests(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_expense_approvals_approver FOREIGN KEY (approver_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_id BIGINT UNSIGNED NOT NULL,
    payment_date DATE NOT NULL,
    amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
    method ENUM('tien_mat','chuyen_khoan') NOT NULL,
    paid_by INT UNSIGNED DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_expense_payments_expense_id (expense_id),
    KEY idx_expense_payments_paid_by (paid_by),
    CONSTRAINT fk_expense_payments_expense FOREIGN KEY (expense_id) REFERENCES expense_requests(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_expense_payments_paid_by FOREIGN KEY (paid_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_refunds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_id BIGINT UNSIGNED NOT NULL,
    refund_date DATE NOT NULL,
    amount_refund DECIMAL(15,2) NOT NULL DEFAULT 0,
    reason VARCHAR(255) DEFAULT NULL,
    refunded_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_expense_refunds_expense_id (expense_id),
    KEY idx_expense_refunds_refunded_by (refunded_by),
    CONSTRAINT fk_expense_refunds_expense FOREIGN KEY (expense_id) REFERENCES expense_requests(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_expense_refunds_refunded_by FOREIGN KEY (refunded_by) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO roles (name, display_name, permissions_json)
VALUES
    ('director', 'Giám đốc', JSON_ARRAY('all')),
    ('accountant', 'Kế toán', JSON_ARRAY('payroll', 'finance', 'invoices', 'expenses')),
    ('manager', 'Quản lý', JSON_ARRAY('operations', 'production', 'warehouse', 'approvals')),
    ('production', 'Sản xuất', JSON_ARRAY('production', 'attendance', 'warehouse')),
    ('warehouse', 'Thủ kho', JSON_ARRAY('warehouse', 'deliveries', 'inventory')),
    ('employee', 'Nhân viên', JSON_ARRAY('attendance', 'leave', 'overtime', 'expenses'))
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name),
    permissions_json = VALUES(permissions_json);

INSERT INTO departments (name, description)
VALUES
    ('Ban Giám Đốc', 'Điều hành và phê duyệt hoạt động chung của công ty'),
    ('Kế Toán', 'Quản lý tài chính, công nợ và tiền lương'),
    ('Sản Xuất', 'Thực hiện gia công và kiểm soát tiến độ sản xuất'),
    ('Kho', 'Quản lý nhập xuất tồn kho và giao nhận hàng')
ON DUPLICATE KEY UPDATE
    description = VALUES(description);

INSERT INTO users (
    employee_code,
    full_name,
    username,
    password_hash,
    role_id,
    department_id,
    phone,
    email,
    hire_date,
    salary_base,
    bank_account,
    bank_name,
    avatar,
    is_active
)
SELECT
    'ADM001',
    'Quản trị hệ thống',
    'admin',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    r.id,
    d.id,
    '0900000000',
    'admin@ntnvietnam.vn',
    '2025-01-01',
    0,
    '0000000000',
    'Vietcombank',
    NULL,
    1
FROM roles r
CROSS JOIN departments d
WHERE r.name = 'director'
  AND d.name = 'Ban Giám Đốc'
  AND NOT EXISTS (
      SELECT 1 FROM users WHERE username = 'admin'
  );

INSERT INTO shifts (name, time_start, time_end, break_minutes, working_hours, color)
VALUES
    ('Ca sáng', '06:00:00', '14:00:00', 30, 7.50, '#0f3460'),
    ('Ca chiều', '14:00:00', '22:00:00', 30, 7.50, '#e94560'),
    ('Hành chính', '08:00:00', '17:00:00', 60, 8.00, '#16213e')
ON DUPLICATE KEY UPDATE
    time_start = VALUES(time_start),
    time_end = VALUES(time_end),
    break_minutes = VALUES(break_minutes),
    working_hours = VALUES(working_hours),
    color = VALUES(color);

INSERT INTO customers (code, name, phone, address, contact_person, email, tax_code, note, is_active)
VALUES
    ('CUS001', 'Công ty TNHH Cơ Khí Minh Phát', '02838260001', 'KCN Tân Bình, TP. Hồ Chí Minh', 'Nguyễn Văn Phát', 'minhphat@co-khi.vn', '0312345678', 'Khách hàng gia công chi tiết cơ khí chính xác', 1),
    ('CUS002', 'Công ty CP Thiết Bị Đông Á', '02743880022', 'VSIP 1, Bình Dương', 'Trần Thị Lan', 'donga@thietbi.vn', '3701122334', 'Đơn vị cung cấp thiết bị công nghiệp', 1),
    ('CUS003', 'Công ty TNHH Nhật Thành Precision', '02253990033', 'KCN Nomura, Hải Phòng', 'Lê Quang Nhật', 'sales@nhatthanh.vn', '0209988776', 'Khách hàng xuất khẩu linh kiện tiện CNC', 1),
    ('CUS004', 'Công ty TNHH Sản Xuất Kim Long', '02513660044', 'KCN Amata, Đồng Nai', 'Phạm Mỹ Dung', 'contact@kimlong.vn', '3604455667', 'Đối tác lâu năm trong ngành linh kiện kim loại', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    phone = VALUES(phone),
    address = VALUES(address),
    contact_person = VALUES(contact_person),
    email = VALUES(email),
    note = VALUES(note),
    is_active = VALUES(is_active);

INSERT INTO product_codes (code, name, unit, description, is_active)
VALUES
    ('PRD001', 'Chi tiết tiện CNC', 'cái', 'Chi tiết tiện chính xác cho cụm truyền động', 1),
    ('PRD002', 'Bánh răng thẳng', 'cái', 'Bánh răng thép module 1.5 dùng cho hộp số', 1),
    ('PRD003', 'Trục dẫn động', 'cái', 'Trục truyền động gia công theo bản vẽ khách hàng', 1),
    ('PRD004', 'Bạc lót đồng', 'cái', 'Bạc lót hợp kim đồng chống mài mòn', 1),
    ('PRD005', 'Vòng bi đỡ trục', 'bộ', 'Cụm vòng bi công nghiệp cho dây chuyền máy', 1),
    ('PRD006', 'Mặt bích inox', 'cái', 'Mặt bích inox gia công theo yêu cầu', 1),
    ('PRD007', 'Khớp nối mềm', 'cái', 'Khớp nối mềm giảm rung cho động cơ', 1),
    ('PRD008', 'Thanh dẫn hướng', 'cái', 'Thanh dẫn hướng mạ cứng cho máy công nghiệp', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    unit = VALUES(unit),
    description = VALUES(description),
    is_active = VALUES(is_active);

INSERT INTO leave_types (name, max_days_per_year, is_paid)
VALUES
    ('Nghỉ phép năm', 12, 1),
    ('Nghỉ ốm', 30, 1),
    ('Nghỉ không lương', 365, 0),
    ('Nghỉ thai sản', 180, 1)
ON DUPLICATE KEY UPDATE
    max_days_per_year = VALUES(max_days_per_year),
    is_paid = VALUES(is_paid);

INSERT INTO expense_categories (name, description)
VALUES
    ('Văn phòng phẩm', 'Chi phí giấy, mực in, dụng cụ làm việc văn phòng'),
    ('Điện nước', 'Chi phí điện, nước và tiện ích phục vụ sản xuất'),
    ('Tiếp khách', 'Chi phí tiếp khách, gặp gỡ đối tác và hội họp'),
    ('Vận chuyển', 'Chi phí giao nhận, vận tải và hậu cần'),
    ('Bảo dưỡng', 'Chi phí bảo trì máy móc, thiết bị và phương tiện'),
    ('Khác', 'Các khoản chi phí phát sinh ngoài danh mục chuẩn')
ON DUPLICATE KEY UPDATE
    description = VALUES(description);

INSERT INTO holidays (name, date, is_recurring)
VALUES
    ('Tết Dương lịch 2025', '2025-01-01', 1),
    ('Tết Nguyên đán 2025 - 29 tháng Chạp', '2025-01-28', 0),
    ('Tết Nguyên đán 2025 - Mùng 1', '2025-01-29', 0),
    ('Tết Nguyên đán 2025 - Mùng 2', '2025-01-30', 0),
    ('Tết Nguyên đán 2025 - Mùng 3', '2025-01-31', 0),
    ('Giỗ Tổ Hùng Vương 2025', '2025-04-07', 0),
    ('Ngày Giải phóng miền Nam 2025', '2025-04-30', 1),
    ('Quốc tế Lao động 2025', '2025-05-01', 1),
    ('Quốc khánh 2025', '2025-09-02', 1),
    ('Tết Dương lịch 2026', '2026-01-01', 1),
    ('Tết Nguyên đán 2026 - 30 tháng Chạp', '2026-02-16', 0),
    ('Tết Nguyên đán 2026 - Mùng 1', '2026-02-17', 0),
    ('Tết Nguyên đán 2026 - Mùng 2', '2026-02-18', 0),
    ('Tết Nguyên đán 2026 - Mùng 3', '2026-02-19', 0),
    ('Giỗ Tổ Hùng Vương 2026', '2026-04-25', 0),
    ('Ngày Giải phóng miền Nam 2026', '2026-04-30', 1),
    ('Quốc tế Lao động 2026', '2026-05-01', 1),
    ('Quốc khánh 2026', '2026-09-02', 1)
ON DUPLICATE KEY UPDATE
    is_recurring = VALUES(is_recurring);
