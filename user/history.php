<?php
session_start();

// 1. ตรวจสอบสิทธิ์การเข้าถึง (ต้องเป็น User ทั่วไปเท่านั้น)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000002') {
    header("Location: ../index.php");
    exit();
}

// 2. โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO
require_once __DIR__ . '/../control/connectPDO_dev.php';

$useraccountName = $_SESSION['Sename'] ?? 'ไม่ทราบชื่อ';
$useraccountID   = $_SESSION['SeUser'] ?? '';
$userDepartment  = $_SESSION['userDepartment'] ?? '';

// หากใน Session ไม่มีแผนก ให้ดึงสดจากฐานข้อมูล
if (empty($userDepartment) && !empty($useraccountID)) {
    try {
        $stmtDept = $pdo->prepare("SELECT userDepartment FROM useraccount WHERE useraccountID = :uid");
        $stmtDept->execute([':uid' => $useraccountID]);
        $userDepartment = $stmtDept->fetchColumn() ?: 'ไม่ระบุแผนก';
        $_SESSION['userDepartment'] = $userDepartment;
    } catch (PDOException $e) {
        $userDepartment = 'ไม่ระบุแผนก';
    }
}

$today = date('Y-m-d');

// 3. ดึงข้อมูลประวัติการยืม-คืนทั้งหมดของผู้ใช้คนนี้ (เรียงจากใหม่ไปเก่า)
try {
    $sqlHistory = "SELECT b.*, da.DA_Number, da.DA_ListName, da.DA_Brand, da.DA_Size, 
                          c.classificationName, bg.buildingName, cr.classroomName,
                          u_back.useraccountName AS receiverName
                   FROM borrow_back b
                   LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
                   LEFT JOIN classification c ON da.classificationID = c.classificationID
                   LEFT JOIN building bg ON da.buildingID = bg.buildingID
                   LEFT JOIN building_classroom cr ON da.classroomID = cr.classroomID
                   LEFT JOIN useraccount u_back ON b.useraccountID_back = u_back.useraccountID
                   WHERE b.useraccountID_borrow = :uid
                   ORDER BY COALESCE(b.timestamp_borrow, b.Date_borrow) DESC, b.IDborrow_back DESC";
    $stmtH = $pdo->prepare($sqlHistory);
    $stmtH->execute([':uid' => $useraccountID]);
    $historyList = $stmtH->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $historyList = [];
    $errorMsg = $e->getMessage();
}

// นับสถิติสำหรับสรุปผล
$totalCount         = count($historyList);
$borrowingCount     = 0;
$pendingReturnCount = 0;
$returnedCount      = 0;
$overdueCount       = 0;

foreach ($historyList as $item) {
    $status          = $item['status'] ?? '';
    $isReturned      = (!empty($item['Date_back']) || $status === 'returned');
    $isPendingReturn = ($status === 'pending_return');

    if ($isReturned) {
        $returnedCount++;
    } elseif ($isPendingReturn) {
        $pendingReturnCount++;
    } else {
        $borrowingCount++;
        $dueDate = !empty($item['due_date']) ? $item['due_date'] : $item['Deadline_borrow'];
        if (!empty($dueDate) && $dueDate < $today) {
            $overdueCount++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติการยืม-คืนครุภัณฑ์ของฉัน | ระบบบริหารจัดการครุภัณฑ์</title>

    <!-- Google Fonts: Sarabun & Prompt -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- DataTables Bootstrap 5 CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css" />
    
    <style>
        :root {
            --app-font-family: 'Prompt', 'Sarabun', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --bs-primary: #2563eb;
            --bs-primary-dark: #1d4ed8;
            --card-radius: 16px;
            --soft-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.03);
            --hover-shadow: 0 10px 25px -3px rgba(15, 23, 42, 0.1), 0 4px 10px -2px rgba(15, 23, 42, 0.04);
        }

        body {
            background-color: #f8fafc;
            font-family: var(--app-font-family);
            color: #1e293b;
            min-height: 100vh;
        }

        /* Modern Navbar */
        .user-navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            box-shadow: 0 4px 16px rgba(30, 58, 138, 0.15);
        }
        .user-navbar .nav-link {
            font-weight: 500;
            color: rgba(255, 255, 255, 0.85);
            transition: all 0.2s ease;
            border-radius: 8px;
            padding: 0.5rem 0.9rem;
        }
        .user-navbar .nav-link:hover, .user-navbar .nav-link.active {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.15);
        }

        /* KPI Metric Cards (Interactive & Clickable) */
        .kpi-card {
            border: 2px solid transparent;
            border-radius: var(--card-radius);
            background: #ffffff;
            box-shadow: var(--soft-shadow);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
            position: relative;
            cursor: pointer;
            user-select: none;
        }
        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--hover-shadow);
            border-color: #cbd5e1;
        }
        .kpi-card.active {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px -2px rgba(15, 23, 42, 0.12);
        }
        .kpi-card.kpi-primary.active { border-color: #2563eb; background-color: #f8faff; }
        .kpi-card.kpi-warning.active { border-color: #f59e0b; background-color: #fffbeb; }
        .kpi-card.kpi-purple.active  { border-color: #8b5cf6; background-color: #fbf9ff; }
        .kpi-card.kpi-success.active { border-color: #10b981; background-color: #f0fdf4; }
        .kpi-card.kpi-danger.active  { border-color: #ef4444; background-color: #fef2f2; }
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
        }
        .kpi-primary::before { background-color: #2563eb; }
        .kpi-warning::before { background-color: #f59e0b; }
        .kpi-purple::before  { background-color: #8b5cf6; }
        .kpi-success::before { background-color: #10b981; }
        .kpi-danger::before  { background-color: #ef4444; }

        .kpi-icon-box {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
        }

        /* Table Card */
        .table-card {
            border: 1px solid #e2e8f0;
            border-radius: var(--card-radius);
            background: #ffffff;
            box-shadow: var(--soft-shadow);
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

        /* Filter Pills */
        .filter-pill-btn {
            border-radius: 50rem;
            padding: 0.4rem 1rem;
            font-size: 0.86rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .filter-pill-btn.active {
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
        }

        /* Status Badges */
        .status-badge {
            font-size: 0.82rem;
            font-weight: 600;
            padding: 0.45rem 0.85rem;
            border-radius: 50rem;
            display: inline-flex;
            align-items: center;
        }

        /* Detail Modal Styling */
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
    </style>
</head>
<body>

<!-- แถบนำทาง (User Navbar) -->
<nav class="navbar navbar-expand-lg navbar-dark user-navbar sticky-top">
    <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="main.php">
            <span class="rounded-3 bg-white bg-opacity-20 p-2 me-2 d-inline-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                <i class="fa-solid fa-boxes-stacked text-white"></i>
            </span>
            <span>Asset Portal</span>
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#userNavbar" aria-controls="userNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="userNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="main.php">
                        <i class="fa-solid fa-house me-1"></i> หน้าแรกแดชบอร์ด
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="main.php#available-section">
                        <i class="fa-solid fa-box-open me-1"></i> ยืมครุภัณฑ์
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-warning" href="../borrow_request.php">
                        <i class="fa-solid fa-qrcode me-1"></i> สแกน QR ขอยืม
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="history.php">
                        <i class="fa-solid fa-clock-rotate-left me-1"></i> ประวัติการยืม-คืนของฉัน
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="request_item.php">
                        <i class="fa-solid fa-file-circle-plus me-1"></i> เสนอขึ้นทะเบียนครุภัณฑ์
                    </a>
                </li>
            </ul>
            <div class="d-flex align-items-center flex-wrap gap-2 text-white">
                <div class="bg-white bg-opacity-10 px-3 py-1.5 rounded-pill border border-white border-opacity-25 d-flex align-items-center gap-2 small">
                    <i class="fa-solid fa-circle-user text-warning fs-6"></i>
                    <div>
                        <span class="fw-semibold"><?= htmlspecialchars($useraccountName); ?></span>
                        <span class="opacity-75 ms-1">(<?= htmlspecialchars($userDepartment); ?>)</span>
                    </div>
                </div>
                <a href="../logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3 shadow-xs" onclick="return confirm('คุณต้องการออกจากระบบหรือไม่?');">
                    <i class="fa-solid fa-right-from-bracket me-1"></i> ออกจากระบบ
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- คอนเทนเนอร์เนื้อหาหลัก -->
<div class="container-fluid px-3 px-md-4 py-4">

    <!-- Breadcrumb & ส่วนหัวหน้าจอ -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small text-muted">
                    <li class="breadcrumb-item"><a href="main.php" class="text-decoration-none text-muted"><i class="fa-solid fa-house me-1"></i>หน้าแรก</a></li>
                    <li class="breadcrumb-item active fw-semibold text-primary" aria-current="page">ประวัติการยืม-คืนของฉัน</li>
                </ol>
            </nav>
            <h3 class="fw-bold text-dark mb-1 d-flex align-items-center">
                <span class="rounded-3 bg-primary-subtle text-primary p-2 me-2 d-inline-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                    <i class="fa-solid fa-clock-rotate-left fa-sm"></i>
                </span>
                ประวัติการยืม - คืนครุภัณฑ์ของฉัน
            </h3>
            <p class="text-muted small mb-0">
                ตรวจสอบบันทึกการทำรายการยืม-คืน กำหนดส่งคืน และสถานะความเคลื่อนไหวทั้งหมดในบัญชีของคุณ
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="../borrow_request.php" class="btn btn-outline-primary rounded-pill px-3 shadow-xs btn-sm">
                <i class="fa-solid fa-qrcode me-1"></i> สแกน QR ขอยืม
            </a>
            <a href="main.php#available-section" class="btn btn-primary rounded-pill px-3 shadow-xs btn-sm">
                <i class="fa-solid fa-box-open me-1"></i> ยืมครุภัณฑ์เพิ่ม
            </a>
            <a href="main.php" class="btn btn-outline-secondary rounded-pill px-3 shadow-xs btn-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าหลัก
            </a>
        </div>
    </div>

    <!-- สรุปสถิติประวัติการยืม-คืน (KPI Summary Cards - คลิกได้เพื่อกรองข้อมูล) -->
    <div class="row g-3 mb-4" id="kpiCardsContainer">
        <!-- การ์ด 1: ทำรายการทั้งหมด -->
        <div class="col-12 col-sm-6 col-xl">
            <div class="kpi-card kpi-primary p-3 h-100 active" data-filter="" title="คลิกเพื่อแสดงรายการทั้งหมด">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">ทำรายการทั้งหมด</div>
                        <h3 class="fw-bold text-dark mb-0 mt-1"><?= number_format($totalCount); ?> <span class="fs-6 fw-normal text-muted">ครั้ง</span></h3>
                    </div>
                    <div class="kpi-icon-box bg-primary-subtle text-primary">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                </div>
                <div class="mt-2 pt-1 border-top border-light-subtle d-flex justify-content-between align-items-center text-primary" style="font-size: 0.74rem;">
                    <span>คลิกเพื่อกรองข้อมูล</span>
                    <i class="fa-solid fa-angle-right"></i>
                </div>
            </div>
        </div>

        <!-- การ์ด 2: กำลังยืมใช้งาน -->
        <div class="col-12 col-sm-6 col-xl">
            <div class="kpi-card kpi-warning p-3 h-100" data-filter="กำลังยืม" title="คลิกเพื่อกรองเฉพาะรายการที่กำลังยืม">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">กำลังยืมใช้งาน</div>
                        <h3 class="fw-bold text-warning mb-0 mt-1"><?= number_format($borrowingCount); ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                    <div class="kpi-icon-box bg-warning-subtle text-warning">
                        <i class="fa-solid fa-hand-holding-hand"></i>
                    </div>
                </div>
                <div class="mt-2 pt-1 border-top border-light-subtle d-flex justify-content-between align-items-center text-warning" style="font-size: 0.74rem;">
                    <span>คลิกเพื่อกรองข้อมูล</span>
                    <i class="fa-solid fa-angle-right"></i>
                </div>
            </div>
        </div>

        <!-- การ์ด 3: แจ้งคืนแล้ว (รออนุมัติ) -->
        <div class="col-12 col-sm-6 col-xl">
            <div class="kpi-card kpi-purple p-3 h-100" data-filter="รออนุมัติรับคืน" title="คลิกเพื่อกรองเฉพาะรายการที่รออนุมัติรับคืน">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">แจ้งคืนแล้ว (รออนุมัติ)</div>
                        <h3 class="fw-bold text-purple mb-0 mt-1" style="color: #8b5cf6;"><?= number_format($pendingReturnCount); ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                    <div class="kpi-icon-box text-purple" style="background-color: #ede9fe; color: #8b5cf6;">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                </div>
                <div class="mt-2 pt-1 border-top border-light-subtle d-flex justify-content-between align-items-center" style="font-size: 0.74rem; color: #8b5cf6;">
                    <span>คลิกเพื่อกรองข้อมูล</span>
                    <i class="fa-solid fa-angle-right"></i>
                </div>
            </div>
        </div>

        <!-- การ์ด 4: คืนเรียบร้อยแล้ว -->
        <div class="col-12 col-sm-6 col-xl">
            <div class="kpi-card kpi-success p-3 h-100" data-filter="คืนแล้ว" title="คลิกเพื่อกรองเฉพาะรายการที่คืนแล้ว">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">คืนเรียบร้อยแล้ว</div>
                        <h3 class="fw-bold text-success mb-0 mt-1"><?= number_format($returnedCount); ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                    <div class="kpi-icon-box bg-success-subtle text-success">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                </div>
                <div class="mt-2 pt-1 border-top border-light-subtle d-flex justify-content-between align-items-center text-success" style="font-size: 0.74rem;">
                    <span>คลิกเพื่อกรองข้อมูล</span>
                    <i class="fa-solid fa-angle-right"></i>
                </div>
            </div>
        </div>

        <!-- การ์ด 5: เลยกำหนดส่งคืน -->
        <div class="col-12 col-sm-6 col-xl">
            <div class="kpi-card kpi-danger p-3 h-100" data-filter="เลยกำหนด" title="คลิกเพื่อกรองเฉพาะรายการที่เลยกำหนดส่งคืน">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">เลยกำหนดส่งคืน</div>
                        <h3 class="fw-bold text-danger mb-0 mt-1"><?= number_format($overdueCount); ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                    <div class="kpi-icon-box bg-danger-subtle text-danger">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                </div>
                <div class="mt-2 pt-1 border-top border-light-subtle d-flex justify-content-between align-items-center text-danger" style="font-size: 0.74rem;">
                    <span>คลิกเพื่อกรองข้อมูล</span>
                    <i class="fa-solid fa-angle-right"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- ตารางบันทึกประวัติการยืม-คืน (DataTable) -->
    <div class="table-card p-3 p-md-4" id="tableCard">
        
        <!-- แถบหัวตารางและปุ่มกรองสถานะ -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 pb-3 border-bottom">
            <div>
                <h5 class="fw-bold text-dark mb-0 d-flex align-items-center">
                    <i class="fa-solid fa-table-list text-primary me-2"></i> บันทึกรายการยืม - คืนทั้งหมด
                </h5>
                <small class="text-muted">แสดงข้อมูลเรียงจากรายการล่าสุดไปเก่าสุด</small>
            </div>
            
            <!-- ปุ่มคัดกรองสถานะอย่างรวดเร็ว (Filter Pills) -->
            <div class="btn-group btn-group-sm p-1 bg-light rounded-pill border shadow-xs" role="group" id="statusFilterButtons">
                <button type="button" class="btn filter-pill-btn btn-primary active rounded-pill px-3" data-filter="">
                    ทั้งหมด (<?= $totalCount; ?>)
                </button>
                <button type="button" class="btn filter-pill-btn btn-light rounded-pill px-3 text-secondary" data-filter="กำลังยืม">
                    กำลังยืม (<?= $borrowingCount; ?>)
                </button>
                <button type="button" class="btn filter-pill-btn btn-light rounded-pill px-3 text-secondary" data-filter="รออนุมัติรับคืน">
                    รออนุมัติรับคืน (<?= $pendingReturnCount; ?>)
                </button>
                <button type="button" class="btn filter-pill-btn btn-light rounded-pill px-3 text-secondary" data-filter="คืนแล้ว">
                    คืนแล้ว (<?= $returnedCount; ?>)
                </button>
                <button type="button" class="btn filter-pill-btn btn-light rounded-pill px-3 text-secondary" data-filter="เลยกำหนด">
                    เลยกำหนด (<?= $overdueCount; ?>)
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="borrowHistoryTable">
                <thead>
                    <tr>
                        <th class="text-center" style="width: 50px;">#</th>
                        <th style="width: 140px;">หมายเลขครุภัณฑ์</th>
                        <th>รายการครุภัณฑ์</th>
                        <th style="width: 120px;">วันที่ยืม</th>
                        <th style="width: 120px;">กำหนดคืน</th>
                        <th class="text-center" style="width: 110px;">ระยะเวลายืม</th>
                        <th class="text-center" style="width: 150px;">สถานะ</th>
                        <th class="text-center" style="width: 130px;">การจัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($historyList) > 0): ?>
                        <?php $i = 1; foreach ($historyList as $row): 
                            $status          = $row['status'] ?? '';
                            $isReturned      = (!empty($row['Date_back']) || $status === 'returned');
                            $isPendingReturn = ($status === 'pending_return');
                            $dueDate         = !empty($row['due_date']) ? $row['due_date'] : $row['Deadline_borrow'];
                            $isOverdue       = (!$isReturned && !$isPendingReturn && !empty($dueDate) && $dueDate < $today);

                            // คำนวณจำนวนวันที่ยืม (Duration)
                            $borrowDays = 0;
                            if (!empty($row['Date_borrow'])) {
                                $borrowDate = new DateTime($row['Date_borrow']);
                                if ($isReturned && !empty($row['Date_back'])) {
                                    $endDate = new DateTime($row['Date_back']);
                                    $diff = $borrowDate->diff($endDate);
                                    $borrowDays = (int)$diff->format('%a');
                                    $borrowDaysBadge = '<span class="badge bg-light text-secondary border font-monospace" title="ยืมเป็นเวลา ' . $borrowDays . ' วัน"><i class="fa-solid fa-check text-success me-1"></i>' . $borrowDays . ' วัน</span>';
                                } else {
                                    $todayDate = new DateTime($today);
                                    if ($todayDate >= $borrowDate) {
                                        $diff = $borrowDate->diff($todayDate);
                                        $borrowDays = (int)$diff->format('%a');
                                    } else {
                                        $borrowDays = 0;
                                    }

                                    if ($isOverdue) {
                                        $borrowDaysBadge = '<span class="badge bg-danger-subtle text-danger border border-danger fw-bold font-monospace" title="ยืมมาแล้ว ' . $borrowDays . ' วัน (เลยกำหนด)"><i class="fa-solid fa-clock me-1"></i>' . $borrowDays . ' วัน</span>';
                                    } else {
                                        $borrowDaysBadge = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold font-monospace" title="ยืมมาแล้ว ' . $borrowDays . ' วัน"><i class="fa-regular fa-clock me-1"></i>' . $borrowDays . ' วัน</span>';
                                    }
                                }
                            } else {
                                $borrowDaysBadge = '<span class="text-muted">-</span>';
                            }

                            // ข้อมูลสำหรับการเปิดดูใน Modal
                            $jsonData = json_encode([
                                'id'          => $row['IDborrow_back'],
                                'da_id'       => $row['DA_ID'],
                                'number'      => $row['DA_Number'] ?? '-',
                                'name'        => $row['DA_ListName'] ?? '-',
                                'brand'       => $row['DA_Brand'] ?? '-',
                                'size'        => $row['DA_Size'] ?? '-',
                                'category'    => $row['classificationName'] ?? 'ไม่ระบุ',
                                'location'    => trim(($row['buildingName'] ?? '') . ' ' . ($row['classroomName'] ?? '')),
                                'borrow_date' => $row['Date_borrow'] ?? '-',
                                'due_date'    => $dueDate ?? '-',
                                'detail'      => $row['Detail_borrow'] ?? '-',
                                'return_date' => $row['Date_back'] ?? '-',
                                'receiver'    => $row['receiverName'] ?? ($row['Name_back'] ?? '-'),
                                'status'      => $isReturned ? 'returned' : ($isPendingReturn ? 'pending_return' : ($isOverdue ? 'overdue' : 'borrowed'))
                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                        ?>
                            <tr>
                                <td class="text-center font-monospace text-muted"><?= $i++; ?></td>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace fw-bold">
                                        <?= htmlspecialchars($row['DA_Number'] ?? '-'); ?>
                                    </span>
                                    <div class="small text-muted font-monospace mt-0.5" style="font-size: 0.75rem;">
                                        ID: <?= htmlspecialchars($row['IDborrow_back']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark mb-1">
                                        <a href="javascript:void(0)" class="text-decoration-none text-dark btn-show-detail" data-info='<?= $jsonData ?>'>
                                            <?= htmlspecialchars($row['DA_ListName'] ?? 'ไม่พบชื่อรายการ'); ?>
                                        </a>
                                    </div>
                                    <div class="d-flex align-items-center gap-1 flex-wrap small">
                                        <?php if (!empty($row['classificationName'])): ?>
                                            <span class="badge bg-info-subtle text-info-emphasis border" style="font-size: 0.72rem;"><?= htmlspecialchars($row['classificationName']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($row['DA_Brand'])): ?>
                                            <span class="text-muted" style="font-size: 0.78rem;"><i class="fa-solid fa-tag me-1"></i><?= htmlspecialchars($row['DA_Brand']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($row['buildingName'])): ?>
                                            <span class="text-muted" style="font-size: 0.78rem;"><i class="fa-solid fa-location-dot text-danger me-1"></i><?= htmlspecialchars($row['buildingName']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($row['Detail_borrow'])): ?>
                                        <div class="small text-muted text-truncate mt-1" style="max-width: 250px;" title="<?= htmlspecialchars($row['Detail_borrow']); ?>">
                                            <i class="fa-regular fa-comment-dots text-primary me-1"></i><?= htmlspecialchars($row['Detail_borrow']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="font-monospace fw-semibold text-secondary">
                                        <i class="fa-regular fa-calendar text-primary me-1"></i><?= htmlspecialchars($row['Date_borrow'] ?? '-'); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="font-monospace fw-semibold <?= $isOverdue ? 'text-danger' : 'text-dark'; ?>">
                                        <i class="fa-regular fa-calendar-check <?= $isOverdue ? 'text-danger' : 'text-success'; ?> me-1"></i><?= htmlspecialchars($dueDate ?? '-'); ?>
                                    </div>
                                    <?php if ($isOverdue): ?>
                                        <span class="badge bg-danger rounded-pill mt-1" style="font-size: 0.7rem;">เกินกำหนด</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?= $borrowDaysBadge; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($isReturned): ?>
                                        <span class="status-badge bg-success-subtle text-success border border-success-subtle" title="คืนเมื่อ: <?= htmlspecialchars($row['Date_back'] ?? '-'); ?>">
                                            <i class="fa-solid fa-circle-check me-1.5"></i> คืนแล้ว
                                        </span>
                                        <?php if (!empty($row['Date_back'])): ?>
                                            <div class="small text-muted font-monospace mt-0.5" style="font-size: 0.72rem;"><?= htmlspecialchars($row['Date_back']); ?></div>
                                        <?php endif; ?>
                                    <?php elseif ($isPendingReturn): ?>
                                        <span class="status-badge bg-warning-subtle text-dark border border-warning" title="แจ้งส่งคืนแล้ว อยู่ระหว่างรอเจ้าหน้าที่/เจ้าของอนุมัติรับคืน">
                                            <i class="fa-solid fa-hourglass-half text-warning me-1.5"></i> รออนุมัติรับคืน
                                        </span>
                                    <?php elseif ($isOverdue): ?>
                                        <span class="status-badge bg-danger-subtle text-danger border border-danger">
                                            <i class="fa-solid fa-triangle-exclamation me-1.5"></i> เลยกำหนด
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge bg-primary-subtle text-primary border border-primary-subtle">
                                            <i class="fa-solid fa-clock me-1.5"></i> กำลังยืม
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center align-items-center gap-1">
                                        <?php if (!$isReturned && !$isPendingReturn): ?>
                                            <a href="return_form.php?IDborrow=<?= urlencode($row['IDborrow_back']); ?>" class="btn btn-warning btn-sm rounded-pill px-3 shadow-xs fw-semibold" title="กดเพื่อบันทึกแจ้งส่งคืนครุภัณฑ์">
                                                <i class="fa-solid fa-rotate-left me-1"></i> แจ้งคืน
                                            </a>
                                        <?php elseif ($isPendingReturn): ?>
                                            <button type="button" class="btn btn-outline-warning btn-sm rounded-pill px-2.5 shadow-xs btn-show-detail" data-info='<?= $jsonData ?>' title="รอเจ้าหน้าที่อนุมัติรับคืน">
                                                <i class="fa-solid fa-clock me-1"></i> รออนุมัติ
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-2.5 shadow-xs btn-show-detail" data-info='<?= $jsonData ?>' title="คลิกดูใบสรุปรายการยืม-คืน">
                                                <i class="fa-solid fa-file-lines me-1"></i> รายละเอียด
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal แสดงรายละเอียดการยืม-คืนฉบับเต็ม -->
<div class="modal fade" id="historyDetailModal" tabindex="-1" aria-labelledby="historyDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-bottom py-3 px-4 bg-light rounded-top-4">
                <div class="d-flex align-items-center">
                    <span class="rounded-3 bg-primary-subtle text-primary p-2 me-2 d-inline-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                        <i class="fa-solid fa-file-invoice"></i>
                    </span>
                    <div>
                        <h5 class="modal-title fw-bold text-dark mb-0" id="historyDetailModalLabel">รายละเอียดบันทึกการยืม - คืน</h5>
                        <small class="text-muted" id="modalSubTitle">รหัสรายการยืม: -</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="modalDetailBody">
                <!-- เนื้อหาจะถูกเติมผ่าน JavaScript -->
            </div>
            <div class="modal-footer bg-light py-2 px-4 rounded-bottom-4">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">
                    <i class="fa-solid fa-xmark me-1"></i> ปิดหน้าต่าง
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    // 1. กำหนดค่าเริ่มต้นให้กับ DataTables
    const table = $('#borrowHistoryTable').DataTable({
        language: {
            url: "//cdn.datatables.net/plug-ins/1.13.4/i18n/th.json"
        },
        pageLength: 10,
        order: [], // เรียงลำดับจากใหม่ไปเก่าตาม SQL query
        responsive: true
    });

    // 2. ฟังก์ชันจัดการคัดกรองตารางตามสถานะ พร้อมซิงค์สถานะ Active ระหว่างการ์ดและปุ่ม
    function applyStatusFilter(filterVal, shouldScroll = false) {
        // ซิงค์ปุ่ม Filter Pills
        $('#statusFilterButtons button').each(function() {
            const btnVal = $(this).data('filter') !== undefined ? String($(this).data('filter')) : '';
            if (btnVal === String(filterVal)) {
                $(this).addClass('active btn-primary').removeClass('btn-light text-secondary');
            } else {
                $(this).removeClass('active btn-primary').addClass('btn-light text-secondary');
            }
        });

        // ซิงค์การ์ด KPI Cards
        $('.kpi-card').each(function() {
            const cardVal = $(this).data('filter') !== undefined ? String($(this).data('filter')) : '';
            if (cardVal === String(filterVal)) {
                $(this).addClass('active');
            } else {
                $(this).removeClass('active');
            }
        });

        // คัดกรองตาราง DataTables ที่คอลัมน์สถานะ (Column index 6)
        table.column(6).search(filterVal).draw();

        // เลื่อนหน้าจอลงมาที่ตารางเบาๆ หากผู้ใช้คลิกมาจากการ์ด KPI
        if (shouldScroll) {
            $('html, body').animate({
                scrollTop: $('#tableCard').offset().top - 80
            }, 300);
        }
    }

    // จัดการ Event เมื่อคลิกที่การ์ด KPI
    $('.kpi-card').on('click', function() {
        const filterVal = $(this).data('filter') !== undefined ? String($(this).data('filter')) : '';
        applyStatusFilter(filterVal, true);
    });

    // จัดการ Event เมื่อคลิกที่ปุ่ม Filter Pills
    $('#statusFilterButtons button').on('click', function() {
        const filterVal = $(this).data('filter') !== undefined ? String($(this).data('filter')) : '';
        applyStatusFilter(filterVal, false);
    });

    // 3. จัดการเปิดดูรายละเอียดการยืม-คืนใน Modal
    const detailModal = new bootstrap.Modal(document.getElementById('historyDetailModal'));
    
    $(document).on('click', '.btn-show-detail', function() {
        const rawData = $(this).attr('data-info');
        if (!rawData) return;
        
        try {
            const d = JSON.parse(rawData);
            $('#modalSubTitle').text('รหัสรายการยืม: ' + d.id);
            
            let statusPill = '';
            if (d.status === 'returned') {
                statusPill = '<span class="badge bg-success-subtle text-success border border-success px-3 py-1 rounded-pill"><i class="fa-solid fa-circle-check me-1"></i>ส่งคืนและตรวจรับเรียบร้อยแล้ว</span>';
            } else if (d.status === 'pending_return') {
                statusPill = '<span class="badge bg-warning-subtle text-dark border border-warning px-3 py-1 rounded-pill"><i class="fa-solid fa-hourglass-half me-1"></i>แจ้งส่งคืนแล้ว อยู่ระหว่างรอเจ้าหน้าที่/เจ้าของอนุมัติรับคืน</span>';
            } else if (d.status === 'overdue') {
                statusPill = '<span class="badge bg-danger-subtle text-danger border border-danger px-3 py-1 rounded-pill"><i class="fa-solid fa-triangle-exclamation me-1"></i>เลยกำหนดส่งคืนแล้ว</span>';
            } else {
                statusPill = '<span class="badge bg-primary-subtle text-primary border border-primary px-3 py-1 rounded-pill"><i class="fa-solid fa-clock me-1"></i>กำลังยืมใช้งาน</span>';
            }

            const html = `
                <div class="text-center mb-4 pb-3 border-bottom">
                    <div class="mb-2">${statusPill}</div>
                    <h4 class="fw-bold text-dark mb-1">${d.name}</h4>
                    <span class="badge bg-light text-primary border font-monospace px-3 py-1 fs-6">
                        หมายเลขครุภัณฑ์: ${d.number}
                    </span>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3 border h-100">
                            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-circle-info text-primary me-2"></i>ข้อมูลครุภัณฑ์</h6>
                            <table class="table table-sm table-borderless mb-0 small">
                                <tr>
                                    <td class="text-muted" style="width: 120px;">หมวดหมู่:</td>
                                    <td class="fw-semibold">${d.category}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">ยี่ห้อ/รุ่น:</td>
                                    <td class="fw-semibold">${d.brand}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">ขนาด/สเปก:</td>
                                    <td class="fw-semibold">${d.size}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">สถานที่จัดเก็บ:</td>
                                    <td class="fw-semibold">${d.location || 'ไม่ระบุ'}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3 border h-100">
                            <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-calendar-days text-success me-2"></i>ข้อมูลการยืม - คืน</h6>
                            <table class="table table-sm table-borderless mb-0 small">
                                <tr>
                                    <td class="text-muted" style="width: 120px;">วันที่เริ่มยืม:</td>
                                    <td class="fw-semibold font-monospace text-primary">${d.borrow_date}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">กำหนดส่งคืน:</td>
                                    <td class="fw-semibold font-monospace text-dark">${d.due_date}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">วันที่คืนจริง:</td>
                                    <td class="fw-semibold font-monospace text-success">${d.return_date !== '-' ? d.return_date : '<span class="text-muted fst-italic">ยังไม่ส่งคืน</span>'}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted">ผู้รับคืน:</td>
                                    <td class="fw-semibold">${d.receiver !== '-' ? d.receiver : '<span class="text-muted fst-italic">-</span>'}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="mt-3 p-3 bg-light rounded-3 border">
                    <h6 class="fw-bold text-dark mb-1 small"><i class="fa-regular fa-comment-dots text-secondary me-2"></i>วัตถุประสงค์ในการยืม / รายละเอียด:</h6>
                    <div class="small text-secondary" style="white-space: pre-line;">${d.detail && d.detail !== '-' ? d.detail : '<span class=\"text-muted fst-italic\">- ไม่ได้ระบุวัตถุประสงค์ -</span>'}</div>
                </div>
            `;

            $('#modalDetailBody').html(html);
            detailModal.show();
        } catch (e) {
            console.error(e);
        }
    });
});
</script>
</body>
</html>
