<?php
/**
 * report_details.php - หน้ารายงานข้อมูลเชิงลึก (Detailed Asset Report)
 * รองรับการเจาะลึกข้อมูลจากการคลิกกราฟ (Chart.js Drill-down) พร้อมตัวกรองและ DataTables
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------------
// 1. ตรวจสอบ Security Check สิทธิ์การเข้าถึง
// -----------------------------------------------------------------------------
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1) {
    header("Location: ../index.php");
    exit();
}

// -----------------------------------------------------------------------------
// 2. โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../control/connectPDO_dev.php';

// =============================================================================
// 3. กฎสิทธิ์การเข้าถึงข้อมูล (Authorization Rules):
//    - ถ้า $_SESSION['useraccountType'] == 'Super Admin' -> ดึงข้อมูลของ "ทุกแผนก"
//    - ถ้าเป็นสิทธิ์อื่นๆ (Admin แผนก) -> ดึงเฉพาะ "แผนกของตัวเอง" ($_SESSION['department_id'])
// =============================================================================
$useraccountType = $_SESSION['useraccountType'] ?? '';
$currentUserID   = $_SESSION['SeUser'] ?? '';
$useraccountName = $_SESSION['Sename'] ?? 'ผู้ดูแลระบบ';

// [PLACEHOLDER 1: ตัวแปร Session แผนก]
// ปรับชื่อ Session ให้ตรงกับฐานข้อมูลของคุณ เช่น $_SESSION['department_id'] หรือ $_SESSION['userDepartment']
$adminDept = $_SESSION['department_id'] 
          ?? $_SESSION['department_name'] 
          ?? $_SESSION['userDepartment'] 
          ?? '';

// ตรวจสอบเงื่อนไข Super Admin
$isSuperAdmin = ($useraccountType === 'Super Admin' || $currentUserID === 'admin');

// หากไม่ใช่ Super Admin และใน Session ยังไม่มีแผนก ให้ดึงสดจาก useraccount
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

// =============================================================================
// 4. รับค่าตัวกรองจาก URL Parameters (GET Parameters จากการคลิกกราฟหรือตัวกรอง)
// =============================================================================
$filterStatus     = trim($_GET['filter_status'] ?? '');
$filterCategory   = trim($_GET['filter_category'] ?? '');
$filterVisibility = trim($_GET['filter_visibility'] ?? ($_GET['view_mode'] ?? 'visible'));

// ดึงรายชื่อหมวดหมู่ทั้งหมดสำหรับใส่ใน Dropdown ตัวกรอง
$categoriesList = [];
try {
    $stmtCat = $pdo->query("SELECT classificationID, classificationName FROM classification ORDER BY classificationName ASC");
    $categoriesList = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categoriesList = [];
}

// =============================================================================
// 5. สร้างคำสั่ง SQL แบบ Dynamic พร้อม PDO Prepared Statements (ปลอดภัย 100%)
// =============================================================================
// [PLACEHOLDER 2: ตารางและคอลัมน์]
// ตาราง: durable_articles (da), classification (c), borrow_back (b)
// หมายเหตุ: ใช้ Subquery เชื่อม borrow_back เพื่อดึงเฉพาะรายการยืมล่าสุดที่ยังไม่คืน ป้องกันแถวครุภัณฑ์ซ้ำซ้อน
$sql = "SELECT 
            da.DA_ID, 
            da.DA_Number, 
            da.DA_ListName, 
            da.DA_Brand, 
            da.DA_Price, 
            da.owner_department, 
            da.statusID, 
            da.is_approved,
            da.is_visible,
            c.classificationName,
            b.IDborrow_back,
            b.Name_borrow,
            b.Date_borrow,
            COALESCE(b.due_date, b.Deadline_borrow) AS Deadline_borrow,
            CASE 
                WHEN b.IDborrow_back IS NOT NULL THEN 'กำลังถูกยืม'
                WHEN da.statusID != 's0001' THEN 'ชำรุด / ไม่พร้อมใช้'
                ELSE 'พร้อมใช้งาน'
            END AS calculated_status
        FROM durable_articles da
        LEFT JOIN classification c ON da.classificationID = c.classificationID
        LEFT JOIN (
            SELECT DA_ID, MAX(IDborrow_back) AS latest_borrow_id
            FROM borrow_back
            WHERE (Date_back IS NULL OR Date_back = '')
            GROUP BY DA_ID
        ) active_b ON da.DA_ID = active_b.DA_ID
        LEFT JOIN borrow_back b ON active_b.latest_borrow_id = b.IDborrow_back
        WHERE 1=1 
          AND (da.is_approved = 1 OR da.is_approved IS NULL)";

$params = [];

// [เงื่อนไขสิทธิ์ 1] ถ้าไม่ใช่ Super Admin ให้กรองเฉพาะแผนกของแอดมินที่ล็อกอินอยู่
if (!$isSuperAdmin) {
    // [PLACEHOLDER 3] เปลี่ยน 'owner_department' เป็นชื่อ Column แผนกของคุณ เช่น 'department' หรือ 'department_id'
    $sql .= " AND (da.owner_department = :dept OR (da.owner_department IS NULL AND da.created_by = :uid))";
    $params[':dept'] = $currentUserDept;
    $params[':uid']  = $currentUserID;
}

// [เงื่อนไขตัวกรอง 2] กรองตามหมวดหมู่ (ถ้ามีการส่งมา)
if (!empty($filterCategory) && $filterCategory !== 'ทั้งหมด') {
    $sql .= " AND c.classificationName = :catName";
    $params[':catName'] = $filterCategory;
}

// [เงื่อนไขตัวกรอง 3] กรองตามสถานะ (ถ้ามีการส่งมาจากการคลิก Doughnut Chart หรือเลือก Dropdown)
if (!empty($filterStatus) && $filterStatus !== 'ทั้งหมด') {
    if ($filterStatus === 'กำลังถูกยืม') {
        // รายการที่กำลังถูกยืมอยู่จริง (มีใน borrow_back และยังไม่ส่งคืน)
        $sql .= " AND b.IDborrow_back IS NOT NULL";
    } elseif ($filterStatus === 'ชำรุด / ไม่พร้อมใช้' || $filterStatus === 'ชำรุด') {
        // รายการที่ชำรุดหรือไม่พร้อมใช้งาน
        $sql .= " AND da.statusID != 's0001'";
    } elseif ($filterStatus === 'พร้อมใช้งาน') {
        // รายการสถานะปกติ (s0001) และไม่ได้ติดสถานะยืมอยู่
        $sql .= " AND da.statusID = 's0001' AND b.IDborrow_back IS NULL";
    }
}

// [เงื่อนไขตัวกรอง 4: Visibility Rules] กรองครุภัณฑ์อาคารเรียนรวม (CLB)
if ($filterVisibility === 'hidden') {
    $sql .= " AND da.is_visible = 0";
} elseif ($filterVisibility === 'all') {
    // แสดงทั้งหมด ไม่กรอง is_visible
} else {
    // ค่าเริ่มต้น (visible): กรองเฉพาะรายการที่ใช้งานปกติ (ซ่อนอาคารเรียนรวม)
    $sql .= " AND (da.is_visible = 1 OR da.is_visible IS NULL)";
}

$sql .= " ORDER BY da.DA_ID DESC";

// รันคำสั่ง SQL ด้วย PDO Prepared Statements
$reportItems = [];
$totalPriceSum = 0;
$countAvailable = 0;
$countBorrowed = 0;
$countMaint = 0;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reportItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // คำนวณสรุปสถิติเบื้องต้นสำหรับการแสดงผลบน Header Summary Cards
    foreach ($reportItems as $row) {
        $totalPriceSum += (float)($row['DA_Price'] ?? 0);
        if ($row['calculated_status'] === 'พร้อมใช้งาน') {
            $countAvailable++;
        } elseif ($row['calculated_status'] === 'กำลังถูกยืม') {
            $countBorrowed++;
        } else {
            $countMaint++;
        }
    }
} catch (PDOException $e) {
    $errorMsg = $e->getMessage();
}

$today = date('Y-m-d');
$pageTitle = "รายงานข้อมูลครุภัณฑ์เชิงลึก (Asset Report Details)";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>
<style>
    .filter-card {
        background: #ffffff;
        border-left: 5px solid #0d6efd;
        box-shadow: 0 4px 15px rgba(0,0,0,0.04);
        border-radius: 14px;
    }
    .mini-stat-card {
        border-radius: 12px;
        padding: 1.25rem;
        color: #fff;
        transition: transform 0.2s;
    }
    .mini-stat-card:hover {
        transform: translateY(-3px);
    }
    .table-custom th {
        background-color: #f8f9fa;
        color: #495057;
        font-size: 0.88rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        vertical-align: middle;
    }
    .table-custom td {
        vertical-align: middle;
        font-size: 0.92rem;
    }
    .badge-status {
        font-size: 0.82rem;
        padding: 0.45em 0.85em;
        border-radius: 50rem;
    }
</style>

<div class="container-fluid px-0">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0 small">
            <li class="breadcrumb-item"><a href="main.php" class="text-decoration-none"><i class="fa-solid fa-house me-1"></i>Dashboard</a></li>
            <li class="breadcrumb-item active" aria-current="page">รายงานข้อมูลเชิงลึก (Report Details)</li>
        </ol>
    </nav>

    <!-- Header Section -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-chart-line text-primary me-2"></i>รายงานข้อมูลครุภัณฑ์เชิงลึก (Asset Report Details)
            </h4>
            <p class="text-muted small mb-0">
                วิเคราะห์และตรวจสอบรายการครุภัณฑ์แบบเจาะลึก พร้อมตัวกรองสถานะและหมวดหมู่แบบไดนามิก
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($isSuperAdmin): ?>
                <span class="badge bg-danger shadow-sm px-3 py-2 fs-6">
                    <i class="fa-solid fa-globe me-1"></i> กำลังดูข้อมูล: ทุกแผนก (Super Admin)
                </span>
            <?php else: ?>
                <span class="badge bg-info text-dark shadow-sm px-3 py-2 fs-6">
                    <i class="fa-solid fa-building me-1"></i> แผนก: <?php echo htmlspecialchars($currentUserDept ?: 'ไม่ระบุ'); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- กล่องตัวกรองข้อมูล (Filter Card) -->
    <div class="card filter-card p-3 p-md-4 mb-4">
        <form method="GET" action="report_details.php" class="row g-3 align-items-end">
            
            <!-- ตัวกรอง: สถานะครุภัณฑ์ -->
            <div class="col-md-3 col-sm-6">
                <label class="form-label fw-bold text-secondary small">
                    <i class="fa-solid fa-circle-notch text-primary me-1"></i> กรองตามสถานะ:
                </label>
                <select name="filter_status" class="form-select border-primary-subtle shadow-sm">
                    <option value="" <?php echo empty($filterStatus) ? 'selected' : ''; ?>>-- ทั้งหมด --</option>
                    <option value="พร้อมใช้งาน" <?php echo ($filterStatus === 'พร้อมใช้งาน') ? 'selected' : ''; ?>>🟢 พร้อมใช้งาน</option>
                    <option value="กำลังถูกยืม" <?php echo ($filterStatus === 'กำลังถูกยืม') ? 'selected' : ''; ?>>🟡 กำลังถูกยืม</option>
                    <option value="ชำรุด / ไม่พร้อมใช้" <?php echo ($filterStatus === 'ชำรุด / ไม่พร้อมใช้' || $filterStatus === 'ชำรุด') ? 'selected' : ''; ?>>🔴 ชำรุด / ไม่พร้อมใช้</option>
                </select>
            </div>

            <!-- ตัวกรอง: หมวดหมู่ครุภัณฑ์ -->
            <div class="col-md-3 col-sm-6">
                <label class="form-label fw-bold text-secondary small">
                    <i class="fa-solid fa-tags text-success me-1"></i> กรองตามหมวดหมู่:
                </label>
                <select name="filter_category" class="form-select border-success-subtle shadow-sm">
                    <option value="" <?php echo empty($filterCategory) ? 'selected' : ''; ?>>-- ทุกหมวดหมู่ --</option>
                    <?php foreach ($categoriesList as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['classificationName']); ?>" 
                                <?php echo ($filterCategory === $cat['classificationName']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['classificationName']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- ตัวกรอง: การแสดงผล (Visibility Rules: CLB Masking) -->
            <div class="col-md-3 col-sm-6">
                <label class="form-label fw-bold text-secondary small">
                    <i class="fa-solid fa-eye text-info me-1"></i> การแสดงผล (Visibility):
                </label>
                <select name="filter_visibility" class="form-select border-info-subtle shadow-sm">
                    <option value="visible" <?php echo ($filterVisibility === 'visible' || empty($filterVisibility)) ? 'selected' : ''; ?>>🟢 ใช้งานปกติ (ซ่อน CLB)</option>
                    <option value="hidden" <?php echo ($filterVisibility === 'hidden') ? 'selected' : ''; ?>>⚪ เฉพาะอาคารเรียนรวม (CLB)</option>
                    <option value="all" <?php echo ($filterVisibility === 'all') ? 'selected' : ''; ?>>🔵 แสดงทั้งหมด (รวมที่ซ่อน)</option>
                </select>
            </div>

            <!-- ปุ่มคำสั่ง -->
            <div class="col-md-3 col-sm-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary shadow-sm flex-grow-1">
                    <i class="fa-solid fa-filter me-1"></i> นำตัวกรองไปใช้
                </button>
                <a href="report_details.php" class="btn btn-outline-secondary shadow-sm" title="ล้างตัวกรองทั้งหมด">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>

        </form>

        <!-- แถบแสดงสถานะตัวกรองปัจจุบัน (Active Filter Badges) -->
        <?php if (!empty($filterStatus) || !empty($filterCategory) || ($filterVisibility !== 'visible' && !empty($filterVisibility))): ?>
            <div class="mt-3 pt-3 border-top d-flex align-items-center flex-wrap gap-2 small">
                <span class="text-muted fw-bold me-1"><i class="fa-solid fa-sliders me-1"></i>ตัวกรองที่ใช้งานอยู่:</span>
                <?php if (!empty($filterStatus)): ?>
                    <span class="badge bg-primary-subtle text-primary border px-3 py-2 rounded-pill">
                        สถานะ: <strong><?php echo htmlspecialchars($filterStatus); ?></strong>
                        <a href="report_details.php?filter_category=<?php echo urlencode($filterCategory); ?>&filter_visibility=<?php echo urlencode($filterVisibility); ?>" class="text-primary ms-1 text-decoration-none">✕</a>
                    </span>
                <?php endif; ?>
                <?php if (!empty($filterCategory)): ?>
                    <span class="badge bg-success-subtle text-success border px-3 py-2 rounded-pill">
                        หมวดหมู่: <strong><?php echo htmlspecialchars($filterCategory); ?></strong>
                        <a href="report_details.php?filter_status=<?php echo urlencode($filterStatus); ?>&filter_visibility=<?php echo urlencode($filterVisibility); ?>" class="text-success ms-1 text-decoration-none">✕</a>
                    </span>
                <?php endif; ?>
                <?php if ($filterVisibility === 'hidden'): ?>
                    <span class="badge bg-warning-subtle text-dark border border-warning px-3 py-2 rounded-pill">
                        โหมด: <strong>เฉพาะอาคารเรียนรวม (CLB)</strong>
                        <a href="report_details.php?filter_status=<?php echo urlencode($filterStatus); ?>&filter_category=<?php echo urlencode($filterCategory); ?>&filter_visibility=visible" class="text-dark ms-1 text-decoration-none">✕</a>
                    </span>
                <?php elseif ($filterVisibility === 'all'): ?>
                    <span class="badge bg-info-subtle text-info-emphasis border border-info px-3 py-2 rounded-pill">
                        โหมด: <strong>รวมทั้งหมด</strong>
                        <a href="report_details.php?filter_status=<?php echo urlencode($filterStatus); ?>&filter_category=<?php echo urlencode($filterCategory); ?>&filter_visibility=visible" class="text-info ms-1 text-decoration-none">✕</a>
                    </span>
                <?php endif; ?>
                <a href="report_details.php" class="text-danger small ms-auto text-decoration-none fw-semibold">
                    <i class="fa-solid fa-trash-can me-1"></i>ล้างตัวกรองทั้งหมด
                </a>
            </div>
        <?php endif; ?>

    </div>

    <!-- แถวสถิติสรุปแบบย่อ (Summary KPI Mini-Cards) -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="mini-stat-card shadow-sm" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);">
                <div class="small opacity-75">จำนวนตามตัวกรอง</div>
                <div class="fs-4 fw-bold my-1"><?php echo number_format(count($reportItems)); ?> <span class="fs-6 fw-normal">รายการ</span></div>
                <div class="small text-white-50"><i class="fa-solid fa-list-check me-1"></i>ค้นพบตามเงื่อนไข</div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="mini-stat-card shadow-sm" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                <div class="small opacity-75">พร้อมใช้งาน</div>
                <div class="fs-4 fw-bold my-1"><?php echo number_format($countAvailable); ?> <span class="fs-6 fw-normal">รายการ</span></div>
                <div class="small text-white-50"><i class="fa-solid fa-circle-check me-1"></i>สถานะปกติ</div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="mini-stat-card shadow-sm" style="background: linear-gradient(135deg, #f7971e 0%, #ffd200 100%); color: #333;">
                <div class="small opacity-75">กำลังถูกยืม</div>
                <div class="fs-4 fw-bold my-1"><?php echo number_format($countBorrowed); ?> <span class="fs-6 fw-normal">รายการ</span></div>
                <div class="small text-muted"><i class="fa-solid fa-hand-holding-hand me-1"></i>รอการส่งคืน</div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="mini-stat-card shadow-sm" style="background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);">
                <div class="small opacity-75">ชำรุด / ไม่พร้อมใช้</div>
                <div class="fs-4 fw-bold my-1"><?php echo number_format($countMaint); ?> <span class="fs-6 fw-normal">รายการ</span></div>
                <div class="small text-white-50"><i class="fa-solid fa-screwdriver-wrench me-1"></i>ต้องซ่อมบำรุง</div>
            </div>
        </div>
    </div>

    <!-- ตารางแสดงข้อมูลรายละเอียด (DataTables Card) -->
    <div class="card shadow-sm p-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h5 class="fw-bold text-dark mb-0">
                <i class="fa-solid fa-table-list text-primary me-2"></i>ตารางรายการครุภัณฑ์
            </h5>
            <span class="text-muted small">
                คลิกที่หัวตารางเพื่อเรียงลำดับข้อมูล หรือใช้ช่องค้นหาด้านขวา
            </span>
        </div>

        <div class="table-responsive">
            <table id="assetReportTable" class="table table-hover align-middle table-custom w-100">
                <thead>
                    <tr>
                        <th style="width: 40px;" class="text-center">#</th>
                        <th>หมายเลขครุภัณฑ์</th>
                        <th>ชื่อรายการครุภัณฑ์</th>
                        <th>หมวดหมู่</th>
                        <th>แผนกที่สังกัด</th>
                        <th>การแสดงผล</th>
                        <th class="text-end">ราคา (บาท)</th>
                        <th class="text-center">สถานะ</th>
                        <th>ข้อมูลการยืมล่าสุด</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($reportItems)): ?>
                        <?php $index = 1; foreach ($reportItems as $row): 
                            $isBorrowed = ($row['calculated_status'] === 'กำลังถูกยืม');
                            $isMaintenance = ($row['calculated_status'] === 'ชำรุด / ไม่พร้อมใช้');
                            $isOverdue = false;
                            if ($isBorrowed && !empty($row['Deadline_borrow'])) {
                                $isOverdue = ($row['Deadline_borrow'] < $today);
                            }
                        ?>
                            <tr>
                                <td class="text-center text-muted"><?php echo $index++; ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border font-monospace px-2 py-1">
                                        <?php echo htmlspecialchars($row['DA_Number'] ?? '-'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($row['DA_ListName'] ?? '-'); ?></div>
                                    <?php if (!empty($row['DA_Brand']) && $row['DA_Brand'] !== '--'): ?>
                                        <small class="text-muted"><i class="fa-solid fa-tag me-1"></i><?php echo htmlspecialchars($row['DA_Brand']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary border">
                                        <?php echo htmlspecialchars($row['classificationName'] ?? 'ไม่ระบุ'); ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="fw-semibold text-secondary">
                                        <i class="fa-solid fa-building me-1 text-muted"></i>
                                        <?php echo htmlspecialchars($row['owner_department'] ?? 'ส่วนกลาง'); ?>
                                    </small>
                                </td>
                                <td class="text-center">
                                    <?php if (($row['is_visible'] ?? 1) == 1): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-eye me-1"></i> ปกติ</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1" title="ครุภัณฑ์อาคารเรียนรวม (CLB) ถูกซ่อนไว้"><i class="fa-solid fa-eye-slash me-1"></i> ซ่อน (CLB)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end fw-semibold text-dark">
                                    <?php echo !empty($row['DA_Price']) ? number_format((float)$row['DA_Price'], 2) : '-'; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($isBorrowed): ?>
                                        <?php if ($isOverdue): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle badge-status">
                                                <i class="fa-solid fa-triangle-exclamation me-1"></i> ยืม (เกินกำหนด)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle badge-status">
                                                <i class="fa-solid fa-clock me-1"></i> กำลังถูกยืม
                                            </span>
                                        <?php endif; ?>
                                    <?php elseif ($isMaintenance): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle badge-status">
                                            <i class="fa-solid fa-wrench me-1"></i> ชำรุด/ซ่อมบำรุง
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle badge-status">
                                            <i class="fa-solid fa-circle-check me-1"></i> พร้อมใช้งาน
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isBorrowed && !empty($row['Name_borrow'])): ?>
                                        <div class="small fw-semibold text-dark">
                                            <i class="fa-solid fa-user me-1 text-primary"></i><?php echo htmlspecialchars($row['Name_borrow']); ?>
                                        </div>
                                        <div class="text-muted" style="font-size: 0.78rem;">
                                            กำหนดคืน: <?php echo htmlspecialchars($row['Deadline_borrow'] ?? '-'); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
$(document).ready(function() {
    $('#assetReportTable').DataTable({
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        order: [[0, 'asc']],
        responsive: true
    });
});
</script>

<?php require_once __DIR__ . '/../component/admin_footer.php'; ?>
