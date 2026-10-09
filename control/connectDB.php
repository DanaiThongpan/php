<?php
$servername = "192.168.15.201";
$username = "tiw_dev"; 
$password = "StrongPassword123!"; 
$dataName = "daam";
// Create connection
$conn = new mysqli($servername, $username, $password, $dataName, 3306);

// Check connection
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
mysqli_query($conn,"SET CHARACTER SET UTF8");

// --- เริ่มต้นส่วน Auto Migration (เพิ่มคอลัมน์อัตโนมัติหากไม่มี) ---
$checkRank = $conn->query("SHOW COLUMNS FROM `useraccount` LIKE 'userRank'");
if ($checkRank && $checkRank->num_rows == 0) {
    $conn->query("ALTER TABLE `useraccount` ADD COLUMN `userRank` VARCHAR(255) NULL AFTER `useraccountName`");
}

$checkDept = $conn->query("SHOW COLUMNS FROM `useraccount` LIKE 'userDepartment'");
if ($checkDept && $checkDept->num_rows == 0) {
    $conn->query("ALTER TABLE `useraccount` ADD COLUMN `userDepartment` VARCHAR(255) NULL AFTER `userRank`");
}
// --- สิ้นสุดส่วน Auto Migration ---

?>