<?php
/**
 * borrow_form.php - Legacy Borrow Route
 * ทำการ Redirect ผู้ใช้งานไปยัง Unified Borrow Request Flow (borrow_request.php) อัตโนมัติ
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$da_id = trim($_GET['DAID'] ?? $_GET['da_id'] ?? $_GET['asset_id'] ?? '');
$targetUrl = '../borrow_request.php' . (!empty($da_id) ? '?asset_id=' . urlencode($da_id) : '');
header("Location: " . $targetUrl);
exit();
