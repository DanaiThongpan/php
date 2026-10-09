<?php
session_start();

// 1. ตรวจสอบการ Login
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1) {
    // ถ้ายังไม่ได้ Login ให้จดจำ URL ปัจจุบันที่สแกนเข้ามา
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header("Location: ../index.php");
    exit();
}

require_once '../control/connectPDO_dev.php';

$useraccountID = $_SESSION['SeUser'];
$useraccountName = $_SESSION['Sename'];

// รับค่า da_id จาก URL (สแกนมาจาก QR Code)
$da_id = $_GET['da_id'] ?? '';

if (empty($da_id)) {
    echo "<script>alert('ไม่พบรหัสครุภัณฑ์'); window.location.href='main.php';</script>";
    exit();
}

try {
    // 2. ดึงข้อมูลครุภัณฑ์จากฐานข้อมูล
    $sqlDA = "SELECT da.*, c.classificationName 
              FROM durable_articles da 
              LEFT JOIN classification c ON da.classificationID = c.classificationID 
              WHERE da.DA_Number = :da_id OR da.DA_ID = :da_id";
    $stmtDA = $pdo->prepare($sqlDA);
    $stmtDA->execute([':da_id' => $da_id]);
    $item = $stmtDA->fetch();

    if (!$item) {
        echo "<script>alert('ไม่พบข้อมูลครุภัณฑ์นี้ในระบบ'); window.location.href='main.php';</script>";
        exit();
    }

    $statusID = $item['statusID'];
    $isMyBorrowedItem = false;
    $idBorrowBack = '';

    // เช็คว่าถ้าถูกยืมอยู่ เป็นการยืมของ User คนนี้ใช่หรือไม่?
    if ($statusID == 's0003') {
        $sqlCheckBorrow = "SELECT IDborrow_back FROM borrow_back 
                           WHERE DA_ID = :da_id 
                           AND useraccountID_borrow = :uid 
                           AND (Date_back IS NULL OR Date_back = '')
                           ORDER BY Deadline_borrow DESC LIMIT 1";
        $stmtCheck = $pdo->prepare($sqlCheckBorrow);
        $stmtCheck->execute([
            ':da_id' => $item['DA_ID'],
            ':uid' => $useraccountID
        ]);
        $borrowRec = $stmtCheck->fetch();
        if ($borrowRec) {
            $isMyBorrowedItem = true;
            $idBorrowBack = $borrowRec['IDborrow_back'];
        }
    }

    // 3. จัดการเมื่อกดปุ่ม (POST Action)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $todayDate = date('Y-m-d');
        $timeStampDateNow = date('Y-m-d H:i:s');

        $pdo->beginTransaction();

        try {
            if ($action === 'borrow' && $statusID === 's0001') {
                // บันทึกการยืม
                $borrow_id = "B" . time() . rand(10, 99);
                // ตั้งค่ายืม 7 วัน
                $deadlineDate = date('Y-m-d', strtotime('+7 days'));

                $sqlInsert = "INSERT INTO borrow_back 
                              (IDborrow_back, DA_ID, Date_borrow, Name_borrow, Deadline_borrow, useraccountID_borrow, timestamp_borrow) 
                              VALUES (:id, :da_id, :d_borrow, :n_borrow, :deadline, :uid, :ts)";
                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute([
                    ':id' => $borrow_id,
                    ':da_id' => $item['DA_ID'],
                    ':d_borrow' => $todayDate,
                    ':n_borrow' => $useraccountName,
                    ':deadline' => $deadlineDate,
                    ':uid' => $useraccountID,
                    ':ts' => $timeStampDateNow
                ]);

                // อัปเดตสถานะเป็น 'ถูกยืม' (s0003)
                $sqlUpdate = "UPDATE durable_articles SET statusID = 's0003' WHERE DA_ID = :da_id";
                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $stmtUpdate->execute([':da_id' => $item['DA_ID']]);

                $pdo->commit();
                echo "<script>alert('✅ ยืนยันการยืมสำเร็จ!'); window.location.href='main.php';</script>";
                exit();

            } elseif ($action === 'return' && $isMyBorrowedItem) {
                // บันทึกการคืน
                $sqlUpdateBorrow = "UPDATE borrow_back 
                                    SET Date_back = :d_back, 
                                        Name_back = :n_back, 
                                        useraccountID_back = :uid_back, 
                                        timestamp_back = :ts 
                                    WHERE IDborrow_back = :id_borrow";
                $stmtUpdateBorrow = $pdo->prepare($sqlUpdateBorrow);
                $stmtUpdateBorrow->execute([
                    ':d_back' => $todayDate,
                    ':n_back' => $useraccountName,
                    ':uid_back' => $useraccountID,
                    ':ts' => $timeStampDateNow,
                    ':id_borrow' => $idBorrowBack
                ]);

                // อัปเดตสถานะเป็น 'พร้อมใช้งาน' (s0001)
                $sqlUpdateDA = "UPDATE durable_articles SET statusID = 's0001' WHERE DA_ID = :da_id";
                $stmtUpdateDA = $pdo->prepare($sqlUpdateDA);
                $stmtUpdateDA->execute([':da_id' => $item['DA_ID']]);

                $pdo->commit();
                echo "<script>alert('✅ ส่งคืนครุภัณฑ์เรียบร้อยแล้ว ขอบคุณครับ!'); window.location.href='main.php';</script>";
                exit();
            } else {
                // กรณีข้อมูลไม่ตรงเงื่อนไข เช่น โดนคนอื่นยืมไปแล้วก่อนกดยืนยัน
                throw new Exception("สถานะครุภัณฑ์มีการเปลี่ยนแปลง ไม่สามารถทำรายการได้");
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $errorMsg = $e->getMessage();
        }
    }

} catch (PDOException $e) {
    $errorMsg = "Database Error: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สแกนทำรายการ ยืม-คืน (QR Code)</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            background-color: #f8f9fa; 
            font-family: 'Sarabun', sans-serif;
        }
        .action-card {
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 20px rgba(0,0,0,0.05);
            border: none;
        }
        .item-icon {
            font-size: 4rem;
            color: #6c757d;
            margin-bottom: 15px;
        }
        /* Mobile First UI: ทำปุ่มให้ใหญ่กดง่ายเต็มจอ */
        .btn-action {
            padding: 15px 20px;
            font-size: 1.2rem;
            border-radius: 12px;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-5">
            
            <div class="text-center mb-4">
                <h4 class="text-primary fw-bold"><i class="fa-solid fa-qrcode me-2"></i>ระบบ ยืม-คืน อัตโนมัติ</h4>
                <p class="text-muted">โปรดตรวจสอบรายการก่อนกดยืนยัน</p>
            </div>

            <?php if (isset($errorMsg)): ?>
                <div class="alert alert-danger rounded-4"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($errorMsg); ?></div>
            <?php endif; ?>

            <div class="card action-card">
                <div class="card-body p-4 text-center">
                    <i class="fa-solid fa-box-archive item-icon"></i>
                    <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($item['DA_ListName']); ?></h5>
                    <p class="text-muted mb-3">รหัส: <?php echo htmlspecialchars($item['DA_Number']); ?></p>
                    
                    <div class="bg-light rounded-3 p-3 text-start mb-4">
                        <p class="mb-1"><strong>หมวดหมู่:</strong> <?php echo htmlspecialchars($item['classificationName'] ?? '-'); ?></p>
                        <?php if(!empty($item['DA_Brand'])): ?>
                            <p class="mb-1"><strong>ยี่ห้อ:</strong> <?php echo htmlspecialchars($item['DA_Brand']); ?></p>
                        <?php endif; ?>
                        
                        <!-- เช็คสถานะปัจจุบัน -->
                        <div class="mt-3 text-center">
                            <?php if ($statusID === 's0001'): ?>
                                <span class="badge bg-success fs-6 px-3 py-2 rounded-pill"><i class="fa-solid fa-check-circle"></i> พร้อมใช้งาน</span>
                            <?php elseif ($isMyBorrowedItem): ?>
                                <span class="badge bg-warning text-dark fs-6 px-3 py-2 rounded-pill"><i class="fa-solid fa-clock-rotate-left"></i> คุณกำลังยืมชิ้นนี้อยู่</span>
                            <?php else: ?>
                                <span class="badge bg-danger fs-6 px-3 py-2 rounded-pill"><i class="fa-solid fa-xmark-circle"></i> ถูกผู้อื่นยืมไปแล้ว</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="POST" action="">
                        <?php if ($statusID === 's0001'): ?>
                            <!-- กรณี: ว่างให้ยืม -->
                            <input type="hidden" name="action" value="borrow">
                            <button type="submit" class="btn btn-primary btn-action w-100 mb-3 shadow-sm">
                                <i class="fa-solid fa-hand-holding-hand me-2"></i> ยืนยันการยืม
                            </button>
                            <p class="text-muted small">ระบบจะกำหนดเวลายืมให้ 7 วันอัตโนมัติ</p>

                        <?php elseif ($isMyBorrowedItem): ?>
                            <!-- กรณี: ของอยู่ที่เรา กำลังจะคืน -->
                            <input type="hidden" name="action" value="return">
                            <button type="submit" class="btn btn-warning btn-action w-100 mb-3 shadow-sm text-dark fw-bold">
                                <i class="fa-solid fa-rotate-left me-2"></i> ยืนยันการคืนครุภัณฑ์
                            </button>
                            <p class="text-muted small">กรุณาวางของไว้ที่เดิมหลังจากกดยืนยัน</p>

                        <?php else: ?>
                            <!-- กรณี: คนอื่นยืมไป -->
                            <button type="button" class="btn btn-secondary btn-action w-100 mb-3" disabled>
                                ไม่สามารถทำรายการได้
                            </button>
                        <?php endif; ?>
                    </form>

                    <a href="main.php" class="btn btn-outline-secondary w-100 rounded-pill mt-2">กลับหน้าหลัก</a>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
