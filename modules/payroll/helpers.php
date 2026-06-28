<?php
if (!function_exists('payrollPeriodColumns')) {
    function payrollPeriodColumns(PDO $pdo): array
    {
        return [
            'id' => pickColumn($pdo, 'payroll_periods', ['id']) ?? 'id',
            'month' => pickColumn($pdo, 'payroll_periods', ['period_month', 'month', 'pay_month']) ?? 'period_month',
            'year' => pickColumn($pdo, 'payroll_periods', ['period_year', 'year', 'pay_year']) ?? 'period_year',
            'working_days' => pickColumn($pdo, 'payroll_periods', ['working_days']) ?? 'working_days',
            'status' => pickColumn($pdo, 'payroll_periods', ['status']) ?? 'status',
            'created_by' => pickColumn($pdo, 'payroll_periods', ['created_by']) ?? 'created_by',
            'created_at' => pickColumn($pdo, 'payroll_periods', ['created_at', 'submitted_at', 'approved_at']),
        ];
    }
}

if (!function_exists('getPayrollSlipTable')) {
    function getPayrollSlipTable(PDO $pdo): ?string
    {
        foreach (['payroll_items', 'payroll_slips'] as $table) {
            if (tableExists($pdo, $table)) {
                return $table;
            }
        }

        return null;
    }
}

if (!function_exists('payrollItemColumns')) {
    function payrollItemColumns(PDO $pdo): array
    {
        $table = getPayrollSlipTable($pdo) ?? 'payroll_slips';
        return [
            'table' => $table,
            'id' => pickColumn($pdo, $table, ['id']) ?? 'id',
            'period' => pickColumn($pdo, $table, ['period_id', 'payroll_period_id']) ?? 'period_id',
            'user' => pickColumn($pdo, $table, ['user_id', 'employee_id']) ?? 'user_id',
            'basic_salary' => pickColumn($pdo, $table, ['basic_salary']) ?? 'basic_salary',
            'working_days' => pickColumn($pdo, $table, ['working_days_actual', 'working_days']) ?? 'working_days_actual',
            'ot_hours' => pickColumn($pdo, $table, ['ot_hours', 'overtime_hours']) ?? 'ot_hours',
            'ot_amount' => pickColumn($pdo, $table, ['ot_amount', 'overtime_amount']) ?? 'ot_amount',
            'allowances' => pickColumn($pdo, $table, ['allowances']) ?? 'allowances',
            'deductions' => pickColumn($pdo, $table, ['deductions']) ?? 'deductions',
            'net_salary' => pickColumn($pdo, $table, ['net_salary']) ?? 'net_salary',
        ];
    }
}

if (!function_exists('payrollStatusBadge')) {
    function payrollStatusBadge(string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'paid' => 'primary',
            'submitted' => 'warning text-dark',
            default => 'secondary',
        };
    }
}

if (!function_exists('fetchPayrollPeriods')) {
    function fetchPayrollPeriods(PDO $pdo): array
    {
        if (!tableExists($pdo, 'payroll_periods')) {
            return [];
        }

        $cols = payrollPeriodColumns($pdo);
        $userTable = getEmployeeSourceTable($pdo);
        $userNameColumn = $userTable ? (pickColumn($pdo, $userTable, ['full_name', 'name', 'employee_name', 'username']) ?? 'id') : null;

        $sql = sprintf(
            'SELECT p.`%s` AS id, p.`%s` AS period_month, p.`%s` AS period_year, p.`%s` AS working_days, p.`%s` AS status, %s AS created_at, p.`%s` AS created_by',
            $cols['id'], $cols['month'], $cols['year'], $cols['working_days'], $cols['status'], $cols['created_at'] ? 'p.`' . $cols['created_at'] . '`' : 'NULL', $cols['created_by']
        );
        if ($userTable && $userNameColumn) {
            $sql .= sprintf(', u.`%s` AS created_by_name', $userNameColumn);
        } else {
            $sql .= ', NULL AS created_by_name';
        }
        $sql .= ' FROM payroll_periods p';
        if ($userTable && $userNameColumn) {
            $sql .= sprintf(' LEFT JOIN `%s` u ON u.id = p.`%s`', $userTable, $cols['created_by']);
        }
        $sql .= sprintf(' ORDER BY p.`%s` DESC, p.`%s` DESC', $cols['year'], $cols['month']);

        return $pdo->query($sql)->fetchAll();
    }
}
