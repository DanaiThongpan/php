<?php
/**
 * admin/process_mail_settings.php - ตัวประมวลผลบันทึกการตั้งค่าระบบส่งอีเมลผ่าน AJAX
 * AJAX Endpoint for Saving Mail Settings (Gmail SMTP & App Password & Active Status)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// ตรวจสอบสิทธิ์ (ต้องเป็น Admin: UT00000001 หรือมี Session แอดมิน)
$isAdmin = isset($_SESSION['Selogin']) && $_SESSION['Selogin'] == 1 
        && (($_SESSION['useraccountType'] ?? '') === 'UT00000001' || ($_SESSION['useraccountType'] ?? '') === 'Admin');

if (!$isAdmin && empty($_SESSION['SeUser'])) {
    echo json_encode([
        'status'  => 'error', 
        'message' => 'ไม่มีสิทธิ์เข้าถึง หรือเซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// นำเข้าไฟล์เชื่อมต่อฐานข้อมูล PDO
require_once __DIR__ . '/../db.php';
$condb = $pdo; // รองรับทั้งตัวแปร $pdo และ $condb

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // -------------------------------------------------------------------------
    // การกระทำที่ 1: สลับสถานะเปิด/ปิดใช้งานระบบ (Quick Toggle Active/Inactive)
    // -------------------------------------------------------------------------
    if ($action === 'toggle_status') {
        $newStatus = (int)($_POST['is_active'] ?? 0);
        $newStatus = ($newStatus === 1) ? 1 : 0;

        try {
            // ตรวจสอบว่ามีแถว id = 1 หรือไม่
            $checkStmt = $pdo->query("SELECT COUNT(*) FROM system_settings WHERE id = 1");
            if ($checkStmt->fetchColumn() == 0) {
                $pdo->exec("
                    INSERT INTO system_settings (id, smtp_host, smtp_port, smtp_user, smtp_pass, sender_name, is_active, updated_at)
                    VALUES (1, 'smtp.gmail.com', 465, '', '', 'ระบบบริหารจัดการครุภัณฑ์', 1, CURRENT_TIMESTAMP)
                ");
            }

            $stmt = $pdo->prepare("UPDATE system_settings SET is_active = :active, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
            $stmt->execute([':active' => $newStatus]);

            echo json_encode([
                'status'    => 'success',
                'is_active' => $newStatus,
                'message'   => ($newStatus === 1) ? 'เปิดใช้งานระบบส่งอีเมลเรียบร้อยแล้ว' : 'ปิดใช้งานระบบส่งอีเมลเรียบร้อยแล้ว'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (PDOException $e) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'เกิดข้อผิดพลาดในการอัปเดตสถานะ: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // การกระทำที่ 2: บันทึกข้อมูลการตั้งค่าฟอร์มทั้งหมด (Full Form Submit)
    // -------------------------------------------------------------------------
    $smtp_user   = trim($_POST['smtp_user'] ?? '');
    // ลบช่องว่างออกจาก App Password 16 หลักอัตโนมัติ (เช่น "abcd efgh ijkl mnop" -> "abcdefghijklmnop")
    $smtp_pass   = str_replace(' ', '', trim($_POST['smtp_pass'] ?? ''));
    $sender_name = trim($_POST['sender_name'] ?? '');
    $smtp_port   = (int)($_POST['smtp_port'] ?? 465);
    $is_active   = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    $is_active   = ($is_active === 1) ? 1 : 0;

    if ($smtp_port !== 587 && $smtp_port !== 465) {
        $smtp_port = 465;
    }

    // ตรวจสอบค่าว่าง
    if (empty($smtp_user) || empty($smtp_pass)) {
        echo json_encode([
            'status'  => 'warning', 
            'message' => 'กรุณากรอกอีเมล Gmail และรหัสผ่านสำหรับแอป (App Password) ให้ครบถ้วน'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ตรวจสอบรูปแบบอีเมล
    if (!filter_var($smtp_user, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'status'  => 'warning', 
            'message' => 'รูปแบบอีเมลผู้ส่งไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ตรวจสอบความยาวรหัสผ่านแอป
    if (strlen($smtp_pass) < 16) {
        echo json_encode([
            'status'  => 'warning', 
            'message' => 'รหัสผ่านสำหรับแอปของ Google ปกติต้องมี 16 ตัวอักษร กรุณาตรวจสอบอีกครั้ง'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($sender_name)) {
        $sender_name = 'ระบบบริหารจัดการครุภัณฑ์';
    }

    try {
        // ตรวจสอบให้แน่ใจว่ามีแถว id = 1 ในตาราง system_settings
        $checkStmt = $pdo->query("SELECT COUNT(*) FROM system_settings WHERE id = 1");
        if ($checkStmt->fetchColumn() == 0) {
            $pdo->exec("
                INSERT INTO system_settings (id, smtp_host, smtp_port, smtp_user, smtp_pass, sender_name, is_active, updated_at)
                VALUES (1, 'smtp.gmail.com', 465, '', '', 'ระบบบริหารจัดการครุภัณฑ์', 1, CURRENT_TIMESTAMP)
            ");
        }

        // อัปเดตข้อมูลลงตาราง system_settings
        $stmt = $pdo->prepare("
            UPDATE system_settings 
            SET smtp_host   = 'smtp.gmail.com',
                smtp_user   = :user, 
                smtp_pass   = :pass, 
                sender_name = :name, 
                smtp_port   = :port,
                is_active   = :active,
                updated_at  = CURRENT_TIMESTAMP
            WHERE id = 1
        ");
        
        $stmt->bindParam(':user', $smtp_user);
        $stmt->bindParam(':pass', $smtp_pass);
        $stmt->bindParam(':name', $sender_name);
        $stmt->bindParam(':port', $smtp_port, PDO::PARAM_INT);
        $stmt->bindParam(':active', $is_active, PDO::PARAM_INT);
        
        $stmt->execute();

        echo json_encode([
            'status'  => 'success', 
            'message' => 'บันทึกการตั้งค่าอีเมลสำเร็จ ระบบพร้อมใช้งานทันที!'
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        echo json_encode([
            'status'  => 'error', 
            'message' => 'Database Error: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
} else {
    echo json_encode([
        'status'  => 'error', 
        'message' => 'Invalid Request Method'
    ], JSON_UNESCAPED_UNICODE);
}
