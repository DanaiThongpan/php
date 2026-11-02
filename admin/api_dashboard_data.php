<?php
/**
 * api_dashboard_data.php
 * API Endpoint สำหรับดึงข้อมูลสถิติ แดชบอร์ด กราฟ และกิจกรรมล่าสุด แบบ Asynchronous (JSON)
 * ออกแบบเพื่อรองรับ High-Performance & Non-blocking rendering
 * ขับเคลื่อนด้วย BorrowService (Single Source of Truth)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// กำหนด Response Header เป็น JSON UTF-8
header('Content-Type: application/json; charset=utf-8');

// 1. ตรวจสอบ Security Check สิทธิ์การเข้าถึง (ต้องเป็น Admin: UT00000001)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || ($_SESSION['useraccountType'] ?? '') !== 'UT00000001') {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'ไม่มีสิทธิ์เข้าถึงข้อมูล (Unauthorized Access)'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 2. โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO และ BorrowService
require_once __DIR__ . '/../control/connectPDO_dev.php';
require_once __DIR__ . '/../includes/BorrowService.php';

// 3. กำหนดตัวแปรและข้อมูลแผนกของ Admin
$adminDept = $_SESSION['department_id'] 
          ?? $_SESSION['department_name'] 
          ?? $_SESSION['userDepartment'] 
          ?? '';

$currentUserID   = $_SESSION['SeUser'] ?? '';
$useraccountType = $_SESSION['useraccountType'] ?? '';
$sessionUsername = $_SESSION['username'] ?? '';
$sessionSename   = $_SESSION['Sename'] ?? '';

// ตรวจสอบสิทธิ์ Super Admin
$isSuperAdmin = (
    $useraccountType === 'Super Admin' ||
    in_array(strtolower($currentUserID), ['admin', 'admin_test']) ||
    strtolower($sessionUsername) === 'admin' ||
    strtolower($sessionSename) === 'administrator' ||
    empty($adminDept) ||
    $adminDept === 'ส่วนกลาง'
);

// หากไม่ใช่ Super Admin และใน Session ยังไม่มีแผนก ให้ดึงจากฐานข้อมูล
if (!$isSuperAdmin && empty($adminDept) && !empty($currentUserID)) {
    try {
        $stmtD = $pdo->prepare("SELECT userDepartment FROM useraccount WHERE useraccountID = :uid");
        $stmtD->execute([':uid' => $currentUserID]);
        $adminDept = $stmtD->fetchColumn() ?: '';
        $_SESSION['userDepartment'] = $adminDept;
    } catch (PDOException $e) {
        $adminDept = '';
    }
}

// 4. จัดการโหมดการแสดงผลข้อมูล (Visibility Rules: normal, all, hidden)
$viewMode = $_GET['view_mode'] ?? 'normal';

try {
    // 5. ดึงข้อมูลแดชบอร์ดผ่าน BorrowService (DRY Principle)
    $dashboardData = BorrowService::getDashboardData($pdo, $viewMode, $adminDept, $currentUserID, $isSuperAdmin);

    // ส่งข้อมูล JSON สำเร็จ
    echo json_encode([
        'status'       => 'success',
        'generated_at' => date('Y-m-d H:i:s'),
        'view_mode'    => $viewMode,
        'data'         => $dashboardData
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูลฐานข้อมูล: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
