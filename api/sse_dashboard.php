<?php
/**
 * sse_dashboard.php
 * Server-Sent Events (SSE) Endpoint สำหรับ Real-Time Dashboard Telemetry
 * 
 * คุณสมบัติ:
 * 1. Non-blocking Session: เรียก session_write_close() ทันทีหลังจากตรวจสิทธิ์ ป้องกัน Session Lock ค้าง
 * 2. Enterprise Resource Guard: วนลูปสูงสุด ~45-50 วินาที แล้วปิดการเชื่อมต่ออย่างนุ่มนวล เพื่อให้ EventSource ทำ Auto-reconnect
 * 3. Smart Diff Detection: ตรวจจับการเปลี่ยนแปลงข้อมูลด้วย Hash หากไม่มีการเปลี่ยนแปลงจะส่ง Heartbeat Ping เพื่อประหยัด Bandwidth
 * 4. Graceful Teardown: ตรวจจับ connection_aborted() เพื่อคืน Memory และ Worker ทันทีที่ผู้ใช้ปิดหรือเปลี่ยนหน้า
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. ตรวจสอบ Security Check สิทธิ์การเข้าถึง (ต้องเป็น Admin: UT00000001)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || ($_SESSION['useraccountType'] ?? '') !== 'UT00000001') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'ไม่มีสิทธิ์เข้าถึงข้อมูล Real-Time Stream (Unauthorized)'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 2. ดึงข้อมูลตัวแปรของ Admin จาก Session
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

// 3. ปลดล็อก Session File Lock ทันที (Non-blocking I/O)
// ป้องกันไม่ให้คำขออื่น ๆ ของผู้ใช้ในระบบถูกบล็อกค้างระหว่างที่ SSE กำลัง Stream ข้อมูล
session_write_close();

// 4. โหลดการเชื่อมต่อฐานข้อมูล PDO และ BorrowService
require_once __DIR__ . '/../control/connectPDO_dev.php';
require_once __DIR__ . '/../includes/BorrowService.php';

// หากไม่ใช่ Super Admin และใน Session ยังไม่มีแผนก ให้ดึงจากฐานข้อมูล
if (!$isSuperAdmin && empty($adminDept) && !empty($currentUserID)) {
    try {
        $stmtD = $pdo->prepare("SELECT userDepartment FROM useraccount WHERE useraccountID = :uid");
        $stmtD->execute([':uid' => $currentUserID]);
        $adminDept = $stmtD->fetchColumn() ?: '';
    } catch (PDOException $e) {
        $adminDept = '';
    }
}

// 5. รับพารามิเตอร์ View Mode
$viewMode = $_GET['view_mode'] ?? 'normal';

// 6. ปิด Output Buffering ของ PHP ทั้งหมดเพื่อส่งข้อมูลแบบ Real-time ทันที
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 7. กำหนด Headers สำหรับ Server-Sent Events (SSE)
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // ป้องกัน Nginx buffering

// กำหนดให้เบราว์เซอร์ Auto-Reconnect หลังหลุดการเชื่อมต่อ 3 วินาที
echo "retry: 3000\n\n";
flush();

// 8. เริ่มต้น Real-Time Stream Loop
$startTime = time();
$maxExecutionSeconds = 45; // วงรอบสูงสุด 45 วินาทีต่อการเชื่อมต่อ 1 ครั้ง
$lastHash = '';

while (true) {
    // ตรวจสอบว่า Client หลุดการเชื่อมต่อหรือปิดหน้าเว็บไปแล้วหรือไม่
    if (connection_aborted()) {
        break;
    }

    try {
        // ดึงข้อมูลแดชบอร์ดล่าสุดผ่าน BorrowService (Single Source of Truth)
        $data = BorrowService::getDashboardData($pdo, $viewMode, $adminDept, $currentUserID, $isSuperAdmin);

        // คำนวณ Hash เพื่อตรวจสอบว่าข้อมูลมีการเปลี่ยนแปลงหรือไม่
        $currentHash = md5(json_encode($data));

        if ($currentHash !== $lastHash) {
            $lastHash = $currentHash;

            $payload = [
                'status'       => 'success',
                'event'        => 'dashboard_update',
                'generated_at' => date('Y-m-d H:i:s'),
                'view_mode'    => $viewMode,
                'data'         => $data
            ];

            echo "event: dashboard_update\n";
            echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        } else {
            // ส่ง Heartbeat Ping เพื่อรักษา Connection ให้คงอยู่
            echo ": ping - " . date('H:i:s') . "\n\n";
        }

    } catch (Exception $e) {
        echo "event: dashboard_error\n";
        echo "data: " . json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    // Flush ข้อมูลออกไปยัง Client ทันที
    flush();

    // หมดเวลา 45 วินาที ปิดการเชื่อมต่ออย่างปลอดภัย เพื่อให้ EventSource ทำการ Reconnect รอบใหม่
    if ((time() - $startTime) >= $maxExecutionSeconds) {
        break;
    }

    // หน่วงเวลา 2 วินาทีระหว่างการตรวจสอบแต่ละรอบ
    sleep(2);
}

exit();
