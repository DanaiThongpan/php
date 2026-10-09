<?php
session_start();

// ตรวจสอบสิทธิ์ (ต้องล็อกอิน)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1) {
    header("Location: ../index.php");
    exit();
}

require_once '../control/connectPDO_dev.php';

$useraccountID = $_SESSION['SeUser'];
$useraccountName = $_SESSION['Sename'];

// ดึงข้อมูลผู้ใช้งานสำหรับเติมฟอร์มอัตโนมัติ
$stmtUser = $pdo->prepare("SELECT * FROM useraccount WHERE useraccountID = :uid");
$stmtUser->execute([':uid' => $useraccountID]);
$userData = $stmtUser->fetch();

// รับค่า IDborrow ของรายการยืมที่ต้องการคืน
$id_borrow = $_GET['IDborrow'] ?? '';

if (empty($id_borrow)) {
    echo "<script>alert('ไม่พบรหัสการยืม'); window.location.href='main.php';</script>";
    exit();
}

// ดึงข้อมูลการยืมและครุภัณฑ์มาแสดง
$sqlBorrowInfo = "SELECT b.*, da.DA_Number, da.DA_ListName, da.DA_Brand 
                  FROM borrow_back b
                  JOIN durable_articles da ON b.DA_ID = da.DA_ID
                  WHERE b.IDborrow_back = :id_borrow AND (b.Date_back IS NULL OR b.Date_back = '')";
$stmtBorrowInfo = $pdo->prepare($sqlBorrowInfo);
$stmtBorrowInfo->execute([':id_borrow' => $id_borrow]);
$borrowData = $stmtBorrowInfo->fetch();

if (!$borrowData) {
    echo "<script>alert('ไม่พบข้อมูลการยืมนี้ หรือถูกส่งคืนไปแล้ว'); window.location.href='main.php';</script>";
    exit();
}

// เมื่อกดบันทึกแบบฟอร์มส่งคืน (POST Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date_Back = $_POST['date_Back'];
    $name_Back = $_POST['name_Back'];
    $rank_Back = $_POST['rank_Back'];
    $detail_Back = $_POST['detail_Back'];
    $timeStampDateNow = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        // 1. อัปเดตข้อมูลการส่งคืนในตาราง borrow_back
        $sqlUpdateBorrow = "UPDATE borrow_back 
                            SET Date_back = :date_back, 
                                Name_back = :name_back, 
                                Rank_back = :rank_back, 
                                Detail_back = :detail_back, 
                                useraccountID_back = :uid_back, 
                                timestamp_back = :timestamp 
                            WHERE IDborrow_back = :id_borrow";
        
        $stmtUpdateBorrow = $pdo->prepare($sqlUpdateBorrow);
        $stmtUpdateBorrow->execute([
            ':date_back' => $date_Back,
            ':name_back' => $name_Back,
            ':rank_back' => $rank_Back,
            ':detail_back' => $detail_Back,
            ':uid_back' => $useraccountID,
            ':timestamp' => $timeStampDateNow,
            ':id_borrow' => $id_borrow
        ]);

        // 2. อัปเดตสถานะครุภัณฑ์กลับมาเป็น 'พร้อมใช้งาน' (s0001)
        $sqlUpdateDA = "UPDATE durable_articles SET statusID = 's0001' WHERE DA_ID = :da_id";
        $stmtUpdateDA = $pdo->prepare($sqlUpdateDA);
        $stmtUpdateDA->execute([':da_id' => $borrowData['DA_ID']]);

        $pdo->commit();
        echo "<script>alert('บันทึกการส่งคืนครุภัณฑ์สำเร็จ ขอบคุณครับ!'); window.location.href='main.php';</script>";
        exit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        $errorMsg = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ทำรายการคืนครุภัณฑ์ (V2)</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; }
        .card-header { background-color: #f6c23e; color: #333; font-weight: bold; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="main.php"><i class="fa-solid fa-boxes-packing text-warning me-2"></i>ระบบจัดการครุภัณฑ์</a>
        <div class="ms-auto text-light">
            <span class="me-3"><i class="fa-regular fa-circle-user me-1"></i> <?php echo htmlspecialchars($useraccountName); ?></span>
            <a href="main.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-arrow-left"></i> กลับสู่หน้าหลัก</a>
        </div>
    </div>
</nav>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow border-0">
                <div class="card-header py-3">
                    <h5 class="mb-0"><i class="fa-solid fa-rotate-left me-2"></i> แบบฟอร์มทำรายการคืนครุภัณฑ์</h5>
                </div>
                <div class="card-body p-4">
                    
                    <?php if (isset($errorMsg)): ?>
                        <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($errorMsg); ?></div>
                    <?php endif; ?>

                    <div class="alert alert-warning text-dark">
                        <strong>ครุภัณฑ์ที่ต้องการส่งคืน:</strong> <br>
                        รหัส: <code><?php echo htmlspecialchars($borrowData['DA_Number']); ?></code> <br>
                        ชื่อรายการ: <strong><?php echo htmlspecialchars($borrowData['DA_ListName']); ?></strong>
                        <?php if(!empty($borrowData['DA_Brand'])) echo " (ยี่ห้อ: ".htmlspecialchars($borrowData['DA_Brand']).")"; ?>
                        <br>
                        <small class="text-muted"><i class="fa-regular fa-calendar me-1"></i> ยืมไปเมื่อ: <?php echo htmlspecialchars($borrowData['Date_borrow']); ?></small>
                    </div>

                    <form method="POST" action="">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">วันที่ส่งคืนจริง</label>
                                <input type="date" class="form-control" name="date_Back" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="col-12 mt-4">
                                <h6 class="border-bottom pb-2 text-warning fw-bold text-dark">ข้อมูลผู้ส่งคืน</h6>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">ชื่อ - นามสกุลผู้ส่งคืน</label>
                                <input type="text" class="form-control" name="name_Back" value="<?php echo htmlspecialchars($userData['useraccountName'] ?? $useraccountName); ?>" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">ตำแหน่ง</label>
                                <input type="text" class="form-control" name="rank_Back" value="<?php echo htmlspecialchars($userData['userRank'] ?? ''); ?>">
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">รายละเอียด/หมายเหตุสภาพครุภัณฑ์</label>
                                <textarea class="form-control" name="detail_Back" rows="3" placeholder="ระบุสภาพของครุภัณฑ์ตอนส่งคืน เช่น สภาพสมบูรณ์, มีรอยขีดข่วน..." required></textarea>
                            </div>

                            <div class="col-12 mt-4 text-center">
                                <a href="main.php" class="btn btn-secondary px-4 me-2">ยกเลิก</a>
                                <button type="submit" class="btn btn-warning px-5 fw-bold"><i class="fa-solid fa-check-circle me-1"></i> ยืนยันการส่งคืน</button>
                            </div>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
