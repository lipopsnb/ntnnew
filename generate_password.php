<?php
/**
 * File tạm - Chạy 1 lần để lấy hash mật khẩu đúng
 * Truy cập: http://localhost/ntn_erp/generate_password.php
 * Xóa file này sau khi dùng xong!
 */

$password = 'Admin@123';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

echo "<h2>Hash mật khẩu Admin@123:</h2>";
echo "<code style='font-size:16px; background:#f0f0f0; padding:10px; display:block;'>" . $hash . "</code>";
echo "<br>";
echo "<strong>Verify test:</strong> " . (password_verify($password, $hash) ? '✅ ĐÚNG' : '❌ SAI');
echo "<br><br>";
echo "<strong>Câu SQL để update:</strong><br>";
echo "<code style='background:#f0f0f0; padding:10px; display:block;'>UPDATE users SET password_hash = '" . $hash . "' WHERE username = 'admin';</code>";
echo "<br><br><span style='color:red'>⚠️ Xóa file này sau khi dùng xong!</span>";
