<?php
session_start();

// ดึงไฟล์เชื่อมต่อฐานข้อมูล PDO แบบ Dev จากโฟลเดอร์หลัก
require_once 'control/connectPDO_dev.php';

$errorMsg = '';

// เช็คว่ามีการส่งฟอร์มเข้าสู่ระบบมาหรือไม่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $errorMsg = "กรุณากรอกชื่อผู้ใช้งานและรหัสผ่านให้ครบถ้วน";
    } else {
        try {
            // ใช้ PDO Prepared Statements เพื่อป้องกัน SQL Injection (ค้นหาจาก useraccountID ตามฐานข้อมูลเดิม)
            $stmt = $pdo->prepare("SELECT * FROM useraccount WHERE useraccountID = :username AND userActive = '1' LIMIT 1");
            $stmt->bindParam(':username', $username);
            $stmt->execute();

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // ตรวจสอบรหัสผ่านที่เข้ารหัสไว้ (hash) ด้วย password_verify
                if (password_verify($password, $user['userPassword'])) {
                    
                    // สร้าง Session สำหรับบันทึกข้อมูลการเข้าสู่ระบบ
                    $_SESSION['Selogin'] = 1;
                    $_SESSION['SeUser'] = $user['useraccountID'];
                    $_SESSION['Sename'] = $user['useraccountName'];
                    $_SESSION['useraccountType'] = $user['useraccountType']; // เก็บระดับสิทธิ์สำหรับระบบใหม่
                    $_SESSION['SeType'] = $user['useraccountType']; // เก็บระดับสิทธิ์สำหรับระบบเก่า (Backward Compatibility)

                    // ตรวจสอบระดับผู้ใช้งาน (รหัส UT00000001 = Admin, นอกนั้นถือเป็น User)
                    if ($user['useraccountType'] === 'UT00000001') {
                        header("Location: admin/main.php");
                        exit();
                    } else {
                        // ตรวจสอบว่ามีการเข้าจาก QR Code แล้วยังไม่ได้ล็อกอินหรือไม่
                        if (isset($_SESSION['redirect_url']) && !empty($_SESSION['redirect_url'])) {
                            $targetUrl = $_SESSION['redirect_url'];
                            unset($_SESSION['redirect_url']); // ล้างค่าทิ้งหลังจากใช้เสร็จ
                            header("Location: " . $targetUrl);
                        } else {
                            header("Location: user/main.php");
                        }
                        exit();
                    }
                } else {
                    $errorMsg = "รหัสผ่านไม่ถูกต้อง";
                }
            } else {
                $errorMsg = "ไม่พบชื่อผู้ใช้งานนี้ หรือบัญชีถูกระงับ";
            }
        } catch (PDOException $e) {
            $errorMsg = "เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - ระบบจัดการครุภัณฑ์</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f4f6f9;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            width: 100%;
            max-width: 450px;
        }
        .login-header {
            background: linear-gradient(135deg, #0d6efd, #0dcaf0);
            padding: 30px 20px;
            text-align: center;
            color: white;
        }
        .login-header i {
            font-size: 3rem;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>

<div class="container d-flex justify-content-center">
    <div class="card login-card">
        <div class="login-header">
            <i class="fa-solid fa-boxes-packing"></i>
            <h3 class="fw-bold mb-0">ระบบจัดการครุภัณฑ์</h3>
            <p class="mb-0 text-light opacity-75">กรุณาเข้าสู่ระบบเพื่อใช้งาน</p>
        </div>
        <div class="card-body p-4">
            
            <?php if (!empty($errorMsg)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-1"></i> <?php echo htmlspecialchars($errorMsg); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form action="" method="POST">
                <div class="mb-3">
                    <label class="form-label text-secondary fw-bold">ชื่อผู้ใช้งาน (Username)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fa-solid fa-user text-muted"></i></span>
                        <input type="text" name="username" class="form-control" placeholder="กรอกชื่อผู้ใช้งาน" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label text-secondary fw-bold">รหัสผ่าน (Password)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fa-solid fa-lock text-muted"></i></span>
                        <input type="password" name="password" class="form-control" placeholder="กรอกรหัสผ่าน" required>
                    </div>
                </div>
                
                <div class="d-grid gap-2 mb-3">
                    <button type="submit" name="login" class="btn btn-primary btn-lg rounded-3 fw-bold shadow-sm">
                        <i class="fa-solid fa-right-to-bracket me-1"></i> เข้าสู่ระบบ
                    </button>
                    <!-- ปุ่มจำลองการเข้าสู่ระบบด้วย SSO (Single Sign-On) -->
                    <button type="button" class="btn btn-outline-dark btn-lg rounded-3 fw-bold">
                        <i class="fa-brands fa-microsoft me-1"></i> เข้าสู่ระบบด้วย SSO
                    </button>
                </div>
                
                <div class="text-center mt-4">
                    <span class="text-muted">ยังไม่มีบัญชีผู้ใช้งาน?</span>
                    <a href="register.php" class="text-decoration-none fw-bold ms-1">ลงทะเบียนสมาชิกใหม่</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
