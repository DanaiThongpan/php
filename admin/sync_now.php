<?php
/**
 * admin/sync_now.php - AJAX / HTTP Endpoint to trigger database synchronization
 * 
 * เรียกใช้งานสคริปต์ sync_database.php แบบปลอดภัยด้วย Session & สิทธิ์ Admin
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// ตรวจสอบสิทธิ์ (ต้องเป็น Admin ที่ล็อกอินแล้ว)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized: กรุณาเข้าสู่ระบบด้วยสิทธิ์ผู้ดูแลระบบ']);
    exit();
}

$useraccountType = $_SESSION['useraccountType'] ?? '';
$isSuperAdmin    = ($useraccountType === 'UT00000001' || ($_SESSION['useraccountID'] ?? '') === 'admin');

if (!$isSuperAdmin) {
    echo json_encode(['status' => 'error', 'message' => 'Forbidden: เฉพาะ Super Admin หรือผู้ดูแลระบบเท่านั้นที่สามารถสั่งซิงค์ฐานข้อมูลได้']);
    exit();
}

// เรียกใช้งาน sync_database.php พร้อมแฟล็ก force
$_GET['force'] = '1';
ob_start();
require_once __DIR__ . '/../sync_database.php';
$output = ob_get_clean();

// ถ้า sync_database.php ส่ง JSON ออกมาแล้ว ให้จบการทำงาน
// มิฉะนั้น ให้ส่งสรุปผลสำเร็จ
if (!empty($output)) {
    $decoded = json_decode($output, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo $output;
        exit();
    }
}

echo json_encode([
    'status'  => 'success',
    'message' => 'ซิงโครไนซ์ฐานข้อมูลสำเร็จเรียบร้อยแล้ว',
    'output'  => strip_tags($output)
]);
exit();
