<?php
session_start();

// ตรวจสอบสิทธิ์ (ต้องเป็น Admin)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

require_once '../control/connectPDO_dev.php';

$useraccountNameAdmin = $_SESSION['Sename'];
$uid = $_GET['uid'] ?? '';

if (empty($uid)) {
    echo "<script>alert('ไม่พบรหัสผู้ใช้งาน'); window.location.href='manage_data.php';</script>";
    exit();
}

// ป้องกันไม่ให้แอดมินแก้ไขสิทธิ์ของตัวเองผ่านหน้านี้ (เพื่อป้องกันการล็อคตัวเองออกจากระบบ)
if ($uid === $_SESSION['SeUser']) {
    echo "<script>alert('เพื่อความปลอดภัย คุณไม่สามารถแก้ไขสิทธิ์ของตัวเองได้ผ่านหน้านี้'); window.location.href='manage_data.php';</script>";
    exit();
}

// 1. ดึงข้อมูลผู้ใช้ที่ต้องการแก้ไข
$stmtUser = $pdo->prepare("SELECT * FROM useraccount WHERE useraccountID = :uid");
$stmtUser->execute([':uid' => $uid]);
$user = $stmtUser->fetch();

if (!$user) {
    echo "<script>alert('ไม่พบข้อมูลผู้ใช้งาน'); window.location.href='manage_data.php';</script>";
    exit();
}

// 2. เมื่อกดปุ่มบันทึก (POST Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uName = $_POST['useraccountName'];
    $uRank = $_POST['userRank'] ?? '';
    $uDept = $_POST['userDepartment'] ?? '';
    $uType = $_POST['useraccountType'];
    $uActive = $_POST['userActive'];
    $newPassword = $_POST['newPassword'] ?? '';
    
    try {
        $pdo->beginTransaction();

        // ตรวจสอบว่ามีการเปลี่ยนรหัสผ่านหรือไม่
        if (!empty($newPassword)) {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $sqlUp = "UPDATE useraccount 
                      SET useraccountName = :name, 
                          userRank = :rank, 
                          userDepartment = :dept, 
                          useraccountType = :type, 
                          userActive = :active,
                          userPassword = :pass
                      WHERE useraccountID = :uid";
            $stmtUp = $pdo->prepare($sqlUp);
            $stmtUp->execute([
                ':name' => $uName,
                ':rank' => $uRank,
                ':dept' => $uDept,
                ':type' => $uType,
                ':active' => $uActive,
                ':pass' => $hashedPassword,
                ':uid' => $uid
            ]);
        } else {
            // ไม่อัปเดตรหัสผ่าน
            $sqlUp = "UPDATE useraccount 
                      SET useraccountName = :name, 
                          userRank = :rank, 
                          userDepartment = :dept, 
                          useraccountType = :type, 
                          userActive = :active
                      WHERE useraccountID = :uid";
            $stmtUp = $pdo->prepare($sqlUp);
            $stmtUp->execute([
                ':name' => $uName,
                ':rank' => $uRank,
                ':dept' => $uDept,
                ':type' => $uType,
                ':active' => $uActive,
                ':uid' => $uid
            ]);
        }
        
        $pdo->commit();
        echo "<script>alert('✅ บันทึกข้อมูลผู้ใช้งานสำเร็จ!'); window.location.href='manage_data.php';</script>";
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
    <title>แก้ไขข้อมูลผู้ใช้งาน</title>
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
            <span class="me-3"><i class="fa-solid fa-user-shield me-1"></i> <?php echo htmlspecialchars($useraccountNameAdmin); ?></span>
            <a href="manage_data.php" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-arrow-left"></i> กลับไปจัดการข้อมูล</a>
        </div>
    </div>
</nav>

<div class="container mt-4 mb-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white border-0 pt-4 pb-2">
                    <h4 class="fw-bold text-primary mb-0"><i class="fa-solid fa-user-pen me-2"></i> แก้ไขข้อมูลผู้ใช้งาน</h4>
                </div>
                <div class="card-body p-4">
                    
                    <?php if (isset($errorMsg)): ?>
                        <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($errorMsg); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">รหัสผู้ใช้งาน (Username / ID)</label>
                                <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($user['useraccountID']); ?>" readonly>
                                <small class="text-muted">รหัสผู้ใช้งานไม่สามารถแก้ไขได้</small>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label fw-bold">ชื่อ - นามสกุล</label>
                                <input type="text" class="form-control" name="useraccountName" value="<?php echo htmlspecialchars($user['useraccountName']); ?>" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">ตำแหน่ง (Rank)</label>
                                <input type="text" class="form-control" name="userRank" value="<?php echo htmlspecialchars($user['userRank'] ?? ''); ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">ฝ่าย / แผนก (Department)</label>
                                <input type="text" class="form-control" name="userDepartment" value="<?php echo htmlspecialchars($user['userDepartment'] ?? ''); ?>">
                            </div>

                            <div class="col-12 mt-4">
                                <h6 class="border-bottom pb-2 text-primary fw-bold">ตั้งค่าสิทธิ์และการเข้าถึง</h6>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold text-danger">ระดับสิทธิ์ (Role)</label>
                                <select class="form-select border-danger" name="useraccountType">
                                    <option value="UT00000002" <?php echo ($user['useraccountType'] === 'UT00000002') ? 'selected' : ''; ?>>User (ผู้ใช้งานทั่วไป)</option>
                                    <option value="UT00000001" <?php echo ($user['useraccountType'] === 'UT00000001') ? 'selected' : ''; ?>>Admin (ผู้ดูแลระบบ)</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold">สถานะบัญชี (Status)</label>
                                <select class="form-select" name="userActive">
                                    <option value="1" <?php echo ($user['userActive'] == '1') ? 'selected' : ''; ?>>✅ ใช้งานได้ (Active)</option>
                                    <option value="0" <?php echo ($user['userActive'] != '1') ? 'selected' : ''; ?>>❌ ระงับใช้งาน (Disabled)</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold">รีเซ็ตรหัสผ่าน (Reset Password)</label>
                                <input type="password" class="form-control" name="newPassword" placeholder="ปล่อยว่างไว้หากไม่ต้องการเปลี่ยนรหัสผ่าน...">
                                <small class="text-muted">หากกรอกช่องนี้ รหัสผ่านเก่าของ User จะถูกรีเซ็ตทันที</small>
                            </div>

                            <div class="col-12 mt-4 text-center">
                                <a href="manage_data.php" class="btn btn-secondary px-4 me-2">ยกเลิก</a>
                                <button type="submit" class="btn btn-primary px-5"><i class="fa-solid fa-save me-1"></i> บันทึกข้อมูล</button>
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
