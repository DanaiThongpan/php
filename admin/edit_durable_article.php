<?php
session_start();

// ตรวจสอบสิทธิ์ (ต้องเป็น Admin)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

require_once '../control/connectPDO_dev.php';

$useraccountName = $_SESSION['Sename'];
$da_id = $_GET['da_id'] ?? '';

if (empty($da_id)) {
    echo "<script>alert('ไม่พบรหัสครุภัณฑ์'); window.location.href='manage_data.php';</script>";
    exit();
}

// 1. ดึงข้อมูลครุภัณฑ์ที่ต้องการแก้ไข
$stmtDA = $pdo->prepare("SELECT * FROM durable_articles WHERE DA_ID = :da_id");
$stmtDA->execute([':da_id' => $da_id]);
$item = $stmtDA->fetch();

if (!$item) {
    echo "<script>alert('ไม่พบข้อมูลครุภัณฑ์'); window.location.href='manage_data.php';</script>";
    exit();
}

// 2. เมื่อกดปุ่มบันทึก (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $da_number = $_POST['DA_Number'];
    $da_listname = $_POST['DA_ListName'];
    $da_brand = $_POST['DA_Brand'] ?? '';
    $class_id = $_POST['classificationID'];
    $build_id = $_POST['buildingID'];
    $status_id = $_POST['statusID'];
    
    try {
        $sqlUp = "UPDATE durable_articles 
                  SET DA_Number = :num, 
                      DA_ListName = :lname, 
                      DA_Brand = :brand, 
                      classificationID = :class, 
                      buildingID = :build, 
                      statusID = :status 
                  WHERE DA_ID = :da_id";
        $stmtUp = $pdo->prepare($sqlUp);
        $stmtUp->execute([
            ':num' => $da_number,
            ':lname' => $da_listname,
            ':brand' => $da_brand,
            ':class' => $class_id,
            ':build' => $build_id,
            ':status' => $status_id,
            ':da_id' => $da_id
        ]);
        
        echo "<script>alert('✅ บันทึกการแก้ไขครุภัณฑ์สำเร็จ!'); window.location.href='manage_data.php';</script>";
        exit();
    } catch (PDOException $e) {
        $errorMsg = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// 3. ดึงตัวเลือกสำหรับ Dropdown
$classifications = $pdo->query("SELECT * FROM classification")->fetchAll();
$buildings = $pdo->query("SELECT * FROM building")->fetchAll();
$statusList = $pdo->query("SELECT * FROM status_active")->fetchAll();

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>แก้ไขข้อมูลครุภัณฑ์</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="main.php"><i class="fa-solid fa-screwdriver-wrench text-warning me-2"></i>Admin Panel</a>
        <div class="ms-auto text-light">
            <span class="me-3"><i class="fa-solid fa-user-shield me-1"></i> <?php echo htmlspecialchars($useraccountName); ?></span>
            <a href="manage_data.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-arrow-left"></i> กลับไปจัดการข้อมูล</a>
        </div>
    </div>
</nav>

<div class="container mt-4 mb-5">
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-white border-0 pt-4 pb-2">
            <h4 class="fw-bold text-primary mb-0"><i class="fa-solid fa-pen-to-square me-2"></i> แก้ไขข้อมูลครุภัณฑ์</h4>
        </div>
        <div class="card-body p-4">
            
            <?php if (isset($errorMsg)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($errorMsg); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">หมายเลขครุภัณฑ์ (DA Number)</label>
                        <input type="text" class="form-control" name="DA_Number" value="<?php echo htmlspecialchars($item['DA_Number']); ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-bold">ชื่อรายการครุภัณฑ์</label>
                        <input type="text" class="form-control" name="DA_ListName" value="<?php echo htmlspecialchars($item['DA_ListName']); ?>" required>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label">ยี่ห้อ (Brand)</label>
                        <input type="text" class="form-control" name="DA_Brand" value="<?php echo htmlspecialchars($item['DA_Brand'] ?? ''); ?>">
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label fw-bold">หมวดหมู่ (Classification)</label>
                        <select class="form-select" name="classificationID">
                            <option value="">-- เลือกหมวดหมู่ --</option>
                            <?php foreach($classifications as $c): ?>
                                <option value="<?php echo $c['classificationID']; ?>" <?php echo ($item['classificationID'] == $c['classificationID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['classificationName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold">สถานที่ตั้ง (Building)</label>
                        <select class="form-select" name="buildingID">
                            <option value="">-- เลือกอาคาร --</option>
                            <?php foreach($buildings as $b): ?>
                                <option value="<?php echo $b['buildingID']; ?>" <?php echo ($item['buildingID'] == $b['buildingID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['buildingName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold text-danger">สถานะปัจจุบัน (Status)</label>
                        <select class="form-select border-danger" name="statusID">
                            <?php foreach($statusList as $s): ?>
                                <option value="<?php echo $s['statusID']; ?>" <?php echo ($item['statusID'] == $s['statusID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['statusName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">คำเตือน: หากปรับสถานะเป็น 'ถูกยืม' โดยไม่มีข้อมูลในประวัติ ระบบอาจแสดงผลผิดพลาด</small>
                    </div>

                    <div class="col-12 mt-4 text-end">
                        <a href="manage_data.php" class="btn btn-secondary px-4 me-2">ยกเลิก</a>
                        <button type="submit" class="btn btn-primary px-5"><i class="fa-solid fa-save me-1"></i> บันทึกการเปลี่ยนแปลง</button>
                    </div>
                </div>
            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
