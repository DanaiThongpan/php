<?php
session_start();

// 1. ตรวจสอบ Security Check สิทธิ์การเข้าถึง (ต้องเป็น Admin เท่านั้น)
// เช็คว่าล็อกอินหรือยัง และ useraccountType ต้องเป็น 'UT00000001' (Admin)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000001') {
    // หากไม่ใช่ Admin ให้เตะกลับไปหน้า Login
    header("Location: ../index.php");
    exit();
}

// 2. โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO แบบ Dev ย้อนกลับไปโฟลเดอร์หลัก
require_once '../control/connectPDO_dev.php';

$useraccountName = $_SESSION['Sename'];

// 3. ดึงสถิติรวมสำหรับ Admin โดยใช้ PDO
$countDA = 0;
$countClass = 0;
$countActive = 0;
$countDisActive = 0;

try {
    // นับจำนวนครุภัณฑ์ทั้งหมด
    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM durable_articles");
    $countDA = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // นับจำนวนหมวดหมู่
    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM classification");
    $countClass = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // นับสถานะพร้อมใช้งาน (s0001)
    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM durable_articles WHERE statusID = 's0001'");
    $countActive = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // นับสถานะไม่พร้อมใช้งาน (s0002)
    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM durable_articles WHERE statusID = 's0002'");
    $countDisActive = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (PDOException $e) {
    $errorMsg = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ระบบจัดการครุภัณฑ์</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fc; }
        .widget-card {
            border: none;
            border-radius: 10px;
            color: white;
            transition: transform 0.3s ease;
        }
        .widget-card:hover { transform: translateY(-5px); }
        .widget-icon { font-size: 3rem; opacity: 0.5; }
        
        .bg-primary-grad { background: linear-gradient(45deg, #4e73df, #224abe); }
        .bg-warning-grad { background: linear-gradient(45deg, #f6c23e, #dda20a); }
        .bg-success-grad { background: linear-gradient(45deg, #1cc88a, #13855c); }
        .bg-danger-grad { background: linear-gradient(45deg, #e74a3b, #be2617); }
    </style>
</head>
<body>

<!-- แถบนำทาง (Navbar จำลองสำหรับ Admin) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="main.php"><i class="fa-solid fa-screwdriver-wrench text-warning me-2"></i>Admin Panel</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link active" href="main.php"><i class="fa-solid fa-house"></i> หน้าแรก (Dashboard)</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="manage_data.php"><i class="fa-solid fa-database"></i> จัดการข้อมูลระบบ</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="import_csv.php"><i class="fa-solid fa-file-csv"></i> นำเข้าข้อมูล (CSV)</a>
                </li>
            </ul>
            <div class="ms-auto text-light d-flex align-items-center">
                <span class="me-3"><i class="fa-solid fa-user-shield me-1"></i> ยินดีต้อนรับ, <?php echo htmlspecialchars($useraccountName); ?></span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ</a>
            </div>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="text-secondary fw-bold mb-0"><i class="fa-solid fa-chart-pie me-2"></i>ภาพรวมระบบ (Admin Dashboard)</h4>
        <a href="manage_data.php" class="btn btn-primary rounded-pill shadow-sm">
            <i class="fa-solid fa-database me-1"></i> จัดการข้อมูลระบบ
        </a>
    </div>
    
    <!-- Widget แสดงผลสถิติ -->
    <div class="row g-4">
        <div class="col-md-6 col-xl-3">
            <div class="card widget-card bg-primary-grad shadow-sm h-100 p-3">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-light mb-1 text-uppercase">ครุภัณฑ์ทั้งหมด</h6>
                        <h2 class="fw-bold mb-0"><?php echo $countDA; ?></h2>
                    </div>
                    <i class="fa-solid fa-boxes-stacked widget-icon"></i>
                </div>
            </div>
        </div>
        
        <div class="col-md-6 col-xl-3">
            <div class="card widget-card bg-warning-grad shadow-sm h-100 p-3">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-light mb-1 text-uppercase">หมวดหมู่ครุภัณฑ์</h6>
                        <h2 class="fw-bold mb-0"><?php echo $countClass; ?></h2>
                    </div>
                    <i class="fa-solid fa-tags widget-icon"></i>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card widget-card bg-success-grad shadow-sm h-100 p-3">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-light mb-1 text-uppercase">สถานะพร้อมใช้</h6>
                        <h2 class="fw-bold mb-0"><?php echo $countActive; ?></h2>
                    </div>
                    <i class="fa-solid fa-check-circle widget-icon"></i>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card widget-card bg-danger-grad shadow-sm h-100 p-3">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-light mb-1 text-uppercase">สถานะไม่พร้อมใช้</h6>
                        <h2 class="fw-bold mb-0"><?php echo $countDisActive; ?></h2>
                    </div>
                    <i class="fa-solid fa-wrench widget-icon"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mt-5">
        <div class="col-12 text-center text-muted">
            <p><i class="fa-solid fa-info-circle me-1"></i>หน้าต่างนี้แสดงเฉพาะผู้ดูแลระบบ (Admin) เท่านั้น</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
