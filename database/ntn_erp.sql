SET FOREIGN_KEY_CHECKS=0;

CREATE DATABASE IF NOT EXISTS `ntn_erp` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ntn_erp`;

DROP TABLE IF EXISTS `cost_entries`;
DROP TABLE IF EXISTS `production_stock`;
DROP TABLE IF EXISTS `warehouse_stock_log`;
DROP TABLE IF EXISTS `warehouse_stock`;
DROP TABLE IF EXISTS `debt_payments`;
DROP TABLE IF EXISTS `debt_tracking`;
DROP TABLE IF EXISTS `invoice_items`;
DROP TABLE IF EXISTS `invoice_delivery_notes`;
DROP TABLE IF EXISTS `invoices`;
DROP TABLE IF EXISTS `delivery_note_items`;
DROP TABLE IF EXISTS `delivery_notes`;
DROP TABLE IF EXISTS `production_outputs`;
DROP TABLE IF EXISTS `production_receipts`;
DROP TABLE IF EXISTS `warehouse_imports`;
DROP TABLE IF EXISTS `product_prices`;
DROP TABLE IF EXISTS `product_codes`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `attendance_audit_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `kpi_results`;
DROP TABLE IF EXISTS `kpi_assignments`;
DROP TABLE IF EXISTS `employee_salaries`;
DROP TABLE IF EXISTS `salary_components`;
DROP TABLE IF EXISTS `employee_profiles`;
DROP TABLE IF EXISTS `payroll_slips`;
DROP TABLE IF EXISTS `payroll_periods`;
DROP TABLE IF EXISTS `holidays`;
DROP TABLE IF EXISTS `overtime_requests`;
DROP TABLE IF EXISTS `leave_requests`;
DROP TABLE IF EXISTS `attendance_logs`;
DROP TABLE IF EXISTS `shift_schedules`;
DROP TABLE IF EXISTS `employee_shifts`;
DROP TABLE IF EXISTS `work_shifts`;
DROP TABLE IF EXISTS `document_sequences`;
DROP TABLE IF EXISTS `communes`;
DROP TABLE IF EXISTS `districts`;
DROP TABLE IF EXISTS `provinces`;
DROP TABLE IF EXISTS `ethnicities`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `departments`;
DROP TABLE IF EXISTS `roles`;

CREATE TABLE `roles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(50) NOT NULL,
    `display_name` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `departments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `employee_code` VARCHAR(50) NOT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `username` VARCHAR(100) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `phone` VARCHAR(30) DEFAULT NULL,
    `role_id` INT UNSIGNED NOT NULL,
    `department_id` INT UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_employee_code` (`employee_code`),
    UNIQUE KEY `uk_users_username` (`username`),
    UNIQUE KEY `uk_users_email` (`email`),
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ethnicities` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ethnicities_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `provinces` (
    `code` VARCHAR(20) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `name_en` VARCHAR(100) DEFAULT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `districts` (
    `code` VARCHAR(20) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `province_code` VARCHAR(20) NOT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    PRIMARY KEY (`code`),
    KEY `idx_districts_province_code` (`province_code`),
    CONSTRAINT `fk_districts_province` FOREIGN KEY (`province_code`) REFERENCES `provinces` (`code`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `communes` (
    `code` VARCHAR(20) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `district_code` VARCHAR(20) NOT NULL,
    `full_name` VARCHAR(150) NOT NULL,
    PRIMARY KEY (`code`),
    KEY `idx_communes_district_code` (`district_code`),
    CONSTRAINT `fk_communes_district` FOREIGN KEY (`district_code`) REFERENCES `districts` (`code`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_sequences` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `doc_type` VARCHAR(20) NOT NULL,
    `doc_date` DATE NOT NULL,
    `last_seq` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_document_sequences_type_date` (`doc_type`, `doc_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `work_shifts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `shift_code` VARCHAR(30) NOT NULL,
    `shift_name` VARCHAR(100) NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `late_threshold` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `break_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `work_hours` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `ot_multiplier` DECIMAL(6,2) NOT NULL DEFAULT 1.50,
    `weekend_multiplier` DECIMAL(6,2) NOT NULL DEFAULT 2.00,
    `holiday_multiplier` DECIMAL(6,2) NOT NULL DEFAULT 3.00,
    `color` VARCHAR(20) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_work_shifts_code` (`shift_code`),
    CONSTRAINT `fk_work_shifts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `employee_shifts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `shift_id` INT UNSIGNED NOT NULL,
    `effective_date` DATE NOT NULL,
    `end_date` DATE DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_employee_shifts_user` (`user_id`),
    KEY `idx_employee_shifts_shift` (`shift_id`),
    CONSTRAINT `fk_employee_shifts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_employee_shifts_shift` FOREIGN KEY (`shift_id`) REFERENCES `work_shifts` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_employee_shifts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `shift_schedules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `shift_id` INT UNSIGNED NOT NULL,
    `work_date` DATE NOT NULL,
    `note` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_shift_schedules_user_date` (`user_id`, `work_date`),
    KEY `idx_shift_schedules_shift` (`shift_id`),
    CONSTRAINT `fk_shift_schedules_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_shift_schedules_shift` FOREIGN KEY (`shift_id`) REFERENCES `work_shifts` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_shift_schedules_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `check_in` DATETIME DEFAULT NULL,
    `check_out` DATETIME DEFAULT NULL,
    `work_date` DATE NOT NULL,
    `shift_id` INT UNSIGNED DEFAULT NULL,
    `work_hours` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `source` ENUM('machine', 'manual', 'system') NOT NULL DEFAULT 'manual',
    `note` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `is_late` TINYINT(1) NOT NULL DEFAULT 0,
    `late_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `early_leave` TINYINT(1) NOT NULL DEFAULT 0,
    `early_leave_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_attendance_logs_user_date` (`user_id`, `work_date`),
    KEY `idx_attendance_logs_shift` (`shift_id`),
    CONSTRAINT `fk_attendance_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_logs_shift` FOREIGN KEY (`shift_id`) REFERENCES `work_shifts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leave_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `leave_type` ENUM('annual', 'sick', 'unpaid', 'other') NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `total_days` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `reason` TEXT DEFAULT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `reject_reason` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_leave_requests_user` (`user_id`),
    KEY `idx_leave_requests_approved_by` (`approved_by`),
    CONSTRAINT `fk_leave_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_leave_requests_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `overtime_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `department_id` INT UNSIGNED DEFAULT NULL,
    `ot_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `hours` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `actual_hours` DECIMAL(5,2) DEFAULT NULL,
    `reason` TEXT DEFAULT NULL,
    `ot_type` ENUM('weekday', 'weekend', 'holiday') NOT NULL DEFAULT 'weekday',
    `shift_id` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `reject_reason` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_overtime_requests_user` (`user_id`),
    KEY `idx_overtime_requests_department` (`department_id`),
    KEY `idx_overtime_requests_shift` (`shift_id`),
    KEY `idx_overtime_requests_approved_by` (`approved_by`),
    CONSTRAINT `fk_overtime_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_overtime_requests_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_overtime_requests_shift` FOREIGN KEY (`shift_id`) REFERENCES `work_shifts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_overtime_requests_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `holidays` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `holiday_date` DATE NOT NULL,
    `holiday_name` VARCHAR(150) NOT NULL,
    `year` SMALLINT UNSIGNED NOT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_holidays_date` (`holiday_date`),
    CONSTRAINT `fk_holidays_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payroll_periods` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `period_year` SMALLINT UNSIGNED NOT NULL,
    `period_month` TINYINT UNSIGNED NOT NULL,
    `period_from` DATE NOT NULL,
    `period_to` DATE NOT NULL,
    `working_days` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft', 'submitted', 'approved', 'locked') NOT NULL DEFAULT 'draft',
    `note` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `submitted_at` DATETIME DEFAULT NULL,
    `submitted_by` INT UNSIGNED DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `approved_by` INT UNSIGNED DEFAULT NULL,
    `locked_at` DATETIME DEFAULT NULL,
    `locked_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_payroll_periods_year_month` (`period_year`, `period_month`),
    CONSTRAINT `fk_payroll_periods_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_payroll_periods_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_payroll_periods_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_payroll_periods_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payroll_slips` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `period_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `basic_salary` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `working_days_standard` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `salary_per_day` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `salary_per_hour` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `actual_workdays` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `paid_leave_days` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `other_paid_leave_days` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `unpaid_leave_days` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `late_early_hours` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `late_early_deduction` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `total_paid_days` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `basic_salary_received` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `meal_allowance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `meal_received` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `clothes_allowance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `clothes_received` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `phone_allowance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `phone_received` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `transport_allowance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `housing_allowance` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `transport_received` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `housing_received` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `performance_bonus` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `basic_salary_per_hour` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `ot_weekday_hours` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `ot_weekend_hours` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `ot_holiday_hours` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `ot_weekday_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `ot_weekend_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `ot_holiday_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `total_ot_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `ot_meal_days` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `ot_meal_bonus` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `kpi_bonus` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `kpi_over_days` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `kpi_under_days` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `annual_leave_total` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `annual_leave_used` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `annual_leave_remaining` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    `annual_leave_payout` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `other_income` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `adjustment` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `other_bonus` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `attendance_bonus` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `attendance_bonus_eligible` TINYINT(1) NOT NULL DEFAULT 0,
    `has_social_insurance` TINYINT(1) NOT NULL DEFAULT 0,
    `si_employee` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `si_company` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `dependants` INT UNSIGNED NOT NULL DEFAULT 0,
    `personal_deduction` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `dependant_deduction` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `ot_exclude_pit` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `taxable_income` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `pit_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `late_deduction` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `kpi_deduction` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `gross_salary` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `advance_payment` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `net_salary` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `pit_adjustment` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `bank_transfer` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `remark` VARCHAR(255) DEFAULT NULL,
    `is_late_warning` TINYINT(1) NOT NULL DEFAULT 0,
    `late_warning_note` VARCHAR(255) DEFAULT NULL,
    `manually_adjusted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_payroll_slips_period_user` (`period_id`, `user_id`),
    KEY `idx_payroll_slips_user` (`user_id`),
    CONSTRAINT `fk_payroll_slips_period` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_payroll_slips_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `employee_profiles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `gender` VARCHAR(20) DEFAULT NULL,
    `date_of_birth` DATE DEFAULT NULL,
    `ethnicity` VARCHAR(100) DEFAULT NULL,
    `marital_status` VARCHAR(50) DEFAULT NULL,
    `mobile_phone` VARCHAR(30) DEFAULT NULL,
    `permanent_province` VARCHAR(20) DEFAULT NULL,
    `permanent_district_text` VARCHAR(150) DEFAULT NULL,
    `permanent_commune_text` VARCHAR(150) DEFAULT NULL,
    `permanent_hamlet` VARCHAR(150) DEFAULT NULL,
    `same_as_permanent` TINYINT(1) NOT NULL DEFAULT 1,
    `temp_province` VARCHAR(20) DEFAULT NULL,
    `temp_district_text` VARCHAR(150) DEFAULT NULL,
    `temp_commune_text` VARCHAR(150) DEFAULT NULL,
    `temp_hamlet` VARCHAR(150) DEFAULT NULL,
    `identity_no` VARCHAR(50) DEFAULT NULL,
    `identity_issue_date` DATE DEFAULT NULL,
    `identity_issue_place` VARCHAR(150) DEFAULT NULL,
    `social_book_no` VARCHAR(50) DEFAULT NULL,
    `personal_tax_code` VARCHAR(50) DEFAULT NULL,
    `bank_account` VARCHAR(50) DEFAULT NULL,
    `bank_name` VARCHAR(150) DEFAULT NULL,
    `bank_branch` VARCHAR(150) DEFAULT NULL,
    `dependants` INT UNSIGNED NOT NULL DEFAULT 0,
    `has_social_insurance` TINYINT(1) NOT NULL DEFAULT 0,
    `insurance_from` DATE DEFAULT NULL,
    `date_joined` DATE DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `annual_leave_total` DECIMAL(6,2) NOT NULL DEFAULT 12.00,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_employee_profiles_user` (`user_id`),
    KEY `idx_employee_profiles_permanent_province` (`permanent_province`),
    KEY `idx_employee_profiles_temp_province` (`temp_province`),
    CONSTRAINT `fk_employee_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_employee_profiles_permanent_province` FOREIGN KEY (`permanent_province`) REFERENCES `provinces` (`code`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_employee_profiles_temp_province` FOREIGN KEY (`temp_province`) REFERENCES `provinces` (`code`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `salary_components` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `component_code` VARCHAR(30) NOT NULL,
    `component_name` VARCHAR(150) NOT NULL,
    `component_name_en` VARCHAR(150) DEFAULT NULL,
    `component_type` VARCHAR(50) NOT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_salary_components_code` (`component_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `employee_salaries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `component_id` INT UNSIGNED DEFAULT NULL,
    `custom_name` VARCHAR(150) DEFAULT NULL,
    `custom_name_en` VARCHAR(150) DEFAULT NULL,
    `component_type` ENUM('earning', 'deduction', 'bonus') NOT NULL,
    `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `insurance_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `note` VARCHAR(255) DEFAULT NULL,
    `effective_date` DATE NOT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_employee_salaries_user` (`user_id`),
    KEY `idx_employee_salaries_component` (`component_id`),
    CONSTRAINT `fk_employee_salaries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_employee_salaries_component` FOREIGN KEY (`component_id`) REFERENCES `salary_components` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_employee_salaries_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_employee_salaries_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kpi_assignments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `assign_date` DATE NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `manager_id` INT UNSIGNED DEFAULT NULL,
    `kpi_target` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `over_bonus_pct` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_kpi_assignments_date_user` (`assign_date`, `user_id`),
    KEY `idx_kpi_assignments_manager` (`manager_id`),
    CONSTRAINT `fk_kpi_assignments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_kpi_assignments_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `kpi_results` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kpi_assignment_id` INT UNSIGNED NOT NULL,
    `actual_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `salary_per_day` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `salary_actual` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `is_deducted` TINYINT(1) NOT NULL DEFAULT 0,
    `reason` VARCHAR(255) DEFAULT NULL,
    `confirmed_by` INT UNSIGNED DEFAULT NULL,
    `confirmed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_kpi_results_assignment` (`kpi_assignment_id`),
    KEY `idx_kpi_results_confirmed_by` (`confirmed_by`),
    CONSTRAINT `fk_kpi_results_assignment` FOREIGN KEY (`kpi_assignment_id`) REFERENCES `kpi_assignments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_kpi_results_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `type` VARCHAR(50) DEFAULT NULL,
    `reference_id` INT UNSIGNED DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_user_read` (`user_id`, `is_read`, `created_at`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_audit_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `attendance_log_id` INT UNSIGNED NOT NULL,
    `changed_by` INT UNSIGNED DEFAULT NULL,
    `change_type` VARCHAR(50) NOT NULL,
    `old_check_in` DATETIME DEFAULT NULL,
    `old_check_out` DATETIME DEFAULT NULL,
    `new_check_in` DATETIME DEFAULT NULL,
    `new_check_out` DATETIME DEFAULT NULL,
    `note` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attendance_audit_log_id` (`attendance_log_id`),
    CONSTRAINT `fk_attendance_audit_log_attendance` FOREIGN KEY (`attendance_log_id`) REFERENCES `attendance_logs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_audit_log_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_code` VARCHAR(50) NOT NULL,
    `customer_name` VARCHAR(150) NOT NULL,
    `address` VARCHAR(255) DEFAULT NULL,
    `contact_person` VARCHAR(150) DEFAULT NULL,
    `phone` VARCHAR(30) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_customers_code` (`customer_code`),
    CONSTRAINT `fk_customers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_codes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_code` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `unit` VARCHAR(50) DEFAULT NULL,
    `category` VARCHAR(100) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_product_codes_code` (`product_code`),
    CONSTRAINT `fk_product_codes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_prices` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_code_id` INT UNSIGNED NOT NULL,
    `unit_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `effective_from` DATE NOT NULL,
    `note` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_product_prices_product_code` (`product_code_id`),
    CONSTRAINT `fk_product_prices_product_code` FOREIGN KEY (`product_code_id`) REFERENCES `product_codes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_product_prices_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `warehouse_imports` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `import_no` VARCHAR(50) NOT NULL,
    `import_date` DATE NOT NULL,
    `product_code_id` INT UNSIGNED NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `quantity_sent` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `note` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('pending', 'partial', 'completed') NOT NULL DEFAULT 'pending',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_warehouse_imports_no` (`import_no`),
    KEY `idx_warehouse_imports_product_code` (`product_code_id`),
    CONSTRAINT `fk_warehouse_imports_product_code` FOREIGN KEY (`product_code_id`) REFERENCES `product_codes` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_warehouse_imports_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `production_receipts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `receipt_no` VARCHAR(50) NOT NULL,
    `receipt_date` DATE NOT NULL,
    `warehouse_import_id` INT UNSIGNED NOT NULL,
    `product_code_id` INT UNSIGNED NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `quantity_received` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `note` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_production_receipts_no` (`receipt_no`),
    KEY `idx_production_receipts_import` (`warehouse_import_id`),
    KEY `idx_production_receipts_product` (`product_code_id`),
    CONSTRAINT `fk_production_receipts_import` FOREIGN KEY (`warehouse_import_id`) REFERENCES `warehouse_imports` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_production_receipts_product` FOREIGN KEY (`product_code_id`) REFERENCES `product_codes` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_production_receipts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `production_outputs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `output_no` VARCHAR(50) NOT NULL,
    `output_date` DATE NOT NULL,
    `production_receipt_id` INT UNSIGNED NOT NULL,
    `product_code_id` INT UNSIGNED NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `quantity_completed` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `quantity_defect` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `quantity_delivered` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `note` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_production_outputs_no` (`output_no`),
    KEY `idx_production_outputs_receipt` (`production_receipt_id`),
    KEY `idx_production_outputs_product` (`product_code_id`),
    CONSTRAINT `fk_production_outputs_receipt` FOREIGN KEY (`production_receipt_id`) REFERENCES `production_receipts` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_production_outputs_product` FOREIGN KEY (`product_code_id`) REFERENCES `product_codes` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_production_outputs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `delivery_notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `delivery_no` VARCHAR(50) NOT NULL,
    `delivery_date` DATE NOT NULL,
    `sender_name` VARCHAR(150) DEFAULT NULL,
    `sender_phone` VARCHAR(30) DEFAULT NULL,
    `vehicle_plate` VARCHAR(30) DEFAULT NULL,
    `driver_name` VARCHAR(150) DEFAULT NULL,
    `driver_phone` VARCHAR(30) DEFAULT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('draft', 'confirmed', 'invoiced') NOT NULL DEFAULT 'draft',
    `note` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `receiver_name` VARCHAR(150) DEFAULT NULL,
    `receiver_phone` VARCHAR(30) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_delivery_notes_no` (`delivery_no`),
    KEY `idx_delivery_notes_customer` (`customer_id`),
    CONSTRAINT `fk_delivery_notes_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_delivery_notes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `delivery_note_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `delivery_note_id` INT UNSIGNED NOT NULL,
    `production_output_id` INT UNSIGNED NOT NULL,
    `product_code_id` INT UNSIGNED NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `unit` VARCHAR(50) DEFAULT NULL,
    `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `unit_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `total_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `note` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_delivery_note_items_delivery` (`delivery_note_id`),
    KEY `idx_delivery_note_items_output` (`production_output_id`),
    KEY `idx_delivery_note_items_product` (`product_code_id`),
    CONSTRAINT `fk_delivery_note_items_delivery` FOREIGN KEY (`delivery_note_id`) REFERENCES `delivery_notes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_delivery_note_items_output` FOREIGN KEY (`production_output_id`) REFERENCES `production_outputs` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_delivery_note_items_product` FOREIGN KEY (`product_code_id`) REFERENCES `product_codes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoices` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_no` VARCHAR(50) NOT NULL,
    `invoice_date` DATE NOT NULL,
    `due_date` DATE DEFAULT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `subtotal` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `vat_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `vat_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `note` VARCHAR(255) DEFAULT NULL,
    `delivery_id` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('draft', 'confirmed', 'cancelled') NOT NULL DEFAULT 'draft',
    `created_by` INT UNSIGNED DEFAULT NULL,
    `confirmed_by` INT UNSIGNED DEFAULT NULL,
    `confirmed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_invoices_no` (`invoice_no`),
    KEY `idx_invoices_customer` (`customer_id`),
    KEY `idx_invoices_delivery` (`delivery_id`),
    CONSTRAINT `fk_invoices_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
    CONSTRAINT `fk_invoices_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `delivery_notes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_invoices_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_invoices_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_delivery_notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_id` INT UNSIGNED NOT NULL,
    `delivery_note_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_invoice_delivery_notes_pair` (`invoice_id`, `delivery_note_id`),
    KEY `idx_invoice_delivery_notes_delivery` (`delivery_note_id`),
    CONSTRAINT `fk_invoice_delivery_notes_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_invoice_delivery_notes_delivery` FOREIGN KEY (`delivery_note_id`) REFERENCES `delivery_notes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_id` INT UNSIGNED NOT NULL,
    `delivery_note_id` INT UNSIGNED DEFAULT NULL,
    `delivery_note_item_id` INT UNSIGNED DEFAULT NULL,
    `product_code_id` INT UNSIGNED NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `unit_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id`),
    KEY `idx_invoice_items_invoice` (`invoice_id`),
    KEY `idx_invoice_items_delivery_note` (`delivery_note_id`),
    KEY `idx_invoice_items_delivery_note_item` (`delivery_note_item_id`),
    KEY `idx_invoice_items_product` (`product_code_id`),
    CONSTRAINT `fk_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_invoice_items_delivery_note` FOREIGN KEY (`delivery_note_id`) REFERENCES `delivery_notes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_invoice_items_delivery_note_item` FOREIGN KEY (`delivery_note_item_id`) REFERENCES `delivery_note_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_invoice_items_product` FOREIGN KEY (`product_code_id`) REFERENCES `product_codes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `debt_tracking` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `paid_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `remaining_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `due_date` DATE DEFAULT NULL,
    `status` ENUM('unpaid', 'partial', 'paid') NOT NULL DEFAULT 'unpaid',
    `note` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_debt_tracking_invoice` (`invoice_id`),
    KEY `idx_debt_tracking_customer` (`customer_id`),
    CONSTRAINT `fk_debt_tracking_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_debt_tracking_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `debt_payments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `debt_id` INT UNSIGNED NOT NULL,
    `invoice_id` INT UNSIGNED NOT NULL,
    `payment_date` DATE NOT NULL,
    `amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `payment_method` ENUM('cash', 'transfer', 'other') NOT NULL DEFAULT 'cash',
    `reference_no` VARCHAR(100) DEFAULT NULL,
    `note` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_debt_payments_debt` (`debt_id`),
    KEY `idx_debt_payments_invoice` (`invoice_id`),
    CONSTRAINT `fk_debt_payments_debt` FOREIGN KEY (`debt_id`) REFERENCES `debt_tracking` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_debt_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_debt_payments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `warehouse_stock` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_code_id` INT UNSIGNED NOT NULL,
    `qty_pending` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `qty_completed` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `qty_defect` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_warehouse_stock_product` (`product_code_id`),
    CONSTRAINT `fk_warehouse_stock_product` FOREIGN KEY (`product_code_id`) REFERENCES `product_codes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `warehouse_stock_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_code_id` INT UNSIGNED NOT NULL,
    `log_date` DATETIME NOT NULL,
    `txn_type` VARCHAR(50) NOT NULL,
    `stock_type` VARCHAR(50) NOT NULL,
    `qty_change` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `ref_table` VARCHAR(50) DEFAULT NULL,
    `ref_id` INT UNSIGNED DEFAULT NULL,
    `note` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_warehouse_stock_log_product` (`product_code_id`),
    CONSTRAINT `fk_warehouse_stock_log_product` FOREIGN KEY (`product_code_id`) REFERENCES `product_codes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_warehouse_stock_log_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `production_stock` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_code_id` INT UNSIGNED NOT NULL,
    `stock_date` DATE NOT NULL,
    `qty_pending` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `qty_completed` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `qty_defect` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_production_stock_product_date` (`product_code_id`, `stock_date`),
    CONSTRAINT `fk_production_stock_product` FOREIGN KEY (`product_code_id`) REFERENCES `product_codes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cost_entries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entry_date` DATE NOT NULL,
    `cost_type` VARCHAR(100) NOT NULL,
    `supplier_name` VARCHAR(150) DEFAULT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `unit` VARCHAR(50) DEFAULT NULL,
    `unit_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `invoice_no` VARCHAR(50) DEFAULT NULL,
    `note` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_cost_entries_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` (`id`, `name`, `display_name`) VALUES
    (1, 'director', 'Giám đốc'),
    (2, 'accountant', 'Kế toán'),
    (3, 'manager', 'Quản lý'),
    (4, 'warehouse', 'Kho'),
    (5, 'production', 'Sản xuất'),
    (6, 'employee', 'Nhân viên');

INSERT INTO `departments` (`id`, `name`, `created_at`) VALUES
    (1, 'Giám đốc', CURRENT_TIMESTAMP),
    (2, 'Kế toán', CURRENT_TIMESTAMP),
    (3, 'Sản xuất', CURRENT_TIMESTAMP),
    (4, 'Kho', CURRENT_TIMESTAMP);

INSERT INTO `users` (`id`, `employee_code`, `full_name`, `username`, `password_hash`, `email`, `phone`, `role_id`, `department_id`, `is_active`, `created_at`) VALUES
    (1, 'GD001', 'Nguyễn Văn Giám Đốc', 'giamdoc', '$2y$10$xgbzs6humrB0r53q0NFfievejcG/zFkON44ac47HkFw7TgPjQwyKm', 'giamdoc@ntn.local', '0900000001', 1, 1, 1, CURRENT_TIMESTAMP);

INSERT INTO `work_shifts` (`id`, `shift_code`, `shift_name`, `start_time`, `end_time`, `late_threshold`, `break_minutes`, `work_hours`, `ot_multiplier`, `weekend_multiplier`, `holiday_multiplier`, `color`, `is_active`, `created_by`, `created_at`) VALUES
    (1, 'HC', 'Hành chính', '08:00:00', '17:00:00', 10, 60, 8.00, 1.50, 2.00, 3.00, '#0d6efd', 1, 1, CURRENT_TIMESTAMP),
    (2, 'CA1', 'Ca sáng', '06:00:00', '14:00:00', 5, 30, 7.50, 1.50, 2.00, 3.00, '#198754', 1, 1, CURRENT_TIMESTAMP),
    (3, 'CA2', 'Ca chiều', '14:00:00', '22:00:00', 5, 30, 7.50, 1.50, 2.00, 3.00, '#fd7e14', 1, 1, CURRENT_TIMESTAMP);

SET FOREIGN_KEY_CHECKS=1;
