<?php
/**
 * API สำหรับเปิด/ปิดการใช้งานสถานที่/อาคาร (Global Location Toggle)
 * อัปเดตตาราง building (is_active) และ durable_articles (is_visible) เพื่อให้มีผลทั่วทั้งระบบ
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ตรวจสอบสิทธิ์ Admin (ผู้ดูแลระบบ)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || ($_SESSION['useraccountType'] ?? '') !== 'UT00000001') {
    http_response_code(403);
    echo json_encode([
        'status'  => 'error',
        'message' => 'คุณไม่มีสิทธิ์ดำเนินการนี้ (Unauthorized Admin Access)'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

require_once __DIR__ . '/../control/connectPDO_dev.php';

// รองรับทั้ง JSON Body และ FormData / $_POST
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

$locationId = trim($data['location_id'] ?? $data['building_id'] ?? $data['id'] ?? '');
$cleanId    = trim($locationId, "'\"");
$statusVal  = $data['is_active'] ?? $data['status'] ?? null;

// แปลงค่าสถานะเป็น 1 หรือ 0
$isActive = ($statusVal === 1 || $statusVal === '1' || $statusVal === true || $statusVal === 'true') ? 1 : 0;

if ($cleanId === '') {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'ไม่พบรหัสสถานที่ (Missing location_id)'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    // ตรวจสอบคอลัมน์ is_active ในตาราง building หากยังไม่มีให้สร้างขึ้นอัตโนมัติ
    $cols = $pdo->query("PRAGMA table_info(building)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_active', $cols)) {
        $pdo->exec("ALTER TABLE building ADD COLUMN is_active INTEGER DEFAULT 1");
    }

    $pdo->beginTransaction();

    if ($cleanId === 'all') {
        // อัปเดตทุกอาคาร
        $stmtBld = $pdo->prepare("UPDATE building SET is_active = :status");
        $stmtBld->execute([':status' => $isActive]);

        $stmtDa = $pdo->prepare("UPDATE durable_articles SET is_visible = :status");
        $stmtDa->execute([':status' => $isActive]);

        $pdo->commit();

        echo json_encode([
            'status'      => 'success',
            'message'     => ($isActive ? 'เปิดการใช้งาน' : 'ปิดการใช้งาน') . 'ทุกสถานที่ในระบบเรียบร้อยแล้ว',
            'location_id' => 'all',
            'is_active'   => $isActive
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // 1. อัปเดตสถานะของอาคารในตาราง building
    $stmtBld = $pdo->prepare("
        UPDATE building 
        SET is_active = :status 
        WHERE buildingID = :id OR buildingID = :quoted_id
    ");
    $stmtBld->execute([
        ':status'    => $isActive,
        ':id'        => $cleanId,
        ':quoted_id' => "'" . $cleanId . "'"
    ]);

    // 2. อัปเดตสถานะการมองเห็นของครุภัณฑ์ในอาคารนี้ (durable_articles.is_visible) เพื่อให้มีผล Global System-wide
    $stmtDa = $pdo->prepare("
        UPDATE durable_articles 
        SET is_visible = :status 
        WHERE buildingID = :id OR buildingID = :quoted_id
    ");
    $stmtDa->execute([
        ':status'    => $isActive,
        ':id'        => $cleanId,
        ':quoted_id' => "'" . $cleanId . "'"
    ]);

    // ดึงชื่ออาคารเพื่อนำมาแสดงในข้อความตอบกลับ
    $stmtInfo = $pdo->prepare("
        SELECT buildingName, is_active 
        FROM building 
        WHERE buildingID = :id OR buildingID = :quoted_id 
        LIMIT 1
    ");
    $stmtInfo->execute([
        ':id'        => $cleanId,
        ':quoted_id' => "'" . $cleanId . "'"
    ]);
    $bldInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);
    $bldName = $bldInfo['buildingName'] ?? $cleanId;

    $pdo->commit();

    echo json_encode([
        'status'        => 'success',
        'message'       => ($isActive ? 'เปิดการใช้งาน' : 'ปิดการใช้งาน') . "อาคาร \"{$bldName}\" สำเร็จ",
        'location_id'   => $cleanId,
        'building_name' => $bldName,
        'is_active'     => $isActive
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'เกิดข้อผิดพลาดในการอัปเดตข้อมูล: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
