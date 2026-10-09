<?php
// ไฟล์: control/connectPDO.php

$servername = "192.168.15.201";
$username = "tiw_dev"; 
$password = "StrongPassword123!"; 
$dataName = "daam";

try {
    // กำหนด charset เป็น utf8mb4 เพื่อรองรับภาษาไทยเต็มรูปแบบ และอิโมจิ
    $pdo = new PDO("mysql:host=$servername;dbname=$dataName;charset=utf8mb4", $username, $password);
    
    // ตั้งค่าให้ PDO แจ้งเตือน Error เป็นแบบ Exception เพื่อให้ใช้ Try-Catch ดักจับได้
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // --- เริ่มต้นส่วน Auto Migration (เพิ่มคอลัมน์อัตโนมัติหากไม่มี) ---
    // ตรวจสอบว่าในตาราง useraccount มีคอลัมน์ userRank แล้วหรือยัง
    $checkRank = $pdo->query("SHOW COLUMNS FROM `useraccount` LIKE 'userRank'");
    if ($checkRank->rowCount() == 0) {
        // หากยังไม่มี ให้เพิ่มคอลัมน์ userRank ทันที
        $pdo->exec("ALTER TABLE `useraccount` ADD COLUMN `userRank` VARCHAR(255) NULL AFTER `useraccountName`");
    }

    // ตรวจสอบว่าในตาราง useraccount มีคอลัมน์ userDepartment แล้วหรือยัง
    $checkDept = $pdo->query("SHOW COLUMNS FROM `useraccount` LIKE 'userDepartment'");
    if ($checkDept->rowCount() == 0) {
        // หากยังไม่มี ให้เพิ่มคอลัมน์ userDepartment ทันที
        $pdo->exec("ALTER TABLE `useraccount` ADD COLUMN `userDepartment` VARCHAR(255) NULL AFTER `userRank`");
    }
    // --- สิ้นสุดส่วน Auto Migration ---

} catch (PDOException $e) {
    // ห้ามแสดง Error โดยตรงใน Production, ควรบันทึกลงไฟล์ Log แทนเพื่อความปลอดภัย
    die("เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล กรุณาติดต่อผู้ดูแลระบบ");
}
?>
