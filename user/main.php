<?php
session_start();

// 1. ตรวจสอบ Security Check สิทธิ์การเข้าถึง (ต้องล็อกอิน)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1) {
    header("Location: ../index.php");
    exit();
}

// 2. โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO แบบ Dev ย้อนกลับไปโฟลเดอร์หลัก
require_once __DIR__ . '/../control/connectPDO_dev.php';

$useraccountName = isset($_SESSION['Sename']) ? $_SESSION['Sename'] : 'ไม่ทราบชื่อ';
$useraccountID   = isset($_SESSION['SeUser']) ? $_SESSION['SeUser'] : '';
$userDepartment  = isset($_SESSION['userDepartment']) ? $_SESSION['userDepartment'] : '';

// หากใน Session ยังไม่มีแผนก ให้ query สดจากฐานข้อมูล
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

// 3. ดึงข้อมูลสถิติของ User ส่วนตัวโดยใช้ PDO Prepared Statements
$borrowCount = 0;
$overdueCount = 0;
$totalHistoryCount = 0;
$myRequestCount = 0;
$todayDate = date('Y-m-d'); // ดึงวันที่ปัจจุบันผ่าน PHP แทนเพื่อความเข้ากันได้กับทั้ง MySQL และ SQLite

try {
    // 1. จำนวนครุภัณฑ์ที่กำลังยืม (รวมถึงรายการที่แจ้งคืนแล้วแต่รออนุมัติ - นับแบบ DISTINCT ป้องกันซ้ำ)
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT DA_ID) AS total FROM borrow_back WHERE useraccountID_borrow = :uid AND (Date_back IS NULL OR Date_back = '' OR status = 'pending_return') AND (status != 'returned' OR status IS NULL)");
    $stmt->execute([':uid' => $useraccountID]);
    $borrowCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // 2. จำนวนครุภัณฑ์ที่เลยกำหนดคืน (Overdue - ไม่รวมรายการที่รออนุมัติคืน)
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT DA_ID) AS total FROM borrow_back WHERE useraccountID_borrow = :uid AND (Date_back IS NULL OR Date_back = '') AND (status != 'returned' OR status IS NULL) AND (status != 'pending_return' OR status IS NULL) AND Deadline_borrow < :today");
    $stmt->execute([
        ':uid' => $useraccountID,
        ':today' => $todayDate
    ]);
    $overdueCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // 3. จำนวนประวัติการยืม-คืนทั้งหมดของผู้ใช้คนนี้
    $stmtHist = $pdo->prepare("SELECT COUNT(*) AS total FROM borrow_back WHERE useraccountID_borrow = :uid");
    $stmtHist->execute([':uid' => $useraccountID]);
    $totalHistoryCount = $stmtHist->fetch(PDO::FETCH_ASSOC)['total'];

    // 4. จำนวนคำขอเสนอขึ้นทะเบียนครุภัณฑ์ที่ตนเองยื่นไว้
    $stmtReq = $pdo->prepare("SELECT COUNT(*) AS total FROM durable_articles WHERE created_by = :uid");
    $stmtReq->execute([':uid' => $useraccountID]);
    $myRequestCount = $stmtReq->fetch(PDO::FETCH_ASSOC)['total'];
    
} catch (PDOException $e) {
    $errorMsg = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - ระบบจัดการครุภัณฑ์</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- DataTables Bootstrap 5 CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" />
    <!-- DataTables Responsive CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css" />
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        html, body {
            overflow-x: hidden;
            width: 100%;
            max-width: 100vw;
            background-color: #f8f9fc;
            font-family: 'Sarabun', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .widget-card {
            border: none;
            border-radius: 12px;
            color: white;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            text-decoration: none;
            display: block;
        }
        .widget-card:hover { 
            transform: translateY(-4px); 
            box-shadow: 0 10px 20px rgba(0,0,0,0.12) !important;
            color: white;
        }
        .widget-icon { font-size: 2.8rem; opacity: 0.55; }
        
        .bg-primary-grad { background: linear-gradient(45deg, #4e73df, #224abe); }
        .bg-danger-grad  { background: linear-gradient(45deg, #e74a3b, #be2617); }
        .bg-success-grad { background: linear-gradient(45deg, #1cc88a, #13855c); }
        .bg-purple-grad  { background: linear-gradient(45deg, #6f42c1, #4e229e); }
        
        .quick-nav-card {
            border: 1px solid #e3e6f0;
            border-radius: 12px;
            background: #fff;
            padding: 1rem;
            transition: all 0.2s ease;
            text-decoration: none;
            color: #4e73df;
            display: flex;
            align-items: center;
        }
        .quick-nav-card:hover {
            border-color: #4e73df;
            background-color: #f8faff;
            transform: translateY(-2px);
            color: #224abe;
        }
    </style>
</head>
<body>

<!-- แถบนำทาง (Navbar เมนูเต็มรูปแบบสำหรับ User) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm sticky-top">
    <div class="container">
        <a class="navbar-brand fw-bold" href="main.php">
            <i class="fa-solid fa-user-astronaut me-2"></i>User Panel
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#userNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="userNavbar">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link active fw-bold" href="main.php">
                        <i class="fa-solid fa-house me-1"></i> หน้าแรก
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#available-section">
                        <i class="fa-solid fa-box-open me-1"></i> ยืมครุภัณฑ์
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-info fw-semibold" href="../borrow_request.php">
                        <i class="fa-solid fa-qrcode me-1"></i> สแกน QR ยืมครุภัณฑ์
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#borrowed-section">
                        <i class="fa-solid fa-hand-holding-hand me-1"></i> รายการกำลังยืม 
                        <?php if ($borrowCount > 0): ?>
                            <span class="badge bg-warning text-dark rounded-pill"><?php echo $borrowCount; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="history.php">
                        <i class="fa-solid fa-clock-rotate-left me-1"></i> ประวัติการยืม-คืน
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="fa-solid fa-boxes-packing me-1"></i> ขึ้นทะเบียนครุภัณฑ์
                    </a>
                    <ul class="dropdown-menu shadow-sm border-0">
                        <li>
                            <a class="dropdown-item" href="request_item.php">
                                <i class="fa-solid fa-file-circle-plus text-primary me-2"></i> เสนอขอขึ้นทะเบียนใหม่
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="request_item.php?tab=csv">
                                <i class="fa-solid fa-file-csv text-success me-2"></i> นำเข้าคำขอผ่าน CSV
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="request_item.php#requestsHistoryTable">
                                <i class="fa-solid fa-clipboard-list text-warning me-2"></i> ติดตามคำขอของฉัน
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
            <div class="ms-auto text-light d-flex align-items-center flex-wrap gap-2">
                <span class="me-2 small">
                    <i class="fa-solid fa-circle-user text-warning me-1"></i> 
                    ผู้ใช้งาน: <strong><?php echo htmlspecialchars($useraccountName); ?></strong> 
                    (<?php echo htmlspecialchars($userDepartment); ?>)
                </span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                    <i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="container mt-4 mb-5">
    
    <!-- ส่วนหัวหน้าจอ -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="text-secondary fw-bold mb-1"><i class="fa-solid fa-id-card text-primary me-2"></i>ภาพรวมผู้ใช้งาน (User Dashboard)</h4>
            <p class="text-muted small mb-0">ยินดีต้อนรับสู่ระบบจัดการครุภัณฑ์ แผนกสังกัด: <strong><?php echo htmlspecialchars($userDepartment); ?></strong></p>
        </div>
        <div class="d-flex gap-2">
            <a href="history.php" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-clock-rotate-left me-1"></i> ประวัติการยืม-คืน
            </a>
            <a href="request_item.php" class="btn btn-primary rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-file-circle-plus me-1"></i> เสนอขอขึ้นทะเบียนครุภัณฑ์
            </a>
        </div>
    </div>

    <!-- เมนูลัดเข้าถึงหน้าต่างๆ (Quick Action Shortcuts) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-lg">
            <a href="../borrow_request.php" class="quick-nav-card shadow-sm h-100 border border-primary-subtle">
                <div class="p-2 bg-primary-subtle rounded-3 text-primary me-3"><i class="fa-solid fa-qrcode fa-lg"></i></div>
                <div>
                    <div class="fw-bold text-primary small">สแกน QR Code</div>
                    <div class="text-muted" style="font-size: 0.78rem;">ยืม/คืน ด้วยรหัสครุภัณฑ์</div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg">
            <a href="#available-section" class="quick-nav-card shadow-sm h-100">
                <div class="p-2 bg-primary-subtle rounded-3 text-primary me-3"><i class="fa-solid fa-box-open fa-lg"></i></div>
                <div>
                    <div class="fw-bold text-dark small">ยืมครุภัณฑ์</div>
                    <div class="text-muted" style="font-size: 0.78rem;">เลือกรายการพร้อมยืม</div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg">
            <a href="#borrowed-section" class="quick-nav-card shadow-sm h-100">
                <div class="p-2 bg-warning-subtle rounded-3 text-warning me-3"><i class="fa-solid fa-hand-holding-hand fa-lg"></i></div>
                <div>
                    <div class="fw-bold text-dark small">รายการกำลังยืม</div>
                    <div class="text-muted" style="font-size: 0.78rem;">รอคืน <?php echo $borrowCount; ?> ชิ้น</div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg">
            <a href="history.php" class="quick-nav-card shadow-sm h-100">
                <div class="p-2 bg-info-subtle rounded-3 text-info me-3"><i class="fa-solid fa-clock-rotate-left fa-lg"></i></div>
                <div>
                    <div class="fw-bold text-dark small">ประวัติยืม-คืน</div>
                    <div class="text-muted" style="font-size: 0.78rem;">ทั้งหมด <?php echo $totalHistoryCount; ?> ครั้ง</div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-6 col-lg">
            <a href="request_item.php" class="quick-nav-card shadow-sm h-100">
                <div class="p-2 bg-success-subtle rounded-3 text-success me-3"><i class="fa-solid fa-file-circle-plus fa-lg"></i></div>
                <div>
                    <div class="fw-bold text-dark small">เสนอขึ้นทะเบียน</div>
                    <div class="text-muted" style="font-size: 0.78rem;">กรอกเดี่ยว / CSV</div>
                </div>
            </a>
        </div>
        <div class="col-12 col-md-6 col-lg">
            <a href="request_item.php#myRequestsTable" class="quick-nav-card shadow-sm h-100">
                <div class="p-2 bg-purple-subtle rounded-3 text-purple me-3"><i class="fa-solid fa-clipboard-check fa-lg"></i></div>
                <div>
                    <div class="fw-bold text-dark small">คำขอของฉัน</div>
                    <div class="text-muted" style="font-size: 0.78rem;">ติดตามสถานะอนุมัติ</div>
                </div>
            </a>
        </div>
    </div>
    
    <!-- Widget แสดงผลสถิติส่วนตัว (4 กล่องแบบคลิกเพื่อเปิดหน้าได้) -->
    <div class="row g-3 mb-5">
        <div class="col-12 col-md-6 col-xl-3">
            <a href="#borrowed-section" class="card widget-card bg-primary-grad shadow-sm h-100 p-3">
                <div class="card-body d-flex justify-content-between align-items-center p-1">
                    <div>
                        <h6 class="fw-light mb-1 text-white-50">ครุภัณฑ์ที่กำลังยืม</h6>
                        <h2 class="fw-bold mb-0"><?php echo $borrowCount; ?> <span class="fs-6 fw-normal">รายการ</span></h2>
                    </div>
                    <i class="fa-solid fa-hand-holding-hand widget-icon"></i>
                </div>
            </a>
        </div>
        
        <div class="col-12 col-md-6 col-xl-3">
            <a href="#borrowed-section" class="card widget-card bg-danger-grad shadow-sm h-100 p-3">
                <div class="card-body d-flex justify-content-between align-items-center p-1">
                    <div>
                        <h6 class="fw-light mb-1 text-white-50">ครุภัณฑ์เลยกำหนดคืน</h6>
                        <h2 class="fw-bold mb-0"><?php echo $overdueCount; ?> <span class="fs-6 fw-normal">รายการ</span></h2>
                    </div>
                    <i class="fa-solid fa-triangle-exclamation widget-icon"></i>
                </div>
            </a>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <a href="history.php" class="card widget-card bg-success-grad shadow-sm h-100 p-3">
                <div class="card-body d-flex justify-content-between align-items-center p-1">
                    <div>
                        <h6 class="fw-light mb-1 text-white-50">ประวัติการยืม-คืนทั้งหมด</h6>
                        <h2 class="fw-bold mb-0"><?php echo $totalHistoryCount; ?> <span class="fs-6 fw-normal">ครั้ง</span></h2>
                    </div>
                    <i class="fa-solid fa-clock-rotate-left widget-icon"></i>
                </div>
            </a>
        </div>

        <div class="col-12 col-md-6 col-xl-3">
            <a href="request_item.php#myRequestsTable" class="card widget-card bg-purple-grad shadow-sm h-100 p-3">
                <div class="card-body d-flex justify-content-between align-items-center p-1">
                    <div>
                        <h6 class="fw-light mb-1 text-white-50">คำขอขึ้นทะเบียนของฉัน</h6>
                        <h2 class="fw-bold mb-0"><?php echo $myRequestCount; ?> <span class="fs-6 fw-normal">รายการ</span></h2>
                    </div>
                    <i class="fa-solid fa-clipboard-list widget-icon"></i>
                </div>
            </a>
        </div>
    </div>
    
    <!-- แสดงข้อความแจ้งเตือน Flash Message (เมื่อมีการทำรายการยืม-คืน) -->
    <?php if (!empty($_SESSION['flash_msg'])): ?>
        <?php 
            $fType = $_SESSION['flash_type'] ?? 'success';
            $alertClass = ($fType === 'error') ? 'danger' : (($fType === 'info') ? 'warning' : 'success');
            $iconClass = ($fType === 'error') ? 'triangle-exclamation' : (($fType === 'info') ? 'hourglass-half' : 'circle-check');
            $alertTitle = ($fType === 'error') ? 'เกิดข้อผิดพลาด' : (($fType === 'info') ? 'แจ้งเตือนสถานะ' : 'ทำรายการสำเร็จ!');
        ?>
        <div class="alert alert-<?php echo $alertClass; ?> border-0 shadow-sm rounded-4 p-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center">
                <div class="p-2 bg-<?php echo $alertClass; ?>-subtle text-<?php echo $alertClass; ?> rounded-circle me-3">
                    <i class="fa-solid fa-<?php echo $iconClass; ?> fa-xl"></i>
                </div>
                <div>
                    <h6 class="fw-bold mb-0 text-dark"><?php echo $alertTitle; ?></h6>
                    <small class="text-muted"><?php echo htmlspecialchars($_SESSION['flash_msg']); ?></small>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    icon: '<?php echo ($fType === "error") ? "error" : (($fType === "info") ? "info" : "success"); ?>',
                    title: '<?php echo $alertTitle; ?>',
                    text: <?php echo json_encode($_SESSION['flash_msg']); ?>,
                    confirmButtonColor: '#0d6efd',
                    confirmButtonText: 'ตกลง'
                });
            });
        </script>
        <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <!-- ส่วน: ตารางรายการที่กำลังยืม -->
    <div class="card shadow-sm border-0 rounded-4 mb-5" id="borrowed-section">
        <div class="card-header bg-white border-0 pt-4 pb-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="fw-bold text-warning mb-0">
                    <i class="fa-solid fa-clock-rotate-left me-2"></i> รายการครุภัณฑ์ที่คุณกำลังยืม (รอส่งคืน)
                </h5>
                <a href="history.php" class="btn btn-outline-warning btn-sm rounded-pill px-3 fw-semibold">
                    <i class="fa-solid fa-clock-rotate-left me-1"></i> ดูประวัติการยืม-คืนทั้งหมด <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle w-100" id="borrowedItemsTable">
                    <thead class="table-light">
                        <tr>
                            <th>ลำดับ</th>
                            <th>หมายเลขครุภัณฑ์</th>
                            <th>ชื่อรายการ</th>
                            <th>วันที่ยืม</th>
                            <th>กำหนดคืน</th>
                            <th class="text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        try {
                            // ดึงข้อมูลการยืมของคนที่ล็อกอินอยู่ ที่ยังไม่ได้คืน โดยเลือกเฉพาะรายการล่าสุดต่อครุภัณฑ์ (ป้องกันรายการซ้ำซ้อน)
                            $sql_Borrowed = "SELECT b.IDborrow_back, b.DA_ID, b.Date_borrow, b.Deadline_borrow, b.status, b.Date_back,
                                                    da.DA_Number, da.DA_ListName 
                                             FROM borrow_back b
                                             JOIN durable_articles da ON b.DA_ID = da.DA_ID
                                             INNER JOIN (
                                                 SELECT DA_ID, MAX(IDborrow_back) AS latest_borrow_id
                                                 FROM borrow_back
                                                 WHERE useraccountID_borrow = :uid 
                                                   AND (status != 'returned' OR status IS NULL)
                                                   AND (Date_back IS NULL OR Date_back = '' OR status = 'pending_return')
                                                 GROUP BY DA_ID
                                             ) latest_b ON b.IDborrow_back = latest_b.latest_borrow_id
                                             ORDER BY 
                                                CASE WHEN b.status = 'pending_return' THEN 1 ELSE 0 END,
                                                b.Deadline_borrow ASC";
                            $stmtB = $pdo->prepare($sql_Borrowed);
                            $stmtB->execute([':uid' => $useraccountID]);
                            $borrowedItems = $stmtB->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (count($borrowedItems) > 0) {
                                $order = 1;
                                foreach ($borrowedItems as $row) {
                                    $today = date('Y-m-d');
                                    $isPendingReturn = (($row['status'] ?? '') === 'pending_return');
                                    $isOverdue       = (!$isPendingReturn && !empty($row['Deadline_borrow']) && $row['Deadline_borrow'] < $today);

                                    if ($isPendingReturn) {
                                        $deadlineBadge = "<span class='badge bg-warning text-dark'><i class='fa-solid fa-hourglass-half me-1'></i> รอเจ้าของอนุมัติรับคืน</span>";
                                    } elseif ($isOverdue) {
                                        $deadlineBadge = "<span class='badge bg-danger'>เลยกำหนด</span>";
                                    } else {
                                        $deadlineBadge = "<span class='badge bg-success'>ปกติ</span>";
                                    }

                                    echo "<tr>";
                                    echo "<td>{$order}</td>";
                                    echo "<td><span class='badge bg-light text-dark border font-monospace'>" . htmlspecialchars($row['DA_Number']) . "</span></td>";
                                    echo "<td><span class='fw-bold text-secondary'>" . htmlspecialchars($row['DA_ListName']) . "</span></td>";
                                    echo "<td>" . htmlspecialchars($row['Date_borrow']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['Deadline_borrow']) . " {$deadlineBadge}</td>";
                                    
                                    // ปุ่มการจัดการ
                                    echo "<td class='text-center'>";
                                    if ($isPendingReturn) {
                                        echo "<span class='badge bg-warning-subtle text-warning-emphasis border border-warning rounded-pill px-3 py-2'>
                                                <i class='fa-solid fa-hourglass-half me-1'></i> รออนุมัติคืน
                                              </span>";
                                    } else {
                                        $targetId = !empty($row['DA_Number']) ? $row['DA_Number'] : $row['DA_ID'];
                                        echo "<a href='../borrow_request.php?asset_id=" . urlencode($targetId) . "' class='btn btn-sm btn-warning rounded-pill px-3 shadow-sm'>
                                                <i class='fa-solid fa-rotate-left me-1'></i> คืนครุภัณฑ์
                                              </a>";
                                    }
                                    echo "</td>";
                                    echo "</tr>";
                                    $order++;
                                }
                            }
                        } catch(PDOException $e) {
                            echo "<tr><td colspan='6' class='text-center text-danger'>เกิดข้อผิดพลาดในการโหลดข้อมูล: ".$e->getMessage()."</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ส่วนล่าง: ตารางรายการครุภัณฑ์พร้อมยืม -->
    <div class="card shadow-sm border-0 rounded-4" id="available-section">
        <div class="card-header bg-white border-0 pt-4 pb-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="fw-bold text-primary mb-0">
                    <i class="fa-solid fa-box-open me-2"></i> รายการครุภัณฑ์ที่พร้อมให้ยืม (Available Assets)
                </h5>
                <a href="request_item.php" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-semibold">
                    <i class="fa-solid fa-file-circle-plus me-1"></i> เสนอขอขึ้นทะเบียนใหม่ <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle w-100" id="availableItemsTable">
                    <thead class="table-light">
                        <tr>
                            <th>ลำดับ</th>
                            <th>หมายเลขครุภัณฑ์</th>
                            <th>ชื่อรายการ</th>
                            <th>หมวดหมู่</th>
                            <th>สถานที่ตั้ง</th>
                            <th class="text-center">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        try {
                            // ดึงเฉพาะครุภัณฑ์ที่มี statusID = 's0001' (พร้อมใช้งาน) โดยใช้ PDO
                            $sql_Available = "SELECT durable_articles.DA_ID, durable_articles.DA_Number, durable_articles.DA_ListName, 
                                                     classification.classificationName, building.buildingName, building_classroom.classroomName 
                                              FROM durable_articles 
                                              LEFT JOIN classification ON classification.classificationID = durable_articles.classificationID 
                                              LEFT JOIN building ON building.buildingID = durable_articles.buildingID
                                              LEFT JOIN building_classroom ON building_classroom.classroomID = durable_articles.classroomID
                                              WHERE durable_articles.statusID = 's0001' 
                                                AND (durable_articles.is_approved = 1 OR durable_articles.is_approved IS NULL)
                                                AND (durable_articles.is_visible = 1 OR durable_articles.is_visible IS NULL)";
                            
                            $stmt = $pdo->query($sql_Available);
                            $availableItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (count($availableItems) > 0) {
                                $order = 1;
                                // วนลูปแสดงผล
                                foreach ($availableItems as $row) {
                                    // หากข้อมูลห้องไม่มี ให้แสดงแค่ชื่อตึก
                                    $location = htmlspecialchars($row['buildingName'] . ' ' . $row['classroomName']);
                                    echo "<tr>";
                                    echo "<td>{$order}</td>";
                                    echo "<td><span class='badge bg-light text-dark border'>" . htmlspecialchars($row['DA_Number']) . "</span></td>";
                                    echo "<td><span class='fw-bold text-secondary'>" . htmlspecialchars($row['DA_ListName']) . "</span></td>";
                                    echo "<td>" . htmlspecialchars($row['classificationName']) . "</td>";
                                    echo "<td><i class='fa-solid fa-location-dot text-danger me-1'></i> {$location}</td>";
                                    
                                    // ลิงก์ไปยังหน้า borrow_request.php (ชี้ Path ย้อนกลับไปโฟลเดอร์หลัก)
                                    echo "<td class='text-center'>
                                            <a href='../borrow_request.php?asset_id=" . htmlspecialchars($row['DA_ID']) . "' class='btn btn-sm btn-primary rounded-pill px-3 shadow-sm'>
                                                <i class='fa-solid fa-hand-holding-hand me-1'></i> ยืมชิ้นนี้
                                            </a>
                                          </td>";
                                    echo "</tr>";
                                    $order++;
                                }
                            }
                        } catch(PDOException $e) {
                            echo "<tr><td colspan='6' class='text-center text-danger'>เกิดข้อผิดพลาดในการโหลดข้อมูล</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
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
        // เปิดใช้งานไลบรารี DataTables สำหรับการค้นหาและแบ่งหน้า พร้อม Responsive
        var table = $('#availableItemsTable').DataTable({
            "responsive": true,
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/th.json" // ภาษาไทย
            },
            "pageLength": 10,
            "ordering": true,
            "info": true,
            initComplete: function () {
                // คอลัมน์ที่ 3 (Index 3) คือ "หมวดหมู่"
                this.api().columns(3).every(function () {
                    var column = this;
                    // สร้าง Dropdown และนำไปต่อท้ายช่องค้นหา (Search)
                    var select = $('<select class="form-select form-select-sm d-inline-block w-auto ms-3"><option value="">-- ทุกหมวดหมู่ --</option></select>')
                        .appendTo('#availableItemsTable_filter')
                        .on('change', function () {
                            var val = $.fn.dataTable.util.escapeRegex($(this).val());
                            // ค้นหาแบบ Exact Match (ตรงตัวเป๊ะ) เพื่อป้องกันหมวดหมู่ชื่อคล้ายกัน
                            column.search(val ? '^' + val + '$' : '', true, false).draw();
                        });
 
                    // ดึงข้อมูลหมวดหมู่ทั้งหมดในตารางมาสร้างเป็น Option แบบไม่ซ้ำ
                    column.data().unique().sort().each(function (d, j) {
                        var text = $('<div>').html(d).text().trim();
                        if (text !== "") {
                            select.append('<option value="' + text + '">' + text + '</option>');
                        }
                    });
                });
            }
        });

        $('#borrowedItemsTable').DataTable({
            "responsive": true,
            "language": {
                "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/th.json"
            },
            "pageLength": 5,
            "ordering": true,
            "info": true,
            "searching": false
        });
    });
</script>
</body>
</html>
