<?php
if (!function_exists('attendanceColumns')) {
    function attendanceColumns(PDO $pdo): array
    {
        return [
            'user' => pickColumn($pdo, 'attendance_logs', ['user_id', 'employee_id']) ?? 'user_id',
            'date' => pickColumn($pdo, 'attendance_logs', ['attendance_date', 'work_date', 'log_date', 'date']) ?? 'attendance_date',
            'check_in' => pickColumn($pdo, 'attendance_logs', ['check_in', 'time_in']) ?? 'check_in',
            'check_out' => pickColumn($pdo, 'attendance_logs', ['check_out', 'time_out']) ?? 'check_out',
            'status' => pickColumn($pdo, 'attendance_logs', ['status']) ?? 'status',
        ];
    }
}

if (!function_exists('leaveColumns')) {
    function leaveColumns(PDO $pdo): array
    {
        return [
            'id' => pickColumn($pdo, 'leave_requests', ['id']) ?? 'id',
            'user' => pickColumn($pdo, 'leave_requests', ['user_id', 'employee_id']) ?? 'user_id',
            'type_id' => pickColumn($pdo, 'leave_requests', ['leave_type_id', 'type_id']) ?? 'leave_type_id',
            'type' => pickColumn($pdo, 'leave_requests', ['leave_type', 'type']) ?? 'leave_type',
            'from' => pickColumn($pdo, 'leave_requests', ['date_from', 'start_date']) ?? 'date_from',
            'to' => pickColumn($pdo, 'leave_requests', ['date_to', 'end_date']) ?? 'date_to',
            'days' => pickColumn($pdo, 'leave_requests', ['days', 'total_days']) ?? 'days',
            'reason' => pickColumn($pdo, 'leave_requests', ['reason', 'note']) ?? 'reason',
            'status' => pickColumn($pdo, 'leave_requests', ['status']) ?? 'status',
            'approved_by' => pickColumn($pdo, 'leave_requests', ['approved_by']) ?? 'approved_by',
            'approved_at' => pickColumn($pdo, 'leave_requests', ['approved_at']) ?? 'approved_at',
            'comment' => pickColumn($pdo, 'leave_requests', ['manager_comment', 'approval_comment', 'comment', 'note']) ?? 'note',
            'created_at' => pickColumn($pdo, 'leave_requests', ['created_at']),
        ];
    }
}

if (!function_exists('otColumns')) {
    function otColumns(PDO $pdo): array
    {
        return [
            'id' => pickColumn($pdo, 'overtime_requests', ['id']) ?? 'id',
            'user' => pickColumn($pdo, 'overtime_requests', ['user_id', 'employee_id']) ?? 'user_id',
            'date' => pickColumn($pdo, 'overtime_requests', ['ot_date', 'work_date']) ?? 'ot_date',
            'time_start' => pickColumn($pdo, 'overtime_requests', ['time_start', 'start_time']) ?? 'time_start',
            'time_end' => pickColumn($pdo, 'overtime_requests', ['time_end', 'end_time']) ?? 'time_end',
            'hours' => pickColumn($pdo, 'overtime_requests', ['hours', 'ot_hours']) ?? 'hours',
            'reason' => pickColumn($pdo, 'overtime_requests', ['reason', 'note']) ?? 'reason',
            'status' => pickColumn($pdo, 'overtime_requests', ['status']) ?? 'status',
            'comment' => pickColumn($pdo, 'overtime_requests', ['manager_comment', 'approval_comment', 'comment', 'note']) ?? 'note',
            'approved_by' => pickColumn($pdo, 'overtime_requests', ['approved_by']) ?? 'approved_by',
            'approved_at' => pickColumn($pdo, 'overtime_requests', ['approved_at']) ?? 'approved_at',
            'created_at' => pickColumn($pdo, 'overtime_requests', ['created_at']),
        ];
    }
}

if (!function_exists('shiftColumns')) {
    function shiftColumns(PDO $pdo): array
    {
        return [
            'id' => pickColumn($pdo, 'shifts', ['id']) ?? 'id',
            'name' => pickColumn($pdo, 'shifts', ['name', 'shift_name']) ?? 'name',
            'abbr' => pickColumn($pdo, 'shifts', ['abbreviation', 'code', 'short_name']) ?? null,
            'time_start' => pickColumn($pdo, 'shifts', ['time_start', 'start_time']) ?? 'time_start',
            'time_end' => pickColumn($pdo, 'shifts', ['time_end', 'end_time']) ?? 'time_end',
            'break_minutes' => pickColumn($pdo, 'shifts', ['break_minutes', 'break_time']) ?? 'break_minutes',
            'working_hours' => pickColumn($pdo, 'shifts', ['working_hours', 'hours']) ?? 'working_hours',
            'color' => pickColumn($pdo, 'shifts', ['color', 'display_color']) ?? 'color',
        ];
    }
}

if (!function_exists('shiftAssignmentColumns')) {
    function shiftAssignmentColumns(PDO $pdo): array
    {
        return [
            'table' => tableExists($pdo, 'shift_assignments') ? 'shift_assignments' : 'shift_assigns',
            'id' => 'id',
            'user' => tableExists($pdo, 'shift_assignments') ? (pickColumn($pdo, 'shift_assignments', ['user_id', 'employee_id']) ?? 'user_id') : (pickColumn($pdo, 'shift_assigns', ['user_id', 'employee_id']) ?? 'user_id'),
            'shift' => tableExists($pdo, 'shift_assignments') ? (pickColumn($pdo, 'shift_assignments', ['shift_id']) ?? 'shift_id') : (pickColumn($pdo, 'shift_assigns', ['shift_id']) ?? 'shift_id'),
            'date' => tableExists($pdo, 'shift_assignments') ? (pickColumn($pdo, 'shift_assignments', ['shift_date', 'work_date', 'date']) ?? 'shift_date') : (pickColumn($pdo, 'shift_assigns', ['assign_date', 'shift_date', 'work_date', 'date']) ?? 'assign_date'),
        ];
    }
}

if (!function_exists('holidayColumns')) {
    function holidayColumns(PDO $pdo): array
    {
        return [
            'id' => pickColumn($pdo, 'holidays', ['id']) ?? 'id',
            'name' => pickColumn($pdo, 'holidays', ['name', 'holiday_name']) ?? 'name',
            'date' => pickColumn($pdo, 'holidays', ['holiday_date', 'date']) ?? 'holiday_date',
            'recurring' => pickColumn($pdo, 'holidays', ['is_recurring', 'recurring']) ?? 'is_recurring',
        ];
    }
}

if (!function_exists('buildDatesInRange')) {
    function buildDatesInRange(string $startDate, string $endDate): array
    {
        $dates = [];
        $current = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
        while ($current <= $end) {
            $dates[] = $current->format('Y-m-d');
            $current = $current->modify('+1 day');
        }
        return $dates;
    }
}

if (!function_exists('calculateLeaveDays')) {
    function calculateLeaveDays(string $dateFrom, string $dateTo): int
    {
        $from = new DateTimeImmutable($dateFrom);
        $to = new DateTimeImmutable($dateTo);
        return max(1, (int) $from->diff($to)->days + 1);
    }
}

if (!function_exists('calculateHourDifference')) {
    function calculateHourDifference(string $timeStart, string $timeEnd): float
    {
        $start = DateTimeImmutable::createFromFormat('H:i', $timeStart) ?: new DateTimeImmutable($timeStart);
        $end = DateTimeImmutable::createFromFormat('H:i', $timeEnd) ?: new DateTimeImmutable($timeEnd);
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        return round(max($seconds, 0) / 3600, 2);
    }
}

if (!function_exists('attendanceStatusMeta')) {
    function attendanceStatusMeta(string $status): array
    {
        return match ($status) {
            'present' => ['label' => 'Có mặt', 'class' => 'success', 'cell' => 'status-present'],
            'late' => ['label' => 'Đi trễ', 'class' => 'warning text-dark', 'cell' => 'status-late'],
            'absent' => ['label' => 'Vắng', 'class' => 'danger', 'cell' => 'status-absent'],
            'leave' => ['label' => 'Nghỉ phép', 'class' => 'info text-dark', 'cell' => 'status-leave'],
            'holiday' => ['label' => 'Ngày lễ', 'class' => 'secondary', 'cell' => 'status-holiday'],
            default => ['label' => 'Chưa tới', 'class' => 'light text-dark', 'cell' => 'status-upcoming'],
        };
    }
}

if (!function_exists('inferAttendanceStatus')) {
    function inferAttendanceStatus(string $date, ?array $log, ?array $leave, ?array $holiday): array
    {
        $today = date('Y-m-d');
        if ($holiday) {
            return ['status' => 'holiday', 'check_in' => null, 'check_out' => null, 'note' => $holiday['name'] ?? null];
        }
        if ($leave) {
            return ['status' => 'leave', 'check_in' => null, 'check_out' => null, 'note' => $leave['leave_type'] ?? null];
        }
        if ($log) {
            $status = $log['status'] ?? '';
            if (!in_array($status, ['present', 'absent', 'late', 'leave', 'holiday'], true)) {
                $checkInTime = !empty($log['check_in']) ? date('H:i', strtotime((string) $log['check_in'])) : null;
                $status = ($checkInTime && $checkInTime > '08:05') ? 'late' : 'present';
            }
            return [
                'status' => $status,
                'check_in' => $log['check_in'] ?? null,
                'check_out' => $log['check_out'] ?? null,
                'note' => null,
            ];
        }
        if ($date <= $today) {
            return ['status' => 'absent', 'check_in' => null, 'check_out' => null, 'note' => null];
        }
        return ['status' => 'upcoming', 'check_in' => null, 'check_out' => null, 'note' => null];
    }
}

if (!function_exists('getAttendanceLogMap')) {
    function getAttendanceLogMap(PDO $pdo, array $userIds, string $startDate, string $endDate): array
    {
        if (!$userIds || !tableExists($pdo, 'attendance_logs')) {
            return [];
        }
        $cols = attendanceColumns($pdo);
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $sql = sprintf(
            'SELECT `%s` AS user_id, `%s` AS work_date, `%s` AS check_in, `%s` AS check_out, `%s` AS status
             FROM attendance_logs
             WHERE `%s` IN (%s) AND `%s` BETWEEN ? AND ?',
            $cols['user'], $cols['date'], $cols['check_in'], $cols['check_out'], $cols['status'], $cols['user'], $placeholders, $cols['date']
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute([...$userIds, $startDate, $endDate]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[$row['user_id']][$row['work_date']] = $row;
        }
        return $rows;
    }
}

if (!function_exists('getApprovedLeaveMap')) {
    function getApprovedLeaveMap(PDO $pdo, array $userIds, string $startDate, string $endDate): array
    {
        if (!$userIds || !tableExists($pdo, 'leave_requests')) {
            return [];
        }
        $cols = leaveColumns($pdo);
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $typeNameSql = 'NULL';
        $joinSql = '';
        if (tableExists($pdo, 'leave_types') && columnExists($pdo, 'leave_requests', $cols['type_id'])) {
            $joinSql = ' LEFT JOIN leave_types lt ON lt.id = leave_requests.`' . $cols['type_id'] . '`';
            $typeNameSql = 'lt.name';
        } elseif (columnExists($pdo, 'leave_requests', $cols['type'])) {
            $typeNameSql = 'leave_requests.`' . $cols['type'] . '`';
        }
        $sql = sprintf(
            'SELECT leave_requests.`%s` AS user_id, %s AS leave_type, leave_requests.`%s` AS date_from, leave_requests.`%s` AS date_to, leave_requests.`%s` AS status
             FROM leave_requests%s
             WHERE leave_requests.`%s` IN (%s) AND leave_requests.`%s` = ? AND leave_requests.`%s` <= ? AND leave_requests.`%s` >= ?',
            $cols['user'], $typeNameSql, $cols['from'], $cols['to'], $cols['status'], $joinSql,
            $cols['user'], $placeholders, $cols['status'], $cols['from'], $cols['to']
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute([...$userIds, 'approved', $endDate, $startDate]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            foreach (buildDatesInRange($row['date_from'], $row['date_to']) as $date) {
                if ($date >= $startDate && $date <= $endDate) {
                    $rows[$row['user_id']][$date] = $row;
                }
            }
        }
        return $rows;
    }
}

if (!function_exists('getHolidayMap')) {
    function getHolidayMap(PDO $pdo, string $startDate, string $endDate): array
    {
        if (!tableExists($pdo, 'holidays')) {
            return [];
        }
        $cols = holidayColumns($pdo);
        $sql = sprintf('SELECT `%s` AS holiday_name, `%s` AS holiday_date, `%s` AS is_recurring FROM holidays', $cols['name'], $cols['date'], $cols['recurring']);
        $rows = $pdo->query($sql)->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $holidayDate = $row['holiday_date'];
            if ((int) $row['is_recurring'] === 1) {
                $monthDay = date('m-d', strtotime($holidayDate));
                foreach (buildDatesInRange($startDate, $endDate) as $date) {
                    if (date('m-d', strtotime($date)) === $monthDay) {
                        $map[$date] = ['name' => $row['holiday_name']];
                    }
                }
            } elseif ($holidayDate >= $startDate && $holidayDate <= $endDate) {
                $map[$holidayDate] = ['name' => $row['holiday_name']];
            }
        }
        return $map;
    }
}

if (!function_exists('getRemainingLeaveDays')) {
    function getRemainingLeaveDays(PDO $pdo, int|string $userId, int $year): float
    {
        if (tableExists($pdo, 'leave_balances')) {
            $userColumn = pickColumn($pdo, 'leave_balances', ['user_id', 'employee_id']) ?? 'user_id';
            $yearColumn = pickColumn($pdo, 'leave_balances', ['year']) ?? 'year';
            $remainingColumn = pickColumn($pdo, 'leave_balances', ['remaining_days', 'balance']) ?? 'remaining_days';
            $stmt = $pdo->prepare(sprintf('SELECT `%s` FROM leave_balances WHERE `%s` = ? AND `%s` = ? LIMIT 1', $remainingColumn, $userColumn, $yearColumn));
            $stmt->execute([$userId, $year]);
            $balance = $stmt->fetchColumn();
            if ($balance !== false) {
                return (float) $balance;
            }
        }

        $used = 0.0;
        if (tableExists($pdo, 'leave_requests')) {
            $cols = leaveColumns($pdo);
            $stmt = $pdo->prepare(sprintf('SELECT COALESCE(SUM(`%s`), 0) FROM leave_requests WHERE `%s` = ? AND `%s` = ? AND YEAR(`%s`) = ?', $cols['days'], $cols['user'], $cols['status'], $cols['from']));
            $stmt->execute([$userId, 'approved', $year]);
            $used = (float) $stmt->fetchColumn();
        }

        return max(0, 12 - $used);
    }
}

if (!function_exists('fetchShiftList')) {
    function fetchShiftList(PDO $pdo): array
    {
        if (!tableExists($pdo, 'shifts')) {
            return [];
        }
        $cols = shiftColumns($pdo);
        $sql = sprintf(
            'SELECT `%s` AS id, `%s` AS name, %s AS abbreviation, `%s` AS time_start, `%s` AS time_end, `%s` AS break_minutes, `%s` AS working_hours, `%s` AS color FROM shifts ORDER BY `%s`',
            $cols['id'], $cols['name'], $cols['abbr'] ? '`' . $cols['abbr'] . '`' : 'NULL', $cols['time_start'], $cols['time_end'], $cols['break_minutes'], $cols['working_hours'], $cols['color'], $cols['name']
        );
        return $pdo->query($sql)->fetchAll();
    }
}

if (!function_exists('upsertShiftAssignment')) {
    function upsertShiftAssignment(PDO $pdo, int|string $userId, int|string $shiftId, string $date): void
    {
        $cols = shiftAssignmentColumns($pdo);
        $table = $cols['table'];
        $check = $pdo->prepare(sprintf('SELECT `%s` FROM `%s` WHERE `%s` = ? AND `%s` = ? LIMIT 1', $cols['id'], $table, $cols['user'], $cols['date']));
        $check->execute([$userId, $date]);
        $id = $check->fetchColumn();

        if ($id) {
            $stmt = $pdo->prepare(sprintf('UPDATE `%s` SET `%s` = ? WHERE `%s` = ?', $table, $cols['shift'], $cols['id']));
            $stmt->execute([$shiftId, $id]);
            return;
        }

        $stmt = $pdo->prepare(sprintf('INSERT INTO `%s` (`%s`, `%s`, `%s`) VALUES (?, ?, ?)', $table, $cols['user'], $cols['shift'], $cols['date']));
        $stmt->execute([$userId, $shiftId, $date]);
    }
}

if (!function_exists('fetchShiftAssignments')) {
    function fetchShiftAssignments(PDO $pdo, string $startDate, string $endDate, ?int $employeeId = null): array
    {
        if ((!tableExists($pdo, 'shift_assignments') && !tableExists($pdo, 'shift_assigns')) || !tableExists($pdo, 'shifts')) {
            return [];
        }
        $assign = shiftAssignmentColumns($pdo);
        $shift = shiftColumns($pdo);
        $table = $assign['table'];
        $sql = sprintf(
            'SELECT sa.`%s` AS id, sa.`%s` AS user_id, sa.`%s` AS shift_date, sa.`%s` AS shift_id,
                    s.`%s` AS shift_name, %s AS abbreviation, s.`%s` AS color
             FROM `%s` sa
             INNER JOIN shifts s ON s.`%s` = sa.`%s`
             WHERE sa.`%s` BETWEEN ? AND ?',
            $assign['id'], $assign['user'], $assign['date'], $assign['shift'], $shift['name'], $shift['abbr'] ? 's.`' . $shift['abbr'] . '`' : 'NULL', $shift['color'], $table, $shift['id'], $assign['shift'], $assign['date']
        );
        $params = [$startDate, $endDate];
        if ($employeeId) {
            $sql .= sprintf(' AND sa.`%s` = ?', $assign['user']);
            $params[] = $employeeId;
        }
        $sql .= sprintf(' ORDER BY sa.`%s`, sa.`%s`', $assign['date'], $assign['user']);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

if (!function_exists('leaveStatusBadge')) {
    function leaveStatusBadge(string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'rejected' => 'danger',
            default => 'warning text-dark',
        };
    }
}
