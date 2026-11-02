<?php
/**
 * admin/process_mail_settings.php - ตัวประมวลผลบันทึกการตั้งค่าระบบส่งอีเมลและทดสอบ API ผ่าน AJAX
 * Handles AJAX requests for saving REST Mail API settings, reminder frequencies, and sending test emails
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// 1. ตรวจสอบสิทธิ์การเข้าถึง (Admin Check)
$isAdmin = isset($_SESSION['Selogin']) && $_SESSION['Selogin'] == 1 
        && (($_SESSION['useraccountType'] ?? '') === 'UT00000001' || ($_SESSION['useraccountType'] ?? '') === 'Admin');

if (!$isAdmin && empty($_SESSION['SeUser'])) {
    echo json_encode([
        'status'  => 'error', 
        'message' => 'ไม่มีสิทธิ์เข้าถึง หรือเซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. นำเข้าไฟล์เชื่อมต่อฐานข้อมูล PDO และ Mailer Service
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../mailer.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // -------------------------------------------------------------------------
    // การกระทำที่ 1: สลับสถานะเปิด/ปิดใช้งานระบบ (Quick Toggle Active/Inactive)
    // -------------------------------------------------------------------------
    if ($action === 'toggle_status') {
        $newStatus = (int)($_POST['is_active'] ?? 0);
        $newStatus = ($newStatus === 1) ? 1 : 0;

        try {
            // ตรวจสอบว่ามีแถว id = 1 ในตาราง system_settings หรือไม่
            $checkStmt = $pdo->query("SELECT COUNT(*) FROM system_settings WHERE id = 1");
            if ($checkStmt->fetchColumn() == 0) {
                $pdo->exec("
                    INSERT INTO system_settings (id, smtp_user, smtp_pass, sender_name, sender_email, is_active, updated_at)
                    VALUES (1, '', '', 'งานบริหารทั่วไป กองวิชาการ', '', 1, CURRENT_TIMESTAMP)
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
    // การกระทำที่ 2: ส่งอีเมลทดสอบผ่าน External REST API (Send Test Email)
    // -------------------------------------------------------------------------
    if ($action === 'send_test') {
        $testTo = trim($_POST['test_to'] ?? '');

        if (empty($testTo) || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            echo json_encode([
                'status'  => 'warning',
                'message' => 'กรุณากรอกอีเมลผู้รับทดสอบให้ถูกต้อง'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // หากผู้ใช้พิมพ์ค่าในฟอร์มมาด้วย ให้ใช้ค่านั้นสำหรับทดสอบชั่วคราว (Override)
        // หรือหากไม่ระบุจะดึงการตั้งค่าล่าสุดจากฐานข้อมูล
        $override = null;
        $formSmtpUser    = trim($_POST['smtp_user'] ?? '');
        $formSmtpPass    = trim($_POST['smtp_pass'] ?? '');
        $formSenderName  = trim($_POST['sender_name'] ?? '');
        $formSenderEmail = trim($_POST['sender_email'] ?? '');

        if (!empty($formSmtpUser) && !empty($formSmtpPass)) {
            $override = [
                'smtp_user'    => $formSmtpUser,
                'smtp_pass'    => $formSmtpPass,
                'sender_name'  => $formSenderName ?: 'งานบริหารทั่วไป กองวิชาการ',
                'sender_email' => $formSenderEmail ?: $formSmtpUser,
                'is_active'    => 1
            ];
        }

        $nowFormatted = date('d/m/Y H:i:s');
        $subject = "✅ [ทดสอบระบบ] ยืนยันการเชื่อมต่อ Email REST API สำเร็จ";

        $htmlTest = "
        <div style=\"font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 620px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background-color: #ffffff;\">
            <div style=\"background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #ffffff; padding: 25px 30px; text-align: center;\">
                <h2 style=\"margin: 0; font-size: 22px; font-weight: 700;\">✉️ ทดสอบการเชื่อมต่อระบบส่งอีเมล</h2>
                <p style=\"margin: 5px 0 0 0; opacity: 0.9; font-size: 14px;\">ระบบบริหารจัดการครุภัณฑ์และสินทรัพย์ (DAAM REST API Service)</p>
            </div>
            
            <div style=\"padding: 30px; color: #334155; line-height: 1.7;\">
                <p style=\"font-size: 16px; margin-top: 0;\">
                    เรียน ผู้ดูแลระบบ,
                </p>
                <p>
                    อีเมลฉบับนี้เป็นการทดสอบการเชื่อมต่อส่งข้อความผ่าน <strong>External REST API</strong> ของมหาวิทยาลัยอุบลราชธานี เพื่อยืนยันว่าการตั้งค่าบัญชีผู้ส่งและสิทธิ์การเข้าถึงทำงานได้อย่างสมบูรณ์
                </p>

                <div style=\"background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; margin: 20px 0;\">
                    <table style=\"width: 100%; border-collapse: collapse; font-size: 14px;\">
                        <tr>
                            <td style=\"padding: 6px 0; width: 38%; color: #64748b; font-weight: 600;\">🌐 API Endpoint:</td>
                            <td style=\"padding: 6px 0; color: #0f172a; font-family: monospace;\">https://admission.ubu.ac.th/mailsender/api/send-email</td>
                        </tr>
                        <tr>
                            <td style=\"padding: 6px 0; color: #64748b; font-weight: 600;\">👤 บัญชีผู้ส่ง (SMTP_USER):</td>
                            <td style=\"padding: 6px 0; color: #0f172a; font-weight: bold;\">" . htmlspecialchars($override['smtp_user'] ?? 'ตามที่ตั้งค่าในฐานข้อมูล') . "</td>
                        </tr>
                        <tr>
                            <td style=\"padding: 6px 0; color: #64748b; font-weight: 600;\">🏷️ ชื่อผู้ส่ง (From):</td>
                            <td style=\"padding: 6px 0; color: #0f172a;\">" . htmlspecialchars(($override['sender_name'] ?? '') . ' ' . ($override['sender_email'] ?? '')) . "</td>
                        </tr>
                        <tr>
                            <td style=\"padding: 6px 0; color: #64748b; font-weight: 600;\">📬 ผู้รับทดสอบ (To):</td>
                            <td style=\"padding: 6px 0; color: #2563eb; font-weight: 600;\">" . htmlspecialchars($testTo) . "</td>
                        </tr>
                        <tr>
                            <td style=\"padding: 6px 0; color: #64748b; font-weight: 600;\">🕒 เวลาที่ส่งทดสอบ:</td>
                            <td style=\"padding: 6px 0; color: #475569;\">{$nowFormatted}</td>
                        </tr>
                    </table>
                </div>

                <div style=\"background-color: #ecfdf5; border-left: 4px solid #10b981; padding: 12px 16px; border-radius: 4px; margin: 20px 0;\">
                    <p style=\"margin: 0; color: #065f46; font-size: 14px; font-weight: 600;\">
                        🎉 การเชื่อมต่อสำเร็จ! ระบบพร้อมสำหรับการส่งอีเมลแจ้งเตือนทวงคืนครุภัณฑ์อัตโนมัติแล้ว
                    </p>
                </div>

                <p style=\"margin-top: 25px; font-size: 13px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 15px;\">
                    ขอแสดงความนับถือ,<br>
                    <strong>ทีมพัฒนาระบบบริหารจัดการครุภัณฑ์ (DAAM Team)</strong><br>
                    <em>* อีเมลฉบับนี้ส่งจากระบบทดสอบอัตโนมัติ</em>
                </p>
            </div>
        </div>
        ";

        $result = sendSystemEmail($testTo, $subject, $htmlTest, $pdo, $override);

        if ($result['success']) {
            echo json_encode([
                'status'  => 'success',
                'message' => "ส่งอีเมลทดสอบไปยัง {$testTo} เรียบร้อยแล้ว! กรุณาตรวจสอบกล่องข้อความหรือถังขยะ (Spam)"
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => $result['message']
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // การกระทำที่ 3: บันทึกข้อมูลการตั้งค่า API และความถี่ในการทวง (Save Settings)
    // -------------------------------------------------------------------------
    $smtp_user    = trim($_POST['smtp_user'] ?? '');
    $smtp_pass    = trim($_POST['smtp_pass'] ?? '');
    $sender_name  = trim($_POST['sender_name'] ?? '');
    $sender_email = trim($_POST['sender_email'] ?? '');
    $reminder_freq = trim($_POST['reminder_frequency'] ?? 'daily');
    $custom_days  = (int)($_POST['reminder_custom_days'] ?? 3);
    $is_active    = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    $is_active    = ($is_active === 1) ? 1 : 0;

    // ตรวจสอบค่าว่าง
    if (empty($smtp_user) || empty($smtp_pass)) {
        echo json_encode([
            'status'  => 'warning', 
            'message' => 'กรุณากรอก SMTP_USER และ SMTP_PASS ให้ครบถ้วน'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ตรวจสอบรูปแบบอีเมลผู้ส่ง
    if (!filter_var($smtp_user, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'status'  => 'warning', 
            'message' => 'รูปแบบ SMTP_USER (อีเมล) ไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($sender_email)) {
        $sender_email = $smtp_user;
    } elseif (!filter_var($sender_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'status'  => 'warning', 
            'message' => 'รูปแบบ Sender Email ไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($sender_name)) {
        $sender_name = 'งานบริหารทั่วไป กองวิชาการ';
    }

    // กำหนดจำนวนวันตามความถี่ที่เลือก
    $reminder_days = 1;
    if ($reminder_freq === 'none') {
        $reminder_days = 0;
    } elseif ($reminder_freq === 'daily') {
        $reminder_days = 1;
    } elseif ($reminder_freq === 'alternate') {
        $reminder_days = 2;
    } elseif ($reminder_freq === 'weekly') {
        $reminder_days = 7;
    } elseif ($reminder_freq === 'custom') {
        $reminder_days = max(1, min(90, $custom_days));
    } else {
        $reminder_freq = 'daily';
        $reminder_days = 1;
    }

    try {
        // ตรวจสอบให้แน่ใจว่ามีแถว id = 1 ในตาราง system_settings
        $checkStmt = $pdo->query("SELECT COUNT(*) FROM system_settings WHERE id = 1");
        if ($checkStmt->fetchColumn() == 0) {
            $pdo->exec("
                INSERT INTO system_settings (id, smtp_user, smtp_pass, sender_name, sender_email, reminder_frequency, reminder_days, is_active, updated_at)
                VALUES (1, '', '', 'งานบริหารทั่วไป กองวิชาการ', '', 'daily', 1, 1, CURRENT_TIMESTAMP)
            ");
        }

        // อัปเดตข้อมูลลงตาราง system_settings
        $stmt = $pdo->prepare("
            UPDATE system_settings 
            SET smtp_user          = :user, 
                smtp_pass          = :pass, 
                sender_name        = :name, 
                sender_email       = :email,
                reminder_frequency = :freq,
                reminder_days      = :days,
                is_active          = :active,
                updated_at         = CURRENT_TIMESTAMP
            WHERE id = 1
        ");
        
        $stmt->bindParam(':user', $smtp_user);
        $stmt->bindParam(':pass', $smtp_pass);
        $stmt->bindParam(':name', $sender_name);
        $stmt->bindParam(':email', $sender_email);
        $stmt->bindParam(':freq', $reminder_freq);
        $stmt->bindParam(':days', $reminder_days, PDO::PARAM_INT);
        $stmt->bindParam(':active', $is_active, PDO::PARAM_INT);
        
        $stmt->execute();

        echo json_encode([
            'status'  => 'success', 
            'message' => 'บันทึกการตั้งค่า API และความถี่ในการส่งเมลทวงคืนเรียบร้อยแล้ว!'
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        echo json_encode([
            'status'  => 'error', 
            'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
