<?php
session_start();

// 1. ตรวจสอบ Security Check สิทธิ์การเข้าถึง (ต้องเป็น User ทั่วไปเท่านั้น)
// เช็คว่าล็อกอินหรือยัง และ useraccountType ต้องเป็น 'UT00000002' (User)
// หากเป็นแบบอื่น (เช่น Admin หรือยังไม่ได้ล็อกอิน) ให้เตะกลับไปหน้า Login
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000002') {
    header("Location: ../index.php");
    exit();
}

// 2. โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO แบบ Dev ย้อนกลับไปโฟลเดอร์หลัก
require_once '../control/connectPDO_dev.php';

// ป้องกัน Error ด้วยการใช้ isset() เช็คก่อนดึงค่าจาก Session
$useraccountName = isset($_SESSION['Sename']) ? $_SESSION['Sename'] : 'ไม่ทราบชื่อ';
$useraccountID   = isset($_SESSION['SeUser']) ? $_SESSION['SeUser'] : '';

// 3. ดึงข้อมูลสถิติของ User ส่วนตัวโดยใช้ PDO Prepared Statements
$borrowCount = 0;
$overdueCount = 0;
$todayDate = date('Y-m-d'); // ดึงวันที่ปัจจุบันผ่าน PHP แทนเพื่อความเข้ากันได้กับทั้ง MySQL และ SQLite

try {
    // จำนวนครุภัณฑ์ที่กำลังยืม (ที่ยังไม่คืน)
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM borrow_back WHERE useraccountID_borrow = :uid AND (Date_back IS NULL OR Date_back = '')");
    $stmt->execute([':uid' => $useraccountID]);
    $borrowCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // จำนวนครุภัณฑ์ที่เลยกำหนดคืน (Overdue)
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM borrow_back WHERE useraccountID_borrow = :uid AND (Date_back IS NULL OR Date_back = '') AND Deadline_borrow < :today");
    $stmt->execute([
        ':uid' => $useraccountID,
        ':today' => $todayDate
    ]);
    $overdueCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
} catch (PDOException $e) {
    // ในโปรดักชันควรเก็บ log ไว้ ไม่ควรแสดง error ตรงๆ
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
        .bg-danger-grad { background: linear-gradient(45deg, #e74a3b, #be2617); }
    </style>
</head>
<body>

<!-- แถบนำทาง (Navbar จำลองสำหรับ User) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="#"><i class="fa-solid fa-user-astronaut me-2"></i>User Panel</a>
        <div class="ms-auto text-light d-flex align-items-center">
            <span class="me-3"><i class="fa-solid fa-user me-1"></i> ผู้ใช้งาน: <?php echo htmlspecialchars($useraccountName); ?></span>
            <!-- ลิงก์ออกจากระบบ ให้วิ่งไปหาไฟล์ logout.php ที่โฟลเดอร์หลัก -->
            <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ</a>
        </div>
    </div>
</nav>

<div class="container mt-4 mb-5">
    <h4 class="mb-4 text-secondary fw-bold"><i class="fa-solid fa-id-card me-2"></i>ภาพรวมผู้ใช้งาน (User Dashboard)</h4>
    
    <!-- Widget ส่วนบนแสดงผลสถิติส่วนตัว -->
    <div class="row g-4 mb-5">
        <div class="col-md-6">
            <div class="card widget-card bg-primary-grad shadow-sm h-100 p-4">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-light mb-2">ครุภัณฑ์ที่กำลังยืม</h5>
                        <h1 class="fw-bold mb-0"><?php echo $borrowCount; ?> <span class="fs-5 fw-normal">รายการ</span></h1>
                    </div>
                    <i class="fa-solid fa-hand-holding-hand widget-icon"></i>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card widget-card bg-danger-grad shadow-sm h-100 p-4">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-light mb-2">ครุภัณฑ์เลยกำหนดคืน (Overdue)</h5>
                        <h1 class="fw-bold mb-0"><?php echo $overdueCount; ?> <span class="fs-5 fw-normal">รายการ</span></h1>
                    </div>
                    <i class="fa-solid fa-triangle-exclamation widget-icon"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ส่วน: ตารางรายการที่กำลังยืม -->
    <div class="card shadow-sm border-0 rounded-4 mb-5">
        <div class="card-header bg-white border-0 pt-4 pb-2">
            <h5 class="fw-bold text-warning mb-0">
                <i class="fa-solid fa-clock-rotate-left me-2"></i> รายการครุภัณฑ์ที่คุณกำลังยืม (รอส่งคืน)
            </h5>
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
                            // ดึงข้อมูลการยืมของคนที่ล็อกอินอยู่ ที่ยังไม่ได้คืน (Date_back ว่าง หรือ null)
                            $sql_Borrowed = "SELECT b.IDborrow_back, b.DA_ID, b.Date_borrow, b.Deadline_borrow, 
                                                    da.DA_Number, da.DA_ListName 
                                             FROM borrow_back b
                                             JOIN durable_articles da ON b.DA_ID = da.DA_ID
                                             WHERE b.useraccountID_borrow = :uid 
                                             AND (b.Date_back IS NULL OR b.Date_back = '')
                                             ORDER BY b.Deadline_borrow ASC";
                            $stmtB = $pdo->prepare($sql_Borrowed);
                            $stmtB->execute([':uid' => $useraccountID]);
                            $borrowedItems = $stmtB->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (count($borrowedItems) > 0) {
                                $order = 1;
                                foreach ($borrowedItems as $row) {
                                    $today = date('Y-m-d');
                                    $isOverdue = ($row['Deadline_borrow'] < $today);
                                    $deadlineBadge = $isOverdue ? "<span class='badge bg-danger'>เลยกำหนด</span>" : "<span class='badge bg-success'>ปกติ</span>";

                                    echo "<tr>";
                                    echo "<td>{$order}</td>";
                                    echo "<td><span class='badge bg-light text-dark border'>" . htmlspecialchars($row['DA_Number']) . "</span></td>";
                                    echo "<td><span class='fw-bold text-secondary'>" . htmlspecialchars($row['DA_ListName']) . "</span></td>";
                                    echo "<td>" . htmlspecialchars($row['Date_borrow']) . "</td>";
                                    echo "<td>" . htmlspecialchars($row['Deadline_borrow']) . " {$deadlineBadge}</td>";
                                    
                                    // ลิงก์ไปยังหน้าแบบฟอร์มคืนครุภัณฑ์ (V2)
                                    echo "<td class='text-center'>
                                            <a href='return_form.php?IDborrow=" . htmlspecialchars($row['IDborrow_back']) . "' class='btn btn-sm btn-warning rounded-pill px-3 shadow-sm'>
                                                <i class='fa-solid fa-rotate-left me-1'></i> คืนครุภัณฑ์
                                            </a>
                                          </td>";
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
                        try {
                            // ดึงเฉพาะครุภัณฑ์ที่มี statusID = 's0001' (พร้อมใช้งาน) โดยใช้ PDO
                            $sql_Available = "SELECT durable_articles.DA_ID, durable_articles.DA_Number, durable_articles.DA_ListName, 
                                                     classification.classificationName, building.buildingName, building_classroom.classroomName 
                                              FROM durable_articles 
                                              LEFT JOIN classification ON classification.classificationID = durable_articles.classificationID 
                                              LEFT JOIN building ON building.buildingID = durable_articles.buildingID
                                              LEFT JOIN building_classroom ON building_classroom.classroomID = durable_articles.classroomID
                                              WHERE durable_articles.statusID = 's0001'";
                            
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
                                    
                                    // ลิงก์ไปยังหน้า page_BorrowDA.php (ชี้ Path ย้อนกลับไปโฟลเดอร์หลัก)
                                    echo "<td class='text-center'>
                                            <a href='borrow_form.php?DAID=" . htmlspecialchars($row['DA_ID']) . "' class='btn btn-sm btn-primary rounded-pill px-3 shadow-sm'>
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

<script>
    $(document).ready(function() {
        // เปิดใช้งานไลบรารี DataTables สำหรับการค้นหาและแบ่งหน้า
        var table = $('#availableItemsTable').DataTable({
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
