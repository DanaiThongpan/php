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

// ดึงข้อมูลผู้ใช้งานสำหรับเติมฟอร์มอัตโนมัติ (Auto-fill)
$stmtUser = $pdo->prepare("SELECT * FROM useraccount WHERE useraccountID = :uid");
$stmtUser->execute([':uid' => $useraccountID]);
$userData = $stmtUser->fetch();

// รับค่า DAID ของครุภัณฑ์ที่ต้องการยืม
$da_id = $_GET['DAID'] ?? '';

if (empty($da_id)) {
    echo "<script>alert('ไม่พบรหัสครุภัณฑ์'); window.location.href='main.php';</script>";
    exit();
}

// ดึงข้อมูลครุภัณฑ์มาแสดง
$stmtDA = $pdo->prepare("SELECT * FROM durable_articles WHERE DA_ID = :daid AND statusID = 's0001'");
$stmtDA->execute([':daid' => $da_id]);
$daData = $stmtDA->fetch();

if (!$daData) {
    echo "<script>alert('ไม่พบครุภัณฑ์นี้ หรือครุภัณฑ์ไม่พร้อมให้ยืม'); window.location.href='main.php';</script>";
    exit();
}

// เมื่อกดบันทึกแบบฟอร์ม (POST Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date_Borrow = $_POST['date_Borrow'];
    $name_Borrow = $_POST['name_Borrow'];
    $rank_Borrow = $_POST['rank_Borrow'];
    $belongto_Borrow = $_POST['belongto_Borrow'];
    $detail_Borrow = $_POST['detail_Borrow'];
    $deadline_Borrow = $_POST['deadline_Borrow'];
    $timeStampDateNow = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        // 1. บันทึกข้อมูลลงตาราง borrow_back
        // IDborrow_back สำหรับ SQLite จะเป็น Autoincrement ถ้าไม่ใส่ค่า หรือปล่อยว่างไว้
        // แต่เพื่อความแน่นอน เนื่องจากตารางเก่าอาจไม่มี AUTOINCREMENT เราใช้รหัสสุ่มผสมเวลา
        $borrow_id = "B" . time() . rand(10, 99);
        
        $sql_Insert = "INSERT INTO borrow_back 
                       (IDborrow_back, DA_ID, Date_borrow, Name_borrow, Rank_borrow, Belongto_borrow, 
                        Detail_borrow, Deadline_borrow, useraccountID_borrow, timestamp_borrow) 
                       VALUES 
                       (:id, :da_id, :date_borrow, :name_borrow, :rank_borrow, :belongto, 
                        :detail, :deadline, :uid, :timestamp)";
        
        $stmtInsert = $pdo->prepare($sql_Insert);
        $stmtInsert->execute([
            ':id' => $borrow_id,
            ':da_id' => $da_id,
            ':date_borrow' => $date_Borrow,
            ':name_borrow' => $name_Borrow,
            ':rank_borrow' => $rank_Borrow,
            ':belongto' => $belongto_Borrow,
            ':detail' => $detail_Borrow,
            ':deadline' => $deadline_Borrow,
            ':uid' => $useraccountID,
            ':timestamp' => $timeStampDateNow
        ]);

        // 2. อัปเดตสถานะครุภัณฑ์เป็น 'ถูกยืม' (s0003)
        $sqlUpdate = "UPDATE durable_articles SET statusID = 's0003' WHERE DA_ID = :da_id";
        $stmtUpdate = $pdo->prepare($sqlUpdate);
        $stmtUpdate->execute([':da_id' => $da_id]);

        $pdo->commit();
        echo "<script>alert('บันทึกการยืมครุภัณฑ์สำเร็จ!'); window.location.href='main.php';</script>";
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
    <title>ทำรายการยืมครุภัณฑ์ (V2)</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; }
        .card-header { background-color: #4e73df; color: white; font-weight: bold; }
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
                    <h5 class="mb-0"><i class="fa-solid fa-hand-holding-hand me-2"></i> แบบฟอร์มทำรายการยืมครุภัณฑ์</h5>
                </div>
                <div class="card-body p-4">
                    
                    <?php if (isset($errorMsg)): ?>
                        <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($errorMsg); ?></div>
                    <?php endif; ?>

                    <div class="alert alert-info">
                        <strong>ครุภัณฑ์ที่ต้องการยืม:</strong> <br>
                        รหัส: <code><?php echo htmlspecialchars($daData['DA_Number']); ?></code> <br>
                        ชื่อรายการ: <strong><?php echo htmlspecialchars($daData['DA_ListName']); ?></strong>
                        <?php if(!empty($daData['DA_Brand'])) echo " (ยี่ห้อ: ".htmlspecialchars($daData['DA_Brand']).")"; ?>
                    </div>

                    <form method="POST" action="">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">วันที่ขอยืม</label>
                                <input type="date" class="form-control" name="date_Borrow" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">วันที่คาดว่าจะคืน (Deadline)</label>
                                <input type="date" class="form-control" name="deadline_Borrow" required>
                            </div>

                            <div class="col-12 mt-4">
                                <h6 class="border-bottom pb-2 text-primary fw-bold">ข้อมูลผู้ขอยืม</h6>
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">ชื่อ - นามสกุลผู้ยืม</label>
                                <input type="text" class="form-control" name="name_Borrow" value="<?php echo htmlspecialchars($userData['useraccountName'] ?? $useraccountName); ?>" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">ตำแหน่ง</label>
                                <input type="text" class="form-control" name="rank_Borrow" value="<?php echo htmlspecialchars($userData['userRank'] ?? ''); ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">สังกัด/แผนก</label>
                                <input type="text" class="form-control" name="belongto_Borrow" value="<?php echo htmlspecialchars($userData['userDepartment'] ?? ''); ?>">
                            </div>

                            <div class="col-md-12">
                                <label class="form-label">รายละเอียด/เหตุผลการยืม</label>
                                <textarea class="form-control" name="detail_Borrow" rows="3" placeholder="ระบุเหตุผล หรือสถานที่ที่นำไปใช้งาน..." required></textarea>
                            </div>

                            <div class="col-12 mt-4 text-center">
                                <a href="main.php" class="btn btn-secondary px-4 me-2">ยกเลิก</a>
                                <button type="submit" class="btn btn-primary px-5"><i class="fa-solid fa-floppy-disk me-1"></i> บันทึกการยืม</button>
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
