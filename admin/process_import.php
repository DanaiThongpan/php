<?php
session_start();
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

require_once '../control/connectPDO_dev.php';

$successCount = 0;
$updateCount = 0;
$errorCount = 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['csvFile'])) {
    $file = $_FILES['csvFile'];
    
    // ตรวจสอบว่าเป็นไฟล์ CSV จริงหรือไม่
    $fileMimeType = mime_content_type($file['tmp_name']);
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($fileExtension === 'csv') {
        
        // เปิดไฟล์เพื่ออ่านข้อมูล
        if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
            
            // ข้ามบรรทัดแรก (Header ของ CSV)
            fgetcsv($handle, 1000, ",");
            
            // เริ่ม Transaction เพื่อประสิทธิภาพในการบันทึกจำนวนมากๆ
            $pdo->beginTransaction();
            
            try {
                // เตรียมคำสั่ง SQL ล่วงหน้า (Prepared Statement) เพื่อป้องกัน SQL Injection
                // 1. ตรวจสอบข้อมูลซ้ำจากรหัสครุภัณฑ์ (DA_Number)
                $stmtCheck = $pdo->prepare("SELECT DA_ID FROM durable_articles WHERE DA_Number = :DA_Number");
                
                // 2. คำสั่ง INSERT
                $stmtInsert = $pdo->prepare("INSERT INTO durable_articles (DA_ID, DA_Number, DA_ListName, classificationID, buildingID, statusID) 
                                             VALUES (:DA_ID, :DA_Number, :DA_ListName, :classificationID, :buildingID, :statusID)");
                
                // 3. คำสั่ง UPDATE
                $stmtUpdate = $pdo->prepare("UPDATE durable_articles 
                                             SET DA_ListName = :DA_ListName, classificationID = :classificationID, buildingID = :buildingID, statusID = :statusID 
                                             WHERE DA_Number = :DA_Number");

                // วนลูปอ่านข้อมูลทีละแถวจากไฟล์ CSV
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    // ป้องกันแถวว่าง
                    if (empty($data[0]) && empty($data[1])) continue;

                    // กำหนดตัวแปรจากคอลัมน์ CSV (เรียงตามลำดับใน Template)
                    $da_number = trim($data[0]);      // รหัสครุภัณฑ์
                    $da_listname = trim($data[1]);    // ชื่อรายการครุภัณฑ์
                    $class_id = trim($data[2]);       // รหัสหมวดหมู่ (เช่น c001)
                    $build_id = trim($data[3]);       // รหัสอาคาร
                    $status_id = trim($data[4]);      // รหัสสถานะ (เช่น s0001 = พร้อมใช้งาน)
                    
                    // สร้างรหัส DA_ID แบบสุ่ม หรือประยุกต์ใช้เวลา (เพื่อใช้เป็น Primary Key ในกรณี Insert)
                    $da_id = "DA" . time() . rand(1000, 9999);

                    // เช็คว่ามี DA_Number นี้ในระบบหรือยัง
                    $stmtCheck->execute([':DA_Number' => $da_number]);
                    $existing = $stmtCheck->fetch();

                    if ($existing) {
                        // ถ้ามีแล้ว -> ให้ทำการอัปเดตข้อมูล (UPDATE)
                        $stmtUpdate->execute([
                            ':DA_Number' => $da_number,
                            ':DA_ListName' => $da_listname,
                            ':classificationID' => $class_id,
                            ':buildingID' => $build_id,
                            ':statusID' => $status_id
                        ]);
                        $updateCount++;
                    } else {
                        // ถ้ายังไม่มี -> ให้เพิ่มข้อมูลใหม่ (INSERT)
                        $stmtInsert->execute([
                            ':DA_ID' => $da_id,
                            ':DA_Number' => $da_number,
                            ':DA_ListName' => $da_listname,
                            ':classificationID' => $class_id,
                            ':buildingID' => $build_id,
                            ':statusID' => $status_id
                        ]);
                        $successCount++;
                    }
                }
                
                // ยืนยันการเปลี่ยนแปลงข้อมูล
                $pdo->commit();
                
            } catch (PDOException $e) {
                // หากพังกลางคัน ให้ยกเลิกทั้งหมด
                $pdo->rollBack();
                $errorCount++;
                $errorMsg = $e->getMessage();
            }
            
            fclose($handle);
        } else {
            $errorCount++;
            $errorMsg = "ไม่สามารถเปิดอ่านไฟล์ได้";
        }
    } else {
        $errorCount++;
        $errorMsg = "ไฟล์ที่อัปโหลดไม่ใช่ไฟล์ CSV (นามสกุลต้องเป็น .csv เท่านั้น)";
    }
} else {
    header("Location: import_csv.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ผลลัพธ์การนำเข้าข้อมูล</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="card shadow border-0 text-center p-5">
        <?php if ($errorCount > 0 && empty($successCount) && empty($updateCount)): ?>
            <h1 class="text-danger"><i class="fa-solid fa-circle-xmark"></i> ล้มเหลว</h1>
            <p class="mt-3 text-muted">เกิดข้อผิดพลาด: <?php echo htmlspecialchars($errorMsg); ?></p>
        <?php else: ?>
            <h1 class="text-success"><i class="fa-solid fa-circle-check"></i> นำเข้าข้อมูลสำเร็จ!</h1>
            <ul class="list-group mt-4 text-start mx-auto" style="max-width: 400px;">
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    เพิ่มครุภัณฑ์ใหม่ (รายการ)
                    <span class="badge bg-success rounded-pill"><?php echo $successCount; ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    อัปเดตข้อมูลเดิม (รายการ)
                    <span class="badge bg-warning text-dark rounded-pill"><?php echo $updateCount; ?></span>
                </li>
            </ul>
        <?php endif; ?>
        <div class="mt-4">
            <a href="manage_data.php" class="btn btn-primary">กลับไปหน้าจัดการข้อมูล</a>
        </div>
    </div>
</div>
</body>
</html>
