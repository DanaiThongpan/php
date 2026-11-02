<?php
// ไฟล์สำหรับการเชื่อมต่อฐานข้อมูลเวอร์ชันพัฒนาด้วย SQLite (Serverless Database)
// ไฟล์ฐานข้อมูลจะถูกสร้างขึ้นอัตโนมัติในโฟลเดอร์เดียวกับไฟล์นี้
$dbFile = __DIR__ . '/daam_dev.sqlite';

try {
    // 1. สร้างการเชื่อมต่อด้วย PDO (SQLite)
    $pdo = new PDO("sqlite:" . $dbFile);
    
    // ตั้งค่าให้ PDO ทำการแจ้งเตือน Error ออกมาเป็น Exception เพื่อง่ายต่อการหาบั๊ก
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // ตั้งค่าให้ดึงข้อมูลออกมาเป็น Associative Array เป็นค่าเริ่มต้น
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // 2. สร้างตาราง useraccount อัตโนมัติหากยังไม่มี (Auto Migration)
    $createTableQuery = "
        CREATE TABLE IF NOT EXISTS useraccount (
            useraccountID VARCHAR(50) PRIMARY KEY,
            useraccountName VARCHAR(100) NOT NULL,
            userPassword VARCHAR(255) NOT NULL,
            userActive VARCHAR(1) DEFAULT '1',
            useraccountType VARCHAR(20) NOT NULL,
            userRank VARCHAR(100) DEFAULT NULL,
            userDepartment VARCHAR(100) DEFAULT NULL,
            userEmail VARCHAR(150) DEFAULT NULL
        );
    ";
    $pdo->exec($createTableQuery);

    // 3. เช็คว่ามีข้อมูลแอดมินจำลอง (Mock Data) หรือยัง ถ้ายังไม่มีให้ทำการ Insert
    $checkAdmin = $pdo->query("SELECT COUNT(*) FROM useraccount WHERE useraccountID = 'admin_test'")->fetchColumn();
    
    if ($checkAdmin == 0) {
        // เข้ารหัสผ่าน '123456' ด้วยเทคโนโลยี Hash ล่าสุด (รหัสผ่านนี้ใช้ล็อกอิน)
        $hashedPassword = password_hash('123456', PASSWORD_DEFAULT);
        
        $insertAdminQuery = "
            INSERT INTO useraccount (useraccountID, useraccountName, userPassword, userActive, useraccountType, userRank, userDepartment)
            VALUES ('admin_test', 'ผู้ดูแลระบบ จำลอง', :password, '1', 'UT00000001', 'หัวหน้างาน', 'ฝ่ายบริหาร')
        ";
        
        $stmt = $pdo->prepare($insertAdminQuery);
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->execute();
    }

    // 4. Auto Migration: ตรวจสอบและเพิ่มคอลัมน์ is_visible ในตาราง durable_articles และ is_active ใน building
    try {
        $hasDA = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='durable_articles'")->fetchColumn();
        if ($hasDA) {
            $cols = $pdo->query("PRAGMA table_info(durable_articles)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('is_visible', $cols)) {
                $pdo->exec("ALTER TABLE durable_articles ADD COLUMN is_visible INTEGER DEFAULT 1");
            }
        }
        $hasBld = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='building'")->fetchColumn();
        if ($hasBld) {
            $colsBld = $pdo->query("PRAGMA table_info(building)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('is_active', $colsBld)) {
                $pdo->exec("ALTER TABLE building ADD COLUMN is_active INTEGER DEFAULT 1");
            }
        }
    } catch (Exception $e) {
        // ข้ามหากติดข้อจำกัดชั่วคราว
    }
    
} catch (PDOException $e) {
    // ดักจับข้อผิดพลาดและแสดงผล
    echo "เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล SQLite: " . $e->getMessage();
    exit();
}
?>
