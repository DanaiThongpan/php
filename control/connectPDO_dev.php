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
            userDepartment VARCHAR(100) DEFAULT NULL
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
    
} catch (PDOException $e) {
    // ดักจับข้อผิดพลาดและแสดงผล
    echo "เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล SQLite: " . $e->getMessage();
    exit();
}
?>
