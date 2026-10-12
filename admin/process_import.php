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
                $stmtCheck = $pdo->prepare("SELECT DA_ID, statusID FROM durable_articles WHERE DA_Number = :DA_Number");
                
                // 2. คำสั่ง INSERT (เพิ่มคอลัมน์ DA_Brand, classroomID, DA_Price และกำหนด statusID เป็น 's0001' เริ่มต้น)
                $stmtInsert = $pdo->prepare("INSERT INTO durable_articles 
                    (DA_ID, DA_Number, DA_ListName, DA_Brand, classificationID, buildingID, classroomID, DA_Price, statusID) 
                    VALUES 
                    (:DA_ID, :DA_Number, :DA_ListName, :DA_Brand, :classificationID, :buildingID, :classroomID, :DA_Price, :statusID)");
                
                // 3. คำสั่ง UPDATE (อัปเดตข้อมูลตามคอลัมน์ใหม่ โดยไม่ไปเปลี่ยน statusID เดิมของครุภัณฑ์)
                $stmtUpdate = $pdo->prepare("UPDATE durable_articles 
                    SET DA_ListName = :DA_ListName, 
                        DA_Brand = :DA_Brand, 
                        classificationID = :classificationID, 
                        buildingID = :buildingID, 
                        classroomID = :classroomID, 
                        DA_Price = :DA_Price 
                    WHERE DA_Number = :DA_Number");

                // โหลดตารางหมวดหมู่ทั้งหมดเข้าหน่วยความจำเพื่อความรวดเร็วในการค้นหา
                $stmtAllClass = $pdo->query("SELECT classificationID, classificationName FROM classification");
                $classList = $stmtAllClass->fetchAll();
                
                // ฟังก์ชันช่วยวิเคราะห์และกำหนด classificationID อัตโนมัติ
                $detectClassification = function($inputClass, $daNumber, $daName) use ($classList) {
                    $inputClass = trim($inputClass);
                    
                    // 1. ถ้าใน CSV ระบุรหัสมาถูกต้องตรงกับในระบบ (เช่น c0000000001) ให้ใช้ค่านั้นทันที
                    if (!empty($inputClass)) {
                        foreach ($classList as $c) {
                            if (strcasecmp($c['classificationID'], $inputClass) === 0) {
                                return $c['classificationID'];
                            }
                            // กรณีผู้ใช้ใส่ชื่อหมวดหมู่มาแทนรหัส (เช่น 'ครุภัณฑ์คอมพิวเตอร์')
                            if (mb_strpos($c['classificationName'], $inputClass) !== false || mb_strpos($inputClass, $c['classificationName']) !== false) {
                                return $c['classificationID'];
                            }
                        }
                    }
                    
                    // 2. วิเคราะห์จากโครงสร้างรหัสครุภัณฑ์ (DA_Number) ตามมาตรฐาน GFMIS / ครุภัณฑ์เดิม
                    if (str_starts_with($daNumber, '631006') || str_contains($daNumber, '671006') || str_starts_with($daNumber, 'อธ')) {
                        return 'c0000000001'; // ครุภัณฑ์ไฟฟ้าและวิทยุ
                    }
                    if (str_starts_with($daNumber, '631014') || str_contains($daNumber, '751014')) {
                        return 'c0000000002'; // ครุภัณฑ์การศึกษา
                    }
                    if (str_starts_with($daNumber, '631004') || str_contains($daNumber, '571004') || str_contains($daNumber, '581004')) {
                        return 'c0000000003'; // ครุภัณฑ์สำนักงาน
                    }
                    if (str_starts_with($daNumber, '631013') || str_contains($daNumber, '551013') || str_contains($daNumber, '561013') || str_contains($daNumber, '571013') || str_contains($daNumber, '581013') || str_contains($daNumber, '581014')) {
                        return 'c0000000004'; // ครุภัณฑ์คอมพิวเตอร์
                    }
                    if (str_starts_with($daNumber, '631007') || str_contains($daNumber, '561004') || str_contains($daNumber, '561006') || str_contains($daNumber, '581007')) {
                        return 'c0000000005'; // ครุภัณฑ์โฆษณาและเผยแพร่
                    }
                    if (str_starts_with($daNumber, '631012')) {
                        return 'c0000000007'; // ครุภัณฑ์วิทยาศาสตร์และการแพทย์
                    }
                    
                    // 3. วิเคราะห์อัจฉริยะจากชื่อรายการ (DA_ListName) ตามคีย์เวิร์ด
                    $nameLower = mb_strtolower($daName);
                    if (preg_match('/(คอมพิวเตอร์|โน้ตบุ๊ก|notebook|laptop|pc|server|printer|ปริ้นเตอร์|scanner|สแกนเนอร์|แท็บเล็ต|tablet|ipad|ups|สำรองไฟ)/iu', $nameLower)) {
                        return 'c0000000004'; // ครุภัณฑ์คอมพิวเตอร์
                    }
                    if (preg_match('/(โปรเจคเตอร์|projector|จอรับภาพ|โทรทัศน์|ทีวี|smart tv|กล้อง|camera|ลำโพง|speaker|ไมค์|microphone|เครื่องเสียง)/iu', $nameLower)) {
                        return 'c0000000005'; // ครุภัณฑ์โฆษณาและเผยแพร่
                    }
                    if (preg_match('/(แอร์|ปรับอากาศ|พัดลม|generator|หม้อแปลง|ตู้ไฟ|เบรคเกอร์|วิทยุ)/iu', $nameLower)) {
                        return 'c0000000001'; // ครุภัณฑ์ไฟฟ้าและวิทยุ
                    }
                    if (preg_match('/(นักเรียน|การสอน|กระดาน|โพเดียม|podium|ห้องเรียน)/iu', $nameLower)) {
                        return 'c0000000002'; // ครุภัณฑ์การศึกษา
                    }
                    if (preg_match('/(ผ้าม่าน|เครื่องซักผ้า|ตู้เย็น|เตา|ไมโครเวฟ|เครื่องขัดพื้น|ดูดฝุ่น)/iu', $nameLower)) {
                        return 'c0000000006'; // ครุภัณฑ์งานบ้านงานครัว
                    }
                    if (preg_match('/(กล้องจุลทรรศน์|วัดอุณหภูมิ|เครื่องช่วยหายใจ|เตียงผู้ป่วย|เครื่องมือแพทย์|สารเคมี)/iu', $nameLower)) {
                        return 'c0000000007'; // ครุภัณฑ์วิทยาศาสตร์และการแพทย์
                    }
                    if (preg_match('/(ปั๊มน้ำ|เครื่องตัดหญ้า|ฉีดน้ำ)/iu', $nameLower)) {
                        return 'c0000000013'; // ครุภัณฑ์การเกษตร
                    }
                    if (preg_match('/(สว่าน|เลื่อย|เครื่องเจียร|ตู้เชื่อม)/iu', $nameLower)) {
                        return 'c0000000012'; // เครื่องมือและอุปกรณ์
                    }
                    if (preg_match('/(รถเข็น|โฟล์คลิฟท์|แม่แรง)/iu', $nameLower)) {
                        return 'c0000000011'; // เครื่องจักรกล
                    }
                    if (preg_match('/(โต๊ะ|เก้าอี้|ตู้|ชั้นวาง|โซฟา|เคาน์เตอร์|บอร์ด)/iu', $nameLower)) {
                        return 'c0000000003'; // ครุภัณฑ์สำนักงาน
                    }
                    
                    // 4. ค่าตั้งต้นกรณีไม่ตรงกับเงื่อนไขใดเลย (Default เป็น ครุภัณฑ์สำนักงาน ซึ่งพบบ่อยที่สุด)
                    return 'c0000000003';
                };

                // วนลูปอ่านข้อมูลทีละแถวจากไฟล์ CSV
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    // ป้องกันแถวว่าง
                    if (empty($data[0]) && empty($data[1])) continue;

                    // กำหนดตัวแปรจากคอลัมน์ CSV (เรียงตามลำดับใน Template)
                    $da_number   = trim($data[0] ?? '');      // 1. รหัสครุภัณฑ์ (DA_Number)
                    $da_listname = trim($data[1] ?? '');      // 2. ชื่อรายการครุภัณฑ์ (DA_ListName)
                    $da_brand    = trim($data[2] ?? '');      // 3. ยี่ห้อ (DA_Brand)
                    $raw_class   = trim($data[3] ?? '');      // 4. รหัสหมวดหมู่ (ถ้าเว้นว่าง ระบบจะตรวจจับให้อัตโนมัติ)
                    $build_id    = trim($data[4] ?? '');      // 5. รหัสอาคาร (buildingID)
                    $classroom_id= trim($data[5] ?? '');      // 6. รหัสห้อง (classroomID)
                    
                    // วิเคราะห์และตั้งค่า classificationID อัตโนมัติ
                    $class_id = $detectClassification($raw_class, $da_number, $da_listname);
                    // 7. ราคา (ตัด Comma และช่องว่างออกเพื่อความถูกต้องของตัวเลข)
                    $raw_price   = trim($data[6] ?? '0');
                    $da_price    = str_replace(',', '', $raw_price);
                    if (!is_numeric($da_price)) {
                        $da_price = 0;
                    }
                    
                    // สร้างรหัส DA_ID แบบสุ่ม หรือประยุกต์ใช้เวลา (สำหรับ Insert)
                    $da_id = "DA" . time() . rand(1000, 9999);

                    // เช็คว่ามี DA_Number นี้ในระบบหรือยัง
                    $stmtCheck->execute([':DA_Number' => $da_number]);
                    $existing = $stmtCheck->fetch();

                    if ($existing) {
                        // ถ้ามีแล้ว -> ให้ทำการอัปเดตข้อมูล (UPDATE)
                        $stmtUpdate->execute([
                            ':DA_Number'        => $da_number,
                            ':DA_ListName'      => $da_listname,
                            ':DA_Brand'         => $da_brand,
                            ':classificationID' => $class_id,
                            ':buildingID'       => $build_id,
                            ':classroomID'      => $classroom_id,
                            ':DA_Price'         => $da_price
                        ]);
                        $updateCount++;
                    } else {
                        // ถ้ายังไม่มี -> ให้เพิ่มข้อมูลใหม่ (INSERT) โดยกำหนดสถานะเริ่มต้นเป็น 's0001' (พร้อมใช้งาน)
                        $stmtInsert->execute([
                            ':DA_ID'            => $da_id,
                            ':DA_Number'        => $da_number,
                            ':DA_ListName'      => $da_listname,
                            ':DA_Brand'         => $da_brand,
                            ':classificationID' => $class_id,
                            ':buildingID'       => $build_id,
                            ':classroomID'      => $classroom_id,
                            ':DA_Price'         => $da_price,
                            ':statusID'         => 's0001'
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
