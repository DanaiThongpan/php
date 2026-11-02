<?php
/**
 * =============================================================================
 * login.php - หน้าเข้าสู่ระบบและระบบส่งต่ออัตโนมัติ (Seamless Login & Redirect Flow)
 * รองรับการทำงานร่วมกับระบบสแกน QR Code สำหรับยืม-คืนครุภัณฑ์
 * มีระบบป้องกันช่องโหว่ Open Redirect (CWE-601) และ SQL Injection 100%
 * =============================================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO
require_once __DIR__ . '/control/connectPDO_dev.php';

$errorMsg = '';

/**
 * -----------------------------------------------------------------------------
 * ฟังก์ชันตรวจสอบความปลอดภัยของ URL สำหรับการ Redirect (Open Redirect Prevention)
 * -----------------------------------------------------------------------------
 * ป้องกันผู้ไม่หวังดีส่ง URL ภายนอกมาหลอกให้ผู้ใช้คลิกล็อกอินแล้วเด้งไปเว็บฟิชชิ่ง (Phishing)
 *
 * @param string|null $url URL ที่ต้องการตรวจสอบ
 * @return string|null คืนค่า Safe URL สำหรับนำไปใช้ใน header("Location: ...") หรือ null หากไม่ปลอดภัย
 */
function validateSafeRedirect(?string $url): ?string {
    if (empty($url) || !is_string($url)) {
        return null;
    }

    $url = trim($url);

    // 1. ป้องกัน HTTP Header Injection / CRLF Injection (\r, \n)
    if (preg_match('/[\r\n]/', $url)) {
        return null;
    }

    // 2. ป้องกัน Protocol-relative URL เช่น //evil.com หรือ /\evil.com หรือ \\evil.com
    if (str_starts_with($url, '//') || str_starts_with($url, '/\\') || str_starts_with($url, '\\\\') || str_starts_with($url, '\\')) {
        return null;
    }

    // 3. แยกองค์ประกอบของ URL
    $parsed = parse_url($url);
    if ($parsed === false) {
        return null;
    }

    // 4. กรณีเป็น Absolute URL ที่มี Scheme/Protocol (http://, https://)
    if (isset($parsed['scheme'])) {
        $scheme = strtolower($parsed['scheme']);
        // อนุญาตเฉพาะ http และ https
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // หากมี Host กำหนด ให้ตรวจสอบว่าตรงกับ Server ปัจจุบันหรือไม่ (Same-Domain Only)
        $currentHost = $_SERVER['HTTP_HOST'] ?? '';
        $currentHostname = explode(':', $currentHost)[0];
        $targetHostname = explode(':', $parsed['host'] ?? '')[0];

        if (!empty($currentHostname) && strcasecmp($currentHostname, $targetHostname) === 0) {
            // โดเมนตรงกัน อนุญาตให้ใช้เฉพาะ Path + Query ภายในระบบ
            $path = $parsed['path'] ?? '/';
            $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
            return $path . $query;
        }

        // ปฏิเสธ External URL ทั้งหมดเพื่อป้องกัน Open Redirect
        return null;
    }

    // 5. กรณีเป็น Relative Path: ต้องขึ้นต้นด้วย / หรือเป็นไฟล์ระบบที่อนุญาต
    $path = $parsed['path'] ?? '';
    if (str_starts_with($path, '/') || 
        preg_match('/^(borrow_request\.php|admin\/|user\/|index\.php)/i', $path)) {
        return $url;
    }

    return null;
}

// -----------------------------------------------------------------------------
// Step 1: ตรวจสอบและบันทึก URL ปลายทาง (Redirect URL)
// -----------------------------------------------------------------------------
// รับค่าจาก $_GET['redirect'] (กรณีถูกส่งต่อมาจาก borrow_request.php หรือ QR Code)
$rawRedirect = $_GET['redirect'] ?? $_SESSION['redirect_url'] ?? '';
$safeRedirect = validateSafeRedirect($rawRedirect);

if (!empty($safeRedirect)) {
    $_SESSION['redirect_url'] = $safeRedirect;
} elseif (empty($rawRedirect)) {
    unset($_SESSION['redirect_url']);
}

// -----------------------------------------------------------------------------
// Step 2: กรณีผู้ใช้ล็อกอินอยู่แล้ว (Already Logged In)
// -----------------------------------------------------------------------------
if (isset($_SESSION['Selogin']) && $_SESSION['Selogin'] == 1) {
    if (!empty($safeRedirect)) {
        unset($_SESSION['redirect_url']);
        header("Location: " . $safeRedirect);
        exit();
    }
    
    // หากไม่มี redirect ให้ส่งไป Dashboard ตามระดับสิทธิ์
    if (($_SESSION['useraccountType'] ?? '') === 'UT00000001') {
        header("Location: admin/main.php");
    } else {
        header("Location: user/main.php");
    }
    exit();
}

// -----------------------------------------------------------------------------
// Step 3: ประมวลผลการเข้าสู่ระบบเมื่อส่งฟอร์ม (POST Request)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // ตรวจสอบค่า redirect จากฟอร์ม POST หรือ Session
    $postedRedirect = trim($_POST['redirect'] ?? ($_SESSION['redirect_url'] ?? ''));
    $validRedirect = validateSafeRedirect($postedRedirect);

    if (empty($username) || empty($password)) {
        $errorMsg = "กรุณากรอกชื่อผู้ใช้งานและรหัสผ่านให้ครบถ้วน";
    } else {
        try {
            // ใช้ PDO Prepared Statements เพื่อความปลอดภัยจาก SQL Injection
            $stmt = $pdo->prepare("SELECT * FROM useraccount WHERE useraccountID = :username AND userActive = '1' LIMIT 1");
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['userPassword'])) {
                // [SECURITY PATCH]: ป้องกัน Session Fixation (CWE-384)
                // ทำลาย Session ID เดิมและออก Session ID ใหม่อย่างปลอดภัยทันทีที่ยืนยันตัวตนสำเร็จ
                session_regenerate_id(true);

                // ยืนยันตัวตนสำเร็จ -> สร้าง Session
                $_SESSION['Selogin']         = 1;
                $_SESSION['SeUser']          = $user['useraccountID'];
                $_SESSION['Sename']          = $user['useraccountName'];
                $_SESSION['useraccountType'] = $user['useraccountType'];
                $_SESSION['SeType']          = $user['useraccountType'];
                $_SESSION['userDepartment']  = $user['userDepartment'] ?? '';

                // ล้างค่า Session redirect เมื่อใช้งานเสร็จ
                unset($_SESSION['redirect_url']);

                // หากมี Safe Redirect URL (เช่น หน้าขอยืมครุภัณฑ์จาก QR Code) ให้ส่งกลับไปหน้านั้นทันที
                if (!empty($validRedirect)) {
                    header("Location: " . $validRedirect);
                    exit();
                }

                // กรณีไม่มีค่า Redirect ให้ส่งไปยังหน้า Dashboard ตามระดับสิทธิ์
                if ($user['useraccountType'] === 'UT00000001') {
                    header("Location: admin/main.php");
                    exit();
                } else {
                    header("Location: user/main.php");
                    exit();
                }
            } else {
                $errorMsg = "ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง หรือบัญชีถูกระงับ";
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
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f4f6f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px 10px;
        }
        .login-card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.08);
            overflow: hidden;
            width: 100%;
            max-width: 440px;
            background: #ffffff;
        }
        .login-header {
            background: linear-gradient(135deg, #0d6efd, #0b5ed7);
            padding: 32px 20px 24px;
            text-align: center;
            color: white;
        }
        .login-header .app-icon {
            width: 68px;
            height: 68px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 12px;
            backdrop-filter: blur(4px);
        }
        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
        }
    </style>
</head>
<body>

<div class="container d-flex justify-content-center">
    <div class="card login-card">
        <div class="login-header">
            <div class="app-icon">
                <i class="fa-solid fa-boxes-packing"></i>
            </div>
            <h4 class="fw-bold mb-1">ระบบจัดการครุภัณฑ์</h4>
            <p class="mb-0 text-white-50 small">กรุณาเข้าสู่ระบบเพื่อดำเนินการ</p>
        </div>
        
        <div class="card-body p-4">
            
            <?php if (!empty($safeRedirect)): ?>
                <!-- แจ้งเตือนผู้ใช้เมื่อสแกน QR Code เข้ามาแล้วติดหน้าล็อกอิน -->
                <div class="alert alert-info alert-dismissible fade show rounded-3 small py-2 d-flex align-items-center mb-3" role="alert">
                    <i class="fa-solid fa-qrcode fs-4 me-2 text-primary"></i>
                    <div>
                        <strong>สแกน QR Code สำเร็จ!</strong><br>
                        กรุณาเข้าสู่ระบบ ระบบจะนำท่านไปยังฟอร์มขอยืมครุภัณฑ์ทันที
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errorMsg)): ?>
                <div class="alert alert-danger alert-dismissible fade show rounded-3 small py-2 d-flex align-items-center mb-3" role="alert">
                    <i class="fa-solid fa-circle-exclamation fs-5 me-2 text-danger"></i>
                    <div><?php echo htmlspecialchars($errorMsg); ?></div>
                    <button type="button" class="btn-close ms-auto py-2" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="" method="POST">
                <!-- ส่งค่า redirect ไปกับ Form เพื่อรักษา URL ปลายทางแม้กรอกรหัสผ่านผิดแล้วลองใหม่ -->
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($safeRedirect ?? ''); ?>">
                
                <div class="mb-3">
                    <label class="form-label text-secondary small fw-bold">ชื่อผู้ใช้งาน (Username)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 text-muted"><i class="fa-solid fa-user"></i></span>
                        <input type="text" name="username" class="form-control border-start-0 ps-1" placeholder="กรอกชื่อผู้ใช้งาน" required autofocus value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary small fw-bold">รหัสผ่าน (Password)</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0 text-muted"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" name="password" class="form-control border-start-0 ps-1" placeholder="กรอกรหัสผ่าน" required>
                    </div>
                </div>
                
                <div class="d-grid gap-2 mb-3">
                    <button type="submit" name="login" class="btn btn-primary btn-lg rounded-3 fw-bold shadow-sm py-2 fs-6">
                        <i class="fa-solid fa-right-to-bracket me-1"></i> เข้าสู่ระบบ
                    </button>
                    <!-- ปุ่มจำลองการเข้าสู่ระบบด้วย SSO (Single Sign-On) -->
                    <button type="button" class="btn btn-outline-secondary rounded-3 fw-bold py-2 fs-6">
                        <i class="fa-brands fa-microsoft me-1"></i> เข้าสู่ระบบด้วย SSO
                    </button>
                </div>
                
                <div class="text-center mt-3 small">
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
