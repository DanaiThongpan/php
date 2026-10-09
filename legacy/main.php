<?php
include("../control/connectDB.php");
session_start();

// ตรวจสอบการล็อกอิน
if ($_SESSION['Selogin'] <> 1) {
    echo "<meta http-equiv='refresh' content='0;url=index.php'>";
    exit();
}

$useraccountID =  $_SESSION['SeUser'];
$useraccountName =  $_SESSION['Sename'];

// ดึงประเภทผู้ใช้งานจาก session (เช่น UT00000001 = Admin, UT00000002 = User)
$userRole = $_SESSION['useraccountType'] ?? 'UT00000002'; 
$isAdmin = ($userRole === 'UT00000001');

?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - ระบบครุภัณฑ์</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../css/style.css" />
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- DataTables Bootstrap 5 CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" />
    
    <style>
        /* ตกแต่ง Widget Card ให้ดูพรีเมียมขึ้น */
        .widget-card {
            border: none;
            border-radius: 15px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            overflow: hidden;
        }
        .widget-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
        }
        .widget-icon {
            font-size: 3.5rem;
            opacity: 0.8;
        }
        .bg-gradient-primary { background: linear-gradient(45deg, #4e73df, #224abe); }
        .bg-gradient-success { background: linear-gradient(45deg, #1cc88a, #13855c); }
        .bg-gradient-warning { background: linear-gradient(45deg, #f6c23e, #dda20a); }
        .bg-gradient-danger { background: linear-gradient(45deg, #e74a3b, #be2617); }
    </style>
</head>

<body class="bg-light">
    <?php require_once("../component/nav.php"); ?>

    <div class="container mt-4 mb-5">
        <h4 class="mb-4 fw-bold text-secondary">
            <i class="fa-solid fa-gauge me-2"></i> ภาพรวมระบบ (Dashboard)
        </h4>

        <!-- พื้นที่แสดง Widget Card (ข้อมูลมาจาก AJAX) -->
        <div class="row g-4" id="dashboard-content">
            <div class="col-12 text-center text-muted">
                <div class="spinner-border text-primary" role="status">
                  <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">กำลังโหลดข้อมูล...</p>
            </div>
        </div>

        <?php if (!$isAdmin): ?>
        <!-- Section: รายการครุภัณฑ์ที่พร้อมให้ยืม (สำหรับ User) -->
        <div class="row mt-5">
            <div class="col-12">
                <div class="card shadow-sm border-0 rounded-4">
                    <div class="card-header bg-white border-0 pt-4 pb-2">
                        <h5 class="fw-bold text-primary mb-0">
                            <i class="fa-solid fa-box-open me-2"></i> รายการครุภัณฑ์ที่พร้อมให้ยืม
                        </h5>
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
                                    /*
                                     * โครงสร้าง SQL Query:
                                     * SELECT ฟิลด์ที่จำเป็นจากตาราง durable_articles
                                     * ใช้ LEFT JOIN ไปยัง classification เพื่อเอาชื่อหมวดหมู่
                                     * ใช้ LEFT JOIN ไปยัง building และ building_classroom เพื่อเอาชื่ออาคารและห้อง
                                     * WHERE statusID = 's0001' กรองเฉพาะครุภัณฑ์ที่มีสถานะ "พร้อมใช้งาน" เท่านั้น
                                     */
                                    $sql_AvailableDA = "SELECT durable_articles.DA_ID, durable_articles.DA_Number, durable_articles.DA_ListName, 
                                                               classification.classificationName, building.buildingName, building_classroom.classroomName 
                                                        FROM `durable_articles` 
                                                        LEFT JOIN classification ON classification.classificationID = durable_articles.classificationID 
                                                        LEFT JOIN building ON building.buildingID = durable_articles.buildingID
                                                        LEFT JOIN building_classroom ON building_classroom.classroomID = durable_articles.classroomID
                                                        WHERE durable_articles.statusID = 's0001'";
                                    
                                    $result_AvailableDA = $conn->query($sql_AvailableDA);
                                    
                                    if ($result_AvailableDA && $result_AvailableDA->num_rows > 0) {
                                        $order = 1;
                                        while ($row = $result_AvailableDA->fetch_assoc()) {
                                            $location = $row['buildingName'] . ' ' . $row['classroomName'];
                                            echo "<tr>";
                                            echo "<td>{$order}</td>";
                                            echo "<td><span class='badge bg-light text-dark border'>{$row['DA_Number']}</span></td>";
                                            echo "<td><span class='fw-bold text-secondary'>{$row['DA_ListName']}</span></td>";
                                            echo "<td>{$row['classificationName']}</td>";
                                            echo "<td><i class='fa-solid fa-location-dot text-danger me-1'></i> {$location}</td>";
                                            
                                            // ปุ่มยืมครุภัณฑ์ ลิงก์ไปยังหน้า page_BorrowDA.php (ผู้ใช้จะเจอ Modal สำหรับกดยืมต่อในหน้านั้น)
                                            echo "<td class='text-center'>
                                                    <a href='page_BorrowDA.php?DAID={$row['DA_ID']}' class='btn btn-sm btn-primary rounded-pill px-3 shadow-sm'>
                                                        <i class='fa-solid fa-hand-holding-hand me-1'></i> ยืมชิ้นนี้
                                                    </a>
                                                  </td>";
                                            echo "</tr>";
                                            $order++;
                                        }
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- DataTables JS (รองรับการทำ Pagination & Search) -->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            
            // เช็คสถานะแอดมินจาก PHP
            const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;

            // ส่ง AJAX ครั้งเดียวดึงข้อมูลสถิติ
            $.ajax({
                url: "loadDashboard_CountDA.php",
                method: "POST",
                dataType: "json",
                success: function(response) {
                    let htmlContent = '';
                    if (isAdmin) {
                        htmlContent = `
                            <div class="col-md-6 col-xl-3">
                                <div class="card widget-card shadow-sm bg-gradient-primary text-white h-100 p-3">
                                    <div class="card-body d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="fw-light mb-1">จำนวนครุภัณฑ์ทั้งหมด</h6>
                                            <h2 class="fw-bold mb-0">${response.countDA}</h2>
                                        </div>
                                        <i class="fa-solid fa-boxes-stacked widget-icon"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-xl-3">
                                <div class="card widget-card shadow-sm bg-gradient-warning text-white h-100 p-3">
                                    <div class="card-body d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="fw-light mb-1">หมวดหมู่ครุภัณฑ์</h6>
                                            <h2 class="fw-bold mb-0">${response.countClass}</h2>
                                        </div>
                                        <i class="fa-solid fa-tags widget-icon"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-xl-3">
                                <div class="card widget-card shadow-sm bg-gradient-success text-white h-100 p-3">
                                    <div class="card-body d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="fw-light mb-1">สถานะพร้อมใช้</h6>
                                            <h2 class="fw-bold mb-0">${response.countStatusActive}</h2>
                                        </div>
                                        <i class="fa-solid fa-check-circle widget-icon"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 col-xl-3">
                                <div class="card widget-card shadow-sm bg-gradient-danger text-white h-100 p-3">
                                    <div class="card-body d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="fw-light mb-1">สถานะไม่พร้อมใช้</h6>
                                            <h2 class="fw-bold mb-0">${response.countStatusDisActive}</h2>
                                        </div>
                                        <i class="fa-solid fa-wrench widget-icon"></i>
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        htmlContent = `
                            <div class="col-md-6">
                                <div class="card widget-card shadow-sm bg-gradient-primary text-white h-100 p-4">
                                    <div class="card-body d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="fw-light mb-2">ครุภัณฑ์ที่กำลังยืม</h5>
                                            <h1 class="fw-bold mb-0">${response.userBorrowCount} <span class="fs-5 fw-normal">รายการ</span></h1>
                                        </div>
                                        <i class="fa-solid fa-hand-holding-hand widget-icon"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card widget-card shadow-sm bg-gradient-danger text-white h-100 p-4">
                                    <div class="card-body d-flex justify-content-between align-items-center">
                                        <div>
                                            <h5 class="fw-light mb-2">ครุภัณฑ์เลยกำหนดคืน (Overdue)</h5>
                                            <h1 class="fw-bold mb-0">${response.userOverdueCount} <span class="fs-5 fw-normal">รายการ</span></h1>
                                        </div>
                                        <i class="fa-solid fa-triangle-exclamation widget-icon"></i>
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                    $('#dashboard-content').html(htmlContent);
                },
                error: function() {
                    $('#dashboard-content').html('<div class="col-12"><div class="alert alert-danger">ไม่สามารถโหลดข้อมูล Dashboard ได้</div></div>');
                }
            });

            // เปิดใช้งาน DataTables (เฉพาะถ้าตารางถูกโหลดมาบนหน้าเว็บแล้ว)
            if ($('#availableItemsTable').length) {
                $('#availableItemsTable').DataTable({
                    "language": {
                        "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/th.json" // แปลงภาษาตารางเป็นภาษาไทย
                    },
                    "pageLength": 5, // แบ่งหน้าแสดงผลทีละ 5 รายการ (ปรับได้)
                    "ordering": true,
                    "info": true
                });
            }
        });
    </script>
</body>
</html>