<?php
/**
 * admin/manage_repairs.php - ระบบบันทึกและจัดการข้อมูลการซ่อมบำรุงครุภัณฑ์ (Maintenance & Repair Records)
 * ออกแบบตาม Requirement Enterprise UI/UX: แสดงเฉพาะข้อมูลและประวัติการซ่อมบำรุงทั้งหมด เรียงจากใหม่ไปเก่า
 */
session_start();

// -----------------------------------------------------------------------------
// 1. ตรวจสอบสิทธิ์การเข้าถึง (Admin Authentication)
// -----------------------------------------------------------------------------
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || ($_SESSION['useraccountType'] ?? '') !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

// -----------------------------------------------------------------------------
// 2. นำเข้าไฟล์เชื่อมต่อฐานข้อมูล PDO
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../db.php';

$currentUserID   = $_SESSION['SeUser'] ?? '';
$useraccountName = $_SESSION['Sename'] ?? 'ผู้ดูแลระบบ';
$currentUserDept = $_SESSION['userDepartment'] ?? '';
$isSuperAdmin    = (($_SESSION['useraccountType'] ?? '') === 'Super Admin' || $currentUserID === 'admin');

// -----------------------------------------------------------------------------
// 3. จัดการคำขอ AJAX (Add / Update / Delete Repair Record)
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        if ($action === 'add') {
            $daId          = trim($_POST['da_id'] ?? '');
            $repairDate    = trim($_POST['repair_Date'] ?? date('Y-m-d'));
            $repairHistory = trim($_POST['repair_Hisory'] ?? '');
            $repairPrice   = (float)($_POST['repair_Price'] ?? 0);

            if (empty($daId) || empty($repairHistory) || empty($repairDate)) {
                echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลครุภัณฑ์ วันที่ และรายละเอียดการซ่อมให้ครบถ้วน']);
                exit();
            }

            // คำนวณ repairID ถัดไป
            $nextId = (int)$pdo->query("SELECT MAX(CAST(repairID AS INTEGER)) FROM repair")->fetchColumn() + 1;
            if ($nextId < 1) $nextId = 1;

            $stmt = $pdo->prepare("
                INSERT INTO repair (repairID, repair_Hisory, repair_Price, DA_ID, repair_Date) 
                VALUES (:repairID, :repair_Hisory, :repair_Price, :DA_ID, :repair_Date)
            ");
            $stmt->execute([
                ':repairID'       => (string)$nextId,
                ':repair_Hisory'  => $repairHistory,
                ':repair_Price'   => $repairPrice,
                ':DA_ID'          => $daId,
                ':repair_Date'    => $repairDate
            ]);

            echo json_encode(['status' => 'success', 'message' => 'บันทึกข้อมูลการซ่อมบำรุงเรียบร้อยแล้ว']);
            exit();

        } elseif ($action === 'update') {
            $repairID      = trim($_POST['repairID'] ?? '');
            $daId          = trim($_POST['da_id'] ?? '');
            $repairDate    = trim($_POST['repair_Date'] ?? date('Y-m-d'));
            $repairHistory = trim($_POST['repair_Hisory'] ?? '');
            $repairPrice   = (float)($_POST['repair_Price'] ?? 0);

            if (empty($repairID) || empty($daId) || empty($repairHistory) || empty($repairDate)) {
                echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
                exit();
            }

            $stmt = $pdo->prepare("
                UPDATE repair 
                SET DA_ID = :da_id, repair_Hisory = :repair_Hisory, repair_Price = :repair_Price, repair_Date = :repair_Date 
                WHERE repairID = :repairID
            ");
            $stmt->execute([
                ':da_id'         => $daId,
                ':repair_Hisory' => $repairHistory,
                ':repair_Price'  => $repairPrice,
                ':repair_Date'   => $repairDate,
                ':repairID'      => $repairID
            ]);

            echo json_encode(['status' => 'success', 'message' => 'แก้ไขข้อมูลการซ่อมบำรุงเรียบร้อยแล้ว']);
            exit();

        } elseif ($action === 'delete') {
            $repairID = trim($_POST['repairID'] ?? '');
            if (empty($repairID)) {
                echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัสรายการซ่อมที่ต้องการลบ']);
                exit();
            }

            $stmt = $pdo->prepare("DELETE FROM repair WHERE repairID = :repairID");
            $stmt->execute([':repairID' => $repairID]);

            echo json_encode(['status' => 'success', 'message' => 'ลบรายการบันทึกการซ่อมเรียบร้อยแล้ว']);
            exit();
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
}

// -----------------------------------------------------------------------------
// 4. ดึงข้อมูลรายการครุภัณฑ์ทั้งหมดสำหรับ Dropdown เลือกรหัสในฟอร์มบันทึก
// -----------------------------------------------------------------------------
$articlesDropdown = [];
try {
    $sqlDA = "SELECT DA_ID, DA_Number, DA_ListName, DA_Brand, owner_department 
              FROM durable_articles 
              WHERE (is_approved = 1 OR is_approved IS NULL)";
    $paramsDA = [];
    if (!$isSuperAdmin && !empty($currentUserDept)) {
        $sqlDA .= " AND (owner_department = :dept OR owner_department = 'ส่วนกลาง' OR (owner_department IS NULL AND created_by = :uid))";
        $paramsDA[':dept'] = $currentUserDept;
        $paramsDA[':uid']  = $currentUserID;
    }
    $sqlDA .= " ORDER BY DA_Number ASC";
    $stmtDA = $pdo->prepare($sqlDA);
    $stmtDA->execute($paramsDA);
    $articlesDropdown = $stmtDA->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// -----------------------------------------------------------------------------
// 5. ดึงข้อมูลบันทึกการซ่อมทั้งหมด (เฉพาะข้อมูลการซ่อม เรียงจากใหม่ไปเก่า)
// -----------------------------------------------------------------------------
$repairsList = [];
$filterDaId  = trim($_GET['da_id'] ?? $_GET['DAID'] ?? '');

try {
    $sqlRepairs = "
        SELECT 
            r.repairID,
            r.repair_Date,
            r.repair_Hisory,
            r.repair_Price,
            r.DA_ID,
            da.DA_Number,
            da.DA_ListName,
            da.DA_Brand,
            da.owner_department,
            bd.buildingName,
            cr.classroomName
        FROM repair r
        LEFT JOIN durable_articles da ON r.DA_ID = da.DA_ID
        LEFT JOIN building bd ON da.buildingID = bd.buildingID
        LEFT JOIN building_classroom cr ON da.classroomID = cr.classroomID
    ";

    $whereClauses = [];
    $paramsR = [];

    // กรองตามแผนกถ้าไม่ใช่ Super Admin
    if (!$isSuperAdmin && !empty($currentUserDept)) {
        $whereClauses[] = "(da.owner_department = :dept OR da.owner_department = 'ส่วนกลาง' OR (da.owner_department IS NULL AND da.created_by = :uid))";
        $paramsR[':dept'] = $currentUserDept;
        $paramsR[':uid']  = $currentUserID;
    }

    // กรองเจาะจงครุภัณฑ์ถ้ามี parameter
    if (!empty($filterDaId)) {
        $whereClauses[] = "r.DA_ID = :filter_da";
        $paramsR[':filter_da'] = $filterDaId;
    }

    if (!empty($whereClauses)) {
        $sqlRepairs .= " WHERE " . implode(" AND ", $whereClauses);
    }

    // เรียงจากใหม่ไปเก่าอย่างเคร่งครัด
    $sqlRepairs .= " ORDER BY r.repair_Date DESC, CAST(r.repairID AS INTEGER) DESC";

    $stmtR = $pdo->prepare($sqlRepairs);
    $stmtR->execute($paramsR);
    $repairsList = $stmtR->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $repairsList = [];
    $errorMsg = $e->getMessage();
}

// -----------------------------------------------------------------------------
// 6. คำนวณสรุปสถิติค่าซ่อมบำรุง (KPI Metrics)
// -----------------------------------------------------------------------------
$totalRepairsCount = count($repairsList);
$totalRepairCost   = 0.0;
$thisMonthRepairs  = 0;
$thisMonthCost     = 0.0;
$distinctAssetsMap = [];

$currentMonthStr = date('Y-m');

foreach ($repairsList as $r) {
    $price = (float)($r['repair_Price'] ?? 0);
    $totalRepairCost += $price;

    $rDate = $r['repair_Date'] ?? '';
    if (strpos($rDate, $currentMonthStr) === 0) {
        $thisMonthRepairs++;
        $thisMonthCost += $price;
    }

    if (!empty($r['DA_ID'])) {
        $distinctAssetsMap[$r['DA_ID']] = true;
    }
}
$distinctAssetsCount = count($distinctAssetsMap);

// -----------------------------------------------------------------------------
// 7. โหลด Layout Components (Header, Sidebar & Navbar)
// -----------------------------------------------------------------------------
$pageTitle = "บันทึกข้อมูลการซ่อมบำรุง (Maintenance & Repair Records)";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>

<style>
    :root {
        --repair-card-radius: 16px;
        --repair-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.03);
    }

    /* KPI Summary Cards */
    .kpi-repair-card {
        border: 1px solid #e2e8f0;
        border-radius: var(--repair-card-radius);
        background: #ffffff;
        box-shadow: var(--repair-shadow);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        position: relative;
        overflow: hidden;
    }
    .kpi-repair-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 25px -3px rgba(15, 23, 42, 0.1);
    }
    .kpi-repair-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
    }
    .kpi-blue::before   { background-color: #2563eb; }
    .kpi-amber::before  { background-color: #f59e0b; }
    .kpi-emerald::before{ background-color: #10b981; }
    .kpi-purple::before { background-color: #8b5cf6; }

    .kpi-icon-box {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
    }

    /* Table Container Card */
    .repair-table-card {
        border: 1px solid #e2e8f0;
        border-radius: var(--repair-card-radius);
        background: #ffffff;
        box-shadow: var(--repair-shadow);
    }

    .table > :not(caption) > * > * {
        padding: 0.85rem 1rem;
        vertical-align: middle;
    }
    .table thead th {
        font-size: 0.84rem;
        font-weight: 600;
        color: #475569;
        background-color: #f8fafc;
        border-bottom: 2px solid #e2e8f0;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
</style>

<div class="container-fluid px-0 mb-5">

    <!-- ส่วนหัวหน้าจอ (Header Action Bar) -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small text-muted">
                    <li class="breadcrumb-item"><a href="main.php" class="text-decoration-none text-muted"><i class="fa-solid fa-house me-1"></i>หน้าแรก</a></li>
                    <li class="breadcrumb-item active fw-semibold text-primary" aria-current="page">บันทึกการซ่อม</li>
                </ol>
            </nav>
            <h3 class="fw-bold text-dark mb-0 d-flex align-items-center">
                <span class="rounded-3 bg-primary-subtle text-primary p-2 me-2 d-inline-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                    <i class="fa-solid fa-wrench fa-sm"></i>
                </span>
                บันทึกข้อมูลการซ่อมบำรุง
            </h3>
            <p class="text-muted small mb-0 mt-1">
                ระบบจัดการและบันทึกประวัติการซ่อมบำรุงครุภัณฑ์ (แสดงเฉพาะข้อมูลและประวัติการซ่อม เรียงจากใหม่ไปเก่า)
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if (!empty($filterDaId)): ?>
                <a href="manage_repairs.php" class="btn btn-outline-secondary rounded-pill px-3 shadow-xs btn-sm">
                    <i class="fa-solid fa-layer-group me-1"></i> แสดงการซ่อมทั้งหมด
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary rounded-pill px-3 shadow-xs btn-sm" id="btnOpenAddModal">
                <i class="fa-solid fa-plus me-1"></i> บันทึกการซ่อมใหม่
            </button>
            <a href="manage_data.php" class="btn btn-outline-secondary rounded-pill px-3 shadow-xs btn-sm">
                <i class="fa-solid fa-boxes-stacked me-1"></i> หน้ารายการครุภัณฑ์
            </a>
        </div>
    </div>

    <!-- สรุปสถิติค่าซ่อมบำรุง (KPI Metrics Cards) -->
    <div class="row g-3 mb-4">
        <!-- การ์ด 1: จำนวนครั้งที่ซ่อมทั้งหมด -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-repair-card kpi-blue p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">บันทึกการซ่อมทั้งหมด</div>
                        <h3 class="fw-bold text-dark mb-0 mt-1"><?= number_format($totalRepairsCount); ?> <span class="fs-6 fw-normal text-muted">ครั้ง</span></h3>
                    </div>
                    <div class="kpi-icon-box bg-primary-subtle text-primary">
                        <i class="fa-solid fa-screwdriver-wrench"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- การ์ด 2: รวมค่าใช้จ่ายทั้งหมด -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-repair-card kpi-amber p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">รวมค่าใช้จ่ายการซ่อมทั้งหมด</div>
                        <h3 class="fw-bold text-danger mb-0 mt-1">฿<?= number_format($totalRepairCost, 2); ?> <span class="fs-6 fw-normal text-muted">บาท</span></h3>
                    </div>
                    <div class="kpi-icon-box bg-warning-subtle text-warning">
                        <i class="fa-solid fa-money-bill-wave"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- การ์ด 3: การซ่อมในเดือนนี้ -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-repair-card kpi-emerald p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">ซ่อมในเดือนนี้ (<?= date('m/Y') ?>)</div>
                        <h3 class="fw-bold text-success mb-0 mt-1"><?= number_format($thisMonthRepairs); ?> <span class="fs-6 fw-normal text-muted">ครั้ง</span></h3>
                        <small class="text-muted">฿<?= number_format($thisMonthCost, 2); ?> บาท</small>
                    </div>
                    <div class="kpi-icon-box bg-success-subtle text-success">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- การ์ด 4: จำนวนครุภัณฑ์ที่เคยซ่อม -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="kpi-repair-card kpi-purple p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">ครุภัณฑ์ที่เคยผ่านการซ่อม</div>
                        <h3 class="fw-bold mb-0 mt-1" style="color: #8b5cf6;"><?= number_format($distinctAssetsCount); ?> <span class="fs-6 fw-normal text-muted">ชิ้น</span></h3>
                    </div>
                    <div class="kpi-icon-box" style="background-color: #ede9fe; color: #8b5cf6;">
                        <i class="fa-solid fa-boxes-packing"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ตารางข้อมูลการซ่อมบำรุง (แสดงเฉพาะข้อมูลการซ่อม) -->
    <div class="repair-table-card p-3 p-md-4">
        
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 pb-3 border-bottom">
            <div>
                <h5 class="fw-bold text-dark mb-0 d-flex align-items-center">
                    <i class="fa-solid fa-list-check text-primary me-2"></i> รายการประวัติการซ่อมบำรุงทั้งหมด
                </h5>
                <small class="text-muted">เรียงลำดับจากรายการซ่อมล่าสุดไปเก่าสุด</small>
            </div>
            
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-primary rounded-pill px-3 py-2">
                    <i class="fa-solid fa-wrench me-1"></i> รวม <?= number_format($totalRepairsCount); ?> รายการ
                </span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="repairsDataTable">
                <thead>
                    <tr>
                        <th class="text-center" style="width: 50px;">#</th>
                        <th class="text-center" style="width: 120px;">วันที่ซ่อม</th>
                        <th style="width: 160px;">หมายเลขครุภัณฑ์</th>
                        <th>รายการครุภัณฑ์</th>
                        <th>รายการซ่อมบำรุง / อาการเสีย</th>
                        <th class="text-end" style="width: 140px;">ค่าใช้จ่าย (บาท)</th>
                        <th class="text-center" style="width: 120px;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($repairsList)): ?>
                        <?php $idx = 1; foreach ($repairsList as $rep): 
                            $cleanNum = trim($rep['DA_Number'] ?? '-', "'\"");
                            $priceVal = (float)($rep['repair_Price'] ?? 0);
                        ?>
                            <tr>
                                <td class="text-center font-monospace text-muted"><?= $idx++; ?></td>
                                <td class="text-center font-monospace fw-semibold text-dark">
                                    <i class="fa-regular fa-calendar text-primary me-1"></i><?= htmlspecialchars($rep['repair_Date'] ?: '-'); ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace fw-bold">
                                        <?= htmlspecialchars($cleanNum ?: '-'); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($rep['DA_ListName'] ?? 'ไม่พบชื่อรายการ'); ?></div>
                                    <div class="d-flex align-items-center gap-1 flex-wrap small mt-1">
                                        <?php if (!empty($rep['DA_Brand'])): ?>
                                            <span class="text-muted" style="font-size: 0.76rem;"><i class="fa-solid fa-tag me-1"></i><?= htmlspecialchars($rep['DA_Brand']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($rep['owner_department'])): ?>
                                            <span class="badge bg-info-subtle text-info-emphasis border" style="font-size: 0.72rem;"><?= htmlspecialchars($rep['owner_department']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($rep['buildingName'])): ?>
                                            <span class="text-muted" style="font-size: 0.76rem;"><i class="fa-solid fa-location-dot text-danger me-1"></i><?= htmlspecialchars($rep['buildingName']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-dark fw-semibold">
                                        <i class="fa-solid fa-wrench text-warning me-1.5"></i><?= htmlspecialchars($rep['repair_Hisory']); ?>
                                    </div>
                                </td>
                                <td class="text-end font-monospace fw-bold text-danger">
                                    ฿<?= number_format($priceVal, 2); ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center align-items-center gap-1">
                                        <button type="button" 
                                                class="btn btn-outline-warning btn-sm rounded-pill px-2.5 btn-edit-repair" 
                                                data-id="<?= htmlspecialchars($rep['repairID']); ?>"
                                                data-da="<?= htmlspecialchars($rep['DA_ID']); ?>"
                                                data-history="<?= htmlspecialchars($rep['repair_Hisory'], ENT_QUOTES); ?>"
                                                data-price="<?= $priceVal; ?>"
                                                data-date="<?= htmlspecialchars($rep['repair_Date']); ?>"
                                                title="แก้ไขรายการซ่อม">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <button type="button" 
                                                class="btn btn-outline-danger btn-sm rounded-pill px-2.5 btn-delete-repair" 
                                                data-id="<?= htmlspecialchars($rep['repairID']); ?>"
                                                title="ลบรายการซ่อม">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="5" class="text-end fw-bold text-dark">รวมค่าใช้จ่ายการซ่อมบำรุงทั้งหมด:</th>
                        <th class="text-end font-monospace fw-bold text-danger">฿<?= number_format($totalRepairCost, 2); ?> บาท</th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div>

<!-- Modal สำหรับเพิ่ม / แก้ไขรายการซ่อมบำรุง -->
<div class="modal fade" id="repairModal" tabindex="-1" aria-labelledby="repairModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-bottom py-3 px-4 bg-light">
                <div class="d-flex align-items-center">
                    <span class="rounded-3 bg-primary-subtle text-primary p-2 me-2 d-inline-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                        <i class="fa-solid fa-wrench"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="repairModalLabel">บันทึกข้อมูลการซ่อมบำรุง</h5>
                        <small class="text-muted" id="repairModalSub">เพิ่มข้อมูลรายการซ่อมบำรุงครุภัณฑ์</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form id="repairModalForm">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" id="modalAction" value="add">
                    <input type="hidden" name="repairID" id="modalRepairID" value="">

                    <!-- 1. เลือกครุภัณฑ์เป้าหมาย -->
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small">
                            <i class="fa-solid fa-barcode text-primary me-1"></i> เลือกครุภัณฑ์ที่ทำการซ่อม: <span class="text-danger">*</span>
                        </label>
                        <select name="da_id" id="modalDaId" class="form-select border shadow-xs" required>
                            <option value="">-- กรุณาเลือกครุภัณฑ์ --</option>
                            <?php foreach ($articlesDropdown as $art): ?>
                                <option value="<?= htmlspecialchars($art['DA_ID']); ?>" <?= ($filterDaId === $art['DA_ID']) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars(trim($art['DA_Number'], "'\"")); ?> - <?= htmlspecialchars($art['DA_ListName']); ?> (<?= htmlspecialchars($art['owner_department'] ?? 'ส่วนกลาง'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <!-- 2. วันที่ซ่อมบำรุง -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">
                                <i class="fa-solid fa-calendar text-success me-1"></i> วันที่ดำเนินการซ่อม: <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control border shadow-xs" name="repair_Date" id="modalRepairDate" value="<?= date('Y-m-d'); ?>" required>
                        </div>

                        <!-- 3. ค่าใช้จ่ายในการซ่อม -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">
                                <i class="fa-solid fa-money-bill-wave text-danger me-1"></i> ค่าใช้จ่าย (บาท): <span class="text-danger">*</span>
                            </label>
                            <div class="input-group shadow-xs">
                                <span class="input-group-text bg-light border-end-0">฿</span>
                                <input type="number" step="0.01" min="0" class="form-control border-start-0" name="repair_Price" id="modalRepairPrice" value="0.00" placeholder="0.00" required>
                            </div>
                        </div>
                    </div>

                    <!-- 4. รายการ / อาการเสียที่ซ่อม -->
                    <div class="mb-2">
                        <label class="form-label fw-bold text-secondary small">
                            <i class="fa-solid fa-file-lines text-warning me-1"></i> รายการซ่อมบำรุง / อาการเสีย / การเปลี่ยนอะไหล่: <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control border shadow-xs" name="repair_Hisory" id="modalRepairHistory" rows="3" placeholder="ระบุรายละเอียดอาการเสีย งานที่ทำ หรืออะไหล่ที่เปลี่ยน..." required></textarea>
                    </div>
                </div>

                <div class="modal-footer bg-light py-2 px-4 border-top">
                    <button type="button" class="btn btn-secondary rounded-pill px-3 shadow-xs" data-bs-dismiss="modal">
                        <i class="fa-solid fa-xmark me-1"></i> ยกเลิก
                    </button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-xs" id="btnSaveRepair">
                        <i class="fa-solid fa-floppy-disk me-1"></i> บันทึกข้อมูล
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../component/admin_footer.php'; ?>

<script>
$(document).ready(function() {
    // 1. กำหนดค่าเริ่มต้น DataTable
    const table = $('#repairsDataTable').DataTable({
        pageLength: 10,
        order: [], // เรียงลำดับจากใหม่ไปเก่าตาม SQL query
        responsive: true
    });

    const repairModal = new bootstrap.Modal(document.getElementById('repairModal'));

    // 2. เปิด Modal เพิ่มรายการใหม่
    $('#btnOpenAddModal').on('click', function() {
        $('#repairModalForm')[0].reset();
        $('#modalAction').val('add');
        $('#modalRepairID').val('');
        $('#modalRepairDate').val('<?= date('Y-m-d'); ?>');
        $('#modalRepairPrice').val('0.00');
        $('#repairModalLabel').text('เพิ่มบันทึกการซ่อมบำรุงใหม่');
        $('#repairModalSub').text('บันทึกรายละเอียดงานซ่อมและค่าใช้จ่ายของครุภัณฑ์');
        repairModal.show();
    });

    // 3. เปิด Modal แก้ไขรายการเดิม
    $(document).on('click', '.btn-edit-repair', function() {
        const id      = $(this).data('id');
        const daId    = $(this).data('da');
        const history = $(this).data('history');
        const price   = $(this).data('price');
        const date    = $(this).data('date');

        $('#modalAction').val('update');
        $('#modalRepairID').val(id);
        $('#modalDaId').val(daId);
        $('#modalRepairDate').val(date);
        $('#modalRepairHistory').val(history);
        $('#modalRepairPrice').val(price);

        $('#repairModalLabel').text('แก้ไขบันทึกการซ่อมบำรุง (รหัส: ' + id + ')');
        $('#repairModalSub').text('ปรับปรุงข้อมูลงานซ่อม วันที่ หรือค่าใช้จ่าย');
        repairModal.show();
    });

    // 4. บันทึก / แก้ไขข้อมูลผ่าน AJAX
    $('#repairModalForm').on('submit', function(e) {
        e.preventDefault();
        const formData = $(this).serialize();
        const actionText = ($('#modalAction').val() === 'add') ? 'บันทึกรายการ' : 'แก้ไขรายการ';

        Swal.fire({
            title: `ยืนยัน${actionText}?`,
            text: 'กรุณาตรวจสอบความถูกต้องของข้อมูลก่อนยืนยัน',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'ยืนยัน',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'กำลังประมวลผล...',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });

                $.ajax({
                    url: 'manage_repairs.php',
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
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
                                text: resp.message
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: 'ไม่สามารถติดต่อเซิร์ฟเวอร์ได้: ' + error
                        });
                    }
                });
            }
        });
    });

    // 5. ลบรายการซ่อมบำรุง
    $(document).on('click', '.btn-delete-repair', function() {
        const repairId = $(this).data('id');

        Swal.fire({
            title: 'ยืนยันการลบรายการซ่อม?',
            text: `คุณต้องการลบประวัติการซ่อมรหัส ${repairId} ใช่หรือไม่? ข้อมูลที่ลบแล้วจะไม่สามารถกู้คืนได้`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: '<i class="fa-solid fa-trash-can me-1"></i> ยืนยันการลบ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'กำลังลบข้อมูล...',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });

                $.ajax({
                    url: 'manage_repairs.php',
                    type: 'POST',
                    data: { action: 'delete', repairID: repairId },
                    dataType: 'json',
                    success: function(resp) {
                        if (resp.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'ลบสำเร็จ!',
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
                                text: resp.message
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: 'ไม่สามารถติดต่อเซิร์ฟเวอร์ได้: ' + error
                        });
                    }
                });
            }
        });
    });
});
</script>
