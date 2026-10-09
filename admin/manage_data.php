<?php
session_start();

// ตรวจสอบสิทธิ์การเข้าถึง (ต้องเป็น Admin เท่านั้น)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

require_once '../control/connectPDO_dev.php';

$useraccountName = $_SESSION['Sename'];

// ดึงข้อมูลผู้ใช้งานทั้งหมด
try {
    $stmtUser = $pdo->query("SELECT * FROM useraccount ORDER BY useraccountType ASC, useraccountName ASC");
    $users = $stmtUser->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// ดึงข้อมูลครุภัณฑ์ทั้งหมด (พร้อมชื่อหมวดหมู่)
try {
    $sql_DA = "SELECT durable_articles.*, classification.classificationName 
               FROM durable_articles 
               LEFT JOIN classification ON classification.classificationID = durable_articles.classificationID";
    $stmtDA = $pdo->query($sql_DA);
    $durableArticles = $stmtDA->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $durableArticles = [];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข้อมูลระบบ (Data Management)</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" />
    <style>
        body { background-color: #f8f9fc; }
        .nav-tabs .nav-link { font-weight: bold; color: #4e73df; }
        .nav-tabs .nav-link.active { color: #495057; border-bottom-color: transparent; }
    </style>
</head>
<body>

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
                    <a class="nav-link active" href="manage_data.php"><i class="fa-solid fa-database"></i> จัดการข้อมูลระบบ</a>
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

<div class="container mt-4 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="text-secondary fw-bold"><i class="fa-solid fa-database me-2"></i>ระบบจัดการข้อมูล (Data Management)</h4>
    </div>

    <!-- แถบ Tabs สำหรับเลือกดูข้อมูล -->
    <ul class="nav nav-tabs" id="myTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="user-tab" data-bs-toggle="tab" data-bs-target="#user-tab-pane" type="button" role="tab"><i class="fa-solid fa-users me-1"></i> ข้อมูลผู้ใช้งาน</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="da-tab" data-bs-toggle="tab" data-bs-target="#da-tab-pane" type="button" role="tab"><i class="fa-solid fa-boxes-stacked me-1"></i> ข้อมูลครุภัณฑ์</button>
        </li>
    </ul>

    <!-- เนื้อหาแต่ละ Tab -->
    <div class="tab-content bg-white border border-top-0 p-4 rounded-bottom shadow-sm" id="myTabContent">
        
        <!-- Tab 1: ข้อมูลผู้ใช้งาน -->
        <div class="tab-pane fade show active" id="user-tab-pane" role="tabpanel" tabindex="0">
            <div class="d-flex justify-content-end mb-3">
                <a href="../register.php" class="btn btn-success btn-sm"><i class="fa-solid fa-plus me-1"></i> เพิ่มผู้ใช้งานใหม่</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-bordered align-middle" id="userTable">
                    <thead class="table-light text-center">
                        <tr>
                            <th>ไอดี (Username)</th>
                            <th>ชื่อ - นามสกุล</th>
                            <th>ตำแหน่ง</th>
                            <th>สังกัด</th>
                            <th>ระดับสิทธิ์</th>
                            <th>สถานะ</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($users as $u): ?>
                        <tr>
                            <td class="text-center"><?php echo htmlspecialchars($u['useraccountID']); ?></td>
                            <td><?php echo htmlspecialchars($u['useraccountName']); ?></td>
                            <td><?php echo htmlspecialchars($u['userRank'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($u['userDepartment'] ?? '-'); ?></td>
                            <td class="text-center">
                                <?php if($u['useraccountType'] === 'UT00000001'): ?>
                                    <span class="badge bg-danger"><i class="fa-solid fa-user-shield"></i> Admin</span>
                                <?php else: ?>
                                    <span class="badge bg-primary"><i class="fa-solid fa-user"></i> User</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php echo ($u['userActive'] == '1') ? '<span class="text-success"><i class="fa-solid fa-circle-check"></i> ใช้งานได้</span>' : '<span class="text-danger"><i class="fa-solid fa-circle-xmark"></i> ระงับใช้งาน</span>'; ?>
                            </td>
                            <td class="text-center">
                                <a href="edit_user.php?uid=<?php echo urlencode($u['useraccountID']); ?>" class="btn btn-warning btn-sm" title="แก้ไขข้อมูลผู้ใช้"><i class="fa-solid fa-pen-to-square"></i></a>
                                <button class="btn btn-danger btn-sm" title="ลบ (จำลอง)"><i class="fa-solid fa-trash"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab 2: ข้อมูลครุภัณฑ์ -->
        <div class="tab-pane fade" id="da-tab-pane" role="tabpanel" tabindex="0">
            <div class="d-flex justify-content-end mb-3 gap-2">
                <a href="import_csv.php" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-file-csv me-1"></i> นำเข้าผ่าน CSV</a>
                <button class="btn btn-success btn-sm"><i class="fa-solid fa-plus me-1"></i> เพิ่มครุภัณฑ์ใหม่ (จำลอง)</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-bordered align-middle" id="daTable">
                    <thead class="table-light text-center">
                        <tr>
                            <th>รหัสอ้างอิง (ID)</th>
                            <th>หมายเลขครุภัณฑ์</th>
                            <th>ชื่อครุภัณฑ์</th>
                            <th>หมวดหมู่</th>
                            <th>สถานะ</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($durableArticles as $da): ?>
                        <tr>
                            <td class="text-center"><?php echo htmlspecialchars($da['DA_ID']); ?></td>
                            <td><?php echo htmlspecialchars($da['DA_Number']); ?></td>
                            <td><?php echo htmlspecialchars($da['DA_ListName']); ?></td>
                            <td><?php echo htmlspecialchars($da['classificationName'] ?? 'ไม่ระบุ'); ?></td>
                            <td class="text-center">
                                <?php if($da['statusID'] === 's0001'): ?>
                                    <span class="badge bg-success">พร้อมใช้งาน</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">ไม่พร้อมใช้งาน</span>
                                <?php endif; ?>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <a href="print_qr.php?da_id=<?php echo urlencode($da['DA_ID']); ?>" target="_blank" class="btn btn-dark btn-sm" title="พิมพ์ QR Code แปะเครื่อง"><i class="fa-solid fa-qrcode"></i></a>
                                    <a href="edit_durable_article.php?da_id=<?php echo urlencode($da['DA_ID']); ?>" class="btn btn-warning btn-sm" title="แก้ไขข้อมูล"><i class="fa-solid fa-pen-to-square"></i></a>
                                    <button class="btn btn-danger btn-sm" title="ลบ (จำลอง)"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
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
        // เปิดใช้งาน DataTables สำหรับตารางผู้ใช้งาน
        $('#userTable').DataTable({
            "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/th.json" },
            "pageLength": 10
        });

        // เปิดใช้งาน DataTables สำหรับตารางครุภัณฑ์
        $('#daTable').DataTable({
            "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/th.json" },
            "pageLength": 10
        });
    });
</script>
</body>
</html>
