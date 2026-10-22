<?php
/**
 * admin/oauth_callback.php - จุดรับ Redirect Callback จาก Google OAuth 2.0 Server
 * Enterprise OAuth 2.0 Callback Handler
 * 
 * หน้าที่ของสคริปต์:
 * 1. รับ Authorization Code และ State จาก Google
 * 2. ตรวจสอบความถูกต้องของ State เพื่อป้องกันการโจมตีแบบ CSRF
 * 3. ส่งคำขอแลกเปลี่ยน Authorization Code เป็น Access Token & Refresh Token
 * 4. จัดเก็บ Refresh Token ลงตาราง system_settings ในฐานข้อมูลด้วย PDO Prepared Statements
 * 5. แสดงผลลัพธ์ผ่าน SweetAlert2 และ Redirect กลับหน้า settings_mail_oauth.php อย่างสวยงาม
 */

// เริ่มต้น Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------------
// 1. ตรวจสอบสิทธิ์การเข้าถึง (Security Check: ต้องล็อกอินเป็นผู้ดูแลระบบ)
// -----------------------------------------------------------------------------
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1) {
    header("Location: ../index.php");
    exit();
}

// -----------------------------------------------------------------------------
// 2. นำเข้าไฟล์เชื่อมต่อฐานข้อมูล PDO
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../db.php';

// กำหนดตัวแปรสำหรับ SweetAlert2
$alertStatus  = 'error';
$alertTitle   = 'การเชื่อมต่อไม่สำเร็จ';
$alertMessage = '';
$redirectUrl  = 'settings_mail_oauth.php';

try {
    // -------------------------------------------------------------------------
    // 3. ตรวจสอบกรณีที่ผู้ใช้กดยกเลิก หรือ Google คืนค่า Error กลับมา
    // -------------------------------------------------------------------------
    if (isset($_GET['error'])) {
        $googleError = htmlspecialchars($_GET['error']);
        $errorDesc   = htmlspecialchars($_GET['error_description'] ?? 'ผู้ใช้ยกเลิกการอนุญาตสิทธิ์');
        throw new Exception("Google ปฏิเสธการอนุญาตสิทธิ์ [{$googleError}]: {$errorDesc}");
    }

    // -------------------------------------------------------------------------
    // 4. ตรวจสอบ CSRF State Token เพื่อความปลอดภัยสูงสุด
    // -------------------------------------------------------------------------
    $stateFromGoogle = $_GET['state'] ?? '';
    $stateInSession  = $_SESSION['oauth2state'] ?? '';

    if (empty($stateFromGoogle) || empty($stateInSession) || !hash_equals($stateInSession, $stateFromGoogle)) {
        throw new Exception("การตรวจสอบ CSRF State Token ล้มเหลว (Invalid State) อาจเกิดจากเซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง");
    }

    // -------------------------------------------------------------------------
    // 5. ตรวจสอบ Authorization Code
    // -------------------------------------------------------------------------
    $authCode = trim($_GET['code'] ?? '');
    if (empty($authCode)) {
        throw new Exception("ไม่พบ Authorization Code จาก Google OAuth Server");
    }

    // -------------------------------------------------------------------------
    // 6. ดึงข้อมูล Client ID และ Secret จากตาราง system_settings
    // -------------------------------------------------------------------------
    $stmt = $pdo->query("SELECT oauth_client_id, oauth_client_secret FROM system_settings WHERE id = 1 LIMIT 1");
    $settings = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

    $clientId     = trim($settings['oauth_client_id'] ?? '');
    $clientSecret = trim($settings['oauth_client_secret'] ?? '');

    if (empty($clientId) || empty($clientSecret)) {
        throw new Exception("ไม่พบข้อมูล Google Client ID หรือ Client Secret ในฐานข้อมูล กรุณาตั้งค่าก่อน");
    }

    // คำนวณ Redirect URI ให้ตรงกับที่ลงทะเบียนไว้ทุกตัวอักษร
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    $currentDir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/');
    $redirectUri = $protocol . $host . $currentDir . '/oauth_callback.php';

    $refreshToken = '';

    // -------------------------------------------------------------------------
    // 7. แลกเปลี่ยน Authorization Code เป็น Tokens
    // -------------------------------------------------------------------------
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
    }

    if (class_exists('League\OAuth2\Client\Provider\Google')) {
        // ใช้ไลบรารี League OAuth2 Google อย่างเป็นทางการ
        $provider = new \League\OAuth2\Client\Provider\Google([
            'clientId'     => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri'  => $redirectUri,
        ]);

        $token = $provider->getAccessToken('authorization_code', [
            'code' => $authCode
        ]);

        $refreshToken = $token->getRefreshToken();
    } else {
        // Direct cURL Fallback แลกเปลี่ยน Token ตามสเปก Google OAuth 2.0 API โดยตรง
        $tokenEndpoint = 'https://oauth2.googleapis.com/token';
        $postData = http_build_query([
            'code'          => $authCode,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code'
        ]);

        $ch = curl_init($tokenEndpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new Exception("cURL Error ในการเชื่อมต่อไปยัง Google: {$curlErr}");
        }

        $resJson = json_decode($response, true);
        if ($httpCode !== 200 || empty($resJson)) {
            $errDetail = $resJson['error_description'] ?? ($resJson['error'] ?? 'HTTP Status ' . $httpCode);
            throw new Exception("Google ปฏิเสธการแลก Token: {$errDetail}");
        }

        $refreshToken = $resJson['refresh_token'] ?? '';
    }

    // -------------------------------------------------------------------------
    // 8. ตรวจสอบว่าได้รับ Refresh Token หรือไม่
    // -------------------------------------------------------------------------
    if (empty($refreshToken)) {
        // ในกรณีที่ Google ไม่ส่ง Refresh Token มา อาจเป็นเพราะบัญชีนี้เคยให้สิทธิ์ไปแล้ว
        throw new Exception(
            "เชื่อมต่อสำเร็จแต่ <strong>Google ไม่ได้ส่ง Refresh Token กลับมา</strong><br><br>" .
            "<small class=\"text-muted\"><strong>สาเหตุ:</strong> บัญชีนี้อาจเคยให้สิทธิ์แอปพลิเคชันนี้มาก่อนแล้ว<br>" .
            "<strong>วิธีแก้ไข:</strong> เข้าไปที่ <a href=\"https://myaccount.google.com/permissions\" target=\"_blank\">myaccount.google.com/permissions</a> " .
            "แล้วกดลบสิทธิ์ของแอปนี้ออก จากนั้นกลับมากดปุ่ม Sign in with Google ใหม่อีกครั้ง</small>"
        );
    }

    // -------------------------------------------------------------------------
    // 9. บันทึก Refresh Token ลงตาราง system_settings ด้วย PDO Prepared Statement
    // -------------------------------------------------------------------------
    $updateSql = "
        UPDATE system_settings
        SET oauth_refresh_token = :refresh_token,
            updated_at          = CURRENT_TIMESTAMP
        WHERE id = 1
    ";
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([
        ':refresh_token' => $refreshToken
    ]);

    // เคลียร์ State ใน Session หลังจากสำเร็จ
    unset($_SESSION['oauth2state']);

    // กำหนดสถานะสำเร็จสำหรับ SweetAlert2
    $alertStatus  = 'success';
    $alertTitle   = '🎉 เชื่อมต่อ Google OAuth สำเร็จ!';
    $alertMessage = 'ระบบได้รับ <strong>Refresh Token</strong> และบันทึกลงฐานข้อมูลเรียบร้อยแล้ว<br><span class="text-success fw-bold">ระบบพร้อมส่งอีเมลแจ้งเตือนผ่าน Gmail API แบบ XOAUTH2 ทันที</span>';

} catch (Exception $e) {
    $alertStatus  = 'error';
    $alertTitle   = 'การเชื่อมต่อ OAuth 2.0 ล้มเหลว';
    $alertMessage = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผลการเชื่อมต่อ Google OAuth 2.0</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts Prompt -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            font-family: 'Prompt', sans-serif;
            background-color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
        }
    </style>
</head>
<body>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const isSuccess = <?= json_encode($alertStatus === 'success') ?>;

        if (isSuccess) {
            Swal.fire({
                icon: 'success',
                title: <?= json_encode($alertTitle) ?>,
                html: <?= json_encode($alertMessage) ?>,
                confirmButtonColor: '#16a34a',
                confirmButtonText: '<i class="fa-solid fa-arrow-right me-1"></i> กลับหน้าตั้งค่าอีเมล',
                timer: 4000,
                timerProgressBar: true,
                allowOutsideClick: false
            }).then(() => {
                window.location.href = <?= json_encode($redirectUrl) ?>;
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: <?= json_encode($alertTitle) ?>,
                html: <?= json_encode($alertMessage) ?>,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'กลับไปลองใหม่อีกครั้ง',
                allowOutsideClick: false
            }).then(() => {
                window.location.href = <?= json_encode($redirectUrl) ?>;
            });
        }
    });
</script>

</body>
</html>
