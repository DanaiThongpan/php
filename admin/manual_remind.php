<?php
/**
 * admin/manual_remind.php - ตัวประมวลผลการส่งอีเมลทวงถามครุภัณฑ์เกินกำหนดส่งคืนด้วย REST Mail API
 * Backend Controller for Overdue Borrowing Email Reminders via UBU Admission REST Mail API
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. ตรวจสอบประเภทการเรียกใช้งาน (AJAX / JSON Request Detector)
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || isset($_GET['ajax']) 
       || isset($_POST['ajax'])
       || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
       || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

$redirectUrl = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'admin_history.php';

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

// 2. ตรวจสอบสิทธิ์การเข้าถึง (Security Authorization: เฉพาะ Admin UT00000001)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || ($_SESSION['useraccountType'] ?? '') !== 'UT00000001') {
    sendResultResponse('error', 'คุณไม่มีสิทธิ์ในการส่งอีเมลแจ้งเตือน หรือเซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่');
}

// 3. นำเข้าไฟล์เชื่อมต่อฐานข้อมูล PDO และ Mailer Service
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../mailer.php';

// 4. รับค่าและตรวจสอบ Parameter รหัสการยืม (IDborrow_back)
$id = trim($_POST['id'] ?? ($_GET['id'] ?? ''));

if (empty($id)) {
    sendResultResponse('error', 'กรุณาระบุรหัสรายการยืมครุภัณฑ์ที่ต้องการส่งแจ้งเตือน');
}

$today = date('Y-m-d');

try {
    // 5. Query ข้อมูลด้วย PDO Prepared Statements
    $sql = "
        SELECT 
            b.IDborrow_back,
            b.Date_borrow,
            COALESCE(b.due_date, b.Deadline_borrow) AS due_date,
            b.Deadline_borrow,
            COALESCE(b.status, CASE WHEN b.Date_back IS NOT NULL AND b.Date_back != '' THEN 'returned' ELSE 'borrowed' END) AS status,
            b.last_reminded_at,
            COALESCE(b.userEmail, u.userEmail) AS user_email,
            COALESCE(b.Name_borrow, u.useraccountName, 'ผู้ยืม') AS borrower_name,
            COALESCE(da.DA_ListName, b.Detail_borrow, 'ครุภัณฑ์ไม่ระบุชื่อ') AS item_name,
            da.DA_Number AS asset_number
        FROM borrow_back b
        LEFT JOIN useraccount u ON b.useraccountID_borrow = u.useraccountID
        LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
        WHERE b.IDborrow_back = :id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        sendResultResponse('error', "ไม่พบข้อมูลรายการยืมรหัส [{$id}] ในระบบ");
    }

    if ($item['status'] === 'returned') {
        sendResultResponse('error', 'รายการนี้ได้รับการส่งคืนครุภัณฑ์เรียบร้อยแล้ว ไม่จำเป็นต้องส่งแจ้งเตือน');
    }

    $dueDate = $item['due_date'];
    if (empty($dueDate)) {
        sendResultResponse('error', 'รายการนี้ไม่มีข้อมูลวันกำหนดส่งคืน จึงไม่สามารถประมวลผลการแจ้งเตือนได้');
    }

    $dueDateObj = new DateTime($dueDate);
    $todayObj   = new DateTime($today);

    $isOverdue   = ($todayObj > $dueDateObj);
    $interval    = $dueDateObj->diff($todayObj);
    $diffDays    = (int)$interval->format('%a');
    $daysOverdue = $isOverdue ? $diffDays : 0;

    $userEmail = trim($item['user_email'] ?? '');
    if (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
        sendResultResponse('error', "รายการนี้ไม่มีอีเมลผู้ยืมที่ถูกต้อง (ข้อมูลอีเมล: \"{$userEmail}\") กรุณาตรวจสอบข้อมูลผู้ใช้งาน");
    }

    // ป้องกันการส่งซ้ำในวันเดียวกัน
    if (!empty($item['last_reminded_at'])) {
        $lastRemindedDate = date('Y-m-d', strtotime($item['last_reminded_at']));
        if ($lastRemindedDate === $today && !isset($_POST['force']) && !isset($_GET['force'])) {
            sendResultResponse('error', "รายการนี้ได้รับการส่งอีเมลแจ้งเตือนไปแล้วในวันนี้ ({$item['last_reminded_at']}) เพื่อป้องกันการส่งสแปมซ้ำ");
        }
    }

    // 6. ดึงข้อมูลการตั้งค่าอีเมลจากฐานข้อมูล
    $stmtSettings = $pdo->query("SELECT smtp_user, smtp_pass, sender_name, sender_email, is_active FROM system_settings WHERE id = 1 LIMIT 1");
    $settings = $stmtSettings ? $stmtSettings->fetch(PDO::FETCH_ASSOC) : null;

    $isActive    = isset($settings['is_active']) ? (int)$settings['is_active'] : 1;
    $smtpUser    = trim($settings['smtp_user'] ?? '');
    $smtpPass    = trim($settings['smtp_pass'] ?? '');
    $senderName  = !empty($settings['sender_name']) ? trim($settings['sender_name']) : 'งานบริหารทั่วไป กองวิชาการ';
    $senderEmail = !empty($settings['sender_email']) ? trim($settings['sender_email']) : $smtpUser;

    if ($isActive === 0) {
        sendResultResponse('error', 'ระบบส่งอีเมลแจ้งเตือนถูก <strong>"ปิดใช้งานชั่วคราว"</strong> โดยผู้ดูแลระบบ<br><small class="text-muted">หากต้องการส่งแจ้งเตือน กรุณาไปเปิดใช้งานที่เมนู <strong>ตั้งค่าอีเมล</strong> ก่อนทำรายการ</small>');
    }

    if (empty($smtpUser) || empty($smtpPass)) {
        sendResultResponse('error', 'กรุณาตั้งค่าบัญชี SMTP_USER และ SMTP_PASS ในระบบก่อน (ไปที่เมนู: ตั้งค่าระบบส่งอีเมล)');
    }

    // 7. จัดเตรียมเนื้อหาและส่งผ่าน REST API ด้วย sendSystemEmail()
    $borrowerName = htmlspecialchars($item['borrower_name']);
    $itemName     = htmlspecialchars($item['item_name']);
    $assetNumber  = htmlspecialchars($item['asset_number'] ?? '-');
    $dueDateText  = !empty($dueDate) ? htmlspecialchars($dueDate) : '- ไม่ระบุ -';
    $borrowDate   = htmlspecialchars($item['Date_borrow'] ?? '-');

    $dueColor     = $isOverdue ? '#b91c1c' : '#1e40af';
    $boxBg        = $isOverdue ? '#fef2f2' : '#eff6ff';
    $boxBorder    = $isOverdue ? '#ef4444' : '#3b82f6';
    $boxTextColor = $isOverdue ? '#991b1b' : '#1e40af';

    if ($isOverdue) {
        $subject = "⚠️ [แจ้งเตือนด่วน] ครุภัณฑ์เลยกำหนดส่งคืน: {$item['item_name']} (เกินกำหนด {$daysOverdue} วัน)";
        $headerBg = "linear-gradient(135deg, #dc2626 0%, #991b1b 100%)";
        $headerTitle = "🔔 หนังสือแจ้งเตือน: ครุภัณฑ์เลยกำหนดส่งคืน";
        $leadText = "ตามที่ท่านได้ดำเนินการยืมพัสดุ/ครุภัณฑ์จากระบบบริหารจัดการครุภัณฑ์ ขณะนี้รายการดังกล่าวได้ 
                    <strong style=\"color: #dc2626;\">ครบกำหนดระยะเวลาการส่งคืนแล้ว</strong> 
                    แต่ยังไม่ปรากฏบันทึกการส่งมอบคืนในระบบ โดยมีรายละเอียดรายการยืมดังต่อไปนี้:";
        $statusRow = "
            <tr style=\"border-bottom: 1px solid #e2e8f0;\">
                <td style=\"padding: 12px 18px; font-weight: 600; color: #475569;\">⏱️ ระยะเวลาที่เกินกำหนด:</td>
                <td style=\"padding: 12px 18px;\">
                    <span style=\"background-color: #fee2e2; color: #b91c1c; padding: 4px 10px; border-radius: 20px; font-weight: 700; font-size: 13px;\">
                        เลยกำหนดคืน {$daysOverdue} วัน
                    </span>
                </td>
            </tr>
        ";
        $actionNotice = "⚠️ <strong>ข้อปฏิบัติ:</strong> จึงเรียนมาเพื่อโปรดนำครุภัณฑ์ดังกล่าวมาส่งมอบคืน ณ งานพัสดุและครุภัณฑ์ หรือติดต่อประสานงานเจ้าหน้าที่ผู้ดูแลระบบโดยเร็วที่สุด เพื่อดำเนินการตามระเบียบพัสดุต่อไป";
    } else {
        $diffText = ($diffDays === 0) ? 'ครบกำหนดส่งคืนวันนี้' : "เหลือเวลาอีก {$diffDays} วัน";
        $subject = "📋 [แจ้งเตือน] กำหนดส่งคืนครุภัณฑ์: {$item['item_name']} (ครบกำหนด {$dueDateText})";
        $headerBg = "linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%)";
        $headerTitle = "📋 หนังสือแจ้งเตือน: กำหนดส่งคืนครุภัณฑ์";
        $leadText = "ตามที่ท่านได้ดำเนินการยืมพัสดุ/ครุภัณฑ์จากระบบบริหารจัดการครุภัณฑ์ ขอเรียนแจ้งเตือนว่ารายการดังกล่าว 
                    <strong style=\"color: #2563eb;\">ใกล้ถึงกำหนดส่งคืนแล้ว</strong> 
                    โดยมีรายละเอียดรายการยืมดังต่อไปนี้:";
        $statusRow = "
            <tr style=\"border-bottom: 1px solid #e2e8f0;\">
                <td style=\"padding: 12px 18px; font-weight: 600; color: #475569;\">⏱️ สถานะกำหนดส่งคืน:</td>
                <td style=\"padding: 12px 18px;\">
                    <span style=\"background-color: #dbeafe; color: #1e40af; padding: 4px 10px; border-radius: 20px; font-weight: 700; font-size: 13px;\">
                        {$diffText}
                    </span>
                </td>
            </tr>
        ";
        $actionNotice = "ℹ️ <strong>ข้อแนะนำ:</strong> โปรดจัดเตรียมครุภัณฑ์และอุปกรณ์เพื่อนำมาส่งมอบคืนตามกำหนด หรือติดต่อเจ้าหน้าที่หากประสงค์ขอขยายเวลาการยืม";
    }

    $bodyHtml = "
    <div style=\"font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 650px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background-color: #ffffff;\">
        <div style=\"background: {$headerBg}; color: #ffffff; padding: 25px 30px; text-align: center;\">
            <h2 style=\"margin: 0; font-size: 22px; font-weight: 700;\">{$headerTitle}</h2>
            <p style=\"margin: 6px 0 0 0; opacity: 0.9; font-size: 14px;\">ระบบบริหารจัดการครุภัณฑ์และสินทรัพย์ (Asset Reminder System)</p>
        </div>
        
        <div style=\"padding: 30px; color: #334155; line-height: 1.7;\">
            <p style=\"font-size: 16px; margin-top: 0;\">
                เรียนคุณ <strong>{$borrowerName}</strong>,
            </p>
            <p>
                {$leadText}
            </p>

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
                        <td style=\"padding: 12px 18px; font-weight: 600; color: #475569;\">⏰ กำหนดส่งคืน:</td>
                        <td style=\"padding: 12px 18px; color: {$dueColor}; font-weight: 600;\">{$dueDateText}</td>
                    </tr>
                    {$statusRow}
                </tbody>
            </table>

            <div style=\"background-color: {$boxBg}; border-left: 4px solid {$boxBorder}; padding: 14px 18px; border-radius: 4px; margin: 20px 0;\">
                <p style=\"margin: 0; color: {$boxTextColor}; font-size: 14px; font-weight: 500;\">
                    {$actionNotice}
                </p>
            </div>

            <p style=\"margin-top: 25px; font-size: 13px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 15px;\">
                ขอแสดงความนับถือ,<br>
                <strong>{$senderName}</strong><br>
                <em style=\"font-size: 12px; color: #94a3b8;\">* อีเมลฉบับนี้ส่งโดยระบบอัตโนมัติผ่าน External REST API</em>
            </p>
        </div>
    </div>
    ";

    $sendRes = sendSystemEmail($userEmail, $subject, $bodyHtml, $pdo);

    if (!$sendRes['success']) {
        sendResultResponse('error', "การส่งอีเมลไม่สำเร็จ: " . $sendRes['message']);
    }

    // 8. อัปเดตเวลา last_reminded_at ในฐานข้อมูล
    $updateSql = "UPDATE borrow_back SET last_reminded_at = CURRENT_TIMESTAMP WHERE IDborrow_back = :id";
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([':id' => $id]);

    // บันทึก Log
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $nowFormatted = date('Y-m-d H:i:s');
    $logEntry = "========================================================\n"
              . "TIMESTAMP : {$nowFormatted}\n"
              . "TRIGGER   : ADMIN_MANUAL_ACTION (UBU REST Mail API)\n"
              . "TO        : {$item['borrower_name']} <{$userEmail}>\n"
              . "SUBJECT   : {$subject}\n"
              . "STATUS    : SENT_SUCCESS_VIA_REST_API\n"
              . "========================================================\n\n";
    @file_put_contents($logDir . '/sent_emails.log', $logEntry, FILE_APPEND);

    sendResultResponse('success', "ส่งอีเมลแจ้งเตือนไปยัง <strong>{$userEmail}</strong> ผ่าน REST Mail API เรียบร้อยแล้ว", [
        'item_id'          => $id,
        'item_name'        => $item['item_name'],
        'borrower_name'    => $item['borrower_name'],
        'user_email'       => $userEmail,
        'last_reminded_at' => date('d/m/Y H:i')
    ]);

} catch (Exception $e) {
    sendResultResponse('error', "เกิดข้อผิดพลาดในการส่งอีเมล: " . $e->getMessage());
}
