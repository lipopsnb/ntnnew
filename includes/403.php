<?php
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - Không có quyền truy cập</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            font-family: Arial, sans-serif;
        }
        .forbidden-card {
            max-width: 540px;
            border: 0;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
        }
        .forbidden-icon {
            font-size: 4rem;
            color: #e94560;
        }
    </style>
</head>
<body>
    <div class="card forbidden-card">
        <div class="card-body text-center p-5">
            <div class="forbidden-icon mb-3">⛔</div>
            <h1 class="h2 fw-bold mb-3">403 - Truy cập bị từ chối</h1>
            <p class="text-muted mb-4">
                Bạn không có quyền truy cập chức năng này. Vui lòng liên hệ quản trị hệ thống hoặc quay lại trang trước đó.
            </p>
            <div class="d-flex justify-content-center gap-2 flex-wrap">
                <button type="button" class="btn btn-outline-secondary" onclick="history.back()">Quay lại</button>
                <a href="/ntn_erp/dashboard.php" class="btn btn-primary">Về trang tổng quan</a>
            </div>
        </div>
    </div>
</body>
</html>
