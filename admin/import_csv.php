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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>นำเข้าข้อมูลครุภัณฑ์ด้วย CSV | Asset Management</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom Style เล็กน้อยให้ UI ดูพรีเมียมขึ้น -->
    <style>
        body { background-color: #f4f6f9; }
        .card { border-radius: 12px; }
        .card-header { border-top-left-radius: 12px !important; border-top-right-radius: 12px !important; }
        .alert-custom { border-left: 5px solid #0dcaf0; }
        .table-preview th { font-size: 0.85rem; font-weight: 600; vertical-align: middle; }
        .table-preview td { font-size: 0.85rem; vertical-align: middle; color: #555; }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="main.php"><i class="fa-solid fa-screwdriver-wrench text-warning me-2"></i>Admin Panel</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="main.php"><i class="fa-solid fa-house"></i> หน้าแรก</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="manage_data.php"><i class="fa-solid fa-database"></i> จัดการข้อมูล</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active fw-bold" href="import_csv.php"><i class="fa-solid fa-file-csv text-info"></i> นำเข้าข้อมูล (CSV)</a>
                </li>
            </ul>
            <div class="ms-auto text-light d-flex align-items-center">
                <span class="me-3"><i class="fa-solid fa-circle-user text-success me-1"></i> <?php echo htmlspecialchars($useraccountName); ?></span>
                <a href="../logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3"><i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ</a>
            </div>
        </div>
    </div>
</nav>

<!-- Main Content -->
<div class="container mt-5 mb-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow border-0">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="fa-solid fa-cloud-arrow-up me-2"></i> ระบบนำเข้าข้อมูลครุภัณฑ์ (CSV Import)</h5>
                </div>
                <div class="card-body p-4 p-md-5">
                    
                    <!-- กล่องคำแนะนำและตัวอย่างข้อมูล -->
                    <div class="alert alert-light shadow-sm alert-custom mb-4" role="alert">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="alert-heading fw-bold text-dark mb-0">
                                <i class="fa-solid fa-circle-info text-info me-2"></i> คำแนะนำและรูปแบบข้อมูล
                            </h6>
                            <a href="template_durable_articles.csv" class="btn btn-info btn-sm text-white fw-bold rounded-pill shadow-sm" download>
                                <i class="fa-solid fa-download me-1"></i> โหลดไฟล์ต้นแบบ (Template)
                            </a>
                        </div>
                        <p class="text-muted small mb-3">กรุณาจัดเตรียมไฟล์ข้อมูลของท่านให้อยู่ในรูปแบบ <code>.csv</code> (UTF-8) โดยมีหัวคอลัมน์ภาษาอังกฤษเรียงตามตัวอย่างด้านล่างนี้ เพื่อให้ระบบสามารถจับคู่ข้อมูลได้อย่างถูกต้อง</p>
                        
                        <!-- ตารางตัวอย่างข้อมูล (Data Preview) -->
                        <div class="table-responsive rounded border">
                            <table class="table table-hover table-striped table-preview text-center bg-white mb-0">
                                <thead class="table-light text-primary">
                                    <tr>
                                        <th>DA_Number<br><span class="text-muted fw-normal">(รหัสครุภัณฑ์)</span></th>
                                        <th>DA_ListName<br><span class="text-muted fw-normal">(ชื่อรายการ)</span></th>
                                        <th>DA_Brand<br><span class="text-muted fw-normal">(ยี่ห้อ)</span></th>
                                        <th>classificationID<br><span class="badge bg-success-subtle text-success fw-normal">เว้นว่างได้ (ตรวจจับอัตโนมัติ)</span></th>
                                        <th>buildingID<br><span class="text-muted fw-normal">(รหัสอาคาร)</span></th>
                                        <th>classroomID<br><span class="text-muted fw-normal">(รหัสห้อง)</span></th>
                                        <th>DA_Price<br><span class="text-muted fw-normal">(ราคา)</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="font-monospace text-primary">1206-001</td>
                                        <td>คอมพิวเตอร์โน้ตบุ๊ก</td>
                                        <td>ASUS</td>
                                        <td>c0000000001</td>
                                        <td>ACB</td>
                                        <td>CLB3100</td>
                                        <td class="text-end">25,000.00</td>
                                    </tr>
                                    <tr>
                                        <td class="font-monospace text-primary">1206-002</td>
                                        <td>โปรเจคเตอร์แสดงภาพ</td>
                                        <td>Epson</td>
                                        <td>c0000000002</td>
                                        <td>CLB3</td>
                                        <td>CLB3000</td>
                                        <td class="text-end">15,900.00</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="form-text mt-2 text-danger fw-bold small">
                            <i class="fa-solid fa-triangle-exclamation"></i> ข้อควรระวัง: แถวแรกของไฟล์ CSV จะต้องเป็นชื่อคอลัมน์เสมอ (ห้ามเว้นว่าง หรือสลับตำแหน่ง)
                        </div>
                    </div>

                    <!-- ฟอร์มอัปโหลดไฟล์ -->
                    <form action="process_import.php" method="POST" enctype="multipart/form-data" class="border p-4 rounded-3 bg-white shadow-sm">
                        <div class="mb-4">
                            <label for="csvFile" class="form-label fw-bold text-dark fs-5 mb-3">
                                <i class="fa-solid fa-file-excel text-success me-2"></i>เลือกไฟล์ CSV ที่ต้องการนำเข้า
                            </label>
                            <input class="form-control form-control-lg border-primary" type="file" id="csvFile" name="csvFile" accept=".csv" required>
                            <div class="form-text mt-2 text-muted">
                                <i class="fa-solid fa-check text-success"></i> รองรับเฉพาะนามสกุล .csv <br>
                                <i class="fa-solid fa-check text-success"></i> ขนาดไฟล์สูงสุดไม่เกิน 5MB
                            </div>
                        </div>
                        <div class="text-center mt-4">
                            <button type="submit" class="btn btn-primary btn-lg px-5 rounded-pill shadow">
                                <i class="fa-solid fa-upload me-2"></i> ยืนยันการนำเข้าข้อมูล
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>