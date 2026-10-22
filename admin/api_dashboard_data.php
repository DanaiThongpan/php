<?php
/**
 * api_dashboard_data.php
 * API Endpoint สำหรับดึงข้อมูลสถิติ แดชบอร์ด กราฟ และกิจกรรมล่าสุด แบบ Asynchronous (JSON)
 * ออกแบบเพื่อรองรับ High-Performance & Non-blocking rendering
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

// 2. โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO
require_once __DIR__ . '/../control/connectPDO_dev.php';

// 3. กำหนดตัวแปรและข้อมูลแผนกของ Admin
$adminDept = $_SESSION['department_id'] 
          ?? $_SESSION['department_name'] 
          ?? $_SESSION['userDepartment'] 
          ?? '';

$currentUserID   = $_SESSION['SeUser'] ?? '';
$useraccountType = $_SESSION['useraccountType'] ?? '';
$sessionUsername = $_SESSION['username'] ?? '';
$sessionSename   = $_SESSION['Sename'] ?? '';

// ตรวจสอบสิทธิ์ Super Admin: หากเป็น Super Admin หรือ administrator ต้องดึงข้อมูลทุกแผนก ห้ามกรองแผนกเด็ดขาด
$isSuperAdmin = (
    $useraccountType === 'Super Admin' ||
    strtolower($currentUserID) === 'admin' ||
    strtolower($sessionUsername) === 'admin' ||
    strtolower($sessionSename) === 'administrator'
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
$visCondition   = " AND (is_visible = 1 OR is_visible IS NULL)";
$visConditionDa = " AND (da.is_visible = 1 OR da.is_visible IS NULL)";

if ($viewMode === 'all') {
    $visCondition   = "";
    $visConditionDa = "";
} elseif ($viewMode === 'hidden') {
    $visCondition   = " AND is_visible = 0";
    $visConditionDa = " AND da.is_visible = 0";
}

$today = date('Y-m-d');

try {
    // -------------------------------------------------------------------------
    // Query 1: ครุภัณฑ์ทั้งหมด (Total Assets)
    // สำหรับ Super Admin: ดึงข้อมูลของทุกแผนก ห้ามใส่ WHERE department เด็ดขาด
    // -------------------------------------------------------------------------
    if ($isSuperAdmin) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM durable_articles WHERE (is_approved = 1 OR is_approved IS NULL) {$visCondition}");
        $totalDA = (int)$stmt->fetchColumn();
    } else {
        $sqlTotal = "SELECT COUNT(*) FROM durable_articles 
                     WHERE (is_approved = 1 OR is_approved IS NULL) 
                       {$visCondition}
                       AND (owner_department = :dept OR (owner_department IS NULL AND created_by = :uid))";
        $stmt = $pdo->prepare($sqlTotal);
        $stmt->execute([':dept' => $adminDept, ':uid' => $currentUserID]);
        $totalDA = (int)$stmt->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // Query 2: กำลังถูกยืม (Currently Borrowed)
    // สำหรับ Super Admin: ดึงข้อมูลของทุกแผนก
    // -------------------------------------------------------------------------
    if ($isSuperAdmin) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM borrow_back b
                             INNER JOIN durable_articles da ON b.DA_ID = da.DA_ID
                             WHERE (b.Date_back IS NULL OR b.Date_back = '') {$visConditionDa}");
        $borrowedDA = (int)$stmt->fetchColumn();
    } else {
        $sqlBorrowed = "SELECT COUNT(*) 
                        FROM borrow_back b
                        INNER JOIN durable_articles da ON b.DA_ID = da.DA_ID
                        WHERE (b.Date_back IS NULL OR b.Date_back = '')
                          {$visConditionDa}
                          AND (da.owner_department = :dept OR (da.owner_department IS NULL AND da.created_by = :uid))";
        $stmt = $pdo->prepare($sqlBorrowed);
        $stmt->execute([':dept' => $adminDept, ':uid' => $currentUserID]);
        $borrowedDA = (int)$stmt->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // Query 3: เลยกำหนดส่งคืน (Overdue)
    // สำหรับ Super Admin: ดึงข้อมูลของทุกแผนก
    // -------------------------------------------------------------------------
    if ($isSuperAdmin) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrow_back b
                               INNER JOIN durable_articles da ON b.DA_ID = da.DA_ID
                               WHERE (b.Date_back IS NULL OR b.Date_back = '') 
                                 AND b.Deadline_borrow < :today
                                 {$visConditionDa}");
        $stmt->execute([':today' => $today]);
        $overdueDA = (int)$stmt->fetchColumn();
    } else {
        $sqlOverdue = "SELECT COUNT(*) 
                       FROM borrow_back b
                       INNER JOIN durable_articles da ON b.DA_ID = da.DA_ID
                       WHERE (b.Date_back IS NULL OR b.Date_back = '')
                         AND b.Deadline_borrow < :today
                         {$visConditionDa}
                         AND (da.owner_department = :dept OR (da.owner_department IS NULL AND da.created_by = :uid))";
        $stmt = $pdo->prepare($sqlOverdue);
        $stmt->execute([':today' => $today, ':dept' => $adminDept, ':uid' => $currentUserID]);
        $overdueDA = (int)$stmt->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // Query 4: คำขอรออนุมัติขึ้นทะเบียนใหม่ (Pending Requests)
    // สำหรับ Super Admin: ดึงข้อมูลของทุกแผนก
    // -------------------------------------------------------------------------
    if ($isSuperAdmin) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM durable_articles WHERE is_approved = 0 {$visCondition}");
        $pendingDA = (int)$stmt->fetchColumn();
    } else {
        $sqlPending = "SELECT COUNT(*) FROM durable_articles 
                       WHERE is_approved = 0 
                         {$visCondition}
                         AND (owner_department = :dept OR (owner_department IS NULL AND created_by = :uid))";
        $stmt = $pdo->prepare($sqlPending);
        $stmt->execute([':dept' => $adminDept, ':uid' => $currentUserID]);
        $pendingDA = (int)$stmt->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // Query 5: ชำรุด/ไม่พร้อมใช้งาน (Maintenance Status)
    // สำหรับ Super Admin: ดึงข้อมูลของทุกแผนก
    // -------------------------------------------------------------------------
    if ($isSuperAdmin) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM durable_articles 
                             WHERE statusID != 's0001' AND (is_approved = 1 OR is_approved IS NULL) {$visCondition}");
        $maintenanceDA = (int)$stmt->fetchColumn();
    } else {
        $sqlMaint = "SELECT COUNT(*) FROM durable_articles 
                     WHERE statusID != 's0001' 
                       AND (is_approved = 1 OR is_approved IS NULL)
                       {$visCondition}
                       AND (owner_department = :dept OR (owner_department IS NULL AND created_by = :uid))";
        $stmt = $pdo->prepare($sqlMaint);
        $stmt->execute([':dept' => $adminDept, ':uid' => $currentUserID]);
        $maintenanceDA = (int)$stmt->fetchColumn();
    }

    // คำนวณพร้อมใช้งาน
    $availableDA = max(0, $totalDA - $borrowedDA - $maintenanceDA);

    // -------------------------------------------------------------------------
    // Query 6: สถิติแยกตามหมวดหมู่ (Category Distribution)
    // สำหรับ Super Admin: ดึงข้อมูลของทุกแผนก
    // -------------------------------------------------------------------------
    $barLabels = [];
    $barValues = [];

    if ($isSuperAdmin) {
        $sqlBar = "SELECT c.classificationName, COUNT(da.DA_ID) AS total 
                   FROM classification c 
                   INNER JOIN durable_articles da ON c.classificationID = da.classificationID 
                        AND (da.is_approved = 1 OR da.is_approved IS NULL)
                        {$visConditionDa}
                   GROUP BY c.classificationID, c.classificationName 
                   ORDER BY total DESC 
                   LIMIT 6";
        $stmtBar = $pdo->query($sqlBar);
    } else {
        $sqlBar = "SELECT c.classificationName, COUNT(da.DA_ID) AS total 
                   FROM classification c 
                   INNER JOIN durable_articles da ON c.classificationID = da.classificationID 
                        AND (da.is_approved = 1 OR da.is_approved IS NULL)
                        {$visConditionDa}
                        AND (da.owner_department = :dept OR (da.owner_department IS NULL AND da.created_by = :uid))
                   GROUP BY c.classificationID, c.classificationName 
                   ORDER BY total DESC 
                   LIMIT 6";
        $stmtBar = $pdo->prepare($sqlBar);
        $stmtBar->execute([':dept' => $adminDept, ':uid' => $currentUserID]);
    }
    $barRows = $stmtBar->fetchAll(PDO::FETCH_ASSOC);

    foreach ($barRows as $row) {
        $barLabels[] = $row['classificationName'];
        $barValues[] = (int)$row['total'];
    }

    // -------------------------------------------------------------------------
    // Query 7: ความเคลื่อนไหวล่าสุด 5 รายการ (Recent Activities)
    // สำหรับ Super Admin: ดึงข้อมูลของทุกแผนก
    // -------------------------------------------------------------------------
    if ($isSuperAdmin) {
        $sqlRecent = "SELECT b.IDborrow_back, b.DA_ID, b.Date_borrow, b.Deadline_borrow, b.Date_back, 
                             b.Name_borrow, da.DA_Number, da.DA_ListName, b.timestamp_borrow
                      FROM borrow_back b
                      LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
                      WHERE 1=1 {$visConditionDa}
                      ORDER BY b.IDborrow_back DESC
                      LIMIT 5";
        $stmtRecent = $pdo->query($sqlRecent);
    } else {
        $sqlRecent = "SELECT b.IDborrow_back, b.DA_ID, b.Date_borrow, b.Deadline_borrow, b.Date_back, 
                             b.Name_borrow, da.DA_Number, da.DA_ListName, b.timestamp_borrow
                      FROM borrow_back b
                      INNER JOIN durable_articles da ON b.DA_ID = da.DA_ID
                      WHERE (da.owner_department = :dept OR (da.owner_department IS NULL AND da.created_by = :uid))
                        {$visConditionDa}
                      ORDER BY b.IDborrow_back DESC
                      LIMIT 5";
        $stmtRecent = $pdo->prepare($sqlRecent);
        $stmtRecent->execute([':dept' => $adminDept, ':uid' => $currentUserID]);
    }
    $rawActivities = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

    $recentActivities = [];
    foreach ($rawActivities as $act) {
        $isReturned = !empty($act['Date_back']);
        $isOverdue  = (!$isReturned && !empty($act['Deadline_borrow']) && $act['Deadline_borrow'] < $today);
        
        $recentActivities[] = [
            'IDborrow_back'   => (int)$act['IDborrow_back'],
            'DA_ID'           => (int)$act['DA_ID'],
            'DA_Number'       => $act['DA_Number'] ?? '-',
            'DA_ListName'     => $act['DA_ListName'] ?? 'ไม่ระบุชื่อ',
            'Name_borrow'     => $act['Name_borrow'] ?? 'ไม่ระบุ',
            'Date_borrow'     => $act['Date_borrow'] ?? '-',
            'Deadline_borrow' => $act['Deadline_borrow'] ?? '-',
            'Date_back'       => $act['Date_back'] ?? null,
            'isReturned'      => $isReturned,
            'isOverdue'       => $isOverdue
        ];
    }

    // ส่งข้อมูล JSON สำเร็จ
    echo json_encode([
        'status' => 'success',
        'generated_at' => date('Y-m-d H:i:s'),
        'view_mode' => $viewMode,
        'data' => [
            'stats' => [
                'totalDA'       => $totalDA,
                'borrowedDA'    => $borrowedDA,
                'overdueDA'     => $overdueDA,
                'pendingDA'     => $pendingDA,
                'maintenanceDA' => $maintenanceDA,
                'availableDA'   => $availableDA
            ],
            'charts' => [
                'status' => [
                    'labels' => ['พร้อมใช้งาน', 'กำลังถูกยืม', 'ชำรุด / ไม่พร้อมใช้'],
                    'values' => [$availableDA, $borrowedDA, $maintenanceDA]
                ],
                'category' => [
                    'labels' => $barLabels,
                    'values' => $barValues
                ]
            ],
            'recentActivities' => $recentActivities
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูลฐานข้อมูล: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
