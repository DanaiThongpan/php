<?php
/**
 * db.php - ไฟล์จัดการการเชื่อมต่อฐานข้อมูล SQLite ผ่าน PDO (Modular Database Connection)
 * พัฒนาสำหรับระบบบริหารจัดการครุภัณฑ์และระบบแจ้งเตือนอีเมลเกินกำหนดส่งคืน
 */

// เส้นทางไฟล์ฐานข้อมูล SQLite
$dbPath = __DIR__ . '/control/daam_dev.sqlite';

try {
    // สร้างการเชื่อมต่อ PDO สำหรับ SQLite
    $pdo = new PDO("sqlite:" . $dbPath);
    
    // ตั้งค่า Error Mode ให้โยน Exception เมื่อเกิดข้อผิดพลาด
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // ตั้งค่า Default Fetch Mode เป็น Associative Array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // =========================================================================
    // ตรวจสอบและอัปเดต Schema อัตโนมัติ (Auto-Migration สำหรับ SQLite)
    // เพิ่มคอลัมน์ที่จำเป็น: userEmail, due_date, status, last_reminded_at
    // =========================================================================
    $tableInfoStmt = $pdo->query("PRAGMA table_info(borrow_back)");
    $existingColumns = [];
    while ($col = $tableInfoStmt->fetch()) {
        $existingColumns[] = $col['name'];
    }

    $requiredColumns = [
        'userEmail'        => 'TEXT',
        'due_date'         => 'DATE',
        'status'           => "TEXT DEFAULT 'borrowed'",
        'last_reminded_at' => 'DATETIME'
    ];

    foreach ($requiredColumns as $columnName => $columnType) {
        if (!in_array($columnName, $existingColumns)) {
            $pdo->exec("ALTER TABLE borrow_back ADD COLUMN {$columnName} {$columnType}");
        }
    }

    // ซิงค์ข้อมูลเดิม (ถ้ามี) ให้สอดคล้องกับคอลัมน์ใหม่
    $pdo->exec("
        UPDATE borrow_back 
        SET due_date = Deadline_borrow 
        WHERE due_date IS NULL AND Deadline_borrow IS NOT NULL;

        UPDATE borrow_back 
        SET status = CASE 
            WHEN Date_back IS NOT NULL AND Date_back != '' THEN 'returned' 
            ELSE 'borrowed' 
        END 
        WHERE status IS NULL OR status = '';

        UPDATE borrow_back 
        SET userEmail = (SELECT userEmail FROM useraccount WHERE useraccount.useraccountID = borrow_back.useraccountID_borrow)
        WHERE userEmail IS NULL;
    ");

    // สร้างตาราง borrowing สำรอง (กรณีมีโค้ดหรือชุดทดสอบอ้างอิงตารางชื่อ borrowing)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS borrowing (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            IDborrow_back VARCHAR(50),
            item_name VARCHAR(255),
            borrower_name VARCHAR(100),
            userEmail TEXT,
            borrow_date DATE,
            due_date DATE,
            status TEXT DEFAULT 'borrowed',
            last_reminded_at DATETIME
        );

        -- สร้างตาราง system_settings สำหรับเก็บการตั้งค่า SMTP และระบบ
        CREATE TABLE IF NOT EXISTS system_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            smtp_host TEXT NOT NULL DEFAULT 'smtp.gmail.com',
            smtp_port INTEGER NOT NULL DEFAULT 587,
            smtp_user TEXT DEFAULT '',
            smtp_pass TEXT DEFAULT '',
            sender_name TEXT DEFAULT 'ระบบบริหารจัดการครุภัณฑ์',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        -- แทรกข้อมูลเริ่มต้นแถวที่ 1 หากยังไม่มี
        INSERT INTO system_settings (id, smtp_host, smtp_port, smtp_user, smtp_pass, sender_name, updated_at)
        SELECT 1, 'smtp.gmail.com', 587, '', '', 'ระบบบริหารจัดการครุภัณฑ์', CURRENT_TIMESTAMP
        WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE id = 1);
    ");

} catch (PDOException $e) {
    // จัดการข้อผิดพลาดในการเชื่อมต่อฐานข้อมูลอย่างปลอดภัย
    die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
}

// ---------------------------------------------------------------
// Auto-migration: เพิ่มคอลัมน์ OAuth สำหรับการเชื่อมต่อ Gmail (OAuth 2.0)
// ---------------------------------------------------------------
try {
    $settingsInfoStmt = $pdo->query("PRAGMA table_info(system_settings)");
    $existingSettingCols = [];
    while ($col = $settingsInfoStmt->fetch()) {
        $existingSettingCols[] = $col['name'];
    }
    $extraCols = [
        'oauth_client_id'        => 'TEXT',
        'oauth_client_secret'    => 'TEXT',
        'oauth_refresh_token'    => 'TEXT',
        'is_active'              => 'INTEGER NOT NULL DEFAULT 1',
        'sender_email'           => "TEXT DEFAULT ''",
        'reminder_frequency'     => "TEXT DEFAULT 'daily'",
        'reminder_days'          => "INTEGER DEFAULT 1"
    ];
    foreach ($extraCols as $colName => $colType) {
        if (!in_array($colName, $existingSettingCols)) {
            $pdo->exec("ALTER TABLE system_settings ADD COLUMN {$colName} {$colType}");
        }
    }
} catch (Exception $e) {
    // หากการอัปเดตคอลัมน์ล้มเหลว ให้บันทึกใน error log แต่ไม่ขัดขั้นตอนการทำงานของแอป
    error_log('Failed to add OAuth columns to system_settings: ' . $e->getMessage());
}
