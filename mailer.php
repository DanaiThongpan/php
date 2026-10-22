<?php
/**
 * mailer.php - โมดูลสำหรับจัดการการส่งอีเมลแจ้งเตือนผ่าน PHPMailer
 * Reusable PHPMailer Service for Overdue Borrowing Reminder System
 */

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

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
    // กำหนดให้โยน Exception เมื่อส่งไม่สำเร็จ
    $mail = new PHPMailer(true);

    try {
        // 1. โหลดค่าการเชื่อมต่อ SMTP จากไฟล์ config
        $config = file_exists(__DIR__ . '/mail_config.php') 
            ? require __DIR__ . '/mail_config.php' 
            : [];

        // 2. ตั้งค่า Server SMTP (Clean, Reusable SMTP Setup Block)
        $mail->isSMTP();
        $mail->Host       = $config['host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = $config['smtp_auth'] ?? true;
        $mail->Username   = $config['username'] ?? '';
        $mail->Password   = $config['password'] ?? '';
        $mail->SMTPSecure = ($config['smtp_secure'] === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $config['port'] ?? 587;
        $mail->CharSet    = 'UTF-8';
        $mail->SMTPDebug  = $config['debug'] ?? 0;

        // 3. กำหนดข้อมูลผู้ส่งและผู้รับ
        $fromEmail = $config['from_email'] ?? 'noreply-asset@organization.go.th';
        $fromName  = $config['from_name'] ?? 'ระบบบริหารจัดการครุภัณฑ์ (Asset Management)';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($recipientEmail, $borrowerName ?: 'ผู้ใช้งาน');

        // 4. สร้างเนื้อหาอีเมล (HTML และ Plain Text Fallback)
        $mail->isHTML(true);
        $mail->Subject = "⚠️ [แจ้งเตือนด่วน] ครุภัณฑ์เลยกำหนดส่งคืน: {$itemName} (เกินกำหนด {$daysOverdue} วัน)";

        // HTML Body ตกแต่งด้วย CSS Styling ให้ดูเป็นมืออาชีพ
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

                <!-- กล่องตารางรายละเอียด -->
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

        $mail->Body    = $bodyContent;
        // ข้อความสำรองกรณีโปรแกรมอีเมลไม่รองรับ HTML
        $mail->AltBody = "แจ้งเตือนครุภัณฑ์เลยกำหนดส่งคืน\n"
                       . "เรียน: {$borrowerName}\n"
                       . "รายการครุภัณฑ์: {$itemName}\n"
                       . "กำหนดส่งคืน: {$dueDate}\n"
                       . "ระยะเวลาที่เลยกำหนด: เลยกำหนดคืน {$daysOverdue} วัน\n"
                       . "กรุณานำครุภัณฑ์มาติดต่อส่งคืนโดยเร็ว";

        // 5. ส่งอีเมล
        $mail->send();

        return [
            'success' => true,
            'message' => 'ส่งอีเมลแจ้งเตือนสำเร็จ'
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => "ส่งอีเมลไม่สำเร็จ: " . $mail->ErrorInfo . " ({$e->getMessage()})"
        ];
    }
}
