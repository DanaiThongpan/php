<?php
/**
 * borrow_request.php - หน้าสแกน QR Code เพื่อทำรายการยืม-คืนครุภัณฑ์ (Asset Management Flow)
 * รองรับการรับค่า ?asset_id=XXX หรือ ?da_id=XXX
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------------
// 1. รับค่าพารามิเตอร์หมายเลขครุภัณฑ์ (Asset Number หรือ DA_ID)
// -----------------------------------------------------------------------------
$asset_id = trim($_GET['asset_id'] ?? $_GET['da_id'] ?? $_POST['asset_id'] ?? '');

// -----------------------------------------------------------------------------
// 2. Authentication Check & Redirect Logic
// -----------------------------------------------------------------------------
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1) {
    // บันทึก URL ปัจจุบันพร้อมพารามิเตอร์เพื่อส่งต่อไปยังหน้าเข้าสู่ระบบ
    $queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : (!empty($asset_id) ? '?asset_id=' . urlencode($asset_id) : '');
    $redirectUrl = 'borrow_request.php' . $queryString;
    
    $_SESSION['redirect_url'] = $redirectUrl;
    
    // Redirect ไปที่ login.php พร้อมแนบพารามิเตอร์ redirect ตามข้อกำหนด
    header("Location: login.php?redirect=" . urlencode($redirectUrl));
    exit();
}

// โหลดการเชื่อมต่อฐานข้อมูล PDO
require_once __DIR__ . '/control/connectPDO_dev.php';

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
$activeBorrow = null;

// -----------------------------------------------------------------------------
// 3. ดึงข้อมูลครุภัณฑ์จากฐานข้อมูลด้วย Prepared Statements
// -----------------------------------------------------------------------------
if (!empty($asset_id)) {
    try {
        $sql = "SELECT da.*, 
                       c.classificationName, 
                       b.buildingName, 
                       bc.classroomName, 
                       sa.statusName 
                FROM durable_articles da
                LEFT JOIN classification c ON da.classificationID = c.classificationID
                LEFT JOIN building b ON da.buildingID = b.buildingID
                LEFT JOIN building_classroom bc ON da.classroomID = bc.classroomID
                LEFT JOIN status_active sa ON da.statusID = sa.statusID
                WHERE (da.DA_Number = :asset_id OR da.DA_ID = :asset_id)
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':asset_id' => $asset_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            // ตรวจสอบว่าครุภัณฑ์นี้ผู้ใช้ปัจจุบันกำลังยืมอยู่หรือไม่
            if ($item['statusID'] === 's0003') {
                $sqlBorrow = "SELECT * FROM borrow_back 
                              WHERE DA_ID = :daid 
                                AND useraccountID_borrow = :uid 
                                AND (Date_back IS NULL OR Date_back = '')
                              ORDER BY Deadline_borrow DESC LIMIT 1";
                $stmtB = $pdo->prepare($sqlBorrow);
                $stmtB->execute([
                    ':daid' => $item['DA_ID'],
                    ':uid'  => $currentUID
                ]);
                $activeBorrow = $stmtB->fetch(PDO::FETCH_ASSOC);
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
        if ($item['statusID'] !== 's0001') {
            $msgError = "ขออภัย ครุภัณฑ์นี้ไม่พร้อมให้ยืมในขณะนี้ (สถานะ: " . htmlspecialchars($item['statusName'] ?? 'ไม่พร้อมใช้งาน') . ")";
        } else {
            $dateBorrow     = trim($_POST['date_borrow'] ?? date('Y-m-d'));
            $deadlineBorrow = trim($_POST['deadline_borrow'] ?? date('Y-m-d', strtotime('+7 days')));
            $nameBorrow     = trim($_POST['name_borrow'] ?? $userName);
            $rankBorrow     = trim($_POST['rank_borrow'] ?? $userRank);
            $belongtoBorrow = trim($_POST['belongto_borrow'] ?? $userDept);
            $detailBorrow   = trim($_POST['detail_borrow'] ?? 'ใช้งานทั่วไป');
            $emailBorrow    = trim($_POST['userEmail'] ?? $userEmail);
            $nowTimestamp   = date('Y-m-d H:i:s');
            $newBorrowID    = "B" . time() . rand(10, 99);

            try {
                $pdo->beginTransaction();

                // บันทึกลงตาราง borrow_back
                $sqlInsert = "INSERT INTO borrow_back 
                              (IDborrow_back, DA_ID, Date_borrow, Name_borrow, Rank_borrow, Belongto_borrow, 
                               Detail_borrow, Deadline_borrow, useraccountID_borrow, timestamp_borrow, userEmail, due_date, status) 
                              VALUES 
                              (:id, :da_id, :d_borrow, :name_borrow, :rank_borrow, :belongto, 
                               :detail, :deadline, :uid, :ts, :email, :due_date, 'borrowed')";
                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute([
                    ':id'          => $newBorrowID,
                    ':da_id'       => $item['DA_ID'],
                    ':d_borrow'    => $dateBorrow,
                    ':name_borrow' => $nameBorrow,
                    ':rank_borrow' => $rankBorrow,
                    ':belongto'    => $belongtoBorrow,
                    ':detail'      => $detailBorrow,
                    ':deadline'    => $deadlineBorrow,
                    ':uid'         => $currentUID,
                    ':ts'          => $nowTimestamp,
                    ':email'       => $emailBorrow,
                    ':due_date'    => $deadlineBorrow
                ]);

                // อัปเดตสถานะครุภัณฑ์เป็น 'ไม่ว่าง (ยืม)' (s0003)
                $sqlUpdate = "UPDATE durable_articles SET statusID = 's0003' WHERE DA_ID = :da_id";
                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $stmtUpdate->execute([':da_id' => $item['DA_ID']]);

                $pdo->commit();

                // ปรับปรุงข้อมูลในตัวแปรสำหรับแสดงผลทันที
                $item['statusID']   = 's0003';
                $item['statusName'] = 'ไม่ว่าง ( ยืม )';
                $activeBorrow = [
                    'IDborrow_back'   => $newBorrowID,
                    'Date_borrow'     => $dateBorrow,
                    'Deadline_borrow' => $deadlineBorrow,
                    'Detail_borrow'   => $detailBorrow
                ];
                $msgSuccess = "ทำรายการขอยืมครุภัณฑ์สำเร็จเรียบร้อย! รหัสอ้างอิง: " . $newBorrowID;

            } catch (Exception $e) {
                $pdo->rollBack();
                $msgError = "ไม่สามารถบันทึกการยืมได้: " . $e->getMessage();
            }
        }

    // ==========================================
    // ACTION 2: ยืนยันการส่งคืนครุภัณฑ์ (Return)
    // ==========================================
    } elseif ($action === 'return' && $activeBorrow) {
        $todayDate    = date('Y-m-d');
        $nowTimestamp = date('Y-m-d H:i:s');
        $idBorrowBack = $activeBorrow['IDborrow_back'];

        try {
            $pdo->beginTransaction();

            // อัปเดตบันทึกการส่งคืนในตาราง borrow_back
            $sqlReturn = "UPDATE borrow_back 
                          SET Date_back = :d_back, 
                              Name_back = :n_back, 
                              Rank_back = :r_back, 
                              useraccountID_back = :uid_back, 
                              timestamp_back = :ts, 
                              status = 'returned' 
                          WHERE IDborrow_back = :id_borrow";
            $stmtReturn = $pdo->prepare($sqlReturn);
            $stmtReturn->execute([
                ':d_back'    => $todayDate,
                ':n_back'    => $userName,
                ':r_back'    => $userRank,
                ':uid_back'  => $currentUID,
                ':ts'        => $nowTimestamp,
                ':id_borrow' => $idBorrowBack
            ]);

            // อัปเดตสถานะครุภัณฑ์กลับมาเป็น 'พร้อมใช้' (s0001)
            $sqlUpdateDA = "UPDATE durable_articles SET statusID = 's0001' WHERE DA_ID = :da_id";
            $stmtUpdateDA = $pdo->prepare($sqlUpdateDA);
            $stmtUpdateDA->execute([':da_id' => $item['DA_ID']]);

            $pdo->commit();

            // ปรับปรุงตัวแปรแสดงผล
            $item['statusID']   = 's0001';
            $item['statusName'] = 'พร้อมใช้';
            $activeBorrow = null;
            $msgSuccess = "บันทึกการส่งคืนครุภัณฑ์เรียบร้อยแล้ว ขอบคุณครับ!";

        } catch (Exception $e) {
            $pdo->rollBack();
            $msgError = "ไม่สามารถส่งคืนครุภัณฑ์ได้: " . $e->getMessage();
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
            overflow: hidden;
        }
        .header-gradient {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            color: white;
            padding: 24px 20px;
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
    </style>
</head>
<body class="py-4">

<div class="container">
    <div class="row justify-content-center">
        <div class="col-12 col-md-9 col-lg-7">

            <!-- Navbar เล็ก / Breadcrumb ด้านบน -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="<?php echo ($userType === 'UT00000001') ? 'admin/main.php' : 'user/main.php'; ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าหลัก
                </a>
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
                        <!-- ฟอร์มค้นหาหากไม่ได้แนบ asset_id มาใน URL -->
                        <div class="text-center py-4">
                            <i class="fa-solid fa-barcode text-muted fa-3x mb-3"></i>
                            <h5 class="fw-bold">ไม่พบรหัสครุภัณฑ์ในลิงก์</h5>
                            <p class="text-muted">กรุณาสแกน QR Code ที่ติดอยู่บนตัวครุภัณฑ์ หรือกรอกหมายเลขครุภัณฑ์ด้านล่าง:</p>
                            
                            <form method="GET" action="" class="mt-3">
                                <div class="input-group mb-3">
                                    <input type="text" name="asset_id" class="form-control form-control-lg" placeholder="ตัวอย่าง: สอ6710060000048 หรือ DA_ID" required autofocus>
                                    <button class="btn btn-primary px-4 fw-bold" type="submit">
                                        <i class="fa-solid fa-search me-1"></i> ค้นหา
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php elseif (!$item): ?>
                        <!-- กรณีหาข้อมูลครุภัณฑ์ไม่พบ -->
                        <div class="text-center py-5">
                            <i class="fa-solid fa-circle-question text-warning fa-4x mb-3"></i>
                            <h5 class="fw-bold text-danger">ไม่พบข้อมูลครุภัณฑ์ในระบบ</h5>
                            <p class="text-muted">หมายเลข: <code><?php echo htmlspecialchars($asset_id); ?></code></p>
                            <p class="small text-secondary">โปรดตรวจสอบว่าสแกน QR Code ถูกต้อง หรือติดต่อผู้ดูแลระบบ</p>
                            <a href="borrow_request.php" class="btn btn-outline-primary rounded-pill px-4 mt-2">
                                <i class="fa-solid fa-arrow-rotate-left me-1"></i> ลองค้นหาใหม่
                            </a>
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
                                <?php if ($item['statusID'] === 's0001'): ?>
                                    <span class="badge bg-success asset-badge shadow-sm">
                                        <i class="fa-solid fa-circle-check me-1"></i> พร้อมใช้งาน (Available)
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
                        <form method="POST" action="" class="needs-validation" novalidate>
                            <input type="hidden" name="asset_id" value="<?php echo htmlspecialchars($asset_id); ?>">

                            <?php if ($item['statusID'] === 's0001'): ?>
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
                                            <label class="form-label small fw-bold">วันที่เริ่มยืม</label>
                                            <input type="date" name="date_borrow" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label small fw-bold text-danger">กำหนดส่งคืน (Deadline)</label>
                                            <input type="date" name="deadline_borrow" class="form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small fw-bold">วัตถุประสงค์ในการยืม</label>
                                            <textarea name="detail_borrow" class="form-control" rows="2" placeholder="ระบุเหตุผลในการยืม เช่น ใช้ในการเรียนการสอน, จัดอบรมสัมมนา">ใช้ในการปฏิบัติงาน</textarea>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary btn-action w-100 shadow">
                                    <i class="fa-solid fa-hand-holding-hand me-2"></i> ยืนยันการขอยืมครุภัณฑ์
                                </button>
                                <p class="text-center text-muted small mt-2 mb-0">ระบบจะบันทึกประวัติการยืมเข้าสู่บัญชีของคุณอัตโนมัติ</p>

                            <?php elseif (!empty($activeBorrow)): ?>
                                <!-- โหมด: ผู้ใช้กำลังยืมชิ้นนี้อยู่ -> แสดงปุ่มส่งคืน (Return) -->
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

                                <button type="submit" class="btn btn-warning btn-action text-dark fw-bold w-100 shadow">
                                    <i class="fa-solid fa-rotate-left me-2"></i> ยืนยันการส่งคืนครุภัณฑ์
                                </button>
                                <p class="text-center text-muted small mt-2 mb-0">กรุณาตรวจสอบสภาพครุภัณฑ์และวางไว้ ณ จุดจัดเก็บเดิม</p>

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

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
