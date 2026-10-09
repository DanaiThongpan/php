<?php
session_start();
// ตรวจสอบสิทธิ์ (ต้องเป็น Admin)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}
$useraccountName = $_SESSION['Sename'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>นำเข้าข้อมูลครุภัณฑ์ด้วย CSV</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="main.php"><i class="fa-solid fa-screwdriver-wrench text-warning me-2"></i>Admin Panel</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="main.php"><i class="fa-solid fa-house"></i> หน้าแรก (Dashboard)</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="manage_data.php"><i class="fa-solid fa-database"></i> จัดการข้อมูลระบบ</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="import_csv.php"><i class="fa-solid fa-file-csv"></i> นำเข้าข้อมูล (CSV)</a>
                </li>
            </ul>
            <div class="ms-auto text-light d-flex align-items-center">
                <span class="me-3"><i class="fa-solid fa-user-shield me-1"></i> ยินดีต้อนรับ, <?php echo htmlspecialchars($useraccountName); ?></span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ</a>
            </div>
        </div>
    </div>
</nav>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fa-solid fa-file-csv me-2"></i>นำเข้าข้อมูลครุภัณฑ์ (CSV Import)</h5>
                </div>
                <div class="card-body p-4">
                    
                    <!-- ส่วนดาวน์โหลด Template -->
                    <div class="alert alert-info">
                        <strong><i class="fa-solid fa-lightbulb text-warning"></i> คำแนะนำ:</strong> 
                        กรุณาเตรียมไฟล์ข้อมูลของท่านให้ตรงกับรูปแบบต้นแบบ (Template) เพื่อป้องกันข้อผิดพลาดในการนำเข้า 
                        <br><br>
                        <a href="template_durable_articles.csv" class="btn btn-sm btn-info text-white fw-bold" download>
                            <i class="fa-solid fa-download"></i> ดาวน์โหลดไฟล์ CSV ต้นแบบ
                        </a>
                    </div>

                    <!-- ฟอร์มอัปโหลดไฟล์ -->
                    <form action="process_import.php" method="POST" enctype="multipart/form-data" class="mt-4">
                        <div class="mb-3">
                            <label for="csvFile" class="form-label fw-bold">เลือกไฟล์ CSV ข้อมูลครุภัณฑ์</label>
                            <input class="form-control form-control-lg" type="file" id="csvFile" name="csvFile" accept=".csv" required>
                            <div class="form-text text-muted">รองรับเฉพาะไฟล์นามสกุล .csv เท่านั้น (ขนาดไม่เกิน 5MB)</div>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success btn-lg"><i class="fa-solid fa-cloud-arrow-up"></i> เริ่มการนำเข้าข้อมูล</button>
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
