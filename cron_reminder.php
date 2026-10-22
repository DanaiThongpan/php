<?php
/**
 * cron_reminder.php - Automated Overdue Borrowing Email Reminder
 * สคริปต์สำหรับการรันผ่าน Linux Crontab (CLI) ทุกวันเพื่อตรวจสอบและส่งอีเมลแจ้งเตือนผู้ยืมครุภัณฑ์ที่เลยกำหนดคืนอัตโนมัติ
 */

// 1. ตรวจสอบการรันคำสั่ง หากรันผ่าน Web Browser ให้แสดงผลเป็น Plain text
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=UTF-8');
}

// 2. นำเข้าไฟล์เชื่อมต่อฐานข้อมูล PDO และระบบ Mailer
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

$startTime     = microtime(true);
$currentDate   = date('Y-m-d');
$nowTimestamp  = date('Y-m-d H:i:s');

echo "========================================================\n";
echo "[{$nowTimestamp}] Starting Overdue Reminder Cron Job...\n";
echo "========================================================\n";

try {
    // 3. Query: ดึงรายการที่ status = 'borrowed' AND due_date < CURRENT_DATE
    // และ (last_reminded_at is NULL OR DATE(last_reminded_at) < CURRENT_DATE)
    $sql = "
        SELECT 
            b.IDborrow_back AS id,
            COALESCE(da.DA_ListName, b.Detail_borrow, 'ครุภัณฑ์ไม่ระบุชื่อ') AS item_name,
            da.DA_Number AS asset_number,
            COALESCE(b.Name_borrow, 'ผู้ยืม') AS borrower_name,
            COALESCE(b.userEmail, u.userEmail) AS userEmail,
            COALESCE(b.due_date, b.Deadline_borrow) AS due_date,
            b.status,
            b.last_reminded_at
        FROM borrow_back b
        LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
        LEFT JOIN useraccount u ON b.useraccountID_borrow = u.useraccountID
        WHERE b.status = 'borrowed'
          AND b.due_date < :currentDate
          AND (b.last_reminded_at IS NULL OR DATE(b.last_reminded_at) < :currentDate2)
        ORDER BY b.due_date ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':currentDate'  => $currentDate,
        ':currentDate2' => $currentDate
    ]);
    $overdueList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalProcessed = count($overdueList);
    $successCount   = 0;
    $failedCount    = 0;

    echo "Found {$totalProcessed} overdue borrowing record(s) requiring email notification.\n";

    // เตรียม Prepared Statement สำหรับอัปเดต timestamp last_reminded_at
    $updateStmt = $pdo->prepare("
        UPDATE borrow_back 
        SET last_reminded_at = CURRENT_TIMESTAMP 
        WHERE IDborrow_back = :id
    ");

    // เตรียม Prepared Statement สำหรับตาราง borrowing สำรอง (ถ้ามี)
    $updateBorrowingStmt = null;
    try {
        $updateBorrowingStmt = $pdo->prepare("
            UPDATE borrowing 
            SET last_reminded_at = CURRENT_TIMESTAMP 
            WHERE id = :id OR IDborrow_back = :id2
        ");
    } catch (Exception $e) {}

    // 4. วนลูปส่งอีเมลแจ้งเตือนทีละรายการ
    foreach ($overdueList as $idx => $row) {
        $id             = $row['id'];
        $recipientEmail = trim($row['userEmail'] ?? '');
        $borrowerName   = $row['borrower_name'];
        $itemName       = $row['item_name'];
        $dueDate        = $row['due_date'];

        // คำนวณจำนวนวันที่เลยกำหนด (Current Date - due_date)
        $dueDateObj  = new DateTime($dueDate);
        $todayObj    = new DateTime($currentDate);
        $diff        = $dueDateObj->diff($todayObj);
        $daysOverdue = (int)$diff->format('%a');

        $logPrefix = " - [" . ($idx + 1) . "/{$totalProcessed}] ID: {$id} | Item: {$itemName} | Email: {$recipientEmail} | Overdue: {$daysOverdue} days... ";

        // ตรวจสอบความถูกต้องของอีเมล
        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            echo $logPrefix . "FAILED (Invalid or missing email)\n";
            $failedCount++;
            continue;
        }

        // ส่งอีเมลด้วย PHPMailer
        $result = sendOverdueReminder(
            $recipientEmail,
            $borrowerName,
            $itemName,
            $dueDate,
            $daysOverdue
        );

        if ($result['success']) {
            // อัปเดต timestamp last_reminded_at สำหรับรายการที่ส่งสำเร็จ
            $updateStmt->execute([':id' => $id]);
            if ($updateBorrowingStmt) {
                try {
                    $updateBorrowingStmt->execute([':id' => $id, ':id2' => $id]);
                } catch (Exception $e) {}
            }
            echo $logPrefix . "SUCCESS\n";
            $successCount++;
        } else {
            echo $logPrefix . "FAILED (" . $result['message'] . ")\n";
            $failedCount++;
        }
    }

    $elapsedTime = round(microtime(true) - $startTime, 2);

    // 5. Output Summary Log สำหรับการเก็บประวัติ Cron
    echo "========================================================\n";
    echo "Summary Log:\n";
    echo "Processed {$totalProcessed} reminders. {$successCount} succeeded, {$failedCount} failed.\n";
    echo "Execution completed in {$elapsedTime} seconds.\n";
    echo "========================================================\n";

} catch (PDOException $e) {
    echo "DATABASE ERROR: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "SYSTEM ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
