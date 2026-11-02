<?php
/**
 * mailer.php - โมดูลสำหรับจัดการการส่งอีเมลแจ้งเตือนผ่าน REST API
 * Reusable Mail Service using Admission UBU REST API & Overdue Reminder System
 */

require_once __DIR__ . '/db.php';

/**
 * ฟังก์ชันทำความสะอาดและแปลงข้อมูลให้อยู่ในรูปแบบ UTF-8 ที่สมบูรณ์แบบ (Recursive)
 * ป้องกันปัญหา json_encode คืนค่า false เมื่อพบอักขระ Non-UTF8 หรือ Malformed Bytes
 *
 * @param mixed $data
 * @return mixed
 */
if (!function_exists('sanitizeUtf8Recursive')) {
    function sanitizeUtf8Recursive($data)
    {
        if (is_string($data)) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }
        if (is_array($data)) {
            $cleaned = [];
            foreach ($data as $key => $val) {
                $cleanKey = is_string($key) ? mb_convert_encoding($key, 'UTF-8', 'UTF-8') : $key;
                $cleaned[$cleanKey] = sanitizeUtf8Recursive($val);
            }
            return $cleaned;
        }
        return $data;
    }
}

/**
 * ฟังก์ชันกลางสำหรับส่งอีเมลผ่าน REST API (External Mail API)
 * API Endpoint: https://admission.ubu.ac.th/mailsender/api/send-email (Method: POST)
 *
 * @param string $to             อีเมลผู้รับ
 * @param string $subject        หัวข้ออีเมล
 * @param string $htmlContent    เนื้อหาอีเมลในรูปแบบ HTML
 * @param PDO|null $pdo          อ็อบเจกต์เชื่อมต่อฐานข้อมูล PDO
 * @param array|null $overrideConfig ค่าคอนฟิกเสริมสำหรับทดสอบชั่วคราว
 * @return array ['success' => bool, 'message' => string, 'data' => mixed]
 */
function sendSystemEmail($to, $subject, $htmlContent, ?PDO $pdo = null, ?array $overrideConfig = null): array
{
    $to = trim($to);
    $subject = trim($subject);

    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'อีเมลผู้รับไม่ถูกต้อง'];
    }

    if (empty($subject)) {
        return ['success' => false, 'message' => 'กรุณาระบุหัวข้ออีเมล'];
    }

    // 1. ดึงข้อมูลการตั้งค่าจาก DB หากไม่มี overrideConfig
    $config = $overrideConfig;
    if (empty($config)) {
        if ($pdo === null) {
            global $pdo;
        }
        try {
            $stmt = $pdo->query("SELECT smtp_user, smtp_pass, sender_name, sender_email, is_active FROM system_settings WHERE id = 1 LIMIT 1");
            $config = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'ไม่สามารถดึงข้อมูลการตั้งค่าอีเมลจากฐานข้อมูล: ' . $e->getMessage()];
        }
    }

    $smtpUser    = trim($config['smtp_user'] ?? '');
    $smtpPass    = trim($config['smtp_pass'] ?? '');
    $senderName  = trim($config['sender_name'] ?? 'ระบบบริหารจัดการครุภัณฑ์');
    $senderEmail = trim($config['sender_email'] ?? '');
    $isActive    = isset($config['is_active']) ? (int)$config['is_active'] : 1;

    // ตรวจสอบสถานะเปิดใช้งาน (ยกเว้นการส่งแบบ overrideConfig สำหรับทดสอบ)
    if (empty($overrideConfig) && $isActive === 0) {
        return ['success' => false, 'message' => 'ระบบส่งอีเมลถูกปิดใช้งานชั่วคราวในการตั้งค่า'];
    }

    if (empty($smtpUser) || empty($smtpPass)) {
        return ['success' => false, 'message' => 'ยังไม่ได้ตั้งค่า SMTP_USER หรือ SMTP_PASS ในระบบ'];
    }

    if (empty($senderEmail)) {
        $senderEmail = $smtpUser;
    }

    // จัดรูปแบบ from: "[Sender Name] [Sender Email]" ตามที่ API กำหนด
    $from = (!empty($senderName) && !empty($senderEmail))
        ? "{$senderName} {$senderEmail}"
        : ($senderEmail ?: $senderName);

    // 2. จัดเตรียม Payload JSON พร้อมทำความสะอาด UTF-8 (Sanitization)
    $payload = [
        'SMTP_USER' => $smtpUser,
        'SMTP_PASS' => $smtpPass,
        'from'      => $from,
        'to'        => $to,
        'subject'   => $subject,
        'html'      => $htmlContent
    ];

    // [SECURITY PATCH: UTF-8 Sanitization] แปลงข้อมูลทั้งหมดเป็น UTF-8 ป้องกัน json_encode พัง
    $cleanPayload = sanitizeUtf8Recursive($payload);
    $jsonPayload = json_encode($cleanPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาดในการแปลง JSON Payload: ' . json_last_error_msg()];
    }

    // 3. ยิง HTTP POST ผ่าน cURL
    $apiUrl = 'https://admission.ubu.ac.th/mailsender/api/send-email';
    $ch = curl_init($apiUrl);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,  // [SECURITY PATCH]: ป้องกัน Worker Starvation เชื่อมต่อไม่เกิน 5 วินาที
        CURLOPT_TIMEOUT        => 10, // [SECURITY PATCH]: รอผลตอบกลับรวมไม่เกิน 10 วินาที ป้องกันหน้าเว็บค้าง
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'Content-Length: ' . strlen($jsonPayload)
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    @curl_close($ch);

    if ($curlErrno !== 0) {
        return [
            'success' => false,
            'message' => "การเชื่อมต่อ API ล้มเหลว (cURL Error {$curlErrno}: {$curlError})"
        ];
    }

    $resData = json_decode($response, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        if (is_array($resData) && isset($resData['status']) && $resData['status'] === 'success') {
            return [
                'success' => true,
                'message' => $resData['message'] ?? 'ส่งอีเมลผ่าน API สำเร็จเรียบร้อยแล้ว',
                'data'    => $resData
            ];
        } else {
            $err = $resData['error'] ?? ($resData['message'] ?? 'การส่งอีเมลไม่สำเร็จ');
            return [
                'success' => false,
                'message' => "API ส่งกลับข้อผิดพลาด: {$err}",
                'data'    => $resData
            ];
        }
    } else {
        $err = is_array($resData) ? ($resData['error'] ?? ($resData['message'] ?? '')) : '';
        $errMsg = !empty($err) ? $err : "HTTP Status {$httpCode}";
        return [
            'success'   => false,
            'message'   => "เซิร์ฟเวอร์ Mail API ตอบกลับข้อผิดพลาด ({$errMsg})",
            'data'      => $resData,
            'http_code' => $httpCode
        ];
    }
}

/**
 * ส่งอีเมลแจ้งเตือนผู้ยืมครุภัณฑ์ที่เลยกำหนดส่งคืน
 *
 * @param string $recipientEmail อีเมลของผู้ยืม (userEmail)
 * @param string $borrowerName   ชื่อ-นามสกุลของผู้ยืม
 * @param string $itemName       ชื่อรายการครุภัณฑ์ (Item Name)
 * @param string $dueDate        วันที่ครบกำหนดคืน (Due Date: YYYY-MM-DD)
 * @param int    $daysOverdue    จำนวนวันที่เลยกำหนด (Days Overdue)
 * @return array ผลลัพธ์ ['success' => bool, 'message' => string]
 */
function sendOverdueReminder($recipientEmail, $borrowerName, $itemName, $dueDate, $daysOverdue)
{
    $subject = "⚠️ [แจ้งเตือนด่วน] ครุภัณฑ์เลยกำหนดส่งคืน: {$itemName} (เกินกำหนด {$daysOverdue} วัน)";

    $bodyContent = "
    <div style=\"font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; max-width: 650px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background-color: #ffffff;\">
        <div style=\"background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: #ffffff; padding: 25px 30px; text-align: center;\">
            <h2 style=\"margin: 0; font-size: 22px; font-weight: 700;\">🔔 หนังสือแจ้งเตือน: ครุภัณฑ์เลยกำหนดส่งคืน</h2>
            <p style=\"margin: 5px 0 0 0; opacity: 0.9; font-size: 14px;\">ระบบบริหารจัดการครุภัณฑ์และสินทรัพย์ (Asset Reminder System)</p>
        </div>
        
        <div style=\"padding: 30px; color: #334155; line-height: 1.7;\">
            <p style=\"font-size: 16px; margin-top: 0;\">
                เรียนคุณ <strong>" . htmlspecialchars($borrowerName ?: 'ผู้ใช้งาน') . "</strong>,
            </p>
            <p>
                ระบบได้ทำการตรวจสอบฐานข้อมูลการยืมครุภัณฑ์ พบว่ารายการครุภัณฑ์ที่คุณได้ทำรายการยืมไว้ 
                <strong style=\"color: #dc2626;\">ได้เลยกำหนดเวลาส่งคืนตามที่ระบุไว้แล้ว</strong> ดังรายละเอียดต่อไปนี้:
            </p>

            <table style=\"width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;\">
                <tbody>
                    <tr style=\"border-bottom: 1px solid #e2e8f0;\">
                        <td style=\"padding: 12px 18px; width: 35%; font-weight: 600; color: #475569;\">📦 รายการครุภัณฑ์:</td>
                        <td style=\"padding: 12px 18px; font-weight: bold; color: #0f172a;\">" . htmlspecialchars($itemName) . "</td>
                    </tr>
                    <tr style=\"border-bottom: 1px solid #e2e8f0;\">
                        <td style=\"padding: 12px 18px; font-weight: 600; color: #475569;\">📅 กำหนดส่งคืนเดิม:</td>
                        <td style=\"padding: 12px 18px; color: #b91c1c; font-weight: 600;\">" . htmlspecialchars($dueDate) . "</td>
                    </tr>
                    <tr style=\"border-bottom: 1px solid #e2e8f0;\">
                        <td style=\"padding: 12px 18px; font-weight: 600; color: #475569;\">⏱️ ระยะเวลาที่เกินกำหนด:</td>
                        <td style=\"padding: 12px 18px;\">
                            <span style=\"background-color: #fee2e2; color: #b91c1c; padding: 4px 10px; border-radius: 20px; font-weight: 700; font-size: 13px;\">
                                เลยกำหนดคืน {$daysOverdue} วัน
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style=\"padding: 12px 18px; font-weight: 600; color: #475569;\">🕒 วันที่แจ้งเตือน:</td>
                        <td style=\"padding: 12px 18px; color: #64748b;\">" . date('Y-m-d H:i:s') . "</td>
                    </tr>
                </tbody>
            </table>

            <div style=\"background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 14px 18px; border-radius: 4px; margin: 20px 0;\">
                <p style=\"margin: 0; color: #991b1b; font-size: 14px; font-weight: 500;\">
                    ⚠️ <strong>ข้อปฏิบัติ:</strong> กรุณานำครุภัณฑ์รายการดังกล่าวมาติดต่อส่งคืน ณ กลุ่มงานพัสดุและครุภัณฑ์ หรือติดต่อประสานงานเจ้าหน้าที่ผู้ดูแลระบบโดยเร็วที่สุด เพื่อไม่ให้ส่งผลกระทบต่อภารกิจการใช้งานของหน่วยงาน
                </p>
            </div>

            <p style=\"margin-top: 25px; font-size: 13px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 15px;\">
                ขอแสดงความนับถือ,<br>
                <strong>กลุ่มงานพัสดุ ครุภัณฑ์ และบริหารสินทรัพย์</strong><br>
                <em>* อีเมลฉบับนี้เป็นการแจ้งเตือนอัตโนมัติจากระบบ กรุณาอย่าตอบกลับ</em>
            </p>
        </div>
    </div>
    ";

    return sendSystemEmail($recipientEmail, $subject, $bodyContent);
}
