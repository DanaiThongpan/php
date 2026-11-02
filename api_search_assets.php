<?php
/**
 * api_search_assets.php - API Endpoint สำหรับ Live Search / Autocomplete ครุภัณฑ์
 * ค้นหาแบบเรียลไทม์ด้วยหมายเลขครุภัณฑ์ (DA_Number) หรือ ชื่อครุภัณฑ์ (DA_ListName)
 * ป้องกัน SQL Injection ด้วย PDO Prepared Statements และจำกัดผลลัพธ์ LIMIT 10
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. ตรวจสอบสิทธิ์การเข้าถึง (Authentication Check)
header('Access-Control-Allow-Origin: *');
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: กรุณาเข้าสู่ระบบก่อนใช้งาน'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 2. รับค่าคำค้นหา (Keyword)
$keyword = trim($_GET['keyword'] ?? $_GET['q'] ?? '');
$availableOnly = isset($_GET['available_only']) ? (int)$_GET['available_only'] : 1;

// หากคำค้นหาว่างเปล่า ให้ส่งค่าว่างกลับทันที โดยไม่ต้อง Query ฐานข้อมูล
if ($keyword === '' || mb_strlen($keyword) < 1) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'count'   => 0,
        'data'    => []
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// 3. โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO
require_once __DIR__ . '/control/connectPDO_dev.php';

try {
    // [SECURITY PATCH: Wildcard Denial-of-Service Prevention]
    // Escape อักขระพิเศษสำหรับ SQL LIKE ทันทีก่อนนำไปผูกกับ % เพื่อป้องกันไม่ให้ Database ทำ Full-Table Scan
    $escapedKeyword = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);

    // กำหนดเงื่อนไขค้นหาด้วย PDO Prepared Statements อย่างปลอดภัย 100%
    $kwLike   = '%' . $escapedKeyword . '%';
    $kwStarts = $escapedKeyword . '%';

    $conditions = [
        "(da.is_visible = 1 OR da.is_visible IS NULL)",
        "(da.DA_Number LIKE :kw1 OR da.DA_ListName LIKE :kw2 OR da.DA_Brand LIKE :kw3)"
    ];

    $params = [
        ':kw1'       => $kwLike,
        ':kw2'       => $kwLike,
        ':kw3'       => $kwLike,
        ':exact_kw'  => $keyword,
        ':starts_kw' => $kwStarts
    ];

    // คัดกรองเฉพาะสถานะพร้อมใช้งาน (s0001) ตาม Requirement สำหรับการยืม
    if ($availableOnly) {
        $conditions[] = "(da.statusID = 's0001' OR sa.statusName LIKE '%พร้อมใช้%' OR da.statusID IS NULL)";
    }

    $whereSql = implode(' AND ', $conditions);

    $sql = "SELECT da.DA_ID, 
                   da.DA_Number, 
                   da.DA_ListName, 
                   da.DA_Brand, 
                   da.DA_Year, 
                   da.DA_Price,
                   da.statusID,
                   COALESCE(sa.statusName, 'พร้อมใช้') AS statusName,
                   c.classificationName, 
                   b.buildingName, 
                   bc.classroomName,
                   da.DA_URLImg
            FROM durable_articles da
            LEFT JOIN classification c ON da.classificationID = c.classificationID
            LEFT JOIN building b ON da.buildingID = b.buildingID
            LEFT JOIN building_classroom bc ON da.classroomID = bc.classroomID
            LEFT JOIN status_active sa ON da.statusID = sa.statusID
            WHERE {$whereSql}
            ORDER BY 
              CASE 
                WHEN da.DA_Number = :exact_kw THEN 1
                WHEN da.DA_Number LIKE :starts_kw THEN 2
                WHEN da.DA_ListName LIKE :starts_kw THEN 3
                ELSE 4
              END,
              da.DA_Number ASC
            LIMIT 10";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cleanData = [];
    foreach ($rows as $r) {
        $cleanNumber = trim($r['DA_Number'], "'\" \t\n\r\0\x0B");
        $cleanData[] = [
            'da_id'               => $r['DA_ID'],
            'da_number'           => $cleanNumber,
            'da_name'             => $r['DA_ListName'],
            'da_brand'            => $r['DA_Brand'] ?? '',
            'status_id'           => $r['statusID'] ?? 's0001',
            'status_name'         => $r['statusName'] ?? 'พร้อมใช้',
            'classification_name' => $r['classificationName'] ?? '',
            'building_name'       => $r['buildingName'] ?? '',
            'classroom_name'      => $r['classroomName'] ?? '',
            'location'            => trim(($r['buildingName'] ?? '') . ' ' . ($r['classroomName'] ?? '')),
            'image'               => !empty($r['DA_URLImg']) ? $r['DA_URLImg'] : ''
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'count'   => count($cleanData),
        'data'    => $cleanData
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
