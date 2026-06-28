<?php
declare(strict_types=1);

function formatDate($date, string $format = 'd/m/Y'): string
{
    if (empty($date)) {
        return '';
    }

    $timestamp = strtotime((string) $date);
    return $timestamp ? date($format, $timestamp) : '';
}

function formatDateTime($datetime): string
{
    return formatDate($datetime, 'd/m/Y H:i');
}

function formatMoney($amount): string
{
    return number_format((float) $amount, 0, '.', ',');
}

function generateDocCode(PDO $pdo, string $prefix, $date = null): string
{
    $docDate = $date ?: date('Y-m-d');
    $normalizedDate = date('Y-m-d', strtotime((string) $docDate));
    $displayDate = date('Ymd', strtotime($normalizedDate));

    $pdo->beginTransaction();

    try {
        $selectStmt = $pdo->prepare('SELECT id, last_seq FROM document_sequences WHERE doc_type = ? AND doc_date = ? FOR UPDATE');
        $selectStmt->execute([$prefix, $normalizedDate]);
        $sequence = $selectStmt->fetch(PDO::FETCH_ASSOC);

        if ($sequence) {
            $nextSeq = (int) $sequence['last_seq'] + 1;
            $updateStmt = $pdo->prepare('UPDATE document_sequences SET last_seq = ? WHERE id = ?');
            $updateStmt->execute([$nextSeq, $sequence['id']]);
        } else {
            $nextSeq = 1;
            $insertStmt = $pdo->prepare('INSERT INTO document_sequences (doc_type, doc_date, last_seq) VALUES (?, ?, ?)');
            $insertStmt->execute([$prefix, $normalizedDate, $nextSeq]);
        }

        $pdo->commit();
        return sprintf('%s-%s-%03d', strtoupper($prefix), $displayDate, $nextSeq);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function showFlash(): void
{
    $flash = getFlash();
    if ($flash) {
        $icon = $flash['type'] === 'success' ? '✅' : ($flash['type'] === 'danger' ? '❌' : 'ℹ️');
        echo "<div class='alert alert-{$flash['type']} alert-dismissible fade show' role='alert'>\n                {$icon} {$flash['message']}\n                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>\n              </div>";
    }
}

function getWorkingDaysInMonth(int $year, int $month): array
{
    $days = [];
    $totalDays = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    for ($d = 1; $d <= $totalDays; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        if (date('N', strtotime($date)) !== '7') {
            $days[] = $date;
        }
    }
    return $days;
}

function calcWorkHours($check_in, $check_out): float
{
    if (empty($check_in) || empty($check_out)) {
        return 0;
    }
    $diff = strtotime((string) $check_out) - strtotime((string) $check_in);
    return round(max($diff, 0) / 3600, 2);
}

function generateCSRF(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRF($token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string) $token);
}

function e($str): string
{
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(generateCSRF()) . '">';
}
