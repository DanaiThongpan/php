<?php
/**
 * admin/manual_remind.php - ตัวประมวลผลการส่งอีเมลทวงถามครุภัณฑ์เกินกำหนดส่งคืนด้วย PHPMailer & Gmail App Password
 * Backend Controller for Overdue Borrowing Email Reminders via PHPMailer & Gmail SMTP
 * 
 * คุณสมบัติตามมาตรฐาน:
 * 1. ดึงการตั้งค่า smtp_user และ smtp_pass (App Password 16 หลัก) จากฐานข้อมูลตาราง system_settings ผ่าน PDO
 * 2. ลบการใช้งาน OAuth 2.0 ออกทั้งหมด ใช้การยืนยันตัวตน SMTPAuth ปกติที่ตั้งค่าง่ายและเสถียร
 * 3. ตรวจสอบสิทธิ์ Admin Session และรับค่า ID ผ่าน HTTP POST / GET
 * 4. ป้องกันการส่งสแปมซ้ำในวันเดียวกัน (Anti-Spam Protection)
 * 5. ป้องกัน SQL Injection 100% ด้วย PDO Prepared Statements
 * 6. ตอบกลับผลลัพธ์เป็น JSON Format สำหรับแสดงผลผ่าน SweetAlert2 บนหน้าเว็บ
 */

// เริ่มต้น Session หากยังไม่ได้เปิด
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------------
// 1. ตรวจสอบประเภทการเรียกใช้งาน (AJAX / JSON Request Detector)
// -----------------------------------------------------------------------------
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || isset($_GET['ajax']) 
       || isset($_POST['ajax'])
       || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
       || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

$redirectUrl = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'admin_history.php';

/**
 * ฟังก์ชันช่วยตอบกลับผลลัพธ์การทำงาน (Helper Response Function)
 * ส่งผลลัพธ์เป็น JSON หากเรียกผ่าน AJAX หรือ Redirect พร้อม Session Flash Message
 * 
 * @param string $status  'success' หรือ 'error'
 * @param string $message ข้อความอธิบายผลลัพธ์
 * @param array  $extra   ข้อมูลเพิ่มเติม (Metadata)
 */
function sendResultResponse(string $status, string $message, array $extra = []) {
    global $isAjax, $redirectUrl;

    if ($isAjax) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array_merge([
            'status'  => $status,
            'message' => $message
        ], $extra), JSON_UNESCAPED_UNICODE);
        exit();
    } else {
        if ($status === 'success') {
            $_SESSION['flash_success'] = $message;
            $_SESSION['alert_msg']     = 'ส่งอีเมลสำเร็จ';
        } else {
            $_SESSION['flash_error'] = $message;
        }
        header("Location: {$redirectUrl}");
        exit();
    }
}

// -----------------------------------------------------------------------------
// 2. ตรวจสอบสิทธิ์การเข้าถึง (Security Authorization: เฉพาะ Admin UT00000001)
// -----------------------------------------------------------------------------
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || ($_SESSION['useraccountType'] ?? '') !== 'UT00000001') {
    sendResultResponse('error', 'คุณไม่มีสิทธิ์ในการส่งอีเมลแจ้งเตือน หรือเซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่');
}

// -----------------------------------------------------------------------------
// 3. นำเข้าไฟล์เชื่อมต่อฐานข้อมูล PDO
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../db.php';

// -----------------------------------------------------------------------------
// 4. รับค่าและตรวจสอบ Parameter รหัสการยืม (IDborrow_back)
// -----------------------------------------------------------------------------
$id = trim($_POST['id'] ?? ($_GET['id'] ?? ''));

if (empty($id)) {
    sendResultResponse('error', 'กรุณาระบุรหัสรายการยืมครุภัณฑ์ที่ต้องการส่งแจ้งเตือน');
}

$today = date('Y-m-d');

try {
    // -------------------------------------------------------------------------
    // 5. Query ข้อมูลด้วย PDO Prepared Statements โดย JOIN 3 ตาราง
    //    - borrow_back (ตารางประวัติการยืม-คืน)
    //    - useraccount (ตารางข้อมูลผู้ใช้งาน เพื่อดึงอีเมลและชื่อผู้ยืม)
    //    - durable_articles (ตารางครุภัณฑ์ เพื่อดึงชื่อและหมายเลขครุภัณฑ์)
    // -------------------------------------------------------------------------
    $sql = "
        SELECT 
            b.IDborrow_back,
            b.Date_borrow,
            COALESCE(b.due_date, b.Deadline_borrow) AS due_date,
            b.Deadline_borrow,
            COALESCE(b.status, CASE WHEN b.Date_back IS NOT NULL AND b.Date_back != '' THEN 'returned' ELSE 'borrowed' END) AS status,
            b.last_reminded_at,
            COALESCE(b.Name_borrow, u.useraccountName, 'ผู้ใช้งาน') AS borrower_name,
            COALESCE(b.userEmail, u.userEmail, '') AS userEmail,
            COALESCE(da.DA_ListName, b.Detail_borrow, 'ครุภัณฑ์ไม่ระบุชื่อ') AS item_name,
            da.DA_Number AS asset_number
        FROM borrow_back b
        LEFT JOIN useraccount u ON b.useraccountID_borrow = u.useraccountID
        LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
        WHERE b.IDborrow_back = :id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    // ตรวจสอบความถูกต้องของรายการ
    if (!$item) {
        sendResultResponse('error', "ไม่พบข้อมูลรายการยืมรหัส #{$id} ในระบบฐานข้อมูล");
    }

    $userEmail = trim($item['userEmail'] ?? '');
    if (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        sendResultResponse('error', "ไม่สามารถส่งอีเมลได้ เนื่องจากไม่พบข้อมูลอีเมลของผู้ยืม หรือรูปแบบอีเมลไม่ถูกต้อง (" . htmlspecialchars($userEmail) . ")");
    }

    if ($item['status'] !== 'borrowed') {
        sendResultResponse('error', "รายการนี้ไม่อยู่ในสถานะยืม (สถานะปัจจุบัน: {$item['status']}) จึงไม่สามารถส่งแจ้งเตือนได้");
    }

    // Anti-Spam: ป้องกันการส่งซ้ำในวันเดียวกัน
    if (!empty($item['last_reminded_at'])) {
        $lastDate = date('Y-m-d', strtotime($item['last_reminded_at']));
        if ($lastDate === $today) {
            $formattedTime = date('d/m/Y H:i', strtotime($item['last_reminded_at']));
            sendResultResponse('error', "รายการนี้ได้รับการส่งอีเมลแจ้งเตือนไปแล้วในวันนี้เมื่อเวลา {$formattedTime} น. (ระบบป้องกันการส่งอีเมลซ้ำวันเดียวกัน)");
        }
    }

    // คำนวณจำนวนวันที่เกินกำหนดส่งคืน
    $dueDate = $item['due_date'];
    $daysOverdue = 0;
    if (!empty($dueDate)) {
        $dueObj   = new DateTime($dueDate);
        $todayObj = new DateTime($today);
        if ($todayObj > $dueObj) {
            $diff = $dueObj->diff($todayObj);
            $daysOverdue = (int)$diff->format('%a');
        }
    }

    // -------------------------------------------------------------------------
    // 6. ดึงข้อมูลการตั้งค่า SMTP จากตาราง system_settings ในฐานข้อมูลด้วย PDO
    // -------------------------------------------------------------------------
    $stmtSettings = $pdo->query("SELECT smtp_host, smtp_port, smtp_user, smtp_pass, sender_name, is_active FROM system_settings WHERE id = 1 LIMIT 1");
    $settings = $stmtSettings ? $stmtSettings->fetch(PDO::FETCH_ASSOC) : null;

    $isActive   = isset($settings['is_active']) ? (int)$settings['is_active'] : 1;
    $smtpHost   = !empty($settings['smtp_host']) ? trim($settings['smtp_host']) : 'smtp.gmail.com';
    $smtpPort   = !empty($settings['smtp_port']) ? (int)$settings['smtp_port'] : 587;
    $smtpUser   = trim($settings['smtp_user'] ?? '');
    // ลบช่องว่างออกเผื่อผู้ใช้คัดลอก App Password มาแบบเว้นวรรค
    $smtpPass   = str_replace(' ', '', trim($settings['smtp_pass'] ?? ''));
    $senderName = !empty($settings['sender_name']) ? trim($settings['sender_name']) : 'ระบบบริหารจัดการครุภัณฑ์';

    // ตรวจสอบว่าระบบส่งอีเมลถูกปิดใช้งานชั่วคราวหรือไม่
    if ($isActive === 0) {
        sendResultResponse('error', 'ระบบส่งอีเมลแจ้งเตือนถูก <strong>"ปิดใช้งานชั่วคราว"</strong> โดยผู้ดูแลระบบ<br><small class="text-muted">หากต้องการส่งแจ้งเตือน กรุณาไปเปิดใช้งานที่เมนู <strong>ตั้งค่าอีเมล</strong> ก่อนทำรายการ</small>');
    }

    // ตรวจสอบว่าแอดมินตั้งค่าอีเมลและรหัสผ่านแอปแล้วหรือยัง
    if (empty($smtpUser) || empty($smtpPass)) {
        sendResultResponse('error', 'กรุณาตั้งค่าบัญชีอีเมล Gmail และรหัสผ่านสำหรับแอป (App Password) ในระบบก่อน (ไปที่เมนู: ตั้งค่าอีเมล)');
    }

    // -------------------------------------------------------------------------
    // 7. โหลดคลาส PHPMailer
    // -------------------------------------------------------------------------
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        $autoloadPath = __DIR__ . '/vendor/autoload.php';
    }

    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
    } else {
        // Fallback โหลดไฟล์คลาสของ PHPMailer โดยตรง
        $srcPath = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
        if (file_exists($srcPath . 'PHPMailer.php')) {
            require_once $srcPath . 'Exception.php';
            require_once $srcPath . 'PHPMailer.php';
            require_once $srcPath . 'SMTP.php';
        } else {
            sendResultResponse('error', 'ไม่พบคลาส PHPMailer ในระบบ กรุณาตรวจสอบโฟลเดอร์ vendor/');
        }
    }

    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // 8. ตั้งค่า PHPMailer ด้วย SMTP ปกติ (ใช้ $mail->Username และ $mail->Password)
    // -------------------------------------------------------------------------
    // ต้องแน่ใจว่าใส่ (true) ไว้ เพื่อให้ระบบโยน Exception เวลาเกิด Error
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    // ----------------------------------------------------
    // 1. ดักจับข้อความ Debug ลงในตัวแปร ไม่ให้ echo ออกหน้าจอโดยตรง (เพื่อป้องกัน JSON ตอบกลับพัง)
    $smtpDebugLog = '';
    $mail->SMTPDebug = 2;
    $mail->Debugoutput = function($str, $level) use (&$smtpDebugLog) {
        $smtpDebugLog .= $str . "\n";
    };

    // 2. ปิดการตรวจสอบ SSL ชั่วคราว (สำคัญมากสำหรับ localhost/XAMPP) พร้อมระบุ peer_name
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
            'peer_name'         => 'smtp.gmail.com'
        )
    );
    // ----------------------------------------------------

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser; // บัญชี Gmail ของผู้ส่งจากฐานข้อมูล
    $mail->Password   = $smtpPass; // รหัสผ่านสำหรับแอป 16 หลักจากฐานข้อมูล
    $mail->CharSet    = 'UTF-8';

    // ข้อมูลผู้ส่งและผู้รับ
    $mail->setFrom($smtpUser, $senderName);
    $mail->addAddress($userEmail, $item['borrower_name'] ?: 'ผู้ใช้งาน');
    $mail->addReplyTo($smtpUser, $senderName);

    // -------------------------------------------------------------------------
    // 9. จัดเตรียมเนื้อหาอีเมลแจ้งเตือน (HTML สวยงาม เป็นทางการ)
    // -------------------------------------------------------------------------
    $borrowerName = htmlspecialchars($item['borrower_name']);
    $itemName     = htmlspecialchars($item['item_name']);
    $assetNumber  = htmlspecialchars($item['asset_number'] ?? '-');
    $dueDateText  = !empty($dueDate) ? htmlspecialchars($dueDate) : '- ไม่ระบุ -';
    $borrowDate   = htmlspecialchars($item['Date_borrow'] ?? '-');

    $mail->isHTML(true);
    $mail->Subject = "⚠️ [แจ้งเตือนด่วน] ครุภัณฑ์เลยกำหนดส่งคืน: {$item['item_name']} (เกินกำหนด {$daysOverdue} วัน)";

    $mail->Body = "
    <div style=\"font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 650px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background-color: #ffffff;\">
        <!-- แถบสีหัวจดหมาย -->
        <div style=\"background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%); color: #ffffff; padding: 25px 30px; text-align: center;\">
            <h2 style=\"margin: 0; font-size: 22px; font-weight: 700;\">🔔 หนังสือแจ้งเตือน: ครุภัณฑ์เลยกำหนดส่งคืน</h2>
            <p style=\"margin: 6px 0 0 0; opacity: 0.9; font-size: 14px;\">ระบบบริหารจัดการครุภัณฑ์และสินทรัพย์ (Asset Reminder System)</p>
        </div>
        
        <div style=\"padding: 30px; color: #334155; line-height: 1.7;\">
            <p style=\"font-size: 16px; margin-top: 0;\">
                เรียนคุณ <strong>{$borrowerName}</strong>,
            </p>
            <p>
                ตามที่ท่านได้ดำเนินการยืมพัสดุ/ครุภัณฑ์จากระบบบริหารจัดการครุภัณฑ์ ขณะนี้รายการดังกล่าวได้ 
                <strong style=\"color: #dc2626;\">ครบกำหนดระยะเวลาการส่งคืนแล้ว</strong> 
                แต่ยังไม่ปรากฏบันทึกการส่งมอบคืนในระบบ โดยมีรายละเอียดรายการยืมดังต่อไปนี้:
            </p>

            <!-- ตารางสรุปรายละเอียดรายการที่ยืม -->
            <table style=\"width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;\">
                <tbody>
                    <tr style=\"border-bottom: 1px solid #e2e8f0;\">
                        <td style=\"padding: 12px 18px; width: 35%; font-weight: 600; color: #475569;\">📦 รายการครุภัณฑ์:</td>
                        <td style=\"padding: 12px 18px; font-weight: bold; color: #0f172a;\">{$itemName}</td>
                    </tr>
                    <tr style=\"border-bottom: 1px solid #e2e8f0;\">
                        <td style=\"padding: 12px 18px; font-weight: 600; color: #475569;\">🏷️ หมายเลขครุภัณฑ์:</td>
                        <td style=\"padding: 12px 18px; font-family: monospace; color: #334155;\">{$assetNumber}</td>
                    </tr>
                    <tr style=\"border-bottom: 1px solid #e2e8f0;\">
                        <td style=\"padding: 12px 18px; font-weight: 600; color: #475569;\">📅 วันที่ยืม:</td>
                        <td style=\"padding: 12px 18px; color: #334155;\">{$borrowDate}</td>
                    </tr>
                    <tr style=\"border-bottom: 1px solid #e2e8f0;\">
                        <td style=\"padding: 12px 18px; font-weight: 600; color: #475569;\">⏰ กำหนดส่งคืนเดิม:</td>
                        <td style=\"padding: 12px 18px; color: #b91c1c; font-weight: 600;\">{$dueDateText}</td>
                    </tr>
                    <tr style=\"border-bottom: 1px solid #e2e8f0;\">
                        <td style=\"padding: 12px 18px; font-weight: 600; color: #475569;\">⏱️ ระยะเวลาที่เกินกำหนด:</td>
                        <td style=\"padding: 12px 18px;\">
                            <span style=\"background-color: #fee2e2; color: #b91c1c; padding: 4px 10px; border-radius: 20px; font-weight: 700; font-size: 13px;\">
                                เลยกำหนดคืน {$daysOverdue} วัน
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- กล่องข้อความแจ้งเตือนระเบียบปฏิบัติ -->
            <div style=\"background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 14px 18px; border-radius: 4px; margin: 20px 0;\">
                <p style=\"margin: 0; color: #991b1b; font-size: 14px; font-weight: 500;\">
                    ⚠️ <strong>ข้อปฏิบัติ:</strong> จึงเรียนมาเพื่อโปรดนำครุภัณฑ์ดังกล่าวมาส่งมอบคืน ณ งานพัสดุและครุภัณฑ์ หรือติดต่อประสานงานเจ้าหน้าที่ผู้ดูแลระบบโดยเร็วที่สุด เพื่อดำเนินการตามระเบียบพัสดุต่อไป
                </p>
            </div>

            <p style=\"margin-top: 25px; font-size: 13px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 15px;\">
                ขอแสดงความนับถือ,<br>
                <strong>{$senderName}</strong><br>
                <em style=\"font-size: 12px; color: #94a3b8;\">* อีเมลฉบับนี้ส่งโดยระบบอัตโนมัติผ่าน Gmail SMTP</em>
            </p>
        </div>
    </div>
    ";

    // Plain text สำรอง
    $mail->AltBody = "แจ้งเตือนครุภัณฑ์เลยกำหนดส่งคืน\n"
                   . "เรียนคุณ {$item['borrower_name']},\n"
                   . "ท่านได้ยืม \"{$item['item_name']}\" เกินกำหนดส่งคืน (กำหนดคืนเดิม: {$dueDateText} | เลยกำหนด {$daysOverdue} วัน)\n"
                   . "กรุณานำมาส่งมอบคืน ณ งานพัสดุและครุภัณฑ์โดยเร็วที่สุด\n"
                   . "{$senderName}";

    // -------------------------------------------------------------------------
    // 10. ส่งอีเมลผ่าน PHPMailer พร้อมระบบ Smart Auto-Fallback (สลับพอร์ต 465 <-> 587 อัตโนมัติ)
    // -------------------------------------------------------------------------
    // หากพอร์ตหลัก (เช่น 465 SSL) ถูกบล็อกหรือเชื่อมต่อไม่ได้ ระบบจะสลับไปลองพอร์ตสำรอง (587 TLS) ให้อัตโนมัติทันที
    $primaryPort  = ($smtpPort === 587) ? 587 : 465;
    $fallbackPort = ($primaryPort === 465) ? 587 : 465;

    $portsToTry = [
        [
            'port'   => $primaryPort,
            'secure' => ($primaryPort === 465) ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
            'label'  => ($primaryPort === 465) ? '465 (SSL)' : '587 (TLS)'
        ],
        [
            'port'   => $fallbackPort,
            'secure' => ($fallbackPort === 465) ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
            'label'  => ($fallbackPort === 465) ? '465 (SSL)' : '587 (TLS)'
        ]
    ];

    $sendSuccess   = false;
    $finalPortUsed = $primaryPort;
    $lastError     = '';

    foreach ($portsToTry as $attemptIndex => $cfg) {
        try {
            $mail->Port       = $cfg['port'];
            $mail->SMTPSecure = $cfg['secure'];
            $mail->Timeout    = 15; // จำกัด Timeout 15 วินาทีเพื่อไม่ให้ผู้ใช้รอนานเกินไปหากโดนบล็อกไฟร์วอลล์

            $mail->send();
            $sendSuccess   = true;
            $finalPortUsed = $cfg['port'];

            // หากพอร์ตที่ส่งสำเร็จ ไม่ตรงกับที่เคยบันทึกไว้ใน DB ให้บันทึกอัปเดตให้อัตโนมัติ เพื่อการส่งครั้งต่อไปจะเร็วทันที
            if ($finalPortUsed !== $smtpPort) {
                try {
                    $updPortStmt = $pdo->prepare("UPDATE system_settings SET smtp_port = :port WHERE id = 1");
                    $updPortStmt->execute([':port' => $finalPortUsed]);
                } catch (Exception $e) {}
            }
            break;
        } catch (PHPMailer\PHPMailer\Exception $e) {
            $lastError = $e->getMessage();

            // ถ้ารหัสผ่านแอปไม่ถูกต้อง (Authentication Failure) ไม่ต้องลองสลับพอร์ต เพราะปัญหาอยู่ที่ App Password
            if (stripos($lastError, 'Password not accepted') !== false || stripos($lastError, 'BadCredentials') !== false) {
                throw $e;
            }

            // ถ้าเป็นการทดสอบพอร์ตแรกแล้วเกิดข้อผิดพลาดด้านเครือข่าย ให้บันทึกข้อความแล้วลองพอร์ตถัดไป
            if ($attemptIndex === 0) {
                $smtpDebugLog .= "--> พอร์ต {$cfg['label']} เชื่อมต่อไม่สำเร็จ ({$lastError}) ระบบกำลังสลับไปทดสอบพอร์ตสำรอง...\n";
                usleep(300000); // 0.3 วินาที
                continue;
            }

            // ถ้าลองครบทั้ง 2 พอร์ตแล้วยังล้มเหลว โยน Exception ออกไป
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // 11. อัปเดตเวลา last_reminded_at ในฐานข้อมูลเป็นเวลาปัจจุบัน
    // -------------------------------------------------------------------------
    $updateSql = "
        UPDATE borrow_back 
        SET last_reminded_at = CURRENT_TIMESTAMP 
        WHERE IDborrow_back = :id
    ";
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([':id' => $id]);

    // บันทึก Log การส่งลงไฟล์ logs/sent_emails.log
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $nowFormatted = date('Y-m-d H:i:s');
    $logEntry = "========================================================\n"
              . "TIMESTAMP : {$nowFormatted}\n"
              . "TRIGGER   : ADMIN_MANUAL_ACTION (PHPMailer Gmail SMTP Port {$finalPortUsed})\n"
              . "TO        : {$item['borrower_name']} <{$userEmail}>\n"
              . "SUBJECT   : {$mail->Subject}\n"
              . "STATUS    : SENT_SUCCESS_VIA_GMAIL_SMTP\n"
              . "========================================================\n\n";
    @file_put_contents($logDir . '/sent_emails.log', $logEntry, FILE_APPEND);

    // -------------------------------------------------------------------------
    // 12. ส่งผลลัพธ์ JSON กลับไปยัง Frontend สำหรับ SweetAlert2
    // -------------------------------------------------------------------------
    sendResultResponse('success', "ส่งอีเมลแจ้งเตือนไปยัง <strong>{$userEmail}</strong> ผ่าน Gmail SMTP (Port {$finalPortUsed}) เรียบร้อยแล้ว", [
        'item_id'          => $id,
        'item_name'        => $item['item_name'],
        'borrower_name'    => $item['borrower_name'],
        'user_email'       => $userEmail,
        'last_reminded_at' => date('d/m/Y H:i'),
        'port_used'        => $finalPortUsed
    ]);

} catch (PHPMailer\PHPMailer\Exception $me) {
    // จัดการข้อผิดพลาดจาก PHPMailer และให้คำแนะนำที่ชัดเจน
    $errInfo = !empty($mail->ErrorInfo) ? $mail->ErrorInfo : $me->getMessage();
    
    // ตรวจสอบกรณี Connection refused / Socket error 61
    if (stripos($errInfo, 'Connection refused') !== false || stripos($errInfo, 'error 61') !== false || stripos($errInfo, 'Could not connect to SMTP host') !== false) {
        $userMsg = "<strong>การเชื่อมต่อ Gmail SMTP ล้มเหลว (Connection Refused):</strong><br>"
                 . "<div class=\"alert alert-warning text-start my-2 small\">"
                 . "<strong><i class=\"fa-solid fa-triangle-exclamation me-1\"></i> สาเหตุหลักและวิธีแก้ไข:</strong><br>"
                 . "เครือข่ายอินเทอร์เน็ตที่ท่านกำลังเชื่อมต่ออยู่ (เช่น <strong>Wi-Fi มหาวิทยาลัย หรือหอพัก</strong>) มีการบล็อกพอร์ตส่งอีเมลออกนอกระบบ เพื่อป้องกันสแปม<br><br>"
                 . "👉 <strong>วิธีแก้ไขที่ใช้งานได้ทันที 100%:</strong><br>"
                 . "1. <strong>แชร์เน็ตมือถือ (Personal Hotspot):</strong> ให้เครื่อง Mac สลับไปต่อ Wi-Fi จากฮอตสปอตมือถือ (4G/5G) ชั่วคราว แล้วกดปุ่มส่งเตือนอีกครั้ง จะส่งผ่านได้ทันที<br>"
                 . "2. <strong>เชื่อมต่อ VPN:</strong> หากจำเป็นต้องใช้เน็ตมหาวิทยาลัย ให้เชื่อมต่อ VPN เพื่อทะลุบล็อกไฟร์วอลล์<br>"
                 . "3. <strong>ตรวจสอบการตั้งค่า:</strong> ตรวจสอบว่าพอร์ตในเมนู <strong>ตั้งค่าอีเมล</strong> บันทึกเป็น <strong>พอร์ต 465 (SSL)</strong>"
                 . "</div>";
    }
    // ตรวจสอบกรณี Username and Password not accepted
    elseif (stripos($errInfo, 'Username and Password not accepted') !== false || stripos($errInfo, 'BadCredentials') !== false) {
        $userMsg = "<strong>การยืนยันตัวตนล้มเหลว (Authentication Failed):</strong><br>"
                 . "<div class=\"alert alert-danger text-start my-2 small\">"
                 . "รหัสผ่านสำหรับแอป (App Password 16 หลัก) ไม่ถูกต้อง หรือบัญชี Google ยังไม่ได้เปิด 2-Step Verification<br>"
                 . "กรุณาไปที่เมนู <strong>ตั้งค่าอีเมล</strong> เพื่อตรวจสอบหรือสร้าง App Password 16 หลักชุดใหม่จาก Google Account"
                 . "</div>";
    } else {
        $userMsg = "การส่งอีเมลผ่าน Gmail SMTP ล้มเหลว: {$errInfo}";
    }

    sendResultResponse('error', $userMsg, [
        'debug_log' => $smtpDebugLog
    ]);
} catch (PDOException $e) {
    sendResultResponse('error', "เกิดข้อผิดพลาดทางฐานข้อมูล: " . $e->getMessage());
} catch (Exception $e) {
    sendResultResponse('error', "เกิดข้อผิดพลาด: " . $e->getMessage());
}
