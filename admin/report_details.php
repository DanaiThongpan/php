<?php
/**
 * admin/report_details.php - หน้ารายงานและสถิติเชิงลึก (Advanced Analytics & Reports)
 * พัฒนาตาม Requirement:
 * 1. Global Date Range Filter พร้อมปุ่ม Export PDF / Excel
 * 2. Bootstrap 5 Tabs แบ่งเป็น 3 แท็บหลัก (ภาพรวมสถานะ, สถิติการยืม-คืน, บทวิเคราะห์เชิงลึก)
 * 3. ชาร์ต Chart.js สวยงามพรีเมียม (Doughnut, Bar, Line, Horizontal Bar, Polar Area)
 * 4. ตาราง Data Table ไฮไลต์สีแดงสำหรับรายการเกินกำหนดส่งคืน (Overdue)
 * 5. คอมเมนต์ภาษาไทยอธิบาย SQL Query แต่ละตัวอย่างละเอียด
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------------
// 1. ตรวจสอบสิทธิ์การเข้าถึง (Admin Security Check)
// -----------------------------------------------------------------------------
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1) {
    header("Location: ../index.php");
    exit();
}

// -----------------------------------------------------------------------------
// 2. โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO และ BorrowService
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/BorrowService.php';

$useraccountType = $_SESSION['useraccountType'] ?? '';
$currentUserID   = $_SESSION['SeUser'] ?? '';
$useraccountName = $_SESSION['Sename'] ?? 'ผู้ดูแลระบบ';

$adminDept = $_SESSION['department_id'] 
          ?? $_SESSION['department_name'] 
          ?? $_SESSION['userDepartment'] 
          ?? '';

// ตรวจสอบเงื่อนไข Super Admin อย่างรัดกุม
$isSuperAdmin = (
    $useraccountType === 'Super Admin' 
    || in_array(strtolower($currentUserID), ['admin', 'admin_test'])
    || strtolower($currentUserID) === 'administrator' 
    || strtolower($_SESSION['username'] ?? '') === 'admin'
    || strtolower($useraccountName) === 'administrator'
    || empty($adminDept)
    || $adminDept === 'ส่วนกลาง'
);

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
$currentUserDept = $adminDept;

// -----------------------------------------------------------------------------
// 3. จัดการคำขอ AJAX อนุมัติรับคืนครุภัณฑ์ (รองรับระบบยืม-คืนเดิม)
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_return', 'mark_returned'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $borrowId = trim($_POST['id'] ?? $_POST['borrow_id'] ?? '');

    if (empty($borrowId)) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัสรายการยืม-คืน']);
        exit();
    }

    $adminName = $_SESSION['Sename'] ?? 'ผู้ดูแลระบบ';
    $result = BorrowService::approveReturn($pdo, [
        'borrow_id'         => $borrowId,
        'approved_by_name'  => $adminName,
        'approved_by_uid'   => $currentUserID,
        'approved_by_dept'  => $currentUserDept,
        'is_super_admin'    => $isSuperAdmin,
        'force'             => true
    ]);

    echo json_encode([
        'status'    => $result['success'] ? 'success' : 'error',
        'message'   => $result['message'],
        'item_name' => $result['da_name'] ?? 'ครุภัณฑ์'
    ]);
    exit();
}

// -----------------------------------------------------------------------------
// 4. รับค่าตัวกรองช่วงวันที่ (Date Range Filter) และกำหนดค่าเริ่มต้น
// -----------------------------------------------------------------------------
$today = date('Y-m-d');
// ค่าเริ่มต้น: ย้อนหลัง 6 เดือน ถึง วันที่ปัจจุบัน
$defaultStartDate = date('Y-m-d', strtotime('-6 months'));
$defaultEndDate   = $today;

$startDate = trim($_GET['start_date'] ?? $defaultStartDate);
$endDate   = trim($_GET['end_date'] ?? $defaultEndDate);

if (empty($startDate)) $startDate = $defaultStartDate;
if (empty($endDate))   $endDate   = $defaultEndDate;

// เงื่อนไขสิทธิ์แผนก (Department Isolation Parameter)
$deptConditionDA = "";
$deptConditionB  = "";
$commonParams    = [];

if (!$isSuperAdmin && !empty($currentUserDept)) {
    $deptConditionDA = " AND (da.owner_department = :dept OR da.owner_department = 'ส่วนกลาง' OR (da.owner_department IS NULL AND da.created_by = :uid))";
    $deptConditionB  = " AND (da.owner_department = :dept OR da.owner_department = 'ส่วนกลาง' OR (da.owner_department IS NULL AND da.created_by = :uid))";
    $commonParams[':dept'] = $currentUserDept;
    $commonParams[':uid']  = $currentUserID;
}

// =============================================================================
// [SQL QUERY 1]: สัดส่วนสถานะครุภัณฑ์ทั้งหมด (สำหรับ Doughnut Chart 1.1)
// ตาราง: durable_articles (da) เชื่อมกับ borrow_back (b)
// เงื่อนไข: นับเฉพาะครุภัณฑ์ที่ผ่านการอนุมัติ (is_approved = 1) และแบ่งเป็น 3 กลุ่ม:
//   - พร้อมใช้งาน: statusID = 's0001' และไม่ได้ติดยืมอยู่
//   - กำลังถูกยืม: มีรายการใน borrow_back ที่ยังไม่คืน หรือ statusID = 's0003'
//   - ชำรุด/สูญหาย: statusID = 's0002' หรือสถานะไม่ปกติ และไม่ได้ถูกยืมอยู่
// =============================================================================
$invAvailableCount = 0;
$invBorrowedCount  = 0;
$invDamagedCount   = 0;
$invTotalCount     = 0;

try {
    $sqlStatusDist = "
        SELECT 
            SUM(CASE WHEN (da.statusID = 's0001' OR da.statusID IS NULL OR da.statusID = '') AND b.DA_ID IS NULL THEN 1 ELSE 0 END) AS available_count,
            SUM(CASE WHEN b.DA_ID IS NOT NULL OR da.statusID = 's0003' THEN 1 ELSE 0 END) AS borrowed_count,
            SUM(CASE WHEN (da.statusID = 's0002' OR (da.statusID NOT IN ('s0001', 's0003') AND da.statusID IS NOT NULL AND da.statusID != '')) AND b.DA_ID IS NULL THEN 1 ELSE 0 END) AS damaged_count,
            COUNT(da.DA_ID) AS total_count
        FROM durable_articles da
        LEFT JOIN (
            SELECT DISTINCT DA_ID 
            FROM borrow_back 
            WHERE (Date_back IS NULL OR Date_back = '') 
              AND (status != 'returned' OR status IS NULL)
        ) b ON da.DA_ID = b.DA_ID
        WHERE (da.is_approved = 1 OR da.is_approved IS NULL)
        {$deptConditionDA}
    ";
    $stmtStatusDist = $pdo->prepare($sqlStatusDist);
    $stmtStatusDist->execute($commonParams);
    $statusDistRes = $stmtStatusDist->fetch(PDO::FETCH_ASSOC);

    if ($statusDistRes) {
        $invAvailableCount = (int)($statusDistRes['available_count'] ?? 0);
        $invBorrowedCount  = (int)($statusDistRes['borrowed_count'] ?? 0);
        $invDamagedCount   = (int)($statusDistRes['damaged_count'] ?? 0);
        $invTotalCount     = (int)($statusDistRes['total_count'] ?? 0);
    }
} catch (PDOException $e) {
    $invTotalCount = 0;
}

// =============================================================================
// [SQL QUERY 2]: จำนวนครุภัณฑ์แยกตามหมวดหมู่ (สำหรับ Bar Chart 1.2)
// ตาราง: durable_articles (da) JOIN classification (c) ด้วย classificationID
// เงื่อนไข: นับจำนวนครุภัณฑ์ในแต่ละหมวดหมู่ จัดกลุ่มตามชื่อหมวดหมู่ เรียงจากมากไปน้อย
// =============================================================================
$categoriesLabels = [];
$categoriesCounts = [];
$categoriesData   = [];

try {
    $sqlCategories = "
        SELECT 
            COALESCE(c.classificationName, 'ไม่ระบุหมวดหมู่') AS cat_name,
            COUNT(da.DA_ID) AS item_count,
            SUM(COALESCE(da.DA_Price, 0)) AS total_value
        FROM durable_articles da
        LEFT JOIN classification c ON da.classificationID = c.classificationID
        WHERE (da.is_approved = 1 OR da.is_approved IS NULL)
        {$deptConditionDA}
        GROUP BY cat_name
        ORDER BY item_count DESC
        LIMIT 10
    ";
    $stmtCategories = $pdo->prepare($sqlCategories);
    $stmtCategories->execute($commonParams);
    $categoriesData = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

    foreach ($categoriesData as $cRow) {
        $categoriesLabels[] = $cRow['cat_name'];
        $categoriesCounts[] = (int)$cRow['item_count'];
    }
} catch (PDOException $e) {
    $categoriesData = [];
}

// =============================================================================
// [SQL QUERY 3]: แนวโน้มการทำรายการยืม-คืนตามช่วงเวลา (สำหรับ Line Chart 2.1)
// ตาราง: borrow_back (b) เชื่อมกับ durable_articles (da)
// ปรับปรุง: รองรับ Continuous Timeline แบบปรับตามช่วงเวลา (Adaptive Granularity)
// - หากช่วงวันที่ <= 45 วัน: แสดงผลเป็นรายวัน (Daily Timeline เช่น 01/10, 02/10...)
// - หากช่วงวันที่ > 45 วัน: แสดงผลเป็นรายเดือน (Monthly Timeline เช่น พ.ค. 69, มิ.ย. 69...)
// พร้อมเติมเต็มทุกช่วงเวลาด้วย 0 ป้องกันปัญหากราฟลีบ/จุดเดี่ยวไม่มีเส้นแนวโน้ม
// =============================================================================
$trendTimeline     = [];
$trendLabels       = [];
$trendBorrowCounts = [];
$trendReturnCounts = [];
$totalTrendBorrows = 0;
$totalTrendReturns = 0;

$thaiMonthsShort = [
    1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
    5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
    9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
];

try {
    $dtStart = new DateTime($startDate);
    $dtEnd   = new DateTime($endDate);
    
    // ตรวจสอบและสลับกรณีวันที่เริ่มต้นมากกว่าวันที่สิ้นสุด
    if ($dtStart > $dtEnd) {
        $temp      = $dtStart;
        $dtStart   = $dtEnd;
        $dtEnd     = $temp;
        $startDate = $dtStart->format('Y-m-d');
        $endDate   = $dtEnd->format('Y-m-d');
    }

    $diffDays = (int)$dtStart->diff($dtEnd)->format('%r%a');
    $isDaily  = ($diffDays <= 45);

    if ($isDaily) {
        // สร้าง Timeline รายวัน (Daily Timeline)
        $period = new DatePeriod($dtStart, new DateInterval('P1D'), (clone $dtEnd)->modify('+1 day'));
        foreach ($period as $dt) {
            $key = $dt->format('Y-m-d');
            $trendTimeline[$key] = [
                'label'   => $dt->format('d/m'),
                'borrows' => 0,
                'returns' => 0
            ];
        }

        // 3.1 สถิติการยืม (รายวัน)
        $sqlTrendBorrow = "
            SELECT 
                b.Date_borrow AS date_key,
                COUNT(b.IDborrow_back) AS total
            FROM borrow_back b
            LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
            WHERE b.Date_borrow IS NOT NULL AND b.Date_borrow != ''
              AND b.Date_borrow >= :start_date AND b.Date_borrow <= :end_date
              {$deptConditionB}
            GROUP BY b.Date_borrow
            ORDER BY b.Date_borrow ASC
        ";

        // 3.2 สถิติการส่งคืน (รายวัน)
        $sqlTrendReturn = "
            SELECT 
                b.Date_back AS date_key,
                COUNT(b.IDborrow_back) AS total
            FROM borrow_back b
            LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
            WHERE b.Date_back IS NOT NULL AND b.Date_back != ''
              AND b.Date_back >= :start_date AND b.Date_back <= :end_date
              {$deptConditionB}
            GROUP BY b.Date_back
            ORDER BY b.Date_back ASC
        ";
    } else {
        // สร้าง Timeline รายเดือน (Monthly Timeline)
        $mStart = new DateTime($dtStart->format('Y-m-01'));
        $mEnd   = new DateTime($dtEnd->format('Y-m-01'));
        $curr   = clone $mStart;
        while ($curr <= $mEnd) {
            $key   = $curr->format('Y-m');
            $mNum  = (int)$curr->format('m');
            $yrTh  = (int)$curr->format('Y') + 543 - 2500;
            $trendTimeline[$key] = [
                'label'   => $thaiMonthsShort[$mNum] . ' ' . $yrTh,
                'borrows' => 0,
                'returns' => 0
            ];
            $curr->modify('+1 month');
        }

        // 3.1 สถิติการยืม (รายเดือน)
        $sqlTrendBorrow = "
            SELECT 
                SUBSTR(b.Date_borrow, 1, 7) AS date_key,
                COUNT(b.IDborrow_back) AS total
            FROM borrow_back b
            LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
            WHERE b.Date_borrow IS NOT NULL AND b.Date_borrow != ''
              AND b.Date_borrow >= :start_date AND b.Date_borrow <= :end_date
              {$deptConditionB}
            GROUP BY date_key
            ORDER BY date_key ASC
        ";

        // 3.2 สถิติการส่งคืน (รายเดือน)
        $sqlTrendReturn = "
            SELECT 
                SUBSTR(b.Date_back, 1, 7) AS date_key,
                COUNT(b.IDborrow_back) AS total
            FROM borrow_back b
            LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
            WHERE b.Date_back IS NOT NULL AND b.Date_back != ''
              AND b.Date_back >= :start_date AND b.Date_back <= :end_date
              {$deptConditionB}
            GROUP BY date_key
            ORDER BY date_key ASC
        ";
    }

    $paramsTrend = array_merge($commonParams, [
        ':start_date' => $startDate,
        ':end_date'   => $endDate
    ]);

    // ประมวลผลยอดการยืม
    $stmtTrendB = $pdo->prepare($sqlTrendBorrow);
    $stmtTrendB->execute($paramsTrend);
    while ($r = $stmtTrendB->fetch(PDO::FETCH_ASSOC)) {
        $k = $r['date_key'] ?? '';
        if (isset($trendTimeline[$k])) {
            $trendTimeline[$k]['borrows'] = (int)$r['total'];
        }
    }

    // ประมวลผลยอดการคืน
    $stmtTrendR = $pdo->prepare($sqlTrendReturn);
    $stmtTrendR->execute($paramsTrend);
    while ($r = $stmtTrendR->fetch(PDO::FETCH_ASSOC)) {
        $k = $r['date_key'] ?? '';
        if (isset($trendTimeline[$k])) {
            $trendTimeline[$k]['returns'] = (int)$r['total'];
        }
    }

} catch (Exception $e) {}

// สร้างอาร์เรย์สำหรับส่งให้ Chart.js
foreach ($trendTimeline as $item) {
    $trendLabels[]       = $item['label'];
    $trendBorrowCounts[] = $item['borrows'];
    $trendReturnCounts[] = $item['returns'];
    $totalTrendBorrows   += $item['borrows'];
    $totalTrendReturns   += $item['returns'];
}

// Fallback ป้องกัน Array ว่าง
if (empty($trendLabels)) {
    $trendLabels       = [date('d/m')];
    $trendBorrowCounts = [0];
    $trendReturnCounts = [0];
}

// =============================================================================
// [SQL QUERY 4]: รายการเกินกำหนดส่งคืน (สำหรับ Data Table 2.2)
// ตาราง: borrow_back (b) เชื่อมกับ durable_articles (da)
// เงื่อนไข:
//   - ยังไม่ได้ส่งคืน (Date_back ว่าง/NULL และ status != 'returned')
//   - กำหนดส่งคืน (due_date หรือ Deadline_borrow) น้อยกว่าวันที่ปัจจุบัน ($today)
//   - คำนวณจำนวนวันที่เกินกำหนดอย่างแม่นยำ
// =============================================================================
$overdueItems = [];
try {
    $sqlOverdue = "
        SELECT 
            b.IDborrow_back,
            b.Date_borrow,
            COALESCE(b.due_date, b.Deadline_borrow) AS due_date,
            COALESCE(b.Name_borrow, 'ไม่ระบุ') AS borrower_name,
            b.userEmail,
            da.DA_ID,
            da.DA_Number,
            da.DA_ListName,
            da.DA_Brand,
            da.owner_department
        FROM borrow_back b
        JOIN durable_articles da ON b.DA_ID = da.DA_ID
        WHERE (b.Date_back IS NULL OR b.Date_back = '')
          AND (b.status != 'returned' OR b.status IS NULL)
          AND COALESCE(b.due_date, b.Deadline_borrow) < :today
          {$deptConditionB}
        ORDER BY due_date ASC
    ";
    $paramsOverdue = array_merge($commonParams, [':today' => $today]);
    $stmtOverdue = $pdo->prepare($sqlOverdue);
    $stmtOverdue->execute($paramsOverdue);
    $rawOverdue = $stmtOverdue->fetchAll(PDO::FETCH_ASSOC);

    $todayObj = new DateTime($today);
    foreach ($rawOverdue as $item) {
        $days = 0;
        if (!empty($item['due_date'])) {
            $dueObj = new DateTime($item['due_date']);
            $diff   = $dueObj->diff($todayObj);
            $days   = (int)$diff->format('%a');
        }
        $item['days_overdue'] = $days;
        $overdueItems[] = $item;
    }
} catch (PDOException $e) {
    $overdueItems = [];
}
$overdueTotalCount = count($overdueItems);

// =============================================================================
// [SQL QUERY 5]: Top 5 ครุภัณฑ์ที่ถูกยืมบ่อยที่สุด (สำหรับ Horizontal Bar Chart 3.1)
// ตาราง: borrow_back (b) เชื่อมกับ durable_articles (da)
// เงื่อนไข:
//   - นับจำนวนครั้งที่ถูกยืม (COUNT(b.IDborrow_back))
//   - จัดกลุ่มตามรหัสครุภัณฑ์ (b.DA_ID)
//   - เรียงลำดับจากจำนวนครั้งที่ถูกยืมมากที่สุด 5 อันดับแรก
// =============================================================================
$topItemsLabels = [];
$topItemsCounts = [];
$topItemsList   = [];

try {
    $sqlTopItems = "
        SELECT 
            da.DA_ID,
            da.DA_Number,
            da.DA_ListName,
            da.DA_Brand,
            da.owner_department,
            COUNT(b.IDborrow_back) AS borrow_count
        FROM borrow_back b
        JOIN durable_articles da ON b.DA_ID = da.DA_ID
        WHERE 1=1
        {$deptConditionB}
        GROUP BY b.DA_ID
        ORDER BY borrow_count DESC, da.DA_ListName ASC
        LIMIT 5
    ";
    $stmtTopItems = $pdo->prepare($sqlTopItems);
    $stmtTopItems->execute($commonParams);
    $topItemsList = $stmtTopItems->fetchAll(PDO::FETCH_ASSOC);

    foreach ($topItemsList as $tRow) {
        $cleanName = trim($tRow['DA_ListName']);
        if (mb_strlen($cleanName, 'UTF-8') > 30) {
            $cleanName = mb_substr($cleanName, 0, 28, 'UTF-8') . '...';
        }
        $topItemsLabels[] = $cleanName;
        $topItemsCounts[] = (int)$tRow['borrow_count'];
    }
} catch (PDOException $e) {
    $topItemsList = [];
}

// =============================================================================
// [SQL QUERY 6]: Top 5 ผู้ใช้งานที่ทำรายการยืมสูงสุด (สำหรับ Polar Area Chart 3.2)
// ตาราง: borrow_back (b)
// เงื่อนไข:
//   - นับจำนวนครั้งที่ทำรายการยืม (COUNT(b.IDborrow_back))
//   - จัดกลุ่มตามชื่อผู้ยืม (Name_borrow)
//   - เรียงลำดับจากผู้ที่ยืมบ่อยที่สุด 5 อันดับแรก
// =============================================================================
$topUsersLabels = [];
$topUsersCounts = [];
$topUsersList   = [];

try {
    $sqlTopUsers = "
        SELECT 
            COALESCE(b.Name_borrow, 'ไม่ระบุชื่อ') AS borrower_name,
            b.userEmail,
            b.Belongto_borrow,
            COUNT(b.IDborrow_back) AS borrow_count
        FROM borrow_back b
        LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
        WHERE b.Name_borrow IS NOT NULL AND b.Name_borrow != ''
        {$deptConditionB}
        GROUP BY borrower_name
        ORDER BY borrow_count DESC
        LIMIT 5
    ";
    $stmtTopUsers = $pdo->prepare($sqlTopUsers);
    $stmtTopUsers->execute($commonParams);
    $topUsersList = $stmtTopUsers->fetchAll(PDO::FETCH_ASSOC);

    foreach ($topUsersList as $uRow) {
        $topUsersLabels[] = $uRow['borrower_name'];
        $topUsersCounts[] = (int)$uRow['borrow_count'];
    }
} catch (PDOException $e) {
    $topUsersList = [];
}

// -----------------------------------------------------------------------------
// 5. โหลดส่วนประกอบ Layout หลัก (Header, Sidebar & Navbar)
// -----------------------------------------------------------------------------
$pageTitle = "รายงานและสถิติเชิงลึก (Advanced Analytics & Reports)";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>

<style>
    :root {
        --report-card-radius: 16px;
        --report-soft-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.03);
        --report-hover-shadow: 0 10px 25px -3px rgba(15, 23, 42, 0.1);
    }

    /* Top Control Bar Card */
    .report-control-card {
        border: 1px solid #e2e8f0;
        border-radius: var(--report-card-radius);
        background: #ffffff;
        box-shadow: var(--report-soft-shadow);
    }

    /* Custom Report Tabs Navigation */
    .custom-report-tabs {
        border-bottom: none;
        gap: 0.5rem;
    }
    .custom-report-tabs .nav-link {
        border-radius: 50rem;
        padding: 0.6rem 1.4rem;
        font-weight: 600;
        font-size: 0.92rem;
        color: #64748b;
        background-color: #f1f5f9;
        border: 1px solid #e2e8f0;
        transition: all 0.2s ease;
    }
    .custom-report-tabs .nav-link:hover {
        color: #2563eb;
        background-color: #e2e8f0;
    }
    .custom-report-tabs .nav-link.active {
        color: #ffffff;
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
        border-color: transparent;
    }

    /* Report Card Container */
    .analytics-card {
        border: 1px solid #e2e8f0;
        border-radius: var(--report-card-radius);
        background: #ffffff;
        box-shadow: var(--report-soft-shadow);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        overflow: hidden;
    }
    .analytics-card:hover {
        box-shadow: var(--report-hover-shadow);
    }
    .analytics-card .card-header {
        background-color: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        padding: 1.15rem 1.4rem;
    }
    .analytics-card .card-body {
        padding: 1.4rem;
    }

    /* Responsive Chart Container */
    .chart-box {
        position: relative;
        height: 330px;
        width: 100%;
    }

    /* Mini KPI Metric Badges */
    .mini-kpi {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 1rem 1.25rem;
        background: #ffffff;
        box-shadow: var(--report-soft-shadow);
        position: relative;
        overflow: hidden;
    }
    .mini-kpi::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
    }
    .mini-kpi-blue::before    { background-color: #2563eb; }
    .mini-kpi-green::before   { background-color: #10b981; }
    .mini-kpi-amber::before   { background-color: #f59e0b; }
    .mini-kpi-red::before     { background-color: #ef4444; }
    .mini-kpi-purple::before  { background-color: #8b5cf6; }

    /* Print Stylesheet */
    @media print {
        #sidebar, #top-navbar, .no-print, .btn, .custom-report-tabs, .report-control-card {
            display: none !important;
        }
        #main-wrapper, #main-content, .container-fluid {
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: visible !important;
        }
        .tab-pane {
            display: block !important;
            opacity: 1 !important;
            visibility: visible !important;
            page-break-after: always;
        }
        .analytics-card {
            border: 1px solid #ccc !important;
            box-shadow: none !important;
            break-inside: avoid;
            margin-bottom: 2rem !important;
        }
    }
</style>

<div class="container-fluid px-0 mb-5">

    <!-- ส่วนหัวหน้าจอ (Header Section) -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small text-muted">
                    <li class="breadcrumb-item"><a href="main.php" class="text-decoration-none text-muted"><i class="fa-solid fa-house me-1"></i>หน้าแรก</a></li>
                    <li class="breadcrumb-item active fw-semibold text-primary" aria-current="page">รายงานและสถิติเชิงลึก</li>
                </ol>
            </nav>
            <h3 class="fw-bold text-dark mb-0 d-flex align-items-center">
                <span class="rounded-3 bg-primary-subtle text-primary p-2 me-2 d-inline-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                    <i class="fa-solid fa-chart-pie fa-sm"></i>
                </span>
                รายงานและสถิติเชิงลึก (Advanced Analytics)
            </h3>
            <p class="text-muted small mb-0 mt-1">
                ศูนย์รวมภาพรวมสถิติทรัพย์สิน แนวโน้มการยืม-คืน และบทวิเคราะห์เชิงลึกสำหรับผู้บริหารและผู้ดูแลระบบ
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($isSuperAdmin): ?>
                <span class="badge bg-danger shadow-xs px-3 py-2 rounded-pill">
                    <i class="fa-solid fa-globe me-1"></i> ทุกแผนก (Super Admin)
                </span>
            <?php else: ?>
                <span class="badge bg-primary shadow-xs px-3 py-2 rounded-pill">
                    <i class="fa-solid fa-building me-1"></i> แผนก: <?= htmlspecialchars($currentUserDept ?: 'ส่วนกลาง'); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ส่วนควบคุมตัวกรองช่วงเวลา (Global Date Range Picker & Export Buttons) -->
    <div class="report-control-card p-3 p-md-4 mb-4">
        <form method="GET" action="report_details.php" class="row g-2 align-items-center" id="dateFilterForm">
            <div class="col-12 col-md-auto d-flex align-items-center gap-2">
                <span class="fw-bold text-secondary small text-nowrap">
                    <i class="fa-regular fa-calendar-days text-primary me-1"></i> ช่วงวันที่วิเคราะห์:
                </span>
            </div>
            <div class="col-6 col-md-auto">
                <input type="date" name="start_date" id="startDateInput" class="form-control form-control-sm border shadow-xs" value="<?= htmlspecialchars($startDate); ?>">
            </div>
            <div class="col-auto text-muted small text-center">ถึง</div>
            <div class="col-6 col-md-auto">
                <input type="date" name="end_date" id="endDateInput" class="form-control form-control-sm border shadow-xs" value="<?= htmlspecialchars($endDate); ?>">
            </div>
            <div class="col-12 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3 shadow-xs">
                    <i class="fa-solid fa-filter me-1"></i> กรองข้อมูล
                </button>
                <div class="btn-group btn-group-sm rounded-pill border shadow-xs d-none d-lg-inline-flex" role="group">
                    <button type="button" class="btn btn-light btn-sm btn-preset" data-days="30">30 วันล่าสุด</button>
                    <button type="button" class="btn btn-light btn-sm btn-preset" data-days="180">6 เดือน</button>
                    <button type="button" class="btn btn-light btn-sm btn-preset" data-range="thisyear">ปีนี้</button>
                </div>
            </div>

            <!-- ปุ่มคำสั่ง Export PDF & Excel -->
            <div class="col-12 col-md-auto ms-md-auto d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3 shadow-xs" onclick="window.print();" title="พิมพ์รายงาน หรือ บันทึกเป็น PDF">
                    <i class="fa-solid fa-file-pdf me-1"></i> Export เป็น PDF
                </button>
                <button type="button" class="btn btn-outline-success btn-sm rounded-pill px-3 shadow-xs" id="btnExportExcel" title="ส่งออกข้อมูลเป็นไฟล์ Excel / CSV">
                    <i class="fa-solid fa-file-excel me-1"></i> Export เป็น Excel
                </button>
            </div>
        </form>
    </div>

    <!-- แถบเลือกแท็บหลัก 3 หมวดหมู่ (Bootstrap 5 Tabs) -->
    <ul class="nav nav-pills custom-report-tabs mb-4 no-print" id="reportMainTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-inventory-btn" data-bs-toggle="pill" data-bs-target="#tab-inventory" type="button" role="tab" aria-controls="tab-inventory" aria-selected="true">
                <i class="fa-solid fa-boxes-stacked me-2"></i>Tab 1: ภาพรวมสถานะครุภัณฑ์
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-borrowing-btn" data-bs-toggle="pill" data-bs-target="#tab-borrowing" type="button" role="tab" aria-controls="tab-borrowing" aria-selected="false">
                <i class="fa-solid fa-chart-line me-2"></i>Tab 2: สถิติการยืม-คืน
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-analytics-btn" data-bs-toggle="pill" data-bs-target="#tab-analytics" type="button" role="tab" aria-controls="tab-analytics" aria-selected="false">
                <i class="fa-solid fa-ranking-star me-2"></i>Tab 3: บทวิเคราะห์เชิงลึก
            </button>
        </li>
    </ul>

    <!-- เนื้อหาภายในแต่ละแท็บ (Tab Content Panes) -->
    <div class="tab-content" id="reportMainTabsContent">

        <!-- =================================================================== -->
        <!-- TAB 1: ภาพรวมสถานะครุภัณฑ์ (Inventory Status) -->
        <!-- =================================================================== -->
        <div class="tab-pane fade show active" id="tab-inventory" role="tabpanel" aria-labelledby="tab-inventory-btn">
            
            <!-- สรุปสถิติ Mini KPIs ภาพรวมครุภัณฑ์ -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="mini-kpi mini-kpi-blue">
                        <div class="text-muted small fw-semibold">ครุภัณฑ์ทั้งหมดในคลัง</div>
                        <h3 class="fw-bold text-dark mb-0 mt-1"><?= number_format($invTotalCount); ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="mini-kpi mini-kpi-green">
                        <div class="text-muted small fw-semibold">พร้อมใช้งาน (Available)</div>
                        <h3 class="fw-bold text-success mb-0 mt-1"><?= number_format($invAvailableCount); ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="mini-kpi mini-kpi-amber">
                        <div class="text-muted small fw-semibold">กำลังถูกยืม (Borrowed)</div>
                        <h3 class="fw-bold text-warning mb-0 mt-1"><?= number_format($invBorrowedCount); ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="mini-kpi mini-kpi-red">
                        <div class="text-muted small fw-semibold">ชำรุด / สูญหาย (Damaged)</div>
                        <h3 class="fw-bold text-danger mb-0 mt-1"><?= number_format($invDamagedCount); ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                </div>
            </div>

            <!-- กราฟหมวดที่ 1 -->
            <div class="row g-4 mb-4">
                <!-- Chart 1.1: สัดส่วนสถานะครุภัณฑ์ทั้งหมด (Doughnut Chart) -->
                <div class="col-12 col-lg-5">
                    <div class="analytics-card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-pie text-primary me-2"></i>Chart 1.1: สัดส่วนสถานะครุภัณฑ์ทั้งหมด</h6>
                                <small class="text-muted">เปรียบเทียบสัดส่วน: พร้อมใช้งาน vs กำลังถูกยืม vs ชำรุด/สูญหาย</small>
                            </div>
                        </div>
                        <div class="card-body d-flex flex-column justify-content-center align-items-center">
                            <div class="chart-box">
                                <canvas id="chartStatusDoughnut"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Chart 1.2: จำนวนครุภัณฑ์แยกตามหมวดหมู่ (Bar Chart) -->
                <div class="col-12 col-lg-7">
                    <div class="analytics-card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-column text-success me-2"></i>Chart 1.2: จำนวนครุภัณฑ์แยกตามหมวดหมู่</h6>
                                <small class="text-muted">จัดอันดับหมวดหมู่ครุภัณฑ์ที่มีจำนวนในคลังมากที่สุด (Top 10)</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="chart-box">
                                <canvas id="chartCategoryBar"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ตารางสรุปข้อมูลตามหมวดหมู่ -->
            <div class="analytics-card">
                <div class="card-header">
                    <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-table-list text-primary me-2"></i>สรุปจำนวนและมูลค่าจัดซื้อแยกตามหมวดหมู่</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="tableCategorySummary">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width: 50px;">#</th>
                                    <th>ชื่อหมวดหมู่ครุภัณฑ์</th>
                                    <th class="text-center" style="width: 150px;">จำนวน (รายการ)</th>
                                    <th class="text-end" style="width: 200px;">มูลค่ารวม (บาท)</th>
                                    <th class="text-center" style="width: 150px;">สัดส่วน</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($categoriesData)): ?>
                                    <?php $cIdx = 1; foreach ($categoriesData as $cRow): 
                                        $ratio = ($invTotalCount > 0) ? round(($cRow['item_count'] / $invTotalCount) * 100, 1) : 0;
                                    ?>
                                        <tr>
                                            <td class="text-center font-monospace text-muted"><?= $cIdx++; ?></td>
                                            <td class="fw-bold text-dark"><?= htmlspecialchars($cRow['cat_name']); ?></td>
                                            <td class="text-center font-monospace fw-semibold"><?= number_format($cRow['item_count']); ?></td>
                                            <td class="text-end font-monospace text-success fw-bold">฿<?= number_format((float)$cRow['total_value'], 2); ?></td>
                                            <td class="text-center">
                                                <div class="progress" style="height: 6px;" title="<?= $ratio ?>%">
                                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $ratio ?>%"></div>
                                                </div>
                                                <small class="text-muted" style="font-size: 0.72rem;"><?= $ratio ?>%</small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">ไม่พบข้อมูลหมวดหมู่ครุภัณฑ์</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <!-- =================================================================== -->
        <!-- TAB 2: สถิติการยืม-คืน (Borrowing Trends & Overdue Table) -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="tab-borrowing" role="tabpanel" aria-labelledby="tab-borrowing-btn">
            
            <!-- Chart 2.1: แนวโน้มการทำรายการยืม-คืน (Line Chart) -->
            <div class="analytics-card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-chart-line text-primary me-2"></i>Chart 2.1: แนวโน้มการทำรายการยืม-คืนตามช่วงเวลา</h6>
                        <small class="text-muted">เปรียบเทียบความเคลื่อนไหว: จำนวนครั้งที่ทำรายการยืม (สีน้ำเงิน) vs รายการคืน (สีเขียว)</small>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                            <i class="fa-solid fa-hand-holding-hand me-1"></i> ยืมสะสม: <strong><?= number_format($totalTrendBorrows); ?></strong> ครั้ง
                        </span>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                            <i class="fa-solid fa-rotate-left me-1"></i> คืนสะสม: <strong><?= number_format($totalTrendReturns); ?></strong> ครั้ง
                        </span>
                        <span class="badge bg-light text-dark border font-monospace">
                            ช่วงข้อมูล: <?= htmlspecialchars($startDate); ?> ถึง <?= htmlspecialchars($endDate); ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-box" style="height: 350px;">
                        <canvas id="chartBorrowTrendLine"></canvas>
                    </div>
                </div>
            </div>

            <!-- Table 2.2: ตารางรายการเกินกำหนดส่งคืน (Overdue List) ไฮไลต์สีแดง -->
            <div class="analytics-card border-danger border-opacity-50">
                <div class="card-header bg-danger bg-opacity-10 py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h6 class="fw-bold text-danger mb-0 d-flex align-items-center">
                            <i class="fa-solid fa-triangle-exclamation me-2 fs-5"></i>
                            Table 2.2: รายการเกินกำหนดส่งคืน (Overdue Borrowings)
                        </h6>
                        <small class="text-muted">แสดงรายการครุภัณฑ์ที่ยังไม่ส่งคืนและเลยกำหนดส่งคืน เพื่อให้ติดตามทวงคืนได้ทันท่วงที</small>
                    </div>
                    <div>
                        <span class="badge bg-danger rounded-pill px-3 py-2">
                            เกินกำหนดทั้งหมด: <?= number_format($overdueTotalCount); ?> รายการ
                        </span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="tableOverdueReport">
                            <thead class="table-light text-secondary">
                                <tr>
                                    <th class="text-center" style="width: 50px;">#</th>
                                    <th style="width: 140px;">หมายเลขครุภัณฑ์</th>
                                    <th>รายการครุภัณฑ์</th>
                                    <th>ผู้ยืม & ข้อมูลติดต่อ</th>
                                    <th class="text-center" style="width: 110px;">วันที่ยืม</th>
                                    <th class="text-center" style="width: 110px;">กำหนดส่งคืน</th>
                                    <th class="text-center" style="width: 150px;">จำนวนวันที่เกินกำหนด</th>
                                    <th class="text-center" style="width: 120px;">การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($overdueItems)): ?>
                                    <?php $ovIdx = 1; foreach ($overdueItems as $ov): 
                                        $cleanNum = trim($ov['DA_Number'] ?? '-', "'\"");
                                    ?>
                                        <tr class="table-danger table-opacity-25">
                                            <td class="text-center font-monospace text-muted"><?= $ovIdx++; ?></td>
                                            <td>
                                                <span class="badge bg-light text-danger border border-danger font-monospace fw-bold">
                                                    <?= htmlspecialchars($cleanNum ?: '-'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($ov['DA_ListName']); ?></div>
                                                <?php if (!empty($ov['DA_Brand'])): ?>
                                                    <small class="text-muted"><i class="fa-solid fa-tag me-1"></i><?= htmlspecialchars($ov['DA_Brand']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-dark"><i class="fa-solid fa-user me-1 text-secondary"></i><?= htmlspecialchars($ov['borrower_name']); ?></div>
                                                <?php if (!empty($ov['userEmail'])): ?>
                                                    <small class="text-muted"><i class="fa-regular fa-envelope me-1"></i><?= htmlspecialchars($ov['userEmail']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center font-monospace small"><?= htmlspecialchars($ov['Date_borrow'] ?: '-'); ?></td>
                                            <td class="text-center font-monospace fw-bold text-danger small"><?= htmlspecialchars($ov['due_date'] ?: '-'); ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-danger rounded-pill px-3 py-1.5 fw-bold shadow-xs">
                                                    <i class="fa-solid fa-clock me-1"></i> เกิน <?= number_format($ov['days_overdue']); ?> วัน
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <a href="admin_history.php" class="btn btn-outline-danger btn-sm rounded-pill px-3" title="ไปหน้าระบบแจ้งเตือนทวงคืน">
                                                    <i class="fa-solid fa-bell me-1"></i> ทวงคืน
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-success py-4">
                                            <i class="fa-solid fa-circle-check fs-3 mb-2 d-block"></i>
                                            <strong>ยอดเยี่ยม! ไม่มีรายการครุภัณฑ์ที่เกินกำหนดส่งคืนในขณะนี้</strong>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <!-- =================================================================== -->
        <!-- TAB 3: บทวิเคราะห์เชิงลึก (Advanced Analytics / Top Rankings) -->
        <!-- =================================================================== -->
        <div class="tab-pane fade" id="tab-analytics" role="tabpanel" aria-labelledby="tab-analytics-btn">
            
            <div class="row g-4 mb-4">
                <!-- Chart 3.1: Top 5 ครุภัณฑ์ที่ถูกยืมบ่อยที่สุด (Horizontal Bar Chart) -->
                <div class="col-12 col-lg-6">
                    <div class="analytics-card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-fire-flame-curved text-danger me-2"></i>Chart 3.1: Top 5 ครุภัณฑ์ที่ถูกยืมบ่อยที่สุด</h6>
                                <small class="text-muted">จัดอันดับครุภัณฑ์ยอดนิยมที่มีการทำรายการยืมสูงสุด</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="chart-box">
                                <canvas id="chartTopItemsHorizontalBar"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Chart 3.2: Top 5 ผู้ใช้งานที่ทำรายการยืมสูงสุด (Polar Area Chart) -->
                <div class="col-12 col-lg-6">
                    <div class="analytics-card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-users text-purple me-2" style="color: #8b5cf6;"></i>Chart 3.2: Top 5 ผู้ใช้งานที่ทำรายการยืมสูงสุด</h6>
                                <small class="text-muted">จัดอันดับผู้ใช้งานที่มีความเคลื่อนไหวในการยืมครุภัณฑ์มากที่สุด</small>
                            </div>
                        </div>
                        <div class="card-body d-flex flex-column justify-content-center align-items-center">
                            <div class="chart-box">
                                <canvas id="chartTopUsersPolar"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ตารางเปรียบเทียบ Top 5 ครุภัณฑ์ & Top 5 ผู้ใช้งาน -->
            <div class="row g-4">
                <div class="col-12 col-lg-6">
                    <div class="analytics-card h-100">
                        <div class="card-header">
                            <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-trophy text-warning me-2"></i>ตารางอันดับครุภัณฑ์ที่ถูกยืมบ่อยที่สุด</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center" style="width: 45px;">อันดับ</th>
                                            <th>หมายเลข / ชื่อครุภัณฑ์</th>
                                            <th class="text-center" style="width: 120px;">จำนวนครั้งที่ยืม</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($topItemsList)): ?>
                                            <?php $rk = 1; foreach ($topItemsList as $ti): ?>
                                                <tr>
                                                    <td class="text-center font-monospace fw-bold text-muted"><?= $rk++; ?></td>
                                                    <td>
                                                        <div class="fw-bold text-dark"><?= htmlspecialchars($ti['DA_ListName']); ?></div>
                                                        <small class="badge bg-light text-primary border font-monospace"><?= htmlspecialchars(trim($ti['DA_Number'], "'\"")); ?></small>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-primary rounded-pill px-3 py-1 font-monospace fs-6">
                                                            <?= number_format($ti['borrow_count']); ?> ครั้ง
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="3" class="text-center text-muted py-3">ยังไม่มีข้อมูลการยืม</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-6">
                    <div class="analytics-card h-100">
                        <div class="card-header">
                            <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-medal text-primary me-2"></i>ตารางอันดับผู้ใช้งานที่ยืมสูงสุด</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center" style="width: 45px;">อันดับ</th>
                                            <th>ชื่อผู้ยืม / แผนก</th>
                                            <th class="text-center" style="width: 120px;">จำนวนครั้งที่ยืม</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($topUsersList)): ?>
                                            <?php $rkU = 1; foreach ($topUsersList as $tu): ?>
                                                <tr>
                                                    <td class="text-center font-monospace fw-bold text-muted"><?= $rkU++; ?></td>
                                                    <td>
                                                        <div class="fw-bold text-dark"><?= htmlspecialchars($tu['borrower_name']); ?></div>
                                                        <?php if (!empty($tu['Belongto_borrow'])): ?>
                                                            <small class="text-muted"><i class="fa-solid fa-building me-1"></i><?= htmlspecialchars($tu['Belongto_borrow']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-purple rounded-pill px-3 py-1 font-monospace fs-6" style="background-color: #8b5cf6;">
                                                            <?= number_format($tu['borrow_count']); ?> ครั้ง
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="3" class="text-center text-muted py-3">ยังไม่มีข้อมูลการยืม</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>

</div>

<!-- โหลด Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php require_once __DIR__ . '/../component/admin_footer.php'; ?>

<!-- ========================================================================= -->
<!-- JAVASCRIPT: DATA INJECTION & CHART.JS RENDERING -->
<!-- ========================================================================= -->
<script>
// จัดเก็บอินสแตนซ์ของกราฟทั้งหมดไว้ใน Object เพื่อง่ายต่อการเรียก Resize
window.reportCharts = {};

$(document).ready(function() {

    // -------------------------------------------------------------------------
    // 1. Data Injection จาก PHP เข้าสู่ตัวแปร JavaScript
    // -------------------------------------------------------------------------
    const statusData = {
        available: <?= json_encode($invAvailableCount); ?>,
        borrowed:  <?= json_encode($invBorrowedCount); ?>,
        damaged:   <?= json_encode($invDamagedCount); ?>
    };

    const categoryData = {
        labels: <?= json_encode($categoriesLabels); ?>,
        counts: <?= json_encode($categoriesCounts); ?>
    };

    const trendData = {
        labels:  <?= json_encode($trendLabels); ?>,
        borrows: <?= json_encode($trendBorrowCounts); ?>,
        returns: <?= json_encode($trendReturnCounts); ?>
    };

    const topItemsData = {
        labels: <?= json_encode($topItemsLabels); ?>,
        counts: <?= json_encode($topItemsCounts); ?>
    };

    const topUsersData = {
        labels: <?= json_encode($topUsersLabels); ?>,
        counts: <?= json_encode($topUsersCounts); ?>
    };

    // -------------------------------------------------------------------------
    // 2. Chart 1.1: สัดส่วนสถานะครุภัณฑ์ (Doughnut Chart)
    // -------------------------------------------------------------------------
    const ctxStatus = document.getElementById('chartStatusDoughnut');
    if (ctxStatus) {
        const rawStatusValues = [statusData.available, statusData.borrowed, statusData.damaged];
        
        // คำนวณสัดส่วนการวาดให้มองเห็นทุกสถานะได้ชัดเจน (Visual Arc Equalizer)
        function calcReportVisualSlices(rawVals, minPct = 0.08) {
            const tot = rawVals.reduce((a, b) => a + b, 0);
            if (tot === 0) return [0, 0, 0];
            const nonZero = rawVals.filter(v => v > 0).length;
            if (nonZero <= 1) return [...rawVals];
            
            let allocPct = 0;
            let remSum = 0;
            const isTiny = rawVals.map(v => {
                if (v <= 0) return false;
                if ((v / tot) < minPct) {
                    allocPct += minPct;
                    return true;
                }
                remSum += v;
                return false;
            });
            const remPct = Math.max(0.1, 1.0 - allocPct);
            return rawVals.map((v, i) => {
                if (v <= 0) return 0;
                if (isTiny[i]) return minPct * tot;
                return (v / remSum) * remPct * tot;
            });
        }

        const visualStatusData = calcReportVisualSlices(rawStatusValues, 0.08);

        window.reportCharts.statusDoughnut = new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: ['พร้อมใช้งาน (Available)', 'กำลังถูกยืม (Borrowed)', 'ชำรุด/สูญหาย (Damaged)'],
                datasets: [{
                    data: visualStatusData,
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    hoverBackgroundColor: ['#059669', '#d97706', '#dc2626'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 14,
                            padding: 18,
                            font: { family: "'Prompt', 'Sarabun', sans-serif", size: 12 },
                            generateLabels: function(chart) {
                                const labels = chart.data.labels;
                                return labels.map((label, i) => ({
                                    text: `${label} (${(rawStatusValues[i] || 0).toLocaleString()})`,
                                    fillStyle: ['#10b981', '#f59e0b', '#ef4444'][i],
                                    strokeStyle: '#ffffff',
                                    lineWidth: 1,
                                    index: i
                                }));
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const index = context.dataIndex;
                                const val = rawStatusValues[index] || 0;
                                const total = rawStatusValues.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                                return ` ${context.label}: ${val.toLocaleString()} รายการ (${pct}%)`;
                            }
                        }
                    }
                },
                cutout: '68%'
            }
        });
    }

    // -------------------------------------------------------------------------
    // 3. Chart 1.2: จำนวนครุภัณฑ์แยกตามหมวดหมู่ (Bar Chart)
    // -------------------------------------------------------------------------
    const ctxCategory = document.getElementById('chartCategoryBar');
    if (ctxCategory) {
        window.reportCharts.categoryBar = new Chart(ctxCategory, {
            type: 'bar',
            data: {
                labels: categoryData.labels,
                datasets: [{
                    label: 'จำนวนครุภัณฑ์ (รายการ)',
                    data: categoryData.counts,
                    backgroundColor: 'rgba(37, 99, 235, 0.85)',
                    hoverBackgroundColor: 'rgba(29, 78, 216, 1)',
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ` จำนวน: ${ctx.raw.toLocaleString()} รายการ`
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { family: "'Prompt', 'Sarabun', sans-serif", size: 11 },
                            maxRotation: 45,
                            minRotation: 20
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { family: "'Prompt', 'Sarabun', sans-serif", size: 11 }
                        }
                    }
                }
            }
        });
    }

    // -------------------------------------------------------------------------
    // 4. Chart 2.1: แนวโน้มการทำรายการยืม-คืน (Line Chart)
    // -------------------------------------------------------------------------
    const ctxTrend = document.getElementById('chartBorrowTrendLine');
    if (ctxTrend) {
        window.reportCharts.trendLine = new Chart(ctxTrend, {
            type: 'line',
            data: {
                labels: trendData.labels,
                datasets: [
                    {
                        label: 'รายการยืม (Borrowings)',
                        data: trendData.borrows,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.12)',
                        fill: true,
                        tension: 0.35,
                        pointBackgroundColor: '#2563eb',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 7
                    },
                    {
                        label: 'รายการคืน (Returns)',
                        data: trendData.returns,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.12)',
                        fill: true,
                        tension: 0.35,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 7
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 14,
                            padding: 16,
                            font: { family: "'Prompt', 'Sarabun', sans-serif", size: 12 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ` ${ctx.dataset.label}: ${ctx.raw} รายการ`
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(0, 0, 0, 0.03)' },
                        ticks: { font: { family: "'Prompt', 'Sarabun', sans-serif", size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        suggestedMax: 5,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            stepSize: 1,
                            font: { family: "'Prompt', 'Sarabun', sans-serif", size: 11 }
                        }
                    }
                }
            }
        });
    }

    // -------------------------------------------------------------------------
    // 5. Chart 3.1: Top 5 ครุภัณฑ์ที่ถูกยืมบ่อยที่สุด (Horizontal Bar Chart)
    // -------------------------------------------------------------------------
    const ctxTopItems = document.getElementById('chartTopItemsHorizontalBar');
    if (ctxTopItems) {
        window.reportCharts.topItemsBar = new Chart(ctxTopItems, {
            type: 'bar',
            data: {
                labels: topItemsData.labels,
                datasets: [{
                    label: 'จำนวนครั้งที่ถูกยืม',
                    data: topItemsData.counts,
                    backgroundColor: 'rgba(139, 92, 246, 0.85)',
                    hoverBackgroundColor: 'rgba(124, 58, 237, 1)',
                    borderRadius: 8
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ` ถูกยืมทั้งหมด: ${ctx.raw} ครั้ง`
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            stepSize: 1,
                            font: { family: "'Prompt', 'Sarabun', sans-serif", size: 11 }
                        }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { font: { family: "'Prompt', 'Sarabun', sans-serif", size: 11 } }
                    }
                }
            }
        });
    }

    // -------------------------------------------------------------------------
    // 6. Chart 3.2: Top 5 ผู้ใช้งานที่ยืมสูงสุด (Polar Area Chart)
    // -------------------------------------------------------------------------
    const ctxTopUsers = document.getElementById('chartTopUsersPolar');
    if (ctxTopUsers) {
        window.reportCharts.topUsersPolar = new Chart(ctxTopUsers, {
            type: 'polarArea',
            data: {
                labels: topUsersData.labels,
                datasets: [{
                    data: topUsersData.counts,
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.75)',
                        'rgba(16, 185, 129, 0.75)',
                        'rgba(245, 158, 11, 0.75)',
                        'rgba(139, 92, 246, 0.75)',
                        'rgba(236, 72, 153, 0.75)'
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 14,
                            font: { family: "'Prompt', 'Sarabun', sans-serif", size: 11 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => ` ${ctx.label}: ${ctx.raw} ครั้ง`
                        }
                    }
                }
            }
        });
    }

    // -------------------------------------------------------------------------
    // 7. จัดการ Resize & Update กราฟอัตโนมัติเมื่อกดสลับแท็บ Bootstrap (ป้องกันปัญหากราฟลีบ/ไม่แสดงผล)
    // -------------------------------------------------------------------------
    $('button[data-bs-toggle="pill"], button[data-bs-toggle="tab"]').on('shown.bs.tab', function(e) {
        if (window.reportCharts) {
            Object.values(window.reportCharts).forEach(chart => {
                if (chart) {
                    if (typeof chart.resize === 'function') chart.resize();
                    if (typeof chart.update === 'function') chart.update('none');
                }
            });
        }
    });

    // -------------------------------------------------------------------------
    // 8. ปุ่ม Preset ช่วงเวลาด่วน (เดือนนี้, 3 เดือน, 6 เดือน, 1 ปี)
    // -------------------------------------------------------------------------
    $('.btn-quick-date, .btn-preset').on('click', function(e) {
        e.preventDefault();
        const range = $(this).data('range') || $(this).attr('data-range');
        const today = new Date();
        const y = today.getFullYear();
        const m = String(today.getMonth() + 1).padStart(2, '0');
        const d = String(today.getDate()).padStart(2, '0');
        const todayStr = `${y}-${m}-${d}`;

        $('#endDateInput').val(todayStr);

        let startDateStr = '';
        if (range === 'this_month') {
            startDateStr = `${y}-${m}-01`;
        } else if (range === '3_months') {
            const dt = new Date(today);
            dt.setMonth(dt.getMonth() - 3);
            startDateStr = dt.toISOString().split('T')[0];
        } else if (range === '6_months') {
            const dt = new Date(today);
            dt.setMonth(dt.getMonth() - 6);
            startDateStr = dt.toISOString().split('T')[0];
        } else if (range === '1_year' || range === 'thisyear') {
            const dt = new Date(today);
            dt.setFullYear(dt.getFullYear() - 1);
            startDateStr = dt.toISOString().split('T')[0];
        }

        if (startDateStr) {
            $('#startDateInput').val(startDateStr);
            $('#dateFilterForm').submit();
        }
    });

    // -------------------------------------------------------------------------
    // 9. ฟังก์ชัน Export Excel / CSV สำหรับดาวน์โหลดข้อมูลสรุป
    // -------------------------------------------------------------------------
    $('#btnExportExcel').on('click', function() {
        // ดึงข้อมูลจากตารางที่กำลังแสดงอยู่เพื่อสร้างไฟล์ CSV
        let csvContent = "\uFEFFลำดับ,หมายเลขครุภัณฑ์,รายการครุภัณฑ์,ผู้ยืม,วันที่ยืม,กำหนดส่งคืน,จำนวนวันเกินกำหนด\n";
        
        <?php foreach ($overdueItems as $k => $item): ?>
            csvContent += `<?= $k + 1 ?>,<?= addslashes(trim($item['DA_Number'], "'\"")) ?>,<?= addslashes($item['DA_ListName']) ?>,<?= addslashes($item['borrower_name']) ?>,<?= $item['Date_borrow'] ?>,<?= $item['due_date'] ?>,<?= $item['days_overdue'] ?> วัน\n`;
        <?php endforeach; ?>

        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.setAttribute('download', `overdue_report_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);

        Swal.fire({
            icon: 'success',
            title: 'ส่งออกข้อมูลสำเร็จ!',
            text: 'ระบบได้ดาวน์โหลดไฟล์รายงานรายการเกินกำหนดเรียบร้อยแล้ว',
            timer: 2000,
            showConfirmButton: false
        });
    });

    // -------------------------------------------------------------------------
    // 10. รองรับการเปิดแท็บอัตโนมัติตาม URL Parameter หรือ Hash
    // -------------------------------------------------------------------------
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab') || '';
    const statusParam = urlParams.get('filter_status') || '';
    const hash = window.location.hash;

    if (tabParam === 'borrowing' || tabParam === '2' || statusParam.includes('ยืม') || hash === '#tab-borrowing') {
        const triggerEl = document.getElementById('tab-borrowing-btn');
        if (triggerEl) {
            const tabInstance = bootstrap.Tab.getOrCreateInstance(triggerEl);
            tabInstance.show();
        }
    } else if (tabParam === 'analytics' || tabParam === '3' || hash === '#tab-analytics') {
        const triggerEl = document.getElementById('tab-analytics-btn');
        if (triggerEl) {
            const tabInstance = bootstrap.Tab.getOrCreateInstance(triggerEl);
            tabInstance.show();
        }
    }

});
</script>
