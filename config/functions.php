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
