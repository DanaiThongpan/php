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
    echo "<script>alert('ไม่พบรหัสผู้ใช้งาน'); window.location.href='manage_users.php';</script>";
    exit();
}

$currentLoggedInUser = $_SESSION['SeUser'] ?? '';
$isEditingSelf = ($uid === $currentLoggedInUser);

// 1. ดึงข้อมูลผู้ใช้ที่ต้องการแก้ไข
$stmtUser = $pdo->prepare("SELECT * FROM useraccount WHERE useraccountID = :uid");
$stmtUser->execute([':uid' => $uid]);
$user = $stmtUser->fetch();

if (!$user) {
    echo "<script>alert('ไม่พบข้อมูลผู้ใช้งาน'); window.location.href='manage_users.php';</script>";
    exit();
}

// 2. เมื่อกดปุ่มบันทึก (POST Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uName = $_POST['useraccountName'];
    $uRank = $_POST['userRank'] ?? '';
    $uDept = $_POST['userDepartment'] ?? '';
    $uEmail = $_POST['userEmail'] ?? '';
    
    // ถ้าเป็นการแก้ไขบัญชีตัวเอง ให้คงสิทธิ์และสถานะเดิมไว้เสมอ (ป้องกันการแก้ไขสิทธิ์ตัวเอง)
    if ($isEditingSelf) {
        $uType = $user['useraccountType'];
        $uActive = $user['userActive'];
        // ไม่ให้แก้ไข email ของตนเองเพื่อความปลอดภัย
        $uEmail = $user['userEmail'] ?? '';
    } else {
        $uType = $_POST['useraccountType'];
        $uActive = $_POST['userActive'];
    }
    
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
                          userEmail = :email,
                          userPassword = :pass
                      WHERE useraccountID = :uid";
            $stmtUp = $pdo->prepare($sqlUp);
            $stmtUp->execute([
                ':name' => $uName,
                ':rank' => $uRank,
                ':dept' => $uDept,
                ':type' => $uType,
                ':active' => $uActive,
                ':email' => $uEmail,
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
                          userActive = :active,
                          userEmail = :email
                      WHERE useraccountID = :uid";
            $stmtUp = $pdo->prepare($sqlUp);
            $stmtUp->execute([
                ':name' => $uName,
                ':rank' => $uRank,
                ':dept' => $uDept,
                ':type' => $uType,
                ':active' => $uActive,
                ':email' => $uEmail,
                ':uid' => $uid
            ]);
        }
        
        $pdo->commit();
        echo "<script>alert('✅ บันทึกข้อมูลผู้ใช้งานสำเร็จ!'); window.location.href='manage_users.php';</script>";
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $errorMsg = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}
$pageTitle = "แก้ไขข้อมูลผู้ใช้งาน - " . ($user['useraccountName'] ?? $uid);
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>

<div class="container-fluid px-0 mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <a href="manage_users.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 shadow-xs">
            <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับไปจัดการผู้ใช้งาน
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-9 col-xl-8">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white border-0 pt-4 pb-2 px-4">
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
                                <label class="form-label">อีเมล (Email)</label>
                                <input type="email" class="form-control" name="userEmail" value="<?= htmlspecialchars($user['userEmail'] ?? '') ?>">
                            </div>

                            <div class="col-12 mt-4">
                                <h6 class="border-bottom pb-2 text-primary fw-bold">ตั้งค่าสิทธิ์และการเข้าถึง</h6>
                                <?php if ($isEditingSelf): ?>
                                    <div class="alert alert-info py-2 px-3 small rounded-3 mb-3">
                                        <i class="fa-solid fa-circle-info me-1"></i> <strong>หมายเหตุ:</strong> คุณกำลังแก้ไขข้อมูลบัญชีของตนเอง ระบบจะไม่อนุญาตให้แก้ไข <strong>"ระดับสิทธิ์"</strong> หรือ <strong>"สถานะบัญชี"</strong> เพื่อความปลอดภัยของระบบ
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold text-danger">ระดับสิทธิ์ (Role)</label>
                                <select class="form-select border-danger <?php echo $isEditingSelf ? 'bg-light' : ''; ?>" name="useraccountType" <?php echo $isEditingSelf ? 'disabled' : ''; ?>>
                                    <option value="UT00000002" <?php echo ($user['useraccountType'] === 'UT00000002') ? 'selected' : ''; ?>>User (ผู้ใช้งานทั่วไป)</option>
                                    <option value="UT00000001" <?php echo ($user['useraccountType'] === 'UT00000001') ? 'selected' : ''; ?>>Admin (ผู้ดูแลระบบ)</option>
                                </select>
                                <?php if ($isEditingSelf): ?>
                                    <input type="hidden" name="useraccountType" value="<?php echo htmlspecialchars($user['useraccountType']); ?>">
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold">สถานะบัญชี (Status)</label>
                                <select class="form-select <?php echo $isEditingSelf ? 'bg-light' : ''; ?>" name="userActive" <?php echo $isEditingSelf ? 'disabled' : ''; ?>>
                                    <option value="1" <?php echo ($user['userActive'] == '1') ? 'selected' : ''; ?>>✅ ใช้งานได้ (Active)</option>
                                    <option value="0" <?php echo ($user['userActive'] != '1') ? 'selected' : ''; ?>>❌ ระงับใช้งาน (Disabled)</option>
                                </select>
                                <?php if ($isEditingSelf): ?>
                                    <input type="hidden" name="userActive" value="<?php echo htmlspecialchars($user['userActive']); ?>">
                                <?php endif; ?>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold">รีเซ็ตรหัสผ่าน (Reset Password)</label>
                                <input type="password" class="form-control" name="newPassword" placeholder="ปล่อยว่างไว้หากไม่ต้องการเปลี่ยนรหัสผ่าน...">
                                <small class="text-muted">หากกรอกช่องนี้ รหัสผ่านเก่าของ User จะถูกรีเซ็ตทันที</small>
                            </div>

                            <div class="col-12 mt-4 text-center">
                                <a href="manage_users.php" class="btn btn-secondary px-4 me-2">ยกเลิก</a>
                                <button type="submit" class="btn btn-primary px-5"><i class="fa-solid fa-save me-1"></i> บันทึกข้อมูล</button>
                            </div>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../component/admin_footer.php'; ?>
