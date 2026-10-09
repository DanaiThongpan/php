<?php
include("../control/connectPDO.php");
session_start();

$response = [
    'countDA' => 0,
    'countClass' => 0,
    'countStatusActive' => 0,
    'countStatusDisActive' => 0,
    'userBorrowCount' => 0,
    'userOverdueCount' => 0
];

try {
    // 1. ดึงข้อมูลจำนวนสำหรับ Admin (ข้อมูลรวมทั้งหมด)
    
    // จำนวนครุภัณฑ์ทั้งหมด
    $stmt = $pdo->query("SELECT COUNT(*) AS CDA FROM `durable_articles`");
    $response['countDA'] = $stmt->fetch(PDO::FETCH_ASSOC)['CDA'];

    // จำนวนหมวดหมู่
    $stmt = $pdo->query("SELECT COUNT(*) AS CCF FROM `classification`");
    $response['countClass'] = $stmt->fetch(PDO::FETCH_ASSOC)['CCF'];

    // จำนวนพร้อมใช้ (S0001)
    $stmt = $pdo->query("SELECT COUNT(*) AS CStatusActive FROM `durable_articles` WHERE statusID = 's0001'");
    $response['countStatusActive'] = $stmt->fetch(PDO::FETCH_ASSOC)['CStatusActive'];

    // จำนวนไม่พร้อมใช้ (S0002)
    $stmt = $pdo->query("SELECT COUNT(*) AS CStatusDisActive FROM `durable_articles` WHERE statusID = 's0002'");
    $response['countStatusDisActive'] = $stmt->fetch(PDO::FETCH_ASSOC)['CStatusDisActive'];


    // 2. ดึงข้อมูลสำหรับ User ทั่วไป (อิงตามชื่อผู้ใช้ที่กำลังล็อกอิน)
    if (isset($_SESSION['Sename'])) {
        $userName = $_SESSION['Sename'];

        // จำนวนที่กำลังยืม (ยังไม่ได้คืน -> timestamp_back เป็นค่าว่าง หรือ null)
        $stmt = $pdo->prepare("SELECT COUNT(*) AS countBorrow FROM `borrow_back` WHERE Name_borrow = :uname AND (timestamp_back = '' OR timestamp_back IS NULL)");
        $stmt->bindParam(':uname', $userName);
        $stmt->execute();
        $response['userBorrowCount'] = $stmt->fetch(PDO::FETCH_ASSOC)['countBorrow'];

        // จำนวนที่เลยกำหนดคืน (Overdue) -> วันที่ปัจจุบัน มากกว่า Deadline_borrow
        $stmt = $pdo->prepare("SELECT COUNT(*) AS countOverdue FROM `borrow_back` WHERE Name_borrow = :uname AND (timestamp_back = '' OR timestamp_back IS NULL) AND Deadline_borrow < CURDATE()");
        $stmt->bindParam(':uname', $userName);
        $stmt->execute();
        $response['userOverdueCount'] = $stmt->fetch(PDO::FETCH_ASSOC)['countOverdue'];
    }

    echo json_encode($response);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>