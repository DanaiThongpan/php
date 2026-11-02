<?php
/**
 * borrow_request.php - หน้าสแกน QR Code เพื่อทำรายการยืม-คืนครุภัณฑ์ (Asset Management Flow)
 * รองรับการรับค่า ?asset_id=XXX หรือ ?da_id=XXX
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// กำหนด Timezone ประเทศไทยสำหรับคำนวณและตรวจสอบวันที่อย่างแม่นยำ
date_default_timezone_set('Asia/Bangkok');

// -----------------------------------------------------------------------------
// 1. รับค่าพารามิเตอร์หมายเลขครุภัณฑ์ (Asset Number หรือ DA_ID)
// -----------------------------------------------------------------------------
$asset_id = trim($_GET['asset_id'] ?? $_GET['da_id'] ?? $_POST['asset_id'] ?? '');

// -----------------------------------------------------------------------------
// 2. Seamless Login & Redirect Flow (ดักจับคนยังไม่ล็อกอิน - Part 1)
// -----------------------------------------------------------------------------
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1) {
    // บันทึก URL ปัจจุบันที่ผู้ใช้เรียกเข้ามา (รวมถึง Query String เช่น /borrow_request.php?asset_id=12345)
    $currentUri = $_SERVER['REQUEST_URI'] ?? ('borrow_request.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : (!empty($asset_id) ? '?asset_id=' . urlencode($asset_id) : '')));
    
    $_SESSION['redirect_url'] = $currentUri;
    
    // ถ้ายังไม่ได้ล็อกอิน: Redirect ไปที่หน้า login.php พร้อมแนบ URL ปัจจุบันไปด้วย
    header("Location: login.php?redirect=" . urlencode($currentUri));
    exit();
}

// โหลดการเชื่อมต่อฐานข้อมูล PDO และ BorrowService
require_once __DIR__ . '/control/connectPDO_dev.php';
require_once __DIR__ . '/includes/BorrowService.php';

// ดึงข้อมูลผู้ใช้งานที่ล็อกอินอยู่
$currentUID  = $_SESSION['SeUser'] ?? '';
$currentUser = null;
if (!empty($currentUID)) {
    try {
        $stmtU = $pdo->prepare("SELECT * FROM useraccount WHERE useraccountID = :uid LIMIT 1");
        $stmtU->execute([':uid' => $currentUID]);
        $currentUser = $stmtU->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $currentUser = null;
    }
}

$userName   = $currentUser['useraccountName'] ?? ($_SESSION['Sename'] ?? 'ผู้ใช้งาน');
$userRank   = $currentUser['userRank'] ?? '';
$userDept   = $currentUser['userDepartment'] ?? ($_SESSION['userDepartment'] ?? '');
$userEmail  = $currentUser['userEmail'] ?? '';
$userType   = $_SESSION['useraccountType'] ?? '';

$msgSuccess = '';
$msgError   = '';
$item       = null;
$activeBorrow       = null;
$currentAssetBorrow = null;
$isAssetOwner       = false;

// ตรวจสอบสถานะ Super Admin
$isSuperAdmin = (
    $userType === 'UT00000001' || 
    $userType === 'Super Admin' ||
    in_array(strtolower($currentUID), ['admin', 'admin_test']) ||
    strtolower($_SESSION['username'] ?? '') === 'admin' ||
    strtolower($userName) === 'administrator' ||
    empty($userDept) ||
    $userDept === 'ส่วนกลาง'
);

// ดึง Session Flash Message หากมี
if (!empty($_SESSION['flash_msg'])) {
    if (($_SESSION['flash_type'] ?? '') === 'error') {
        $msgError = $_SESSION['flash_msg'];
    } else {
        $msgSuccess = $_SESSION['flash_msg'];
    }
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// -----------------------------------------------------------------------------
// 3. ดึงข้อมูลครุภัณฑ์จากฐานข้อมูลผ่าน BorrowService
// -----------------------------------------------------------------------------
if (!empty($asset_id)) {
    try {
        $item = BorrowService::getAsset($pdo, $asset_id);
        if ($item) {
            // ตรวจสอบสิทธิ์ความเป็นเจ้าของครุภัณฑ์ หรือแอดมิน
            $isAssetOwner = (
                $isSuperAdmin ||
                (!empty($item['owner_useraccountID']) && $item['owner_useraccountID'] === $currentUID) ||
                (!empty($item['created_by']) && $item['created_by'] === $currentUID) ||
                (!empty($item['owner_department']) && $item['owner_department'] === $userDept) ||
                ($item['owner_department'] === 'ส่วนกลาง') ||
                empty($item['owner_department'])
            );

            // ค้นหารายการยืมปัจจุบันของครุภัณฑ์นี้ (ไม่ว่าจะยืมโดยใคร)
            $currentAssetBorrow = BorrowService::getActiveBorrow($pdo, $item['DA_ID'], null);
            if ($currentAssetBorrow && $currentAssetBorrow['useraccountID_borrow'] === $currentUID) {
                $activeBorrow = $currentAssetBorrow;
            }
        }
    } catch (PDOException $e) {
        $msgError = "เกิดข้อผิดพลาดในการค้นหาข้อมูล: " . $e->getMessage();
    }
}

// -----------------------------------------------------------------------------
// 4. ประมวลผลการทำรายการ (POST Actions)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $item) {
    $action = $_POST['action'] ?? '';

    // ==========================================
    // ACTION 1: ยืนยันการขอยืมครุภัณฑ์ (Borrow)
    // ==========================================
    if ($action === 'borrow') {
        if (!BorrowService::isAssetAvailable($item)) {
            $msgError = "ขออภัย ครุภัณฑ์นี้ไม่พร้อมให้ยืมในขณะนี้ (สถานะ: " . htmlspecialchars($item['statusName'] ?? 'ไม่พร้อมใช้งาน') . ")";
        } else {
            $dateBorrow     = trim($_POST['date_borrow'] ?? '');
            $deadlineBorrow = trim($_POST['deadline_borrow'] ?? '');
            $nameBorrow     = trim($_POST['name_borrow'] ?? $userName);
            $rankBorrow     = trim($_POST['rank_borrow'] ?? $userRank);
            $belongtoBorrow = trim($_POST['belongto_borrow'] ?? $userDept);
            $detailBorrow   = trim($_POST['detail_borrow'] ?? 'ใช้งานทั่วไป');
            $emailBorrow    = trim($_POST['userEmail'] ?? $userEmail);

            // เก็บค่าที่ผู้ใช้กรอกไว้ เพื่อแสดงคืนในฟอร์มกรณีเกิด Validation Error
            $postDateBorrow     = $dateBorrow;
            $postDeadlineBorrow = $deadlineBorrow;
            $postDetailBorrow   = $detailBorrow;

            $borrowResult = BorrowService::executeBorrow($pdo, [
                'da_id'           => $item['DA_ID'],
                'date_borrow'     => $dateBorrow,
                'deadline_borrow' => $deadlineBorrow,
                'name_borrow'     => $nameBorrow,
                'rank_borrow'     => $rankBorrow,
                'belongto_borrow' => $belongtoBorrow,
                'detail_borrow'   => $detailBorrow,
                'uid'             => $currentUID,
                'email'           => $emailBorrow
            ]);

            if ($borrowResult['success']) {
                $_SESSION['flash_msg'] = 'ยืมครุภัณฑ์สำเร็จเรียบร้อยแล้ว!';
                $_SESSION['flash_type'] = 'success';
                header("Location: user/main.php#borrowed-section");
                exit();
            } else {
                $msgError = $borrowResult['message'];
            }
        }

    // ==========================================
    // ACTION 2: แจ้งส่งคืนครุภัณฑ์ (Request Return by Borrower)
    // ==========================================
    } elseif ($action === 'return' && ($activeBorrow || $currentAssetBorrow)) {
        $targetBorrowId = $activeBorrow['IDborrow_back'] ?? ($currentAssetBorrow['IDborrow_back'] ?? '');
        $detailBack     = trim($_POST['detail_back'] ?? 'ส่งคืนตามกำหนด');

        $returnResult = BorrowService::requestReturn($pdo, [
            'borrow_id'        => $targetBorrowId,
            'returned_by_name' => $userName,
            'returned_by_rank' => $userRank,
            'returned_by_uid'  => $currentUID,
            'detail_back'      => $detailBack
        ]);

        if ($returnResult['success']) {
            $_SESSION['flash_msg'] = 'แจ้งส่งคืนครุภัณฑ์เรียบร้อยแล้ว! กรุณารอเจ้าของครุภัณฑ์หรือผู้ดูแลระบบตรวจสอบและกดยืนยันการรับคืน';
            $_SESSION['flash_type'] = 'info';
            header("Location: user/main.php#borrowed-section");
            exit();
        } else {
            $msgError = $returnResult['message'];
        }

    // ==========================================
    // ACTION 3: อนุมัติการรับคืนครุภัณฑ์ (Approve Return by Owner or Admin)
    // ==========================================
    } elseif ($action === 'approve_return' && $currentAssetBorrow) {
        if (!$isAssetOwner) {
            $msgError = 'คุณไม่มีสิทธิ์อนุมัติการรับคืนครุภัณฑ์นี้ (เฉพาะเจ้าของครุภัณฑ์หรือผู้ดูแลระบบ)';
        } else {
            $targetBorrowId = $currentAssetBorrow['IDborrow_back'];
            $approveResult = BorrowService::approveReturn($pdo, [
                'borrow_id'        => $targetBorrowId,
                'approved_by_uid'  => $currentUID,
                'approved_by_name' => $userName,
                'approved_by_dept' => $userDept,
                'is_super_admin'   => $isSuperAdmin,
                'force'            => true
            ]);

            if ($approveResult['success']) {
                $_SESSION['flash_msg'] = 'อนุมัติการรับคืนครุภัณฑ์เรียบร้อยแล้ว! ครุภัณฑ์กลับสู่สถานะพร้อมใช้งาน';
                $_SESSION['flash_type'] = 'success';
                header("Location: borrow_request.php?asset_id=" . urlencode($asset_id));
                exit();
            } else {
                $msgError = $approveResult['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบสแกนขอยืมครุภัณฑ์ (QR Code Scan & Borrow)</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- HTML5-QRCode Library CDN สำหรับเปิดกล้องสแกน QR Code บนเว็บ -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <!-- SweetAlert2 CDN สำหรับแจ้งเตือน UI สวยงาม -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
        }
        .main-card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            overflow: visible;
        }
        .header-gradient {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            color: white;
            padding: 24px 20px;
            border-top-left-radius: 18px;
            border-top-right-radius: 18px;
        }
        .asset-badge {
            font-size: 0.95rem;
            padding: 6px 14px;
            border-radius: 50px;
        }
        .info-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 0.88rem;
        }
        .info-value {
            font-size: 1rem;
            color: #212529;
            font-weight: 500;
        }
        .btn-action {
            border-radius: 12px;
            padding: 14px 20px;
            font-size: 1.15rem;
            font-weight: 600;
            transition: all 0.2s ease-in-out;
        }
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.15);
        }
        .asset-img-container {
            max-height: 220px;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border-radius: 12px;
            border: 1px dashed #dee2e6;
        }
        .asset-img-container img {
            max-height: 200px;
            object-fit: contain;
        }

        /* =====================================================================
         * Live Search & Autocomplete Dropdown Styles (Google Search Style)
         * ===================================================================== */
        .search-autocomplete-wrapper {
            position: relative;
            width: 100%;
        }
        .search-input-group {
            border-radius: 14px;
            overflow: hidden;
            border: 2px solid #cbd5e1;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            background: #ffffff;
        }
        .search-input-group:focus-within {
            border-color: #0d6efd;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.15) !important;
        }
        .search-input-group input:focus {
            box-shadow: none !important;
        }
        .clear-search-btn {
            border: none;
            background: transparent;
            cursor: pointer;
            padding: 0 12px;
            transition: color 0.15s ease;
        }
        .clear-search-btn:hover {
            color: #dc3545 !important;
        }
        .live-search-dropdown {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            background: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 16px;
            box-shadow: 0 18px 38px rgba(0, 0, 0, 0.14), 0 4px 12px rgba(0, 0, 0, 0.06);
            max-height: 380px;
            overflow-y: auto;
            z-index: 1060;
            padding: 8px;
            animation: dropdownFadeIn 0.18s ease-out;
            text-align: left;
        }
        @keyframes dropdownFadeIn {
            from {
                opacity: 0;
                transform: translateY(-6px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .live-search-item {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.18s ease;
            text-decoration: none;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
        }
        .live-search-item:last-child {
            border-bottom: none;
        }
        .live-search-item:hover,
        .live-search-item.active {
            background: #eff6ff;
            color: #0d6efd;
            transform: translateX(3px);
        }
        .live-search-item-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: #f1f5f9;
            color: #3b82f6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            margin-right: 12px;
            flex-shrink: 0;
            transition: all 0.2s ease;
            overflow: hidden;
        }
        .live-search-item:hover .live-search-item-icon,
        .live-search-item.active .live-search-item-icon {
            background: #0d6efd;
            color: #ffffff;
        }
        .live-search-item-content {
            flex-grow: 1;
            min-width: 0;
        }
        .live-search-item-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: #0f172a;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .live-search-item:hover .live-search-item-title {
            color: #0d6efd;
        }
        .live-search-item-sub {
            font-size: 0.82rem;
            color: #64748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .live-search-badge-number {
            font-family: 'Courier New', Courier, monospace;
            font-weight: 700;
            font-size: 0.8rem;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #334155;
            padding: 2px 7px;
            border-radius: 6px;
            display: inline-block;
            margin-right: 6px;
        }
        .live-search-empty {
            padding: 30px 16px;
            text-align: center;
            color: #64748b;
        }
        .live-search-empty i {
            font-size: 2.2rem;
            color: #94a3b8;
            margin-bottom: 8px;
            display: block;
        }
        .highlight-match {
            background-color: #fef08a;
            color: #854d0e;
            padding: 0 2px;
            border-radius: 3px;
            font-weight: 700;
        }

        /* =====================================================================
         * In-App QR Code Scanner Styles (Modal & Camera Viewport)
         * ===================================================================== */
        .qr-scanner-box {
            width: 100%;
            min-height: 290px;
            background: #0f172a;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            overflow: hidden;
        }
        #qr-reader {
            width: 100% !important;
            border: none !important;
        }
        #qr-reader video {
            width: 100% !important;
            height: auto !important;
            border-radius: 12px;
            object-fit: cover;
        }
        #qr-reader__scan_region {
            background: transparent !important;
        }
        .qr-scanner-laser {
            position: absolute;
            left: 8%;
            right: 8%;
            height: 3px;
            background: linear-gradient(90deg, transparent, #22c55e, #86efac, #22c55e, transparent);
            box-shadow: 0 0 10px #22c55e, 0 0 18px #4ade80;
            z-index: 20;
            pointer-events: none;
            animation: qrLaserScan 2s ease-in-out infinite alternate;
        }
        @keyframes qrLaserScan {
            0% { top: 12%; opacity: 0.3; }
            50% { opacity: 1; }
            100% { top: 88%; opacity: 0.3; }
        }
        .btn-scan-qr-prominent {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-scan-qr-prominent:hover {
            background: linear-gradient(135deg, #059669, #047857);
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(16, 185, 129, 0.35) !important;
        }
    </style>
</head>
<body class="py-4">

<div class="container">
    <div class="row justify-content-center">
        <div class="col-12 col-md-9 col-lg-7">

            <!-- Navbar เล็ก / Breadcrumb ด้านบน -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="d-flex align-items-center gap-2">
                    <a href="<?php echo ($userType === 'UT00000001') ? 'admin/main.php' : 'user/main.php'; ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                        <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าหลัก
                    </a>
                    <?php if (!empty($asset_id)): ?>
                        <a href="borrow_request.php" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm">
                            <i class="fa-solid fa-magnifying-glass me-1"></i> ค้นหาครุภัณฑ์อื่น
                        </a>
                        <button type="button" class="btn btn-outline-success btn-sm rounded-pill px-3 shadow-sm btn-open-qr-scanner" title="เปิดกล้องสแกน QR Code">
                            <i class="fa-solid fa-camera me-1"></i> สแกน QR Code
                        </button>
                    <?php endif; ?>
                </div>
                <div class="text-end small text-muted">
                    <i class="fa-solid fa-user-circle text-primary me-1"></i>
                    <strong><?php echo htmlspecialchars($userName); ?></strong>
                    <?php if (!empty($userDept)): ?>
                        (<?php echo htmlspecialchars($userDept); ?>)
                    <?php endif; ?>
                </div>
            </div>

            <!-- แจ้งเตือนสถานะการทำรายการ -->
            <?php if (!empty($msgSuccess)): ?>
                <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex align-items-center mb-3" role="alert">
                    <i class="fa-solid fa-circle-check fs-3 me-3 text-success"></i>
                    <div>
                        <h6 class="mb-0 fw-bold">ทำรายการสำเร็จ</h6>
                        <div><?php echo htmlspecialchars($msgSuccess); ?></div>
                    </div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($msgError)): ?>
                <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex align-items-center mb-3" role="alert">
                    <i class="fa-solid fa-circle-exclamation fs-3 me-3 text-danger"></i>
                    <div>
                        <h6 class="mb-0 fw-bold">เกิดข้อผิดพลาด</h6>
                        <div><?php echo htmlspecialchars($msgError); ?></div>
                    </div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- การ์ดหลัก -->
            <div class="card main-card">
                <div class="header-gradient text-center">
                    <div class="d-inline-flex align-items-center justify-content-center bg-white text-primary rounded-circle shadow-sm mb-2" style="width: 52px; height: 52px;">
                        <i class="fa-solid fa-qrcode fs-3"></i>
                    </div>
                    <h4 class="fw-bold mb-1">ระบบยืม-คืนครุภัณฑ์ (สแกน QR Code)</h4>
                    <p class="mb-0 opacity-75 small">ตรวจสอบรายละเอียดครุภัณฑ์และยืนยันการทำรายการ</p>
                </div>

                <div class="card-body p-4">

                    <?php if (empty($asset_id)): ?>
                        <!-- ฟอร์มค้นหาหากไม่ได้แนบ asset_id มาใน URL พร้อมระบบ Live Search & Autocomplete -->
                        <div class="text-center py-4">
                            <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-circle mb-3" style="width: 68px; height: 68px;">
                                <i class="fa-solid fa-barcode fa-2x"></i>
                            </div>
                            <h5 class="fw-bold text-dark">ค้นหาหรือสแกนหมายเลขครุภัณฑ์</h5>
                            <p class="text-muted small mb-4">สแกน QR Code จากตัวครุภัณฑ์ หรือพิมพ์ค้นหาหมายเลข/ชื่อครุภัณฑ์แบบเรียลไทม์:</p>
                            
                            <form method="GET" action="" id="searchAssetForm" class="mt-2 text-start">
                                <div class="search-autocomplete-wrapper mb-2">
                                    <div class="input-group input-group-lg search-input-group shadow-sm">
                                        <span class="input-group-text bg-white border-end-0 ps-3">
                                            <i class="fa-solid fa-magnifying-glass text-primary" id="searchIcon"></i>
                                            <span class="spinner-border spinner-border-sm text-primary d-none" id="searchSpinner" role="status"></span>
                                        </span>
                                        <input type="text" 
                                               name="asset_id" 
                                               id="liveSearchInput" 
                                               class="form-control border-start-0 border-end-0 ps-2" 
                                               placeholder="พิมพ์เลขครุภัณฑ์ หรือชื่อครุภัณฑ์ (เช่น เก้าอี้, 66...)" 
                                               autocomplete="off" 
                                               spellcheck="false" 
                                               required 
                                               autofocus>
                                        <button class="btn btn-light border-start-0 border-end-0 clear-search-btn text-muted d-none" 
                                                type="button" 
                                                id="clearSearchBtn" 
                                                title="ล้างข้อความ">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                        <button class="btn btn-outline-success border-start-0 border-end-0 px-3 btn-open-qr-scanner" 
                                                type="button" 
                                                title="เปิดกล้องสแกน QR Code">
                                            <i class="fa-solid fa-qrcode fs-5"></i>
                                        </button>
                                        <button class="btn btn-primary px-4 fw-bold shadow-sm" type="submit" id="btnSubmitSearch">
                                            <i class="fa-solid fa-search me-1"></i> ค้นหา
                                        </button>
                                    </div>

                                    <!-- Dropdown แสดงผลการค้นหาแบบ Live Autocomplete (Google Search Style) -->
                                    <div id="liveSearchDropdown" class="live-search-dropdown d-none" role="listbox">
                                        <div id="liveSearchResults" class="live-search-results"></div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center justify-content-between text-muted small px-1 mt-2">
                                    <span>
                                        <i class="fa-solid fa-bolt text-warning me-1"></i> ค้นหาแบบเรียลไทม์ (Live Search)
                                    </span>
                                    <span>
                                        <i class="fa-solid fa-keyboard text-secondary me-1"></i> ใช้ &uarr; &darr; และ Enter เพื่อเลือก
                                    </span>
                                </div>

                                <!-- ปุ่มเปิดกล้องสแกน QR Code โดดเด่น ชัดเจน (Part 2) -->
                                <div class="d-grid gap-2 mt-4 pt-3 border-top">
                                    <button type="button" class="btn btn-scan-qr-prominent btn-lg rounded-pill fw-bold py-3 shadow-sm d-flex align-items-center justify-content-center gap-2 btn-open-qr-scanner">
                                        <i class="fa-solid fa-camera fs-4"></i>
                                        <span>📷 สแกน QR Code ด้วยกล้อง</span>
                                    </button>
                                    <div class="text-center text-muted small">
                                        กดเพื่อเปิดกล้องมือถือหรือเว็บแคม สแกนสติกเกอร์ QR Code บนตัวครุภัณฑ์ได้ทันที
                                    </div>
                                </div>
                            </form>
                        </div>

                    <?php elseif (!$item): ?>
                        <!-- กรณีหาข้อมูลครุภัณฑ์ไม่พบ -->
                        <div class="text-center py-4">
                            <i class="fa-solid fa-circle-question text-warning fa-3x mb-3"></i>
                            <h5 class="fw-bold text-danger">ไม่พบข้อมูลครุภัณฑ์ในระบบ</h5>
                            <p class="text-muted">หมายเลขที่ระบุ: <code><?php echo htmlspecialchars($asset_id); ?></code></p>
                            <p class="small text-secondary mb-3">โปรดตรวจสอบว่าสแกน QR Code ถูกต้อง หรือพิมพ์ค้นหาครุภัณฑ์ชิ้นอื่นจากกล่องด้านล่าง:</p>
                            
                            <!-- ช่องค้นหาพร้อม Live Search สำหรับค้นหาใหม่ได้ทันที -->
                            <form method="GET" action="" id="searchAssetForm" class="mt-2 text-start">
                                <div class="search-autocomplete-wrapper mb-2">
                                    <div class="input-group input-group-lg search-input-group shadow-sm">
                                        <span class="input-group-text bg-white border-end-0 ps-3">
                                            <i class="fa-solid fa-magnifying-glass text-primary" id="searchIcon"></i>
                                            <span class="spinner-border spinner-border-sm text-primary d-none" id="searchSpinner" role="status"></span>
                                        </span>
                                        <input type="text" 
                                               name="asset_id" 
                                               id="liveSearchInput" 
                                               class="form-control border-start-0 border-end-0 ps-2" 
                                               placeholder="พิมพ์เลขครุภัณฑ์ หรือชื่อครุภัณฑ์ใหม่..." 
                                               autocomplete="off" 
                                               spellcheck="false" 
                                               required 
                                               autofocus>
                                        <button class="btn btn-light border-start-0 border-end-0 clear-search-btn text-muted d-none" 
                                                type="button" 
                                                id="clearSearchBtn" 
                                                title="ล้างข้อความ">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                        <button class="btn btn-outline-success border-start-0 border-end-0 px-3 btn-open-qr-scanner" 
                                                type="button" 
                                                title="เปิดกล้องสแกน QR Code">
                                            <i class="fa-solid fa-qrcode fs-5"></i>
                                        </button>
                                        <button class="btn btn-primary px-4 fw-bold shadow-sm" type="submit" id="btnSubmitSearch">
                                            <i class="fa-solid fa-search me-1"></i> ค้นหา
                                        </button>
                                    </div>
                                    <div id="liveSearchDropdown" class="live-search-dropdown d-none" role="listbox">
                                        <div id="liveSearchResults" class="live-search-results"></div>
                                    </div>
                                </div>
                                <div class="d-grid gap-2 mt-3 pt-2 border-top">
                                    <button type="button" class="btn btn-scan-qr-prominent rounded-pill fw-bold py-2 shadow-sm d-flex align-items-center justify-content-center gap-2 btn-open-qr-scanner">
                                        <i class="fa-solid fa-camera me-1"></i>
                                        <span>📷 สแกน QR Code ใหม่อีกครั้ง</span>
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php else: ?>
                        <!-- แสดงรายละเอียดครุภัณฑ์ -->
                        <div class="text-center mb-4">
                            <!-- รูปภาพครุภัณฑ์ (ถ้ามี) -->
                            <?php 
                            $imgSrc = '';
                            if (!empty($item['DA_URLImg']) && file_exists(__DIR__ . '/' . $item['DA_URLImg'])) {
                                $imgSrc = $item['DA_URLImg'];
                            } elseif (!empty($item['DA_URLImg']) && file_exists(__DIR__ . '/upload_imgDA/' . $item['DA_URLImg'])) {
                                $imgSrc = 'upload_imgDA/' . $item['DA_URLImg'];
                            }
                            ?>
                            <?php if (!empty($imgSrc)): ?>
                                <div class="asset-img-container mb-3 p-2">
                                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="Asset Image" class="img-fluid rounded">
                                </div>
                            <?php endif; ?>

                            <h5 class="fw-bold text-primary mb-1"><?php echo htmlspecialchars($item['DA_ListName']); ?></h5>
                            <div class="font-monospace text-muted mb-2">
                                <i class="fa-solid fa-tag me-1"></i> หมายเลขครุภัณฑ์: <strong><?php echo htmlspecialchars($item['DA_Number']); ?></strong>
                            </div>

                            <!-- ป้ายสถานะปัจจุบัน -->
                            <div>
                                <?php if ($item['statusID'] === 's0001' && empty($currentAssetBorrow)): ?>
                                    <span class="badge bg-success asset-badge shadow-sm">
                                        <i class="fa-solid fa-circle-check me-1"></i> พร้อมใช้งาน (Available)
                                    </span>
                                <?php elseif ($currentAssetBorrow && ($currentAssetBorrow['status'] ?? '') === 'pending_return'): ?>
                                    <span class="badge bg-warning text-dark asset-badge shadow-sm">
                                        <i class="fa-solid fa-hourglass-half me-1"></i> รอเจ้าของอนุมัติรับคืน (Pending Approval)
                                    </span>
                                <?php elseif (!empty($activeBorrow)): ?>
                                    <span class="badge bg-warning text-dark asset-badge shadow-sm">
                                        <i class="fa-solid fa-clock-rotate-left me-1"></i> คุณกำลังยืมชิ้นนี้อยู่ (Borrowed by You)
                                    </span>
                                <?php elseif ($item['statusID'] === 's0003'): ?>
                                    <span class="badge bg-secondary asset-badge shadow-sm">
                                        <i class="fa-solid fa-lock me-1"></i> ถูกผู้อื่นยืมไปแล้ว (Currently Borrowed)
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger asset-badge shadow-sm">
                                        <i class="fa-solid fa-ban me-1"></i> <?php echo htmlspecialchars($item['statusName'] ?? 'ไม่พร้อมใช้งาน'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ตารางรายละเอียดครุภัณฑ์ -->
                        <div class="bg-light rounded-4 p-3 mb-4">
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="info-label">หมวดหมู่</div>
                                    <div class="info-value"><?php echo htmlspecialchars($item['classificationName'] ?? '-'); ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="info-label">ยี่ห้อ / รุ่น</div>
                                    <div class="info-value"><?php echo htmlspecialchars($item['DA_Brand'] ?: '-'); ?></div>
                                </div>
                                <div class="col-6 mt-2">
                                    <div class="info-label">สถานที่จัดเก็บ</div>
                                    <div class="info-value">
                                        <?php echo htmlspecialchars($item['buildingName'] ?? '-'); ?>
                                        <?php if (!empty($item['classroomName'])): ?>
                                            (<?php echo htmlspecialchars($item['classroomName']); ?>)
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-6 mt-2">
                                    <div class="info-label">ปีที่ได้มา / ราคา</div>
                                    <div class="info-value">
                                        <?php echo htmlspecialchars($item['DA_Year'] ?: '-'); ?>
                                        <?php if (!empty($item['DA_Price'])): ?>
                                            / ฿<?php echo number_format($item['DA_Price'], 2); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ฟอร์มการยืนยันการยืม หรือ คืน -->
                        <form method="POST" action="" class="needs-validation" id="borrowActionForm" novalidate>
                            <input type="hidden" name="asset_id" value="<?php echo htmlspecialchars($asset_id); ?>">

                            <?php if ($item['statusID'] === 's0001' && empty($currentAssetBorrow)): ?>
                                <!-- โหมด: พร้อมใช้งาน -> แบบฟอร์มทำเรื่องยืม -->
                                <input type="hidden" name="action" value="borrow">
                                
                                <div class="border rounded-4 p-3 mb-4 bg-white">
                                    <h6 class="fw-bold text-primary mb-3">
                                        <i class="fa-solid fa-file-signature me-1"></i> ข้อมูลผู้ขอยืมและเงื่อนไข
                                    </h6>
                                    
                                    <div class="row g-3">
                                        <div class="col-sm-6">
                                            <label class="form-label small fw-bold">ชื่อผู้ขอยืม</label>
                                            <input type="text" name="name_borrow" class="form-control bg-light" value="<?php echo htmlspecialchars($userName); ?>" required readonly>
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label small fw-bold">หน่วยงาน / สังกัด</label>
                                            <input type="text" name="belongto_borrow" class="form-control" value="<?php echo htmlspecialchars($userDept); ?>" placeholder="เช่น ฝ่ายบริหาร, แผนกสารสนเทศ">
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label small fw-bold">ตำแหน่ง</label>
                                            <input type="text" name="rank_borrow" class="form-control" value="<?php echo htmlspecialchars($userRank); ?>" placeholder="เช่น เจ้าหน้าที่, นักศึกษา">
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label small fw-bold">อีเมลสำหรับติดต่อ/แจ้งเตือน</label>
                                            <input type="email" name="userEmail" class="form-control" value="<?php echo htmlspecialchars($userEmail); ?>" placeholder="example@org.ac.th">
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label small fw-bold">วันที่เริ่มยืม <span class="text-danger">*</span></label>
                                            <input type="date" 
                                                   name="date_borrow" 
                                                   id="date_borrow" 
                                                   class="form-control" 
                                                   value="<?php echo htmlspecialchars($postDateBorrow ?? date('Y-m-d')); ?>" 
                                                   min="<?php echo date('Y-m-d'); ?>" 
                                                   max="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" 
                                                   required>
                                            <div class="form-text small text-muted">
                                                <i class="fa-solid fa-circle-info me-1"></i>เลือกได้ตั้งแต่วันนี้ถึงล่วงหน้าไม่เกิน 30 วัน
                                            </div>
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label small fw-bold text-danger">กำหนดส่งคืน (Deadline) <span class="text-danger">*</span></label>
                                            <input type="date" 
                                                   name="deadline_borrow" 
                                                   id="deadline_borrow" 
                                                   class="form-control" 
                                                   value="<?php echo htmlspecialchars($postDeadlineBorrow ?? date('Y-m-d', strtotime('+7 days'))); ?>" 
                                                   min="<?php echo htmlspecialchars($postDateBorrow ?? date('Y-m-d')); ?>" 
                                                   max="<?php echo date('Y-m-d', strtotime('+365 days')); ?>" 
                                                   required>
                                            <div class="form-text small text-muted">
                                                <i class="fa-solid fa-clock me-1"></i>ไม่ก่อนวันยืม และไม่เกิน 1 ปี (365 วัน)
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small fw-bold">วัตถุประสงค์ในการยืม</label>
                                            <textarea name="detail_borrow" class="form-control" rows="2" placeholder="ระบุเหตุผลในการยืม เช่น ใช้ในการเรียนการสอน, จัดอบรมสัมมนา"><?php echo htmlspecialchars($postDetailBorrow ?? 'ใช้ในการปฏิบัติงาน'); ?></textarea>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary btn-action w-100 shadow">
                                    <i class="fa-solid fa-hand-holding-hand me-2"></i> ยืนยันการขอยืมครุภัณฑ์
                                </button>
                                <p class="text-center text-muted small mt-2 mb-0">ระบบจะบันทึกประวัติการยืมเข้าสู่บัญชีของคุณอัตโนมัติ</p>

                            <?php elseif ($currentAssetBorrow && ($currentAssetBorrow['status'] ?? '') === 'pending_return'): ?>
                                <!-- โหมด: มีการแจ้งส่งคืนแล้ว และกำลังรออนุมัติรับคืน -->
                                <?php if ($isAssetOwner): ?>
                                    <!-- สำหรับ เจ้าของครุภัณฑ์ หรือ แอดมิน: แสดงปุ่มอนุมัติรับคืน -->
                                    <input type="hidden" name="action" value="approve_return">
                                    <div class="alert alert-warning border border-warning rounded-4 p-3 mb-3 shadow-sm">
                                        <div class="d-flex align-items-center mb-2">
                                            <div class="p-2 bg-warning-subtle text-warning-emphasis rounded-circle me-3">
                                                <i class="fa-solid fa-bell fa-xl"></i>
                                            </div>
                                            <div>
                                                <h6 class="fw-bold text-dark mb-0">ผู้ยืมได้แจ้งส่งคืนครุภัณฑ์นี้แล้ว</h6>
                                                <small class="text-muted">รอคุณในฐานะเจ้าของ/ผู้ดูแล ตรวจสอบสภาพและกดยืนยันการรับคืน</small>
                                            </div>
                                        </div>
                                        <div class="bg-white p-3 rounded-3 border small mb-3">
                                            <div><i class="fa-solid fa-user me-2 text-secondary"></i>ผู้ยืม: <strong><?php echo htmlspecialchars($currentAssetBorrow['Name_borrow'] ?? 'ผู้ใช้งาน'); ?></strong> (<?php echo htmlspecialchars($currentAssetBorrow['belongto_borrow'] ?? '-'); ?>)</div>
                                            <div><i class="fa-solid fa-calendar me-2 text-secondary"></i>วันที่เริ่มยืม: <strong><?php echo htmlspecialchars($currentAssetBorrow['Date_borrow'] ?? '-'); ?></strong> | กำหนดส่งคืน: <strong><?php echo htmlspecialchars($currentAssetBorrow['Deadline_borrow'] ?? '-'); ?></strong></div>
                                            <div><i class="fa-solid fa-calendar-check me-2 text-secondary"></i>วันที่แจ้งส่งคืน: <strong><?php echo htmlspecialchars($currentAssetBorrow['Date_back'] ?? date('Y-m-d')); ?></strong></div>
                                        </div>
                                        <button type="submit" class="btn btn-success btn-action text-white fw-bold w-100 shadow">
                                            <i class="fa-solid fa-check-circle me-2"></i> อนุมัติการรับคืนครุภัณฑ์ (ยืนยันรับของแล้ว)
                                        </button>
                                        <p class="text-center text-muted small mt-2 mb-0">เมื่อกดยืนยัน ครุภัณฑ์จะกลับสู่สถานะพร้อมใช้งานทันที</p>
                                    </div>
                                <?php elseif (!empty($activeBorrow)): ?>
                                    <!-- สำหรับ ผู้ยืมเอง: แสดงข้อความว่าแจ้งคืนแล้ว -->
                                    <div class="alert alert-warning border-0 rounded-4 p-3 mb-3 shadow-sm">
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="fa-solid fa-hourglass-half fs-4 text-warning me-2"></i>
                                            <span class="fw-bold">คุณได้ทำการแจ้งส่งคืนครุภัณฑ์นี้แล้ว</span>
                                        </div>
                                        <p class="small text-muted mb-3">ระบบได้บันทึกการส่งคืนของคุณเมื่อวันที่ <strong><?php echo htmlspecialchars($currentAssetBorrow['Date_back'] ?? date('Y-m-d')); ?></strong> เรียบร้อยแล้ว ขณะนี้อยู่ระหว่างรอเจ้าของครุภัณฑ์หรือผู้ดูแลระบบตรวจสอบสภาพและกดยืนยันการรับคืน</p>
                                        <a href="user/main.php#borrowed-section" class="btn btn-outline-warning btn-action text-dark fw-bold w-100">
                                            <i class="fa-solid fa-list-check me-2"></i> ไปที่หน้ารายการกำลังยืมของคุณ
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <!-- สำหรับ บุคคลอื่น: แสดงว่ากำลังรอตรวจรับคืน -->
                                    <div class="alert alert-secondary text-center rounded-4 p-3 mb-3">
                                        <i class="fa-solid fa-hourglass-half fs-3 text-secondary mb-2 d-block"></i>
                                        <strong>ครุภัณฑ์นี้อยู่ระหว่างรอเจ้าของอนุมัติการรับคืน</strong>
                                        <p class="mb-0 small text-muted">ผู้ยืมได้แจ้งส่งคืนแล้ว และอยู่ระหว่างรอตรวจรับเข้าคลัง</p>
                                    </div>
                                    <button type="button" class="btn btn-secondary btn-action w-100" disabled>
                                        อยู่ระหว่างรอเจ้าของอนุมัติรับคืน
                                    </button>
                                <?php endif; ?>

                            <?php elseif (!empty($activeBorrow)): ?>
                                <!-- โหมด: ผู้ใช้กำลังยืมชิ้นนี้อยู่ และยังไม่ได้แจ้งส่งคืน -> แสดงปุ่มแจ้งส่งคืน -->
                                <input type="hidden" name="action" value="return">
                                
                                <div class="alert alert-warning border-0 rounded-4 p-3 mb-3">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="fa-solid fa-circle-info fs-4 text-warning me-2"></i>
                                        <span class="fw-bold">คุณเป็นผู้ครอบครองครุภัณฑ์นี้อยู่</span>
                                    </div>
                                    <ul class="mb-0 small ps-3">
                                        <li>วันที่เริ่มยืม: <strong><?php echo htmlspecialchars($activeBorrow['Date_borrow'] ?? '-'); ?></strong></li>
                                        <li>กำหนดส่งคืน: <strong><?php echo htmlspecialchars($activeBorrow['Deadline_borrow'] ?? '-'); ?></strong></li>
                                        <?php if (!empty($activeBorrow['Detail_borrow'])): ?>
                                            <li>วัตถุประสงค์: <?php echo htmlspecialchars($activeBorrow['Detail_borrow']); ?></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold">หมายเหตุ / สภาพครุภัณฑ์</label>
                                    <input type="text" name="detail_back" class="form-control" value="ส่งคืนตามกำหนด" placeholder="ระบุสภาพครุภัณฑ์หรือข้อความถึงผู้รับคืน">
                                </div>

                                <button type="submit" class="btn btn-warning btn-action text-dark fw-bold w-100 shadow">
                                    <i class="fa-solid fa-rotate-left me-2"></i> แจ้งส่งคืนครุภัณฑ์ (ส่งคำขอไปยังเจ้าของ)
                                </button>
                                <p class="text-center text-muted small mt-2 mb-0">เมื่อกดแจ้งส่งคืน เจ้าของครุภัณฑ์หรือผู้ดูแลระบบจะสามารถกดอนุมัติการรับคืนได้</p>

                            <?php elseif ($isAssetOwner && $currentAssetBorrow): ?>
                                <!-- โหมด: เจ้าของ/แอดมิน เข้ามาดูของที่ถูกยืมอยู่ สามารถบันทึกรับคืนได้ -->
                                <input type="hidden" name="action" value="approve_return">
                                
                                <div class="alert alert-info border-0 rounded-4 p-3 mb-3">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="fa-solid fa-user-tag fs-4 text-info me-2"></i>
                                        <span class="fw-bold">ครุภัณฑ์นี้กำลังถูกยืมโดย <?php echo htmlspecialchars($currentAssetBorrow['Name_borrow'] ?? 'ผู้ใช้งาน'); ?></span>
                                    </div>
                                    <p class="small text-muted mb-2">วันที่ยืม: <?php echo htmlspecialchars($currentAssetBorrow['Date_borrow']); ?> | กำหนดคืน: <?php echo htmlspecialchars($currentAssetBorrow['Deadline_borrow']); ?></p>
                                    <small class="text-muted d-block">สังกัด: <?php echo htmlspecialchars($currentAssetBorrow['belongto_borrow'] ?? '-'); ?></small>
                                </div>

                                <button type="submit" class="btn btn-outline-success btn-action w-100 shadow-sm" onclick="return confirm('ยืนยันการรับคืนครุภัณฑ์ชิ้นนี้เข้าสู่ระบบทันทีใช่หรือไม่?')">
                                    <i class="fa-solid fa-box-archive me-2"></i> บันทึกรับคืนครุภัณฑ์ (เจ้าของ/ผู้ดูแล)
                                </button>

                            <?php else: ?>
                                <!-- โหมด: ไม่สามารถทำรายการได้ (ผู้อื่นยืมไป หรือ ชำรุด) -->
                                <div class="alert alert-secondary text-center rounded-4 p-3 mb-3">
                                    <i class="fa-solid fa-ban fs-3 text-secondary mb-2 d-block"></i>
                                    <strong>ขณะนี้ไม่สามารถทำรายการยืมครุภัณฑ์นี้ได้</strong>
                                    <p class="mb-0 small text-muted">เนื่องจากมีผู้อื่นยืมใช้งานอยู่ หรือสถานะครุภัณฑ์อยู่ระหว่างการซ่อมบำรุง</p>
                                </div>
                                <button type="button" class="btn btn-secondary btn-action w-100" disabled>
                                    ไม่สามารถทำรายการได้
                                </button>
                            <?php endif; ?>
                        </form>

                    <?php endif; ?>

                </div>
            </div>

            <!-- Footer ล่าง -->
            <div class="text-center mt-3 text-muted small">
                ระบบบริหารจัดการครุภัณฑ์ &bull; Asset Management QR Code Flow
            </div>

        </div>
    </div>
</div>

<!-- Modal สำหรับเปิดกล้องสแกน QR Code ในแอป (In-App QR Code Scanner - Part 2) -->
<div class="modal fade" id="qrScannerModal" tabindex="-1" aria-labelledby="qrScannerModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg overflow-hidden">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="bg-success bg-opacity-25 text-success rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                        <i class="fa-solid fa-qrcode fs-5"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0" id="qrScannerModalLabel">สแกน QR Code ครุภัณฑ์</h6>
                        <small class="text-white-50">หันกล้องไปที่ QR Code บนสติกเกอร์เพื่อทำรายการ</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" id="btnCloseScannerX"></button>
            </div>
            
            <div class="modal-body p-3 bg-black text-center position-relative">
                <!-- Dropdown เลือกกล้อง (สลับกล้องหน้า/กล้องหลัง บนอุปกรณ์ที่มีหลายกล้อง) -->
                <div id="cameraSelectorContainer" class="mb-2 d-none">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-secondary border-0 text-white"><i class="fa-solid fa-camera-rotate"></i></span>
                        <select id="cameraSelection" class="form-select bg-dark text-white border-0">
                            <option value="">กำลังค้นหากล้อง...</option>
                        </select>
                    </div>
                </div>

                <!-- กรอบพื้นที่แสดงผลกล้อง (HTML5-QRCode Viewport) -->
                <div class="qr-scanner-box position-relative rounded-3 overflow-hidden">
                    <div id="qr-reader" style="width: 100%;"></div>
                    <!-- เส้นสแกนเลเซอร์แบบแอนิเมชัน -->
                    <div class="qr-scanner-laser" id="qrLaserLine"></div>
                </div>

                <!-- สถานะการทำงานของกล้อง -->
                <div id="qrScannerStatus" class="mt-2 text-white-50 small">
                    <span class="spinner-border spinner-border-sm text-primary me-1" role="status"></span> กำลังเปิดกล้อง...
                </div>

                <!-- กล่องข้อความแจ้งเตือน Error -->
                <div id="qrScannerError" class="alert alert-danger d-none text-start small mt-3 mb-1 rounded-3"></div>
            </div>

            <div class="modal-footer bg-light border-0 py-2 d-flex justify-content-between align-items-center">
                <span class="text-muted small">
                    <i class="fa-solid fa-shield-halved text-success me-1"></i> รองรับกล้องมือถือและคอมพิวเตอร์
                </span>
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal" id="btnCancelScanner">
                    <i class="fa-solid fa-xmark me-1"></i> ปิดกล้อง / ยกเลิก
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Live Search & Autocomplete Script (Google Search Style) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('liveSearchInput');
    const dropdown = document.getElementById('liveSearchDropdown');
    const resultsContainer = document.getElementById('liveSearchResults');
    const searchSpinner = document.getElementById('searchSpinner');
    const searchIcon = document.getElementById('searchIcon');
    const clearBtn = document.getElementById('clearSearchBtn');
    const searchForm = document.getElementById('searchAssetForm');

    // =========================================================================
    // SECTION 1: Live Search & Autocomplete Controller (Google Search Style)
    // =========================================================================
    if (searchInput && dropdown && resultsContainer) {

    let activeIndex = -1;
    let currentItems = [];
    let abortController = null;

    /**
     * 1. Debounce Function: หน่วงเวลา 300ms เพื่อป้องกันการยิง Request ถี่เกินไป
     */
    function debounce(func, delay = 300) {
        let timer = null;
        return function(...args) {
            clearTimeout(timer);
            timer = setTimeout(() => func.apply(this, args), delay);
        };
    }

    // Helper Functions: จัดการสถานะแสดงผล (UI State Helpers)
    function setLoading(isLoading) {
        if (searchSpinner && searchIcon) {
            if (isLoading) {
                searchSpinner.classList.remove('d-none');
                searchIcon.classList.add('d-none');
            } else {
                searchSpinner.classList.add('d-none');
                searchIcon.classList.remove('d-none');
            }
        }
    }

    function toggleClearBtn(show) {
        if (clearBtn) {
            if (show) clearBtn.classList.remove('d-none');
            else clearBtn.classList.add('d-none');
        }
    }

    function hideDropdown() {
        dropdown.classList.add('d-none');
        activeIndex = -1;
    }

    function showDropdown() {
        dropdown.classList.remove('d-none');
    }

    function clearResults() {
        resultsContainer.innerHTML = '';
        currentItems = [];
        activeIndex = -1;
    }

    // ป้องกัน XSS Injection ในการแสดงผล
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ทำไฮไลต์คำที่ตรงกับคีย์เวิร์ด (Highlight Keyword)
    function highlightMatch(text, query) {
        if (!text || !query) return escapeHtml(text);
        const safeQuery = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`(${safeQuery})`, 'gi');
        return escapeHtml(text).replace(regex, '<mark class="highlight-match">$1</mark>');
    }

    /**
     * 2. ส่ง Fetch API ไปยัง Backend (api_search_assets.php)
     */
    function performSearch(query) {
        const trimmedQuery = query ? query.trim() : '';

        // ⚠️ CRITICAL REQUIREMENT:
        // หากผู้ใช้ลบข้อความในช่องค้นหาจนว่างเปล่า ต้องเคลียร์ค่าและซ่อนกล่อง Dropdown ทันที โดยไม่ต้องส่ง Request ไปที่ Server
        if (trimmedQuery === '') {
            if (abortController) {
                abortController.abort();
            }
            setLoading(false);
            toggleClearBtn(false);
            clearResults();
            hideDropdown();
            return;
        }

        toggleClearBtn(true);
        setLoading(true);

        // ยกเลิก Request ก่อนหน้าที่ยังทำงานไม่เสร็จ (Prevent Race Condition)
        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();

        const apiUrl = 'api_search_assets.php?keyword=' + encodeURIComponent(trimmedQuery);

        fetch(apiUrl, { signal: abortController.signal })
            .then(res => {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(resData => {
                setLoading(false);
                const items = Array.isArray(resData) ? resData : (resData.data || []);
                currentItems = items;
                renderResults(items, trimmedQuery);
            })
            .catch(err => {
                if (err.name === 'AbortError') return; // คำขอนี้ถูกยกเลิก ไม่ต้องทำอะไร
                setLoading(false);
                renderError();
            });
    }

    /**
     * 3. แสดงผลรายการแนะนำ (Render Suggestions) ในกล่อง Dropdown
     */
    function renderResults(items, query) {
        clearResults();

        if (items.length === 0) {
            // Empty State ตาม Requirement: แสดงข้อความอักษรสีเทา "ไม่พบข้อมูลครุภัณฑ์ที่ค้นหา"
            resultsContainer.innerHTML = `
                <div class="live-search-empty">
                    <i class="fa-solid fa-magnifying-glass text-muted"></i>
                    <div class="fw-bold text-secondary">ไม่พบข้อมูลครุภัณฑ์ที่ค้นหา</div>
                    <div class="small text-muted mt-1">ลองพิมพ์เฉพาะบางส่วนของหมายเลขหรือชื่อครุภัณฑ์ เช่น "เก้าอี้" หรือ "66"</div>
                </div>
            `;
            showDropdown();
            return;
        }

        const fragment = document.createDocumentFragment();

        items.forEach((item, index) => {
            const itemEl = document.createElement('div');
            itemEl.className = 'live-search-item';
            itemEl.setAttribute('role', 'option');
            itemEl.setAttribute('data-index', index);

            // ไอคอนหรือภาพประกอบครุภัณฑ์
            let iconHtml = '<i class="fa-solid fa-boxes-stacked"></i>';
            if (item.image) {
                iconHtml = `<img src="${escapeHtml(item.image)}" alt="" style="width: 100%; height: 100%; object-fit: cover; border-radius: 8px;">`;
            }

            const highlightedName   = highlightMatch(item.da_name, query);
            const highlightedNumber = highlightMatch(item.da_number, query);

            itemEl.innerHTML = `
                <div class="live-search-item-icon">
                    ${iconHtml}
                </div>
                <div class="live-search-item-content">
                    <div class="live-search-item-title d-flex align-items-center justify-content-between">
                        <span>${highlightedName}</span>
                        <span class="badge bg-success-subtle text-success border border-success-subtle ms-2">
                            <i class="fa-solid fa-circle-check me-1"></i>${escapeHtml(item.status_name || 'พร้อมใช้')}
                        </span>
                    </div>
                    <div class="live-search-item-sub d-flex align-items-center gap-2 mt-1">
                        <span class="live-search-badge-number">
                            <i class="fa-solid fa-barcode me-1 text-muted"></i>${highlightedNumber}
                        </span>
                        ${item.classification_name ? `<span class="text-truncate text-muted">&bull; ${escapeHtml(item.classification_name)}</span>` : ''}
                        ${item.location ? `<span class="text-truncate text-muted">&bull; <i class="fa-solid fa-location-dot me-1"></i>${escapeHtml(item.location)}</span>` : ''}
                    </div>
                </div>
            `;

            // คลิกเพื่อ Auto-fill และโหลดหน้าครุภัณฑ์ทันที
            itemEl.addEventListener('click', function(e) {
                e.preventDefault();
                selectItem(item);
            });

            fragment.appendChild(itemEl);
        });

        resultsContainer.appendChild(fragment);
        showDropdown();
    }

    function renderError() {
        resultsContainer.innerHTML = `
            <div class="live-search-empty text-danger">
                <i class="fa-solid fa-triangle-exclamation text-danger"></i>
                <div class="fw-bold">เกิดข้อผิดพลาดในการค้นหา</div>
                <div class="small text-muted mt-1">กรุณาลองใหม่อีกครั้ง หรือตรวจสอบการเชื่อมต่อ</div>
            </div>
        `;
        showDropdown();
    }

    /**
     * 4. เมื่อผู้ใช้คลิกเลือกรายการ: Auto-fill ข้อมูล และโหลดฟอร์มยืม
     */
    function selectItem(item) {
        if (!item || !item.da_number) return;

        // Auto-fill หมายเลขครุภัณฑ์ลงในช่องค้นหา
        searchInput.value = item.da_number;
        hideDropdown();

        // ส่งฟอร์มเพื่อโหลดรายละเอียดครุภัณฑ์และแสดงฟอร์มทำเรื่องยืม
        if (searchForm) {
            searchForm.submit();
        } else {
            window.location.href = 'borrow_request.php?asset_id=' + encodeURIComponent(item.da_number);
        }
    }

    /**
     * 5. ผูก Event Listeners พร้อม Debounce 300ms
     */
    const debouncedSearch = debounce(function() {
        performSearch(searchInput.value);
    }, 300);

    searchInput.addEventListener('input', function() {
        debouncedSearch();
    });

    searchInput.addEventListener('focus', function() {
        if (this.value.trim() !== '') {
            if (currentItems.length > 0) {
                showDropdown();
            } else {
                debouncedSearch();
            }
        }
    });

    // ปุ่มกากบาท (Clear Button) สำหรับล้างข้อความ
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            searchInput.value = '';
            searchInput.focus();
            if (abortController) abortController.abort();
            setLoading(false);
            toggleClearBtn(false);
            clearResults();
            hideDropdown();
        });
    }

    /**
     * 6. การควบคุมด้วยคีย์บอร์ด (Keyboard Navigation: Arrow Up, Arrow Down, Enter, Escape)
     */
    searchInput.addEventListener('keydown', function(e) {
        const items = resultsContainer.querySelectorAll('.live-search-item');
        if (!items || items.length === 0 || dropdown.classList.contains('d-none')) {
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex++;
            if (activeIndex >= items.length) activeIndex = 0;
            updateActiveItem(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex--;
            if (activeIndex < 0) activeIndex = items.length - 1;
            updateActiveItem(items);
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0 && activeIndex < currentItems.length) {
                e.preventDefault();
                selectItem(currentItems[activeIndex]);
            }
        } else if (e.key === 'Escape') {
            hideDropdown();
        }
    });

    function updateActiveItem(items) {
        items.forEach((el, idx) => {
            if (idx === activeIndex) {
                el.classList.add('active');
                el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                el.classList.remove('active');
            }
        });
    }

    // ซ่อน Dropdown เมื่อคลิกที่บริเวณอื่นนอกกล่องค้นหา
    document.addEventListener('click', function(e) {
        if (searchInput && dropdown && !searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            hideDropdown();
        }
    });
    } // สิ้นสุด if (searchInput && dropdown && resultsContainer)

    // =========================================================================
    // SECTION 2: In-App QR Code Scanner Controller (Part 2 - HTML5-QRCode)
    // =========================================================================
    let html5QrCode = null;
    let isScannerRunning = false;
    let currentCameraId = null;

    const scannerModalEl = document.getElementById('qrScannerModal');
    const scannerStatusEl = document.getElementById('qrScannerStatus');
    const scannerErrorEl = document.getElementById('qrScannerError');
    const cameraSelectEl = document.getElementById('cameraSelection');
    const cameraContainerEl = document.getElementById('cameraSelectorContainer');

    /**
     * สกัดเอาเฉพาะ asset_id ออกมาจาก URL หรือข้อความที่สแกนได้
     * รองรับรูปแบบ:
     * - http://localhost:8000/borrow_request.php?asset_id=12345
     * - borrow_request.php?asset_id=12345
     * - ?asset_id=12345
     * - 12345 (รหัสครุภัณฑ์ตรงๆ)
     */
    function extractAssetIdFromQr(decodedText) {
        if (!decodedText || typeof decodedText !== 'string') return '';
        decodedText = decodedText.trim();

        // 1. ตรวจสอบกรณีเป็น URL หรือมี Query String
        try {
            let urlObj;
            if (decodedText.startsWith('http://') || decodedText.startsWith('https://')) {
                urlObj = new URL(decodedText);
            } else if (decodedText.includes('?') || decodedText.includes('=')) {
                urlObj = new URL(decodedText, window.location.origin || 'http://localhost');
            }

            if (urlObj && urlObj.searchParams) {
                const assetParam = urlObj.searchParams.get('asset_id') || 
                                   urlObj.searchParams.get('da_id') ||
                                   urlObj.searchParams.get('id') ||
                                   urlObj.searchParams.get('q');
                if (assetParam) {
                    return assetParam.trim().replace(/['"]/g, '');
                }
            }
        } catch (e) {
            console.warn('URL parsing fallback:', e);
        }

        // 2. ใช้ Regex สำรองดึงพารามิเตอร์ asset_id
        const match = decodedText.match(/[?&](?:asset_id|da_id|id)=([^&#\s]+)/i);
        if (match && match[1]) {
            return decodeURIComponent(match[1]).trim().replace(/['"]/g, '');
        }

        // 3. กรณีเป็นรหัสครุภัณฑ์โดยตรง
        return decodedText.replace(/['"]/g, '').trim();
    }

    /**
     * เล่นเสียง Beep สั้นๆ เมื่อสแกนติด
     */
    function playScanSuccessTone() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const ctx = new AudioCtx();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.15);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.15);
        } catch(e) {}
    }

    /**
     * เริ่มการทำงานของกล้องเพื่อสแกน QR Code
     */
    function startQrScanner() {
        if (!scannerModalEl || !scannerStatusEl || !scannerErrorEl) return;

        scannerErrorEl.classList.add('d-none');
        scannerErrorEl.innerHTML = '';
        scannerStatusEl.innerHTML = '<span class="spinner-border spinner-border-sm text-primary me-1" role="status"></span> กำลังเปิดกล้อง...';

        if (typeof Html5Qrcode === 'undefined') {
            scannerStatusEl.innerHTML = '';
            scannerErrorEl.classList.remove('d-none');
            scannerErrorEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> ไม่สามารถโหลดไลบรารีกล้องได้ โปรดตรวจสอบการเชื่อมต่ออินเทอร์เน็ต';
            return;
        }

        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode("qr-reader");
        }

        const scanConfig = {
            fps: 15,
            qrbox: function(viewfinderWidth, viewfinderHeight) {
                const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                const edgeSize = Math.max(180, Math.floor(minEdge * 0.72));
                return { width: edgeSize, height: edgeSize };
            },
            aspectRatio: 1.0
        };

        Html5Qrcode.getCameras().then(devices => {
            if (!devices || devices.length === 0) {
                throw new Error("ไม่พบอุปกรณ์กล้องบนเครื่องของคุณ");
            }

            // จัดการ Dropdown สำหรับสลับกล้องเมื่อมีหลายตัว
            if (devices.length > 1 && cameraSelectEl && cameraContainerEl) {
                cameraContainerEl.classList.remove('d-none');
                cameraSelectEl.innerHTML = '';
                devices.forEach((cam, idx) => {
                    const opt = document.createElement('option');
                    opt.value = cam.id;
                    opt.text = cam.label || `กล้องตัวที่ ${idx + 1}`;
                    if (currentCameraId && currentCameraId === cam.id) {
                        opt.selected = true;
                    }
                    cameraSelectEl.appendChild(opt);
                });
            }

            const cameraParam = currentCameraId ? currentCameraId : { facingMode: "environment" };

            return html5QrCode.start(
                cameraParam,
                scanConfig,
                onQrScanSuccess,
                onQrScanFailure
            );
        }).then(() => {
            isScannerRunning = true;
            scannerStatusEl.innerHTML = '<i class="fa-solid fa-bullseye text-success me-1"></i> เล็งกล้องไปที่ QR Code บนสติกเกอร์';
        }).catch(err => {
            isScannerRunning = false;
            scannerStatusEl.innerHTML = '';
            scannerErrorEl.classList.remove('d-none');

            let errorMsg = 'ไม่สามารถเปิดกล้องได้: ' + (err.message || err);
            const errStr = String(err).toLowerCase();

            if (errStr.includes('permission') || err.name === 'NotAllowedError') {
                errorMsg = '<strong>ไม่ได้รับอนุญาตให้ใช้กล้อง:</strong> กรุณากดอนุญาตให้เบราว์เซอร์เข้าถึงกล้องถ่ายรูปในการตั้งค่า';
            } else if (errStr.includes('device') || errStr.includes('notfound') || err.name === 'NotFoundError') {
                errorMsg = '<strong>ไม่พบอุปกรณ์กล้อง:</strong> กรุณาตรวจสอบว่าอุปกรณ์มีกล้องที่พร้อมใช้งาน';
            } else if (window.location.protocol !== 'https:' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1') {
                errorMsg = '<strong>เบราว์เซอร์บล็อกการใช้กล้อง:</strong> การเปิดกล้องบนเว็บต้องการการเชื่อมต่อผ่าน HTTPS หรือ localhost';
            }

            scannerErrorEl.innerHTML = `<i class="fa-solid fa-triangle-exclamation me-1"></i> ${errorMsg}`;
        });
    }

    /**
     * หยุดการทำงานของกล้องและคืนทรัพยากร
     */
    function stopQrScanner() {
        if (html5QrCode && isScannerRunning) {
            return html5QrCode.stop().then(() => {
                isScannerRunning = false;
                html5QrCode.clear();
            }).catch(err => {
                console.warn("หยุดกล้องไม่สำเร็จ:", err);
                isScannerRunning = false;
            });
        }
        return Promise.resolve();
    }

    /**
     * Scan Callback เมื่อกล้องจับ QR Code ได้สำเร็จ
     */
    function onQrScanSuccess(decodedText, decodedResult) {
        if (!decodedText) return;

        const assetId = extractAssetIdFromQr(decodedText);
        if (!assetId) {
            return;
        }

        // เล่นเสียงแจ้งเตือนสแกนสำเร็จ
        playScanSuccessTone();

        if (scannerStatusEl) {
            scannerStatusEl.innerHTML = `<span class="badge bg-success py-2 px-3 fs-6"><i class="fa-solid fa-check me-1"></i> สแกนพบ: ${assetId}</span>`;
        }

        // ปิดกล้องและปิด Modal
        stopQrScanner().then(() => {
            const modalInstance = bootstrap.Modal.getInstance(scannerModalEl);
            if (modalInstance) {
                modalInstance.hide();
            }

            // นำรหัสครุภัณฑ์ใส่ในกล่องค้นหา (ถ้ามี)
            const liveInput = document.getElementById('liveSearchInput');
            if (liveInput) {
                liveInput.value = assetId;
            }

            // นำทางไปยังหน้าขอยืมครุภัณฑ์ชิ้นนั้นทันที
            window.location.href = 'borrow_request.php?asset_id=' + encodeURIComponent(assetId);
        });
    }

    function onQrScanFailure(error) {
        // ละเว้น error ขณะกำลังค้นหาเฟรมถัดไป
    }

    // สลับกล้องเมื่อผู้ใช้เลือกจาก Dropdown
    if (cameraSelectEl) {
        cameraSelectEl.addEventListener('change', function() {
            currentCameraId = this.value;
            if (currentCameraId && html5QrCode) {
                stopQrScanner().then(() => {
                    startQrScanner();
                });
            }
        });
    }

    // จัดการ Event ของ Modal
    if (scannerModalEl) {
        scannerModalEl.addEventListener('shown.bs.modal', function() {
            startQrScanner();
        });
        scannerModalEl.addEventListener('hidden.bs.modal', function() {
            stopQrScanner();
        });
    }

    // ผูก Event ให้กับทุกปุ่มสแกน (.btn-open-qr-scanner)
    document.querySelectorAll('.btn-open-qr-scanner').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (scannerModalEl) {
                const modal = bootstrap.Modal.getOrCreateInstance(scannerModalEl);
                modal.show();
            }
        });
    });

    // =========================================================================
    // SECTION 3: Date Validation Controller (การตรวจสอบความถูกต้องของวันที่)
    // =========================================================================

    // -------------------------------------------------------------------------
    // กำหนดค่าคงที่สำหรับการตรวจสอบวันที่ (Date Validation Settings)
    // สามารถปรับแก้จำนวนวันได้ตามนโยบายของหน่วยงาน:
    // -------------------------------------------------------------------------
    const MAX_ADVANCE_DAYS         = 30;  // อนุญาตให้เลือกวันเริ่มยืมล่วงหน้าได้ไม่เกิน (วัน)
    const MAX_BORROW_PERIOD_DAYS   = 365; // ระยะเวลาการยืมสูงสุดนับจากวันที่เริ่มยืม (วัน / 1 ปี)
    const DEFAULT_BORROW_DAYS      = 7;   // ระยะเวลาส่งคืนเริ่มต้นอัตโนมัติ (วัน)

    const startDateInput  = document.getElementById('date_borrow');
    const returnDateInput = document.getElementById('deadline_borrow');
    const borrowForm      = document.getElementById('borrowActionForm');

    if (startDateInput && returnDateInput) {

        /**
         * แปลง Date Object เป็นสตริงวันที่รูปแบบ YYYY-MM-DD
         */
        function toISODateString(date) {
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        /**
         * แปลงสตริง YYYY-MM-DD เป็น Date Object (ตัดเวลาออกเพื่อเปรียบเทียบเฉพาะวัน)
         */
        function parseDateOnly(str) {
            if (!str || !/^\d{4}-\d{2}-\d{2}$/.test(str)) return null;
            const [y, m, d] = str.split('-').map(Number);
            const date = new Date(y, m - 1, d);
            if (date.getFullYear() === y && date.getMonth() === m - 1 && date.getDate() === d) {
                date.setHours(0, 0, 0, 0);
                return date;
            }
            return null;
        }

        /**
         * ฟังก์ชันบวกจำนวนวันให้กับ Date Object
         */
        function addDays(baseDate, days) {
            const result = new Date(baseDate.getTime());
            result.setDate(result.getDate() + days);
            result.setHours(0, 0, 0, 0);
            return result;
        }

        // วันที่ปัจจุบัน (Today)
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const todayStr = toISODateString(today);

        // 1. ตั้งค่าเริ่มต้นและกรอบของช่อง "วันที่เริ่มยืม" (Start Date)
        if (!startDateInput.value) {
            startDateInput.value = todayStr;
        }
        // กำหนด min เป็นวันที่ปัจจุบัน (ไม่อนุญาตให้เลือกวันที่ย้อนหลังในอดีต)
        startDateInput.min = todayStr;
        // กำหนด max ล่วงหน้าได้ไม่เกิน MAX_ADVANCE_DAYS (30 วัน)
        const maxAdvanceDate = addDays(today, MAX_ADVANCE_DAYS);
        startDateInput.max = toISODateString(maxAdvanceDate);

        /**
         * 2. ฟังก์ชันอัปเดตข้อจำกัดของช่อง "กำหนดส่งคืน" ให้สอดคล้องกับ "วันที่เริ่มยืม"
         */
        function syncReturnDateConstraints() {
            const startDate = parseDateOnly(startDateInput.value);
            if (!startDate) return;

            // min ของวันส่งคืน = วันเดียวกับวันที่เริ่มยืม (ไม่อนุญาตให้คืนก่อนยืม)
            const minReturnStr = toISODateString(startDate);
            returnDateInput.min = minReturnStr;

            // max ของวันส่งคืน = วันที่เริ่มยืม + MAX_BORROW_PERIOD_DAYS (ไม่เกิน 1 ปี / 365 วัน)
            const maxReturnDate = addDays(startDate, MAX_BORROW_PERIOD_DAYS);
            const maxReturnStr  = toISODateString(maxReturnDate);
            returnDateInput.max = maxReturnStr;

            // ตรวจสอบค่าปัจจุบันของกำหนดส่งคืน
            const currentReturn = parseDateOnly(returnDateInput.value);
            if (currentReturn) {
                // หากวันส่งคืนอยู่ก่อนวันเริ่มยืม หรือเกิน 365 วัน ให้ปรับอัตโนมัติเป็นวันเริ่มยืม + DEFAULT_BORROW_DAYS (7 วัน)
                if (currentReturn < startDate || currentReturn > maxReturnDate) {
                    const newReturn = addDays(startDate, DEFAULT_BORROW_DAYS);
                    returnDateInput.value = toISODateString(newReturn);
                }
            } else {
                const newReturn = addDays(startDate, DEFAULT_BORROW_DAYS);
                returnDateInput.value = toISODateString(newReturn);
            }
        }

        // เรียกทำงานทันทีตอนโหลดหน้า
        syncReturnDateConstraints();

        // ดักจับ Event change และ input เมื่อวันที่เริ่มยืมเปลี่ยน ให้ไปอัปเดตกำหนดส่งคืนทันที
        startDateInput.addEventListener('change', syncReturnDateConstraints);
        startDateInput.addEventListener('input', syncReturnDateConstraints);

        // ปลดคลาสสถานะ error เมื่อผู้ใช้แก้ไขข้อมูล
        startDateInput.addEventListener('input', () => startDateInput.classList.remove('is-invalid'));
        returnDateInput.addEventListener('input', () => returnDateInput.classList.remove('is-invalid'));

        /**
         * 3. ตรวจสอบความถูกต้องของวันที่เมื่อกด Submit Form (Client-side Form Validation)
         */
        if (borrowForm) {
            borrowForm.addEventListener('submit', function(e) {
                const startDate  = parseDateOnly(startDateInput.value);
                const returnDate = parseDateOnly(returnDateInput.value);

                let validationError = '';
                let targetEl = null;

                if (!startDate) {
                    validationError = 'กรุณาระบุวันที่เริ่มยืมให้ถูกต้องตามรูปแบบ ปี-เดือน-วัน (YYYY-MM-DD)';
                    targetEl = startDateInput;
                } else if (startDate < today) {
                    validationError = `วันที่เริ่มยืม (${startDateInput.value}) ต้องไม่เป็นวันที่ในอดีต (ต้องเป็นวันนี้ [${todayStr}] หรือหลังจากนี้)`;
                    targetEl = startDateInput;
                } else if (startDate > maxAdvanceDate) {
                    validationError = `ไม่อนุญาตให้จองยืมล่วงหน้าเกิน ${MAX_ADVANCE_DAYS} วัน (เลือกได้ไม่เกิน ${toISODateString(maxAdvanceDate)})`;
                    targetEl = startDateInput;
                } else if (!returnDate) {
                    validationError = 'กรุณาระบุกำหนดส่งคืนให้ถูกต้องตามรูปแบบ ปี-เดือน-วัน (YYYY-MM-DD)';
                    targetEl = returnDateInput;
                } else if (returnDate < startDate) {
                    validationError = `กำหนดส่งคืน (${returnDateInput.value}) ต้องไม่เกิดขึ้นก่อนวันที่เริ่มยืม (${startDateInput.value})`;
                    targetEl = returnDateInput;
                } else {
                    const maxReturnDate = addDays(startDate, MAX_BORROW_PERIOD_DAYS);
                    if (returnDate > maxReturnDate) {
                        validationError = `ระยะเวลาการยืมต้องไม่เกิน ${MAX_BORROW_PERIOD_DAYS} วัน (1 ปี) นับจากวันที่เริ่มยืม (กำหนดส่งคืนได้ไม่เกิน ${toISODateString(maxReturnDate)})`;
                        targetEl = returnDateInput;
                    }
                }

                // หากพบข้อผิดพลาด ให้หยุดการส่งฟอร์มและแสดง Alert แจ้งเตือน
                if (validationError) {
                    e.preventDefault();
                    e.stopPropagation();

                    if (targetEl) {
                        targetEl.classList.add('is-invalid');
                        targetEl.focus();
                    }

                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'warning',
                            title: 'ข้อมูลวันที่ไม่ถูกต้อง',
                            text: validationError,
                            confirmButtonText: 'รับทราบและแก้ไข',
                            confirmButtonColor: '#0d6efd'
                        });
                    } else {
                        alert('ข้อผิดพลาด: ' + validationError);
                    }
                }
            });
        }
    }
});
</script>
</body>
</html>
