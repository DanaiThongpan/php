<?php
// ไฟล์: register_process.php
session_start();
require_once 'control/connectPDO.php';

// ป้องกันการเข้าถึงไฟล์นี้ตรงๆ จากบราวเซอร์ (รับเฉพาะการ POST เท่านั้น)
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: register.php");
    exit();
}

// 1. ตรวจสอบ CSRF Token (ความปลอดภัย)
$csrf_token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
    $_SESSION['error'] = "Token ความปลอดภัยไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง (CSRF Error)";
    header("Location: register.php");
    exit();
}

// 2. จัดการข้อมูล (Sanitize Inputs) 
$useraccountID   = trim($_POST['useraccountID'] ?? '');
$useraccountName = trim($_POST['useraccountName'] ?? '');
$password        = $_POST['userPassword'] ?? '';
$userRank        = trim($_POST['userRank'] ?? '');
$userDepartment  = trim($_POST['userDepartment'] ?? '');

// กำหนดให้ประเภทเป็น User เสมอ (เพื่อความปลอดภัยหากถูกแก้ HTML ส่งมา)
$useraccountType = 'UT00000002'; // รหัส User
$userActive      = '1'; // กำหนดให้สถานะบัญชีเปิดใช้งานอัตโนมัติ

// 3. ตรวจสอบข้อมูลเบื้องต้น (Backend Validation) ป้องกันค่าว่าง
if (empty($useraccountID) || empty($useraccountName) || empty($password) || empty($userRank) || empty($userDepartment)) {
    $_SESSION['error'] = "กรุณากรอกข้อมูลให้ครบถ้วนทุกช่อง";
    header("Location: register.php");
    exit();
}

// ตรวจสอบความปลอดภัยของรหัสผ่าน
if (strlen($password) < 8) {
    $_SESSION['error'] = "รหัสผ่านต้องมีความยาวอย่างน้อย 8 ตัวอักษร";
    header("Location: register.php");
    exit();
}

// 4. เข้ารหัสรหัสผ่าน (Password Hashing แบบ Bcrypt ตามมาตรฐาน)
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// 5. บันทึกข้อมูล (PDO Prepared Statements เพื่อป้องกัน SQL Injection)
try {
    // เพิ่มฟิลด์ userRank และ userDepartment ลงในคำสั่ง SQL
    $sql = "INSERT INTO useraccount (useraccountID, useraccountName, userRank, userDepartment, userPassword, userActive, useraccountType) 
            VALUES (:id, :name, :rank, :dept, :pass, :active, :type)";
    
    $stmt = $pdo->prepare($sql);
    
    // Binding parameters จับคู่ตัวแปรกับ SQL
    $stmt->bindParam(':id', $useraccountID);
    $stmt->bindParam(':name', $useraccountName);
    $stmt->bindParam(':rank', $userRank);
    $stmt->bindParam(':dept', $userDepartment);
    $stmt->bindParam(':pass', $hashedPassword);
    $stmt->bindParam(':active', $userActive);
    $stmt->bindParam(':type', $useraccountType);
    
    // ทำการรันคำสั่ง SQL
    $stmt->execute();
    
    // เมื่อลงทะเบียนสำเร็จ จะตั้ง Session บอกผลลัพธ์
    $_SESSION['success'] = "สมัครสมาชิกเสร็จสมบูรณ์! คุณสามารถเข้าสู่ระบบได้ทันที";
    
    // รีไดเร็คไปหน้า login (index.php)
    header("Location: index.php"); 
    exit();

} catch (PDOException $e) {
    // 6. ดักจับ Error Code 23000 (Duplicate Entry สำหรับ Primary Key)
    if ($e->getCode() == 23000) {
        $_SESSION['error'] = "ชื่อผู้ใช้งานนี้ (Username) มีในระบบแล้ว กรุณาใช้ชื่ออื่น";
    } else {
        // หากเกิดข้อผิดพลาดอื่น ๆ จากฐานข้อมูล
        $_SESSION['error'] = "เกิดข้อผิดพลาดของระบบ: " . $e->getMessage();
    }
    
    header("Location: register.php");
    exit();
}
?>
