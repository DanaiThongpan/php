<?php
session_start();

// ตรวจสอบสิทธิ์การเข้าถึง (ต้องเป็น Admin เท่านั้น)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

require_once '../control/connectPDO_dev.php';

$useraccountName = $_SESSION['Sename'];
$currentUserID   = $_SESSION['SeUser'] ?? '';
$currentUserDept = $_SESSION['userDepartment'] ?? '';

// 1. ตรวจสอบเงื่อนไข Super Admin: ไอดี 'admin' เท่านั้น
$isSuperAdmin = ($currentUserID === 'admin');

// =============================================================================
// จัดการคำขอ AJAX: ดึงรายละเอียดครุภัณฑ์ฉบับเต็ม (Full Asset Details Modal)
// =============================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $daId = trim($_GET['id'] ?? '');

    if (empty($daId)) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัสครุภัณฑ์']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 
                da.*, 
                c.classificationName,
                bd.buildingName,
                cr.classroomName,
                b.IDborrow_back,
                b.Date_borrow,
                b.Deadline_borrow,
                b.Name_borrow,
                b.userEmail,
                b.Detail_borrow,
                sa.statusName
            FROM durable_articles da
            LEFT JOIN classification c ON c.classificationID = da.classificationID 
            LEFT JOIN building bd ON bd.buildingID = da.buildingID
            LEFT JOIN building_classroom cr ON cr.classroomID = da.classroomID
            LEFT JOIN status_active sa ON sa.statusID = da.statusID
            LEFT JOIN (
                SELECT IDborrow_back, DA_ID, Date_borrow, Deadline_borrow, Name_borrow, userEmail, Detail_borrow, status 
                FROM borrow_back 
                WHERE (status = 'borrowed' OR status = 'ยังไม่ส่งคืน' OR (Date_back IS NULL AND status != 'returned'))
                ORDER BY Date_borrow DESC
                LIMIT 1
            ) b ON b.DA_ID = da.DA_ID
            WHERE da.DA_ID = :id
        ");
        $stmt->execute([':id' => $daId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลครุภัณฑ์รหัสนี้ในระบบ']);
            exit();
        }

        // ดึงประวัติการยืม-คืนย้อนหลัง 5 รายการล่าสุดของครุภัณฑ์ชิ้นนี้
        $stmtHist = $pdo->prepare("
            SELECT IDborrow_back, Date_borrow, Deadline_borrow, Date_back, Name_borrow, Detail_borrow, status
            FROM borrow_back
            WHERE DA_ID = :id
            ORDER BY Date_borrow DESC
            LIMIT 5
        ");
        $stmtHist->execute([':id' => $daId]);
        $history = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status'  => 'success',
            'data'    => $item,
            'history' => $history
        ]);
        exit();
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
        exit();
    }
}

// =============================================================================
// จัดการคำขอ AJAX: Toggle Visibility (สลับสถานะ ซ่อน/แสดงผล ครุภัณฑ์)
// =============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_visibility') {
    header('Content-Type: application/json; charset=utf-8');
    $da_id  = trim($_POST['da_id'] ?? '');
    $newVis = isset($_POST['is_visible']) ? (int)$_POST['is_visible'] : 1;

    if (empty($da_id)) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัสครุภัณฑ์ที่ต้องการแก้ไข']);
        exit();
    }

    try {
        // ตรวจสอบสิทธิ์ความเป็นเจ้าของหากไม่ใช่ Super Admin
        if (!$isSuperAdmin) {
            $stmtAuth = $pdo->prepare("SELECT owner_department, created_by FROM durable_articles WHERE DA_ID = :id");
            $stmtAuth->execute([':id' => $da_id]);
            $ownerRow = $stmtAuth->fetch(PDO::FETCH_ASSOC);
            if (!$ownerRow || ($ownerRow['owner_department'] !== $currentUserDept && $ownerRow['created_by'] !== $currentUserID)) {
                echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์แก้ไขสถานะครุภัณฑ์ของแผนกอื่น']);
                exit();
            }
        }

        // อัปเดตสถานะการแสดงผล
        $stmtUp = $pdo->prepare("UPDATE durable_articles SET is_visible = :vis WHERE DA_ID = :id");
        $stmtUp->execute([
            ':vis' => $newVis,
            ':id'  => $da_id
        ]);

        echo json_encode([
            'status'     => 'success',
            'message'    => ($newVis == 1) ? 'เปิดการแสดงผลครุภัณฑ์เรียบร้อยแล้ว' : 'ซ่อนครุภัณฑ์ (Inactive/Hidden) เรียบร้อยแล้ว',
            'is_visible' => $newVis,
            'da_id'      => $da_id
        ]);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
        exit();
    }
}

// =============================================================================
// AJAX Handler: อัปเดตสถานะความพร้อมครุภัณฑ์ (Update Status & Optional Repair Log)
// =============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    header('Content-Type: application/json; charset=UTF-8');
    
    $da_id     = trim($_POST['da_id'] ?? '');
    $newStatus = trim($_POST['status_id'] ?? '');
    $note      = trim($_POST['repair_note'] ?? '');
    $price     = trim($_POST['repair_price'] ?? '0');

    if (empty($da_id) || empty($newStatus)) {
        echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ครบถ้วนหรือไม่ถูกต้อง']);
        exit();
    }

    // อนุญาตเฉพาะรหัสสถานะ s0001 (พร้อมใช้งาน) หรือ s0002 (ชำรุด/ไม่พร้อมใช้งาน)
    if (!in_array($newStatus, ['s0001', 's0002'])) {
        echo json_encode(['status' => 'error', 'message' => 'รหัสสถานะไม่ถูกต้อง (อนุญาตเฉพาะ s0001 หรือ s0002)']);
        exit();
    }

    try {
        // ตรวจสอบสิทธิ์ความเป็นเจ้าของหากไม่ใช่ Super Admin
        if (!$isSuperAdmin) {
            $stmtAuth = $pdo->prepare("SELECT owner_department, created_by FROM durable_articles WHERE DA_ID = :id");
            $stmtAuth->execute([':id' => $da_id]);
            $ownerRow = $stmtAuth->fetch(PDO::FETCH_ASSOC);
            if (!$ownerRow || ($ownerRow['owner_department'] !== $currentUserDept && $ownerRow['created_by'] !== $currentUserID)) {
                echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์แก้ไขสถานะครุภัณฑ์ของแผนกอื่น']);
                exit();
            }
        }

        // ตรวจสอบว่าครุภัณฑ์กำลังถูกยืมอยู่หรือไม่
        $stmtBorrow = $pdo->prepare("SELECT IDborrow_back FROM borrow_back WHERE DA_ID = :id AND (status = 'borrowed' OR status = 'ยังไม่ส่งคืน' OR (Date_back IS NULL AND status != 'returned')) LIMIT 1");
        $stmtBorrow->execute([':id' => $da_id]);
        $borrowCheck = $stmtBorrow->fetchColumn();
        if (!empty($borrowCheck)) {
            echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเปลี่ยนสถานะได้ เนื่องจากครุภัณฑ์นี้กำลังถูกยืมใช้งานอยู่']);
            exit();
        }

        $pdo->beginTransaction();

        // อัปเดตสถานะใน durable_articles
        $stmtUp = $pdo->prepare("UPDATE durable_articles SET statusID = :statusID WHERE DA_ID = :id");
        $stmtUp->execute([
            ':statusID' => $newStatus,
            ':id'       => $da_id
        ]);

        // หากมีการบันทึกประวัติการชำรุด/แจ้งซ่อม (ถ้ามีระบุข้อความ)
        if (!empty($note)) {
            $repairPriceVal = is_numeric($price) ? floatval($price) : 0;
            $repairDate = date('Y-m-d');
            $nextId = (int)$pdo->query("SELECT MAX(CAST(repairID AS INTEGER)) FROM repair")->fetchColumn() + 1;
            if ($nextId < 1) $nextId = 1;
            $newRepairID = (string)$nextId;

            $stmtRep = $pdo->prepare("INSERT INTO repair (repairID, repair_Hisory, repair_Price, DA_ID, repair_Date) VALUES (:rid, :history, :price, :da_id, :rdate)");
            $stmtRep->execute([
                ':rid'     => $newRepairID,
                ':history' => $note,
                ':price'   => $repairPriceVal,
                ':da_id'   => $da_id,
                ':rdate'   => $repairDate
            ]);
        }

        $pdo->commit();

        $statusText = ($newStatus === 's0001') ? 'พร้อมใช้งาน' : 'ชำรุด / ไม่พร้อมใช้งาน';
        echo json_encode([
            'status'     => 'success',
            'message'    => 'อัปเดตสถานะครุภัณฑ์เป็น "' . $statusText . '" สำเร็จแล้ว',
            'da_id'      => $da_id,
            'status_id'  => $newStatus,
            'status_text'=> $statusText
        ]);
        exit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
        exit();
    }
}

// ดึงข้อมูลแผนกสดจากตาราง useraccount เพื่อความแม่นยำหาก Session ยังไม่ถูกรีเฟรช
if (empty($currentUserDept) && !empty($currentUserID)) {
    $stmtDept = $pdo->prepare("SELECT userDepartment FROM useraccount WHERE useraccountID = :uid");
    $stmtDept->execute([':uid' => $currentUserID]);
    $currentUserDept = $stmtDept->fetchColumn() ?: '';
}

// 2. ตรวจสอบว่าแอดมินระดับแผนกมีการระบุสังกัด/แผนกหรือไม่
$hasDepartment = !empty(trim($currentUserDept));

// 3. สร้างตัวแปร $roleBadge และ $roleBadgeSm (HTML Badge ตามสิทธิ์จริง)
if ($isSuperAdmin) {
    $roleBadge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 rounded-pill fs-6 shadow-sm">
                    <i class="fa-solid fa-crown text-warning me-1"></i> 👑 Super Admin (สิทธิ์สูงสุด มองเห็นข้อมูลทุกแผนก)
                  </span>';
    $roleBadgeSm = '<span class="badge bg-danger text-white rounded-pill"><i class="fa-solid fa-crown me-1"></i> Super Admin</span>';
} else {
    $deptDisplayName = $hasDepartment ? htmlspecialchars($currentUserDept) : '<span class="text-danger fw-bold">ไม่ระบุแผนก</span>';
    $roleBadge = '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-3 py-2 rounded-pill fs-6 shadow-sm">
                    <i class="fa-solid fa-building me-1"></i> 🏢 แอดมินประจำแผนก: ' . $deptDisplayName . '
                  </span>';
    $roleBadgeSm = '<span class="badge bg-info text-dark rounded-pill"><i class="fa-solid fa-building me-1"></i> แผนก: ' . ($hasDepartment ? htmlspecialchars($currentUserDept) : 'ไม่ระบุ') . '</span>';
}

// =============================================================================
// ดึงข้อมูล Master Data สำหรับตัวเลือกใน Dropdown Filters
// =============================================================================
// 1. หมวดหมู่ครุภัณฑ์ (Classification)
$categoriesList = [];
try {
    $categoriesList = $pdo->query("SELECT classificationID, classificationName FROM classification ORDER BY classificationName ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// 2. สถานที่ / อาคาร (Building)
$buildingsList = [];
try {
    $buildingsList = $pdo->query("SELECT buildingID, buildingName FROM building ORDER BY buildingName ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// 3. ปีงบประมาณ / ประจำปี (Acquisition Year)
$yearsList = [];
try {
    $stmtYears = $pdo->query("SELECT DISTINCT REPLACE(DA_Year, '''', '') AS cleanYear FROM durable_articles WHERE DA_Year IS NOT NULL AND DA_Year != '' ORDER BY cleanYear DESC");
    $yearsList = $stmtYears->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// =============================================================================
// รับค่าพารามิเตอร์การกรอง (Filters) ผ่าน GET
// =============================================================================
$filterVisibility = $_GET['filter_visibility'] ?? 'visible'; // 'visible' (default), 'hidden', 'all'
$filterCategory   = trim($_GET['filter_category'] ?? '');
$filterBuilding   = trim($_GET['filter_building'] ?? '');
$filterStatus     = trim($_GET['filter_status'] ?? '');
$filterYear       = trim($_GET['filter_year'] ?? '');

// =============================================================================
// คำนวณยอดรวม Badge สรุปภาพรวมตามสิทธิ์ (Visible, Hidden, All)
// =============================================================================
$countVisible  = 0;
$countHidden   = 0;
$countTotalAll = 0;
try {
    if ($isSuperAdmin) {
        $countVisible = (int)$pdo->query("SELECT COUNT(*) FROM durable_articles WHERE (is_approved = 1 OR is_approved IS NULL) AND (is_visible = 1 OR is_visible IS NULL)")->fetchColumn();
        $countHidden  = (int)$pdo->query("SELECT COUNT(*) FROM durable_articles WHERE (is_approved = 1 OR is_approved IS NULL) AND is_visible = 0")->fetchColumn();
    } else {
        if ($hasDepartment) {
            $stmtCv = $pdo->prepare("SELECT COUNT(*) FROM durable_articles WHERE (is_approved = 1 OR is_approved IS NULL) AND (is_visible = 1 OR is_visible IS NULL) AND (owner_department = :dept OR (owner_department IS NULL AND created_by = :uid))");
            $stmtCv->execute([':dept' => $currentUserDept, ':uid' => $currentUserID]);
            $countVisible = (int)$stmtCv->fetchColumn();

            $stmtCh = $pdo->prepare("SELECT COUNT(*) FROM durable_articles WHERE (is_approved = 1 OR is_approved IS NULL) AND is_visible = 0 AND (owner_department = :dept OR (owner_department IS NULL AND created_by = :uid))");
            $stmtCh->execute([':dept' => $currentUserDept, ':uid' => $currentUserID]);
            $countHidden = (int)$stmtCh->fetchColumn();
        }
    }
    $countTotalAll = $countVisible + $countHidden;
} catch (Exception $e) {}

// =============================================================================
// สร้างคำสั่ง SQL สำหรับดึงข้อมูลครุภัณฑ์ตามตัวกรองทั้งหมด (PDO Prepared Statements)
// =============================================================================
$conditions = ["(durable_articles.is_approved = 1 OR durable_articles.is_approved IS NULL)"];
$params     = [];

// 1. กฎการแบ่งแยกสิทธิ์ (Department Isolation)
if (!$isSuperAdmin) {
    if ($hasDepartment) {
        $conditions[] = "(durable_articles.owner_department = :my_dept OR (durable_articles.owner_department IS NULL AND durable_articles.created_by = :my_uid))";
        $params[':my_dept'] = $currentUserDept;
        $params[':my_uid']  = $currentUserID;
    } else {
        $conditions[] = "1 = 0";
    }
}

// 2. ตัวกรองการแสดงผล (Visibility Rules / CLB Masking)
if ($filterVisibility === 'visible' || empty($filterVisibility)) {
    $conditions[] = "(durable_articles.is_visible = 1 OR durable_articles.is_visible IS NULL)";
} elseif ($filterVisibility === 'hidden') {
    $conditions[] = "durable_articles.is_visible = 0";
} // 'all' -> แสดงทั้งหมด

// 3. ตัวกรองหมวดหมู่ (Category / Classification)
if (!empty($filterCategory)) {
    $conditions[] = "durable_articles.classificationID = :f_cat";
    $params[':f_cat'] = $filterCategory;
}

// 4. ตัวกรองสถานที่ / อาคาร (Building)
if (!empty($filterBuilding)) {
    $conditions[] = "durable_articles.buildingID = :f_bld";
    $params[':f_bld'] = $filterBuilding;
}

// 5. ตัวกรองปีที่ได้มา / ปีงบประมาณ (Year)
if (!empty($filterYear)) {
    $conditions[] = "(durable_articles.DA_Year = :f_yr OR durable_articles.DA_Year = :f_yr_q)";
    $params[':f_yr']   = $filterYear;
    $params[':f_yr_q'] = "'" . $filterYear . "'";
}

// 6. ตัวกรองสถานะการใช้งาน (Status)
if (!empty($filterStatus) && $filterStatus !== 'ทั้งหมด') {
    if ($filterStatus === 'กำลังถูกยืม') {
        $conditions[] = "b.IDborrow_back IS NOT NULL";
    } elseif ($filterStatus === 'ชำรุด / ไม่พร้อมใช้' || $filterStatus === 'ชำรุด') {
        $conditions[] = "durable_articles.statusID != 's0001'";
    } elseif ($filterStatus === 'พร้อมใช้งาน') {
        $conditions[] = "durable_articles.statusID = 's0001' AND b.IDborrow_back IS NULL";
    }
}

$whereClause = "WHERE " . implode(" AND ", $conditions);

$durableArticles = [];
try {
    $sql_DA = "SELECT 
                    durable_articles.*, 
                    classification.classificationName,
                    building.buildingName,
                    building_classroom.classroomName,
                    status_active.statusName,
                    b.IDborrow_back,
                    b.Date_borrow,
                    b.Deadline_borrow,
                    b.Name_borrow
               FROM durable_articles 
               LEFT JOIN classification ON classification.classificationID = durable_articles.classificationID 
               LEFT JOIN status_active ON status_active.statusID = durable_articles.statusID
               LEFT JOIN building ON building.buildingID = durable_articles.buildingID
               LEFT JOIN building_classroom ON building_classroom.classroomID = durable_articles.classroomID
               LEFT JOIN (
                   SELECT IDborrow_back, DA_ID, Date_borrow, Deadline_borrow, Name_borrow, status 
                   FROM borrow_back 
                   WHERE (status = 'borrowed' OR status = 'ยังไม่ส่งคืน' OR (Date_back IS NULL AND status != 'returned'))
                   GROUP BY DA_ID
               ) b ON b.DA_ID = durable_articles.DA_ID
               {$whereClause}
               ORDER BY durable_articles.DA_Number ASC";

    $stmtDA = $pdo->prepare($sql_DA);
    $stmtDA->execute($params);
    $durableArticles = $stmtDA->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $durableArticles = [];
}

$hasActiveFilters = (!empty($filterCategory) || !empty($filterBuilding) || !empty($filterStatus) || !empty($filterYear) || ($filterVisibility !== 'visible'));

// โหลดโครงสร้าง Layout หลัก (Responsive Header, Sidebar & Top Navbar)
$pageTitle = "จัดการข้อมูลครุภัณฑ์ (Durable Articles)";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>
<!-- Page Custom CSS -->
<style>
    .card { border-radius: 12px; }
    .table th { font-weight: 600; font-size: 0.88rem; background-color: #f8f9fa; white-space: nowrap; }
    .table td { font-size: 0.88rem; vertical-align: middle; }
    .btn-action { border-radius: 6px; padding: 4px 8px; }
    .dataTables_wrapper .dataTables_length, .dataTables_wrapper .dataTables_filter { margin-bottom: 15px; }
    .filter-card { background-color: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; }
    .form-select-sm, .btn-sm { border-radius: 8px; }
    .detail-row-label { font-weight: 600; color: #64748b; width: 140px; }
    .img-preview-box { background-color: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 12px; display: flex; align-items: center; justify-content: center; overflow: hidden; min-height: 220px; }
    .img-preview-box img { max-width: 100%; max-height: 240px; object-fit: contain; }
</style>

<!-- Main Container -->
<div class="container-fluid px-0">
    
    <!-- Header Section with Role Badge -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1"><i class="fa-solid fa-boxes-stacked text-primary me-2"></i>ระบบจัดการและแสดงรายละเอียดครุภัณฑ์</h4>
            <p class="text-muted small mb-0">เรียกดูข้อมูลจำเพาะเชิงลึก กรองตามหมวดหมู่/สถานที่ และตรวจสอบสถานะแสดงผลครุภัณฑ์ทั้งหมด</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="manage_users.php" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-users-gear me-1"></i> จัดการข้อมูลผู้ใช้
            </a>
        </div>
    </div>

    <!-- Alert กรณีไม่พบแผนก -->
    <?php if (!$isSuperAdmin && !$hasDepartment): ?>
        <div class="alert alert-warning border-warning shadow-sm rounded-3 py-3 px-4 mb-4">
            <h6 class="alert-heading fw-bold mb-1"><i class="fa-solid fa-triangle-exclamation me-2"></i>ไม่สามารถแสดงข้อมูลครุภัณฑ์ได้</h6>
            <p class="mb-0 small text-secondary">บัญชีของคุณยังไม่ได้ระบุ <strong>"สังกัด / แผนก"</strong> จึงไม่สามารถแสดงหรือจัดการรายการครุภัณฑ์ประจำแผนกได้</p>
        </div>
    <?php endif; ?>

    <!-- แผงตัวกรองข้อมูลขั้นสูง (Advanced Filter Card) -->
    <div class="filter-card shadow-sm p-4 mb-4">
        
        <!-- แถวบน: ตัวกรองโหมดแสดงผล (Visibility Pills) และปุ่มคำสั่งระบบ -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 pb-3 border-bottom">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="fw-bold text-secondary small"><i class="fa-solid fa-shield-halved me-1 text-primary"></i>โหมดการแสดงผล:</span>
                <div class="btn-group btn-group-sm rounded-pill p-1 bg-light border" role="group">
                    <a href="?filter_visibility=visible&filter_category=<?= urlencode($filterCategory) ?>&filter_building=<?= urlencode($filterBuilding) ?>&filter_status=<?= urlencode($filterStatus) ?>&filter_year=<?= urlencode($filterYear) ?>" 
                       class="btn rounded-pill px-3 fw-semibold <?= ($filterVisibility === 'visible' || empty($filterVisibility)) ? 'btn-success text-white shadow-sm' : 'btn-light text-secondary' ?>" 
                       title="แสดงเฉพาะครุภัณฑ์ใช้งานปกติ (ซ่อนอาคารเรียนรวม/CLB)">
                        <i class="fa-solid fa-eye me-1"></i>ใช้งานปกติ <span class="badge bg-white text-success rounded-pill ms-1"><?= number_format($countVisible) ?></span>
                    </a>
                    <a href="?filter_visibility=hidden&filter_category=<?= urlencode($filterCategory) ?>&filter_building=<?= urlencode($filterBuilding) ?>&filter_status=<?= urlencode($filterStatus) ?>&filter_year=<?= urlencode($filterYear) ?>" 
                       class="btn rounded-pill px-3 fw-semibold <?= ($filterVisibility === 'hidden') ? 'btn-warning text-dark shadow-sm' : 'btn-light text-secondary' ?>" 
                       title="แสดงเฉพาะครุภัณฑ์อาคารเรียนรวม/CLB ที่ถูกซ่อนไว้">
                        <i class="fa-solid fa-eye-slash me-1"></i>ซ่อนอยู่ (CLB) <span class="badge bg-dark text-white rounded-pill ms-1"><?= number_format($countHidden) ?></span>
                    </a>
                    <a href="?filter_visibility=all&filter_category=<?= urlencode($filterCategory) ?>&filter_building=<?= urlencode($filterBuilding) ?>&filter_status=<?= urlencode($filterStatus) ?>&filter_year=<?= urlencode($filterYear) ?>" 
                       class="btn rounded-pill px-3 fw-semibold <?= ($filterVisibility === 'all') ? 'btn-primary text-white shadow-sm' : 'btn-light text-secondary' ?>" 
                       title="แสดงรายการครุภัณฑ์ทั้งหมดรวมทั้งที่ซ่อน">
                        <i class="fa-solid fa-layer-group me-1"></i>ทั้งหมด <span class="badge bg-white text-primary rounded-pill ms-1"><?= number_format($countTotalAll) ?></span>
                    </a>
                </div>
            </div>
            
            <div class="d-flex gap-2">
                <?php if ($isSuperAdmin): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm btn-sync-trigger" title="ซิงค์ข้อมูลจาก daam.sql สู่ SQLite">
                        <i class="fa-solid fa-rotate me-1"></i> ซิงค์ฐานข้อมูล
                    </button>
                <?php endif; ?>
                <a href="print_qrcode.php" class="btn btn-outline-info btn-sm rounded-pill px-3 shadow-sm">
                    <i class="fa-solid fa-qrcode me-1"></i> พิมพ์ QR Code
                </a>
                <a href="import_csv.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 shadow-sm">
                    <i class="fa-solid fa-file-csv me-1"></i> นำเข้าผ่าน CSV
                </a>
            </div>
        </div>

        <!-- แถวล่าง: ฟอร์มกรองตาม หมวดหมู่, สถานที่/อาคาร, สถานะ, และปีงบประมาณ -->
        <form method="GET" action="manage_data.php" class="row g-3 align-items-end">
            <!-- เก็บโหมด Visibility ปัจจุบันไว้เสมอ -->
            <input type="hidden" name="filter_visibility" value="<?= htmlspecialchars($filterVisibility) ?>">

            <!-- 1. กรองตามหมวดหมู่ (Category) -->
            <div class="col-lg-3 col-md-6 col-sm-12">
                <label class="form-label fw-bold text-secondary small mb-1">
                    <i class="fa-solid fa-tags text-primary me-1"></i> หมวดหมู่ครุภัณฑ์:
                </label>
                <select name="filter_category" class="form-select form-select-sm border shadow-sm">
                    <option value="">-- ทุกหมวดหมู่ (All) --</option>
                    <?php foreach ($categoriesList as $cat): ?>
                        <option value="<?= htmlspecialchars($cat['classificationID']) ?>" <?= ($filterCategory === $cat['classificationID']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['classificationName']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 2. กรองตามสถานที่ / อาคาร (Building / Location) -->
            <div class="col-lg-3 col-md-6 col-sm-12">
                <label class="form-label fw-bold text-secondary small mb-1">
                    <i class="fa-solid fa-building text-success me-1"></i> สถานที่ / อาคาร:
                </label>
                <select name="filter_building" class="form-select form-select-sm border shadow-sm">
                    <option value="">-- ทุกสถานที่ / ทุกอาคาร --</option>
                    <?php foreach ($buildingsList as $bld): ?>
                        <option value="<?= htmlspecialchars($bld['buildingID']) ?>" <?= ($filterBuilding === $bld['buildingID']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($bld['buildingName']) ?> (<?= htmlspecialchars($bld['buildingID']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 3. กรองตามสถานะความพร้อม (Status) -->
            <div class="col-lg-2 col-md-6 col-sm-12">
                <label class="form-label fw-bold text-secondary small mb-1">
                    <i class="fa-solid fa-circle-notch text-warning me-1"></i> สถานะความพร้อม:
                </label>
                <select name="filter_status" class="form-select form-select-sm border shadow-sm">
                    <option value="">-- ทุกสถานะ --</option>
                    <option value="พร้อมใช้งาน" <?= ($filterStatus === 'พร้อมใช้งาน') ? 'selected' : '' ?>>🟢 พร้อมใช้งาน</option>
                    <option value="กำลังถูกยืม" <?= ($filterStatus === 'กำลังถูกยืม') ? 'selected' : '' ?>>🟡 กำลังถูกยืม</option>
                    <option value="ชำรุด / ไม่พร้อมใช้" <?= ($filterStatus === 'ชำรุด / ไม่พร้อมใช้' || $filterStatus === 'ชำรุด') ? 'selected' : '' ?>>🔴 ชำรุด / ไม่พร้อมใช้</option>
                </select>
            </div>

            <!-- 4. กรองตามปีที่ได้มา / ปีงบประมาณ (Year) -->
            <div class="col-lg-2 col-md-6 col-sm-12">
                <label class="form-label fw-bold text-secondary small mb-1">
                    <i class="fa-solid fa-calendar-days text-info me-1"></i> ปีงบประมาณ / ประจำปี:
                </label>
                <select name="filter_year" class="form-select form-select-sm border shadow-sm">
                    <option value="">-- ทุกปี (All) --</option>
                    <?php foreach ($yearsList as $yr): ?>
                        <option value="<?= htmlspecialchars($yr) ?>" <?= ($filterYear === $yr) ? 'selected' : '' ?>>
                            พ.ศ. <?= htmlspecialchars($yr) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- ปุ่มคำสั่งค้นหาและล้างตัวกรอง -->
            <div class="col-lg-2 col-md-12 col-sm-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1 shadow-sm fw-semibold">
                    <i class="fa-solid fa-filter me-1"></i> กรองข้อมูล
                </button>
                <a href="manage_data.php?filter_visibility=<?= htmlspecialchars($filterVisibility) ?>" class="btn btn-outline-secondary btn-sm shadow-sm" title="ล้างตัวกรองหมวดหมู่/สถานที่">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>
        </form>

        <!-- แถบแสดงสถานะตัวกรองปัจจุบัน (Active Filter Badges) -->
        <?php if ($hasActiveFilters): ?>
            <div class="mt-3 pt-3 border-top d-flex align-items-center flex-wrap gap-2 small">
                <span class="text-muted fw-bold me-1"><i class="fa-solid fa-sliders me-1"></i>กำลังกรอง:</span>
                
                <?php if (!empty($filterCategory)): ?>
                    <?php 
                        $catName = '';
                        foreach ($categoriesList as $c) {
                            if ($c['classificationID'] === $filterCategory) { $catName = $c['classificationName']; break; }
                        }
                    ?>
                    <span class="badge bg-primary-subtle text-primary border px-3 py-2 rounded-pill">
                        หมวดหมู่: <strong><?= htmlspecialchars($catName ?: $filterCategory) ?></strong>
                        <a href="manage_data.php?filter_visibility=<?= urlencode($filterVisibility) ?>&filter_building=<?= urlencode($filterBuilding) ?>&filter_status=<?= urlencode($filterStatus) ?>&filter_year=<?= urlencode($filterYear) ?>" class="text-primary ms-1 text-decoration-none">✕</a>
                    </span>
                <?php endif; ?>

                <?php if (!empty($filterBuilding)): ?>
                    <?php 
                        $bldName = '';
                        foreach ($buildingsList as $b) {
                            if ($b['buildingID'] === $filterBuilding) { $bldName = $b['buildingName']; break; }
                        }
                    ?>
                    <span class="badge bg-success-subtle text-success border px-3 py-2 rounded-pill">
                        สถานที่/อาคาร: <strong><?= htmlspecialchars($bldName ?: $filterBuilding) ?></strong>
                        <a href="manage_data.php?filter_visibility=<?= urlencode($filterVisibility) ?>&filter_category=<?= urlencode($filterCategory) ?>&filter_status=<?= urlencode($filterStatus) ?>&filter_year=<?= urlencode($filterYear) ?>" class="text-success ms-1 text-decoration-none">✕</a>
                    </span>
                <?php endif; ?>

                <?php if (!empty($filterStatus)): ?>
                    <span class="badge bg-warning-subtle text-dark border border-warning px-3 py-2 rounded-pill">
                        สถานะ: <strong><?= htmlspecialchars($filterStatus) ?></strong>
                        <a href="manage_data.php?filter_visibility=<?= urlencode($filterVisibility) ?>&filter_category=<?= urlencode($filterCategory) ?>&filter_building=<?= urlencode($filterBuilding) ?>&filter_year=<?= urlencode($filterYear) ?>" class="text-dark ms-1 text-decoration-none">✕</a>
                    </span>
                <?php endif; ?>

                <?php if (!empty($filterYear)): ?>
                    <span class="badge bg-info-subtle text-info-emphasis border border-info px-3 py-2 rounded-pill">
                        ปี: <strong>พ.ศ. <?= htmlspecialchars($filterYear) ?></strong>
                        <a href="manage_data.php?filter_visibility=<?= urlencode($filterVisibility) ?>&filter_category=<?= urlencode($filterCategory) ?>&filter_building=<?= urlencode($filterBuilding) ?>&filter_status=<?= urlencode($filterStatus) ?>" class="text-info ms-1 text-decoration-none">✕</a>
                    </span>
                <?php endif; ?>

                <?php if ($filterVisibility === 'hidden'): ?>
                    <span class="badge bg-secondary text-white border px-3 py-2 rounded-pill">
                        โหมด: <strong>ซ่อนอยู่ (CLB)</strong>
                        <a href="manage_data.php?filter_visibility=visible&filter_category=<?= urlencode($filterCategory) ?>&filter_building=<?= urlencode($filterBuilding) ?>&filter_status=<?= urlencode($filterStatus) ?>&filter_year=<?= urlencode($filterYear) ?>" class="text-white ms-1 text-decoration-none">✕</a>
                    </span>
                <?php elseif ($filterVisibility === 'all'): ?>
                    <span class="badge bg-dark text-white border px-3 py-2 rounded-pill">
                        โหมด: <strong>รวมทั้งหมด</strong>
                        <a href="manage_data.php?filter_visibility=visible&filter_category=<?= urlencode($filterCategory) ?>&filter_building=<?= urlencode($filterBuilding) ?>&filter_status=<?= urlencode($filterStatus) ?>&filter_year=<?= urlencode($filterYear) ?>" class="text-white ms-1 text-decoration-none">✕</a>
                    </span>
                <?php endif; ?>

                <a href="manage_data.php?filter_visibility=visible" class="btn btn-link btn-sm text-danger text-decoration-none p-0 ms-2">
                    <i class="fa-solid fa-trash-can me-1"></i>ล้างทั้งหมด
                </a>
            </div>
        <?php endif; ?>

    </div>

    <!-- Main Card แสดงตารางข้อมูลครุภัณฑ์ -->
    <div class="card shadow border-0">
        <div class="card-body p-4">
            
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <h6 class="fw-bold text-secondary mb-0"><i class="fa-solid fa-list-check me-1"></i> รายการครุภัณฑ์ในระบบ</h6>
                    <span class="badge bg-primary rounded-pill"><?= number_format(count($durableArticles)) ?> รายการ</span>
                </div>
                <div class="small text-muted">
                    <i class="fa-solid fa-circle-info text-info me-1"></i> คลิกที่ <strong>ชื่อรายการ</strong> หรือปุ่ม <strong><i class="fa-solid fa-circle-info text-info"></i></strong> เพื่อเปิดดูรายละเอียดครุภัณฑ์ฉบับเต็ม
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle border" id="daTable">
                    <thead class="text-center">
                        <tr>
                            <th class="text-nowrap" style="width: 140px;">หมายเลขครุภัณฑ์</th>
                            <th>ชื่อรายการครุภัณฑ์</th>
                            <th class="text-nowrap">หมวดหมู่</th>
                            <th class="text-nowrap">สถานที่ / อาคาร</th>
                            <th class="text-nowrap text-end" style="width: 120px;">ราคาจัดซื้อ</th>
                            <th class="text-nowrap text-center" style="width: 110px;">แผนกเจ้าของ</th>
                            <th class="text-nowrap text-center" style="width: 90px;">การแสดงผล</th>
                            <th class="text-nowrap text-center" style="width: 110px;">สถานะ</th>
                            <th class="text-nowrap text-center" style="width: 190px;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($durableArticles as $da): ?>
                        <?php 
                            $vis = (isset($da['is_visible']) && $da['is_visible'] !== null) ? (int)$da['is_visible'] : 1;
                            $isBorrowed = !empty($da['IDborrow_back']);
                            $isBroken = ($da['statusID'] !== 's0001');
                            $priceVal = is_numeric($da['DA_Price']) ? (float)$da['DA_Price'] : 0;
                            $cleanNum = trim($da['DA_Number'] ?? '', "'\"");
                            $cleanYear = trim(str_replace("'", "", $da['DA_Year'] ?? ''));

                            // ตรวจสอบรูปภาพ
                            $rawImg = trim($da['DA_URLImg'] ?? '');
                            $cleanImg = basename($rawImg);
                            $imgWebPath = '';
                            $hasImg = false;
                            if (!empty($cleanImg) && file_exists(__DIR__ . '/../upload_imgDA/' . $cleanImg)) {
                                $imgWebPath = '../upload_imgDA/' . $cleanImg;
                                $hasImg = true;
                            } elseif (!empty($rawImg) && file_exists(__DIR__ . '/../' . ltrim($rawImg, '/'))) {
                                $imgWebPath = '../' . ltrim($rawImg, '/');
                                $hasImg = true;
                            }
                        ?>
                        <tr id="row-da-<?= htmlspecialchars($da['DA_ID']); ?>">
                            <!-- 1. หมายเลขครุภัณฑ์ -->
                            <td class="text-center font-monospace fw-bold text-primary text-nowrap">
                                <a href="javascript:void(0)" class="text-decoration-none btn-view-detail" data-id="<?= htmlspecialchars($da['DA_ID']); ?>" title="คลิกดูรายละเอียดฉบับเต็ม">
                                    <?= htmlspecialchars($cleanNum); ?>
                                </a>
                                <?php if (!empty($cleanYear) && $cleanYear !== '0000'): ?>
                                    <div class="small text-muted font-monospace">ปี <?= htmlspecialchars($cleanYear) ?></div>
                                <?php endif; ?>
                            </td>

                            <!-- 2. ชื่อรายการครุภัณฑ์ -->
                            <td>
                                <div>
                                    <a href="javascript:void(0)" class="fw-semibold text-dark text-decoration-none btn-view-detail" data-id="<?= htmlspecialchars($da['DA_ID']); ?>" title="คลิกดูรายละเอียดฉบับเต็ม">
                                        <?= htmlspecialchars($da['DA_ListName']); ?>
                                    </a>
                                </div>
                                <?php if (!empty($da['DA_Brand'])): ?>
                                    <small class="text-muted"><i class="fa-solid fa-tag me-1"></i><?= htmlspecialchars($da['DA_Brand']); ?></small>
                                <?php endif; ?>
                            </td>

                            <!-- 3. หมวดหมู่ -->
                            <td class="text-nowrap">
                                <span class="badge bg-info-subtle text-info-emphasis border">
                                    <?= htmlspecialchars($da['classificationName'] ?? 'ไม่ระบุ'); ?>
                                </span>
                            </td>

                            <!-- 4. สถานที่ / อาคาร -->
                            <td>
                                <div class="fw-semibold text-dark">
                                    <i class="fa-solid fa-location-dot text-danger me-1"></i>
                                    <?= htmlspecialchars($da['buildingName'] ?? ($da['buildingID'] ?: 'ไม่ระบุ')); ?>
                                </div>
                                <?php if (!empty($da['classroomName']) || !empty($da['DA_DetailLocation'])): ?>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($da['classroomName'] ?: $da['DA_DetailLocation']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>

                            <!-- 5. ราคาจัดซื้อ -->
                            <td class="text-end font-monospace text-nowrap">
                                <?php if ($priceVal > 0): ?>
                                    <span class="fw-bold text-dark">฿<?= number_format($priceVal, 2) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>

                            <!-- 6. แผนกเจ้าของ -->
                            <td class="text-center text-nowrap">
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($da['owner_department'] ?? 'ส่วนกลาง'); ?></span>
                            </td>

                            <!-- 7. การแสดงผล (Visibility) -->
                            <td class="text-center text-nowrap" id="vis-badge-<?= htmlspecialchars($da['DA_ID']); ?>">
                                <?php if ($vis === 1): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-eye me-1"></i> ปกติ</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" title="ครุภัณฑ์อาคารเรียนรวม (CLB) ถูกซ่อนไว้"><i class="fa-solid fa-eye-slash me-1"></i> ซ่อน (CLB)</span>
                                <?php endif; ?>
                            </td>

                            <!-- 8. สถานะความพร้อม -->
                            <td class="text-center text-nowrap" id="status-cell-<?= htmlspecialchars($da['DA_ID']); ?>">
                                <?php if ($isBorrowed): ?>
                                    <span class="badge bg-warning text-dark border border-warning px-2 py-1" title="ผู้ยืม: <?= htmlspecialchars($da['Name_borrow'] ?? '-') ?> (กำหนดส่ง: <?= htmlspecialchars($da['Deadline_borrow'] ?? '-') ?>)">
                                        <i class="fa-solid fa-clock-rotate-left me-1"></i> กำลังถูกยืม
                                    </span>
                                <?php elseif ($isBroken): ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-danger py-0 px-2 rounded-pill btn-change-status shadow-none" 
                                            data-id="<?= htmlspecialchars($da['DA_ID']); ?>" 
                                            data-name="<?= htmlspecialchars($da['DA_ListName'], ENT_QUOTES); ?>" 
                                            data-status="s0002"
                                            title="คลิกเพื่อเปลี่ยนสถานะ (ปัจจุบัน: ชำรุด/ไม่พร้อม)">
                                        <i class="fa-solid fa-triangle-exclamation me-1"></i> ชำรุด/ไม่พร้อม <i class="fa-solid fa-pen ms-1 small"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-success py-0 px-2 rounded-pill btn-change-status shadow-none" 
                                            data-id="<?= htmlspecialchars($da['DA_ID']); ?>" 
                                            data-name="<?= htmlspecialchars($da['DA_ListName'], ENT_QUOTES); ?>" 
                                            data-status="s0001"
                                            title="คลิกเพื่อเปลี่ยนสถานะ (ปัจจุบัน: พร้อมใช้งาน)">
                                        <i class="fa-solid fa-circle-check me-1"></i> พร้อมใช้งาน <i class="fa-solid fa-pen ms-1 small"></i>
                                    </button>
                                <?php endif; ?>
                            </td>

                            <!-- 9. ปุ่มจัดการ -->
                            <td class="text-center text-nowrap">
                                <div class="d-flex justify-content-center align-items-center gap-1">
                                    <!-- ปุ่มดูรายละเอียดฉบับเต็ม -->
                                    <button type="button" 
                                            class="btn btn-info btn-sm btn-action text-white btn-view-detail" 
                                            data-id="<?= htmlspecialchars($da['DA_ID']); ?>" 
                                            title="ดูรายละเอียดครุภัณฑ์ทั้งหมด">
                                        <i class="fa-solid fa-circle-info"></i>
                                    </button>

                                    <!-- ปุ่มแก้ไขข้อมูล -->
                                    <a href="edit_durable_article.php?da_id=<?= urlencode($da['DA_ID']); ?>" class="btn btn-warning btn-sm btn-action text-white" title="แก้ไขข้อมูล">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>

                                    <!-- ปุ่มอัปเดตสถานะความพร้อม (พร้อมใช้งาน / ชำรุด) -->
                                    <?php if (!$isBorrowed): ?>
                                    <button type="button" 
                                            class="btn btn-outline-danger btn-sm btn-action btn-change-status" 
                                            data-id="<?= htmlspecialchars($da['DA_ID']); ?>" 
                                            data-name="<?= htmlspecialchars($da['DA_ListName'], ENT_QUOTES); ?>" 
                                            data-status="<?= $isBroken ? 's0002' : 's0001'; ?>" 
                                            title="อัปเดตสถานะ (พร้อมใช้งาน / ชำรุด)">
                                        <i class="fa-solid fa-arrows-rotate"></i>
                                    </button>
                                    <?php endif; ?>

                                    <!-- ปุ่มประวัติการซ่อมบำรุง -->
                                    <a href="page_Repair_AddEdit.php?DAID=<?= urlencode($da['DA_ID']); ?>" class="btn btn-outline-secondary btn-sm btn-action" title="ประวัติการซ่อมบำรุง">
                                        <i class="fa-solid fa-screwdriver-wrench"></i>
                                    </a>

                                    <!-- ปุ่มดูรูปภาพ -->
                                    <button type="button" 
                                            class="btn btn-outline-success btn-sm btn-action" 
                                            onclick="ShowimgURLDA('<?= $hasImg ? htmlspecialchars($imgWebPath, ENT_QUOTES) : '' ?>', '<?= htmlspecialchars($da['DA_ListName'], ENT_QUOTES) ?>')" 
                                            title="ดูรูปภาพครุภัณฑ์">
                                        <i class="fa-solid fa-image"></i>
                                    </button>

                                    <!-- ปุ่มพิมพ์ QR Code -->
                                    <a href="print_qr.php?da_id=<?= urlencode($da['DA_ID']); ?>" target="_blank" class="btn btn-dark btn-sm btn-action" title="พิมพ์ QR Code">
                                        <i class="fa-solid fa-qrcode"></i>
                                    </a>

                                    <!-- ปุ่ม Toggle แสดง/ซ่อน (Soft Delete) -->
                                    <button type="button" 
                                            class="btn btn-sm btn-action <?= ($vis === 1) ? 'btn-outline-secondary' : 'btn-outline-success' ?> btn-toggle-vis" 
                                            data-id="<?= htmlspecialchars($da['DA_ID']); ?>" 
                                            data-name="<?= htmlspecialchars($da['DA_ListName'], ENT_QUOTES); ?>" 
                                            data-vis="<?= $vis; ?>" 
                                            title="<?= ($vis === 1) ? 'คลิกเพื่อซ่อนครุภัณฑ์นี้ (Soft Delete)' : 'คลิกเพื่อเปิดการแสดงผล'; ?>">
                                        <i class="fa-solid <?= ($vis === 1) ? 'fa-eye-slash text-secondary' : 'fa-eye text-success'; ?>"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- Modal แสดงรายละเอียดครุภัณฑ์ฉบับเต็ม (Full Asset Detail Modal) -->
<!-- ========================================================================= -->
<div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            
            <!-- Modal Header -->
            <div class="modal-header bg-primary text-white py-3 px-4 rounded-top-4">
                <div class="d-flex align-items-center gap-2">
                    <div class="bg-white text-primary rounded-circle p-2 d-flex align-items-center justify-content-center shadow-sm" style="width: 42px; height: 42px;">
                        <i class="fa-solid fa-boxes-stacked fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="detailModalLabel">รายละเอียดข้อมูลครุภัณฑ์</h5>
                        <small class="text-white-50" id="modalSubTitle">กำลังโหลดข้อมูล...</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-4" id="modalBodyContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">กำลังโหลดข้อมูล...</span>
                    </div>
                    <div class="text-muted mt-2 small">กำลังดึงข้อมูลรายละเอียดครุภัณฑ์...</div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer bg-light py-2 px-4 rounded-bottom-4 d-flex justify-content-between">
                <div id="modalFooterLeft"></div>
                <div class="d-flex gap-2">
                    <a href="#" id="modalRepairBtn" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                        <i class="fa-solid fa-screwdriver-wrench me-1"></i> ประวัติการซ่อม
                    </a>
                    <a href="#" id="modalPrintQrBtn" target="_blank" class="btn btn-outline-dark btn-sm rounded-pill px-3">
                        <i class="fa-solid fa-qrcode me-1"></i> พิมพ์ QR Code
                    </a>
                    <a href="#" id="modalEditBtn" class="btn btn-warning btn-sm rounded-pill px-3 text-white">
                        <i class="fa-solid fa-pen-to-square me-1"></i> แก้ไขข้อมูล
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- Modal แสดงรูปภาพครุภัณฑ์ (Show Image Modal) -->
<!-- ========================================================================= -->
<div class="modal fade" id="ShowDAImgModal" tabindex="-1" aria-labelledby="ShowDAImgModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-success text-white py-3 px-4 rounded-top-4">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-image fs-5"></i>
                    <h5 class="modal-title fw-bold mb-0" id="ShowDAImgModalLabel">รูปภาพครุภัณฑ์</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <h6 class="fw-bold text-dark mb-3" id="modalImgTitle"></h6>
                <div class="img-preview-box shadow-sm mb-0">
                    <img id="ModalimgDA" src="../img/noimg.jpg" class="img-fluid rounded" alt="รูปภาพครุภัณฑ์" onerror="this.src='../img/noimg.jpg';">
                </div>
            </div>
            <div class="modal-footer bg-light py-2 px-4 rounded-bottom-4">
                <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- Modal ปรับปรุงสถานะความพร้อมครุภัณฑ์ (Update Asset Status Modal) -->
<!-- ========================================================================= -->
<div class="modal fade" id="modalUpdateStatus" tabindex="-1" aria-labelledby="modalUpdateStatusLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-gradient bg-primary text-white py-3 px-4 rounded-top-4">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-arrows-rotate fs-5"></i>
                    <h5 class="modal-title fw-bold mb-0" id="modalUpdateStatusLabel">อัปเดตสถานะความพร้อมครุภัณฑ์</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formUpdateStatus">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="da_id" id="statusModalDaId" value="">
                
                <div class="modal-body p-4">
                    <!-- รายละเอียดครุภัณฑ์เบื้องต้น -->
                    <div class="bg-light p-3 rounded-3 mb-3 border">
                        <div class="d-flex align-items-start gap-2">
                            <i class="fa-solid fa-box text-primary mt-1"></i>
                            <div>
                                <div class="fw-bold text-dark" id="statusModalDaName">-</div>
                                <small class="text-muted" id="statusModalDaIdText">รหัส: -</small>
                            </div>
                        </div>
                    </div>

                    <!-- เลือกสถานะความพร้อม -->
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary mb-2">เลือกสถานะใหม่:</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="status_id" id="statusRadioAvailable" value="s0001" autocomplete="off" checked>
                                <label class="btn btn-outline-success w-100 py-2 d-flex flex-column align-items-center justify-content-center h-100" for="statusRadioAvailable">
                                    <i class="fa-solid fa-circle-check fs-4 mb-1"></i>
                                    <span class="fw-bold">พร้อมใช้งาน</span>
                                    <small class="text-muted" style="font-size: 0.75rem;">สภาพดี ยืมได้</small>
                                </label>
                            </div>
                            <div class="col-6">
                                <input type="radio" class="btn-check" name="status_id" id="statusRadioBroken" value="s0002" autocomplete="off">
                                <label class="btn btn-outline-danger w-100 py-2 d-flex flex-column align-items-center justify-content-center h-100" for="statusRadioBroken">
                                    <i class="fa-solid fa-triangle-exclamation fs-4 mb-1"></i>
                                    <span class="fw-bold">ชำรุด / ไม่พร้อม</span>
                                    <small class="text-muted" style="font-size: 0.75rem;">ส่งซ่อม/ระงับยืม</small>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- กล่องบันทึกข้อมูลการแจ้งซ่อม/หมายเหตุ (แสดงเมื่อเลือกชำรุด หรือต้องการบันทึกประวัติ) -->
                    <div id="repairNoteBox" class="border rounded-3 p-3 bg-danger-subtle bg-opacity-25 mb-2" style="display: none;">
                        <h6 class="fw-bold text-danger mb-2 d-flex align-items-center gap-1">
                            <i class="fa-solid fa-screwdriver-wrench"></i> บันทึกประวัติการชำรุด / ส่งซ่อม
                        </h6>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold text-dark">รายละเอียดอาการชำรุด / หมายเหตุ:</label>
                            <textarea class="form-control form-control-sm" name="repair_note" id="statusModalNote" rows="2" placeholder="ระบุอาการชำรุด หรือรายละเอียดการส่งซ่อม (ถ้ามี)"></textarea>
                        </div>
                        <div class="mb-1">
                            <label class="form-label small fw-semibold text-dark">ประมาณการค่าซ่อม (บาท):</label>
                            <input type="number" step="0.01" class="form-control form-control-sm" name="repair_price" id="statusModalPrice" placeholder="0.00">
                        </div>
                        <small class="text-muted" style="font-size: 0.75rem;">* ข้อมูลนี้จะถูกบันทึกเข้าตารางประวัติการซ่อมบำรุงโดยอัตโนมัติ</small>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2 px-4 rounded-bottom-4">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill px-4 shadow-sm" id="btnSaveStatus">
                        <i class="fa-solid fa-floppy-disk me-1"></i> บันทึกสถานะ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    $(document).ready(function() {
        // เปิดใช้งาน DataTables สำหรับตารางครุภัณฑ์ (โหมด Responsive ยุบคอลัมน์ซ่อนใต้ปุ่ม + บนมือถือ)
        $('#daTable').DataTable({
            responsive: true,
            "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/th.json" },
            "pageLength": 10,
            "order": [[ 0, "asc" ]]
        });

        // =====================================================================
        // จัดการคลิกปุ่มดูรายละเอียดฉบับเต็ม (View Detail Modal)
        // =====================================================================
        const detailModalInstance = new bootstrap.Modal(document.getElementById('detailModal'));

        $(document).on('click', '.btn-view-detail', function(e) {
            e.preventDefault();
            const daId = $(this).data('id');
            if (!daId) return;

            // Reset modal state
            $('#modalSubTitle').text('กำลังโหลดรหัส: ' + daId + '...');
            $('#modalBodyContent').html(`
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="text-muted mt-2 small">กำลังดึงข้อมูลรายละเอียดครุภัณฑ์...</div>
                </div>
            `);
            $('#modalPrintQrBtn').attr('href', 'print_qr.php?da_id=' + encodeURIComponent(daId));
            $('#modalEditBtn').attr('href', 'edit_durable_article.php?da_id=' + encodeURIComponent(daId));
            $('#modalRepairBtn').attr('href', 'page_Repair_AddEdit.php?DAID=' + encodeURIComponent(daId));
            detailModalInstance.show();

            // Fetch detail from backend AJAX endpoint
            $.ajax({
                url: 'manage_data.php',
                type: 'GET',
                dataType: 'json',
                data: { action: 'get_detail', id: daId },
                success: function(resp) {
                    if (resp.status === 'success' && resp.data) {
                        const d = resp.data;
                        const hist = resp.history || [];
                        
                        $('#modalSubTitle').text('หมายเลขครุภัณฑ์: ' + (d.DA_Number || '-'));

                        // Status badges & banners
                        let statusBanner = '';
                        if (d.IDborrow_back) {
                            statusBanner = `
                                <div class="alert alert-warning border-warning shadow-sm rounded-3 py-2 px-3 mb-3 d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-clock-rotate-left fs-4 text-warning"></i>
                                    <div>
                                        <div class="fw-bold">สถานะ: กำลังถูกยืม</div>
                                        <small class="text-muted">ผู้ยืม: <b>${d.Name_borrow || '-'}</b> (${d.userEmail || '-'}) | กำหนดส่งคืน: <b>${d.Deadline_borrow || '-'}</b></small>
                                    </div>
                                </div>
                            `;
                        } else if (d.statusID !== 's0001') {
                            statusBanner = `
                                <div class="alert alert-danger border-danger shadow-sm rounded-3 py-2 px-3 mb-3 d-flex align-items-center justify-content-between gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fa-solid fa-triangle-exclamation fs-4 text-danger"></i>
                                        <div>
                                            <div class="fw-bold">สถานะ: ชำรุด / ไม่พร้อมใช้งาน</div>
                                            <small class="text-muted">รายการนี้ระบุสถานะว่าไม่พร้อมใช้งาน</small>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-danger bg-white btn-change-status" data-id="${d.DA_ID}" data-name="${escapeHtml(d.DA_ListName || '')}" data-status="s0002">
                                        <i class="fa-solid fa-arrows-rotate me-1"></i> เปลี่ยนสถานะ
                                    </button>
                                </div>
                            `;
                        } else {
                            statusBanner = `
                                <div class="alert alert-success border-success shadow-sm rounded-3 py-2 px-3 mb-3 d-flex align-items-center justify-content-between gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fa-solid fa-circle-check fs-4 text-success"></i>
                                        <div>
                                            <div class="fw-bold">สถานะ: พร้อมใช้งาน (Available)</div>
                                            <small class="text-muted">ครุภัณฑ์สภาพปกติและพร้อมสำหรับทำรายการยืม</small>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-success bg-white btn-change-status" data-id="${d.DA_ID}" data-name="${escapeHtml(d.DA_ListName || '')}" data-status="s0001">
                                        <i class="fa-solid fa-arrows-rotate me-1"></i> เปลี่ยนสถานะ
                                    </button>
                                </div>
                            `;
                        }

                        // Visibility tag
                        const visBadge = (d.is_visible == 1 || d.is_visible === null)
                            ? '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-eye me-1"></i> แสดงผลปกติ</span>'
                            : '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1"><i class="fa-solid fa-eye-slash me-1"></i> ซ่อน (อาคารเรียนรวม/CLB)</span>';

                        // Price formatting
                        const priceNum = parseFloat(d.DA_Price);
                        const priceStr = (!isNaN(priceNum) && priceNum > 0)
                            ? '฿' + priceNum.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' บาท'
                            : '<span class="text-muted">- ไม่ระบุ -</span>';

                        // Image source
                        let imgSrc = '../img/noimg.jpg';
                        if (d.DA_URLImg && d.DA_URLImg.trim() !== '' && d.DA_URLImg !== 'noimg.jpg') {
                            imgSrc = '../upload_imgDA/' + d.DA_URLImg;
                        }

                        // Year formatting
                        const cleanYear = (d.DA_Year || '').replace(/'/g, '');

                        // Lifespan calculation
                        let ageSpanStr = '-';
                        const yearNum = parseInt(cleanYear, 10);
                        if (!isNaN(yearNum) && yearNum > 2400) {
                            const diff = (new Date().getFullYear() + 543) - yearNum;
                            ageSpanStr = (diff >= 0 ? diff : 0) + ' ปี';
                        }

                        // History rows
                        let histHtml = '';
                        if (hist.length > 0) {
                            histHtml = `
                                <div class="table-responsive mt-2">
                                    <table class="table table-sm table-bordered text-center align-middle small mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>วันที่ยืม</th>
                                                <th>ผู้ยืม</th>
                                                <th>กำหนดคืน</th>
                                                <th>วันที่คืนจริง</th>
                                                <th>สถานะ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${hist.map(h => `
                                                <tr>
                                                    <td>${h.Date_borrow || '-'}</td>
                                                    <td class="text-start">${h.Name_borrow || '-'}</td>
                                                    <td>${h.Deadline_borrow || '-'}</td>
                                                    <td>${h.Date_back || '<span class="text-danger fw-bold">ยังไม่ส่งคืน</span>'}</td>
                                                    <td>
                                                        ${h.Date_back 
                                                            ? '<span class="badge bg-success-subtle text-success border">คืนแล้ว</span>'
                                                            : '<span class="badge bg-warning-subtle text-dark border border-warning">กำลังยืม</span>'}
                                                    </td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            `;
                        } else {
                            histHtml = '<div class="text-muted small fst-italic p-2 bg-light rounded text-center">ยังไม่มีประวัติการยืม-คืนสำหรับครุภัณฑ์ชิ้นนี้</div>';
                        }

                        // Render modal body HTML
                        const htmlContent = `
                            ${statusBanner}

                            <div class="row g-4">
                                <!-- คอลัมน์ซ้าย: รูปภาพและ QR Code -->
                                <div class="col-md-4 text-center">
                                    <div class="img-preview-box shadow-sm mb-3">
                                        <img src="${imgSrc}" alt="${d.DA_ListName}" onerror="this.src='../img/noimg.jpg';">
                                    </div>
                                    <div class="p-3 bg-light rounded-3 border">
                                        <div class="small fw-bold text-secondary mb-2"><i class="fa-solid fa-qrcode me-1"></i>QR Code ประจำครุภัณฑ์</div>
                                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=130x130&data=${encodeURIComponent(d.DA_Number)}" alt="QR Code" class="img-fluid border p-1 bg-white rounded shadow-sm mb-2" style="width: 120px; height: 120px;">
                                        <div class="font-monospace small text-muted">${d.DA_Number}</div>
                                    </div>
                                </div>

                                <!-- คอลัมน์ขวา: รายละเอียดข้อมูลจำเพาะ -->
                                <div class="col-md-8">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h5 class="fw-bold text-dark mb-0">${d.DA_ListName}</h5>
                                        <div>${visBadge}</div>
                                    </div>
                                    <div class="text-muted small mb-3">
                                        <span class="me-2"><i class="fa-solid fa-tag me-1"></i>ยี่ห้อ: <b>${d.DA_Brand || '-'}</b></span>
                                        ${cleanYear ? `<span><i class="fa-solid fa-calendar me-1"></i>ปีงบประมาณ: <b>พ.ศ. ${cleanYear}</b></span>` : ''}
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-sm table-borderless align-middle mb-0">
                                            <tbody>
                                                <tr>
                                                    <td class="detail-row-label"><i class="fa-solid fa-barcode text-primary me-2"></i>รหัสระบบ (ID):</td>
                                                    <td class="font-monospace fw-bold text-primary">${d.DA_ID}</td>
                                                </tr>
                                                <tr>
                                                    <td class="detail-row-label"><i class="fa-solid fa-tags text-success me-2"></i>หมวดหมู่:</td>
                                                    <td><span class="badge bg-info-subtle text-info-emphasis border">${d.classificationName || 'ไม่ระบุ'}</span></td>
                                                </tr>
                                                <tr>
                                                    <td class="detail-row-label"><i class="fa-solid fa-ruler-combined text-secondary me-2"></i>ขนาด / สเปค:</td>
                                                    <td>${d.DA_Size || '- ไม่ระบุ -'}</td>
                                                </tr>
                                                <tr>
                                                    <td class="detail-row-label"><i class="fa-solid fa-clock-rotate-left text-warning me-2"></i>อายุการใช้งาน:</td>
                                                    <td><span class="badge bg-info-subtle text-info-emphasis border">${ageSpanStr}</span></td>
                                                </tr>
                                                <tr>
                                                    <td class="detail-row-label"><i class="fa-solid fa-money-bill-wave text-success me-2"></i>ราคาจัดซื้อ:</td>
                                                    <td class="fw-bold text-success">${priceStr}</td>
                                                </tr>
                                                <tr>
                                                    <td class="detail-row-label"><i class="fa-solid fa-building text-danger me-2"></i>อาคาร / สถานที่:</td>
                                                    <td>
                                                        <b>${d.buildingName || d.buildingID || '-'}</b>
                                                        ${d.classroomName ? ` <span class="badge bg-secondary-subtle text-secondary border">ห้อง ${d.classroomName}</span>` : ''}
                                                    </td>
                                                </tr>
                                                ${d.DA_DetailLocation ? `
                                                <tr>
                                                    <td class="detail-row-label"><i class="fa-solid fa-location-dot text-danger me-2"></i>จุดติดตั้งย่อย:</td>
                                                    <td class="text-secondary">${d.DA_DetailLocation}</td>
                                                </tr>
                                                ` : ''}
                                                <tr>
                                                    <td class="detail-row-label"><i class="fa-solid fa-sitemap text-info me-2"></i>แผนกผู้รับผิดชอบ:</td>
                                                    <td><span class="badge bg-light text-dark border">${d.owner_department || 'ส่วนกลาง'}</span></td>
                                                </tr>
                                                ${d.useraccountID ? `
                                                <tr>
                                                    <td class="detail-row-label"><i class="fa-solid fa-user-pen text-secondary me-2"></i>ผู้บันทึกข้อมูล:</td>
                                                    <td class="small text-muted font-monospace">${d.useraccountID}</td>
                                                </tr>
                                                ` : ''}
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- ข้อมูลคุณลักษณะเฉพาะเพิ่มเติม (DA_Detail) -->
                                    <div class="mt-3 p-3 bg-light rounded-3 border">
                                        <div class="small fw-bold text-secondary mb-1"><i class="fa-solid fa-file-lines me-1"></i>คุณลักษณะเฉพาะ / รายละเอียดเพิ่มเติม:</div>
                                        <div class="small text-dark" style="white-space: pre-line;">${d.DA_Detail && d.DA_Detail.trim() !== '' ? d.DA_Detail : '<span class=\"text-muted fst-italic\">- ไม่มีรายละเอียดเพิ่มเติม -</span>'}</div>
                                    </div>
                                </div>
                            </div>

                            <!-- ประวัติการยืม-คืนย้อนหลัง -->
                            <div class="mt-4 pt-3 border-top">
                                <div class="fw-bold text-dark mb-2"><i class="fa-solid fa-clock-rotate-left text-primary me-1"></i> ประวัติการทำรายการยืม-คืนล่าสุด (Recent Borrow Records)</div>
                                ${histHtml}
                            </div>
                        `;

                        $('#modalBodyContent').html(htmlContent);
                    } else {
                        $('#modalBodyContent').html(`
                            <div class="alert alert-danger my-3">
                                <i class="fa-solid fa-triangle-exclamation me-2"></i>${resp.message || 'ไม่สามารถโหลดข้อมูลได้'}
                            </div>
                        `);
                    }
                },
                error: function(xhr, status, error) {
                    $('#modalBodyContent').html(`
                        <div class="alert alert-danger my-3">
                            <i class="fa-solid fa-triangle-exclamation me-2"></i>เกิดข้อผิดพลาดในการเชื่อมต่อ: ${error}
                        </div>
                    `);
                }
            });
        });

        // =====================================================================
        // จัดการคลิกปุ่มสลับสถานะการแสดงผล (Toggle Visibility: Show / Hide)
        // =====================================================================
        $(document).on('click', '.btn-toggle-vis', function(e) {
            e.preventDefault();
            const btn = $(this);
            const daId = btn.data('id');
            const daName = btn.data('name');
            const currentVis = parseInt(btn.data('vis'), 10);
            const newVis = (currentVis === 1) ? 0 : 1;
            const actionText = (currentVis === 1) ? 'ซ่อนครุภัณฑ์' : 'เปิดการแสดงผลครุภัณฑ์';
            const actionDesc = (currentVis === 1) 
                ? `ต้องการซ่อนครุภัณฑ์ <b>[${daName}]</b> ออกจากหน้าจอปกติใช่หรือไม่?<br><small class='text-muted'>(แอดมินยังสามารถเปิดดูได้ในโหมด "ซ่อนอยู่")</small>` 
                : `ต้องการเปิดให้ครุภัณฑ์ <b>[${daName}]</b> แสดงผลบนหน้าจอตามปกติใช่หรือไม่?`;

            Swal.fire({
                title: `${actionText}?`,
                html: actionDesc,
                icon: (currentVis === 1) ? 'warning' : 'question',
                showCancelButton: true,
                confirmButtonColor: (currentVis === 1) ? '#ffc107' : '#198754',
                cancelButtonColor: '#6c757d',
                confirmButtonText: `<i class="fa-solid fa-${currentVis === 1 ? 'eye-slash' : 'eye'} me-1"></i> ยืนยัน${actionText}`,
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'กำลังบันทึกข้อมูล...',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });

                    $.ajax({
                        url: 'manage_data.php',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'toggle_visibility',
                            da_id: daId,
                            is_visible: newVis
                        },
                        success: function(resp) {
                            if (resp.status === 'success') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'สำเร็จ!',
                                    text: resp.message,
                                    timer: 1500,
                                    showConfirmButton: false
                                }).then(() => {
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'เกิดข้อผิดพลาด',
                                    text: resp.message || 'ไม่สามารถเปลี่ยนสถานะได้'
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            Swal.fire({
                                icon: 'error',
                                title: 'เกิดข้อผิดพลาดในการเชื่อมต่อ',
                                text: error
                            });
                        }
                    });
                }
            });
        });

        // =====================================================================
        // จัดการคลิกปุ่มซิงค์ฐานข้อมูล (Database Sync)
        // =====================================================================
        $(document).on('click', '.btn-sync-trigger', function(e) {
            e.preventDefault();
            Swal.fire({
                title: 'ซิงค์ฐานข้อมูล MySQL -> SQLite?',
                html: '<p class="text-secondary small mb-0">ระบบจะตรวจสอบและนำเข้าข้อมูลล่าสุดจาก <code>control/daam.sql</code> เข้าสู่ SQLite พร้อมคัดกรองซ่อนครุภัณฑ์อาคารเรียนรวม (CLB) อัตโนมัติ</p>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0d6efd',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fa-solid fa-rotate me-1"></i> เริ่มซิงค์ทันที',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'กำลังซิงโครไนซ์ข้อมูล...',
                        html: 'กรุณารอสักครู่ ระบบกำลังอ่าน Schema และประมวลผล Data Masking...',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });

                    fetch('sync_now.php')
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'success') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'ซิงโครไนซ์สำเร็จ!',
                                    html: `
                                        <div class="text-start p-3 bg-light rounded-3 font-monospace small border">
                                             <div class="text-success fw-bold mb-2"><i class="fa-solid fa-circle-check me-1"></i>${data.message}</div>
                                             ${data.summary ? `
                                                 <div>• ครุภัณฑ์ทั้งหมด: <b>${Number(data.summary.total_records).toLocaleString()}</b> รายการ</div>
                                                 <div class="text-warning">• ซ่อนอาคารเรียนรวม/CLB: <b>${Number(data.summary.hidden_records).toLocaleString()}</b> รายการ</div>
                                                 <div class="text-primary">• ใช้งานปกติ: <b>${Number(data.summary.visible_records).toLocaleString()}</b> รายการ</div>
                                                 <div class="text-muted mt-1">• ระยะเวลาประมวลผล: ${data.elapsed_seconds} วินาที</div>
                                             ` : ''}
                                        </div>
                                    `,
                                    confirmButtonText: 'ตกลง'
                                }).then(() => {
                                    window.location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'เกิดข้อผิดพลาดในการซิงค์',
                                    text: data.message || 'ไม่สามารถดำเนินการซิงค์ข้อมูลได้'
                                });
                            }
                        })
                        .catch(err => {
                            Swal.fire({
                                icon: 'error',
                                title: 'เกิดข้อผิดพลาดในการเชื่อมต่อ',
                                text: err.message
                            });
                        });
                }
            });
        });

        // =====================================================================
        // จัดการคลิกปุ่มเปลี่ยนสถานะความพร้อม (Change Status Modal)
        // =====================================================================
        const statusModalElement = document.getElementById('modalUpdateStatus');
        const statusModalInstance = statusModalElement ? new bootstrap.Modal(statusModalElement) : null;

        $(document).on('click', '.btn-change-status', function(e) {
            e.preventDefault();
            const daId = $(this).data('id');
            const daName = $(this).data('name') || '';
            const currStatus = $(this).data('status') || 's0001';

            if (!daId) return;

            $('#statusModalDaId').val(daId);
            $('#statusModalDaName').text(daName);
            $('#statusModalDaIdText').text('รหัสครุภัณฑ์: ' + daId);
            $('#statusModalNote').val('');
            $('#statusModalPrice').val('');

            if (currStatus === 's0002') {
                $('#statusRadioBroken').prop('checked', true);
                $('#repairNoteBox').show();
            } else {
                $('#statusRadioAvailable').prop('checked', true);
                $('#repairNoteBox').hide();
            }

            if (statusModalInstance) {
                statusModalInstance.show();
            }
        });

        // Toggle แสดงกล่องบันทึกการส่งซ่อมเมื่อเลือก "ชำรุด"
        $('input[name="status_id"]').on('change', function() {
            if ($(this).val() === 's0002') {
                $('#repairNoteBox').slideDown(200);
            } else {
                $('#repairNoteBox').slideUp(200);
            }
        });

        // บันทึกฟอร์มสถานะ
        $('#formUpdateStatus').on('submit', function(e) {
            e.preventDefault();
            const submitBtn = $('#btnSaveStatus');
            const daId = $('#statusModalDaId').val();
            const chosenStatus = $('input[name="status_id"]:checked').val();
            const formData = $(this).serialize();

            submitBtn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> กำลังบันทึก...');

            $.ajax({
                url: 'manage_data.php',
                type: 'POST',
                dataType: 'json',
                data: formData,
                success: function(res) {
                    submitBtn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> บันทึกสถานะ');

                    if (res.status === 'success') {
                        if (statusModalInstance) {
                            statusModalInstance.hide();
                        }

                        // แสดงแจ้งเตือนสำเร็จ
                        Swal.fire({
                            icon: 'success',
                            title: 'อัปเดตสถานะสำเร็จ',
                            text: res.message,
                            timer: 1800,
                            showConfirmButton: false
                        });

                        // อัปเดต DOM ในตารางทันที (Column 8 & Column 9)
                        const statusCell = $('#status-cell-' + daId);
                        const daName = $('#statusModalDaName').text();
                        if (statusCell.length > 0) {
                            if (chosenStatus === 's0001') {
                                statusCell.html(`
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-success py-0 px-2 rounded-pill btn-change-status shadow-none" 
                                            data-id="${daId}" 
                                            data-name="${escapeHtml(daName)}" 
                                            data-status="s0001"
                                            title="คลิกเพื่อเปลี่ยนสถานะ (ปัจจุบัน: พร้อมใช้งาน)">
                                        <i class="fa-solid fa-circle-check me-1"></i> พร้อมใช้งาน <i class="fa-solid fa-pen ms-1 small"></i>
                                    </button>
                                `);
                            } else {
                                statusCell.html(`
                                    <button type="button" 
                                            class="btn btn-sm btn-outline-danger py-0 px-2 rounded-pill btn-change-status shadow-none" 
                                            data-id="${daId}" 
                                            data-name="${escapeHtml(daName)}" 
                                            data-status="s0002"
                                            title="คลิกเพื่อเปลี่ยนสถานะ (ปัจจุบัน: ชำรุด/ไม่พร้อม)">
                                        <i class="fa-solid fa-triangle-exclamation me-1"></i> ชำรุด/ไม่พร้อม <i class="fa-solid fa-pen ms-1 small"></i>
                                    </button>
                                `);
                            }
                        }

                        // อัปเดต data-status ของปุ่มใน Column 9
                        $('.btn-change-status[data-id="' + daId + '"]').data('status', chosenStatus);

                        // ถ้า detail modal กำลังเปิดอยู่ ให้โหลดข้อมูลใหม่
                        if ($('#detailModal').hasClass('show')) {
                            $('.btn-view-detail[data-id="' + daId + '"]').trigger('click');
                        }
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'ไม่สามารถบันทึกได้',
                            text: res.message || 'เกิดข้อผิดพลาดในการบันทึกสถานะ'
                        });
                    }
                },
                error: function(xhr) {
                    submitBtn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> บันทึกสถานะ');
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาดในการเชื่อมต่อ',
                        text: 'ไม่สามารถติดต่อเซิร์ฟเวอร์ได้ (HTTP ' + xhr.status + ')'
                    });
                }
            });
        });

    });

    // Helper: หลีกเลี่ยง XSS ใน HTML Strings
    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // =========================================================================
    // ฟังก์ชันเปิดดูรูปภาพครุภัณฑ์ (Show Image Modal)
    // =========================================================================
    function ShowimgURLDA(url, title) {
        if (!url || url.trim() === '' || url.includes('noimg.jpg')) {
            Swal.fire({
                icon: 'info',
                title: 'ไม่มีข้อมูลรูปภาพ',
                text: title ? `ครุภัณฑ์ "${title}" ยังไม่มีข้อมูลรูปภาพในระบบ` : 'ครุภัณฑ์นี้ยังไม่มีข้อมูลรูปภาพในระบบ',
                confirmButtonText: 'ตกลง',
                confirmButtonColor: '#198754'
            });
        } else {
            $('#modalImgTitle').text(title || '');
            $('#ModalimgDA').attr('src', url);
            const imgModal = new bootstrap.Modal(document.getElementById('ShowDAImgModal'));
            imgModal.show();
        }
    }
</script>

<?php
// โหลดส่วนท้าย Layout หลัก (Scripts & Sticky Footer)
require_once __DIR__ . '/../component/admin_footer.php';
?>
