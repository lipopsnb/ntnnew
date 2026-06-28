<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/ntn_erp/config/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
$pdo = getDBConnection();
$userId = (int) (currentUser()['id'] ?? ($_SESSION['user_id'] ?? 0));

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Không xác định được người dùng.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action !== 'mark_read') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Hành động không hợp lệ.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $id = $_POST['id'] ?? null;
        if ($id === 'all' || isset($_POST['all'])) {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
            $stmt->execute([$userId]);
        } elseif ((int) $id > 0) {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id = ?');
            $stmt->execute([$userId, (int) $id]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Thiếu ID thông báo.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $countStmt->execute([$userId]);
    $unreadCount = (int) $countStmt->fetchColumn();

    $listStmt = $pdo->prepare('SELECT id, title, message, type, reference_id, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY is_read ASC, created_at DESC LIMIT 20');
    $listStmt->execute([$userId]);
    $notifications = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'unread_count' => $unreadCount,
        'notifications' => $notifications,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
