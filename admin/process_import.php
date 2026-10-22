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

$currentUserID = $_SESSION['SeUser'] ?? 'admin';
$currentUserDept = $_SESSION['userDepartment'] ?? '';

// หากใน Session ไม่มี userDepartment ให้ลอง query สดจาก useraccount
if (empty($currentUserDept) && !empty($currentUserID)) {
    $stmtDept = $pdo->prepare("SELECT userDepartment FROM useraccount WHERE useraccountID = :uid");
    $stmtDept->execute([':uid' => $currentUserID]);
    $currentUserDept = $stmtDept->fetchColumn() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['csvFile'])) {
    $file = $_FILES['csvFile'];
    
    // ตรวจสอบความถูกต้องของไฟล์ที่อัปโหลด
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorCount++;
        $errorMsg = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์ (Error Code: " . $file['error'] . ")";
    } else {
        $fileMimeType = mime_content_type($file['tmp_name']);
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($fileExtension === 'csv') {
            
            // เปิดไฟล์เพื่ออ่านข้อมูล
            if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
                
                // ข้ามบรรทัดแรก (Header ของ CSV)
                fgetcsv($handle, 1000, ",");
                
                // เริ่ม Transaction เพื่อประสิทธิภาพและความปลอดภัยของข้อมูล (ACID)
                $pdo->beginTransaction();
                
                try {
                    // เตรียมคำสั่ง SQL ล่วงหน้า (Prepared Statements)
                    // 1. ตรวจสอบข้อมูลซ้ำจากรหัสครุภัณฑ์ (DA_Number)
                    $stmtCheck = $pdo->prepare("SELECT DA_ID, statusID FROM durable_articles WHERE DA_Number = :DA_Number");
                    
                    // 2. คำสั่ง INSERT: ผูก created_by, owner_department และ useraccountID
                    $stmtInsert = $pdo->prepare("INSERT INTO durable_articles 
                        (DA_ID, DA_Number, DA_ListName, DA_Brand, classificationID, buildingID, classroomID, DA_Price, statusID, created_by, owner_department, owner_useraccountID, useraccountID) 
                        VALUES 
                        (:DA_ID, :DA_Number, :DA_ListName, :DA_Brand, :classificationID, :buildingID, :classroomID, :DA_Price, :statusID, :created_by, :owner_department, :owner_useraccountID, :useraccountID)");
                    
                    // 3. คำสั่ง UPDATE: อัปเดตข้อมูลรายละเอียดครุภัณฑ์เดิม
                    $stmtUpdate = $pdo->prepare("UPDATE durable_articles 
                        SET DA_ListName = :DA_ListName, 
                            DA_Brand = :DA_Brand, 
                            classificationID = :classificationID, 
                            buildingID = :buildingID, 
                            classroomID = :classroomID, 
                            DA_Price = :DA_Price 
                        WHERE DA_Number = :DA_Number");

                    // โหลดตารางหมวดหมู่ทั้งหมดเข้าหน่วยความจำเพื่อความรวดเร็วในการจับคู่
                    $stmtAllClass = $pdo->query("SELECT classificationID, classificationName FROM classification");
                    $classList = $stmtAllClass->fetchAll();
                    
                    // ฟังก์ชันช่วยวิเคราะห์และกำหนด classificationID อัตโนมัติ (Smart Auto-detection)
                    $detectClassification = function($inputClass, $daNumber, $daName) use ($classList) {
                        $inputClass = trim($inputClass);
                        
                        // 1. ถ้าใน CSV ระบุรหัสหรือชื่อหมวดหมู่มา ให้ค้นหาเทียบในฐานข้อมูลก่อน
                        if (!empty($inputClass)) {
                            foreach ($classList as $c) {
                                if (strcasecmp($c['classificationID'], $inputClass) === 0) {
                                    return $c['classificationID'];
                                }
                                if (mb_strpos($c['classificationName'], $inputClass) !== false || mb_strpos($inputClass, $c['classificationName']) !== false) {
                                    return $c['classificationID'];
                                }
                            }
                            // ถ้าระบุมาเป็นรหัสเฉพาะ (เช่น C001, C999) ให้ใช้ค่านั้นได้
                            return $inputClass;
                        }
                        
                        // 2. วิเคราะห์จากโครงสร้างรหัสครุภัณฑ์ (DA_Number)
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
                        
                        // 4. หากหาไม่เจอจริงๆ ให้ใส่ค่าหมวดหมู่อื่นๆ เช่น 'C999' หรือ 'c0000000003'
                        return 'C999';
                    };

                    // ฟังก์ชันแปลงค่าว่างเป็น NULL
                    $toNull = function($val) {
                        $val = trim($val ?? '');
                        return ($val === '') ? null : $val;
                    };

                    // วนลูปอ่านข้อมูลทีละแถวจากไฟล์ CSV
                    $rowNumber = 1;
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $rowNumber++;
                        // ข้ามแถวที่ว่างทั้งหมด
                        if (empty(array_filter($data, fn($v) => trim($v) !== ''))) {
                            continue;
                        }

                        // ตรวจสอบฟิลด์บังคับ: DA_Number และ DA_ListName
                        $da_number   = trim($data[0] ?? '');
                        $da_listname = trim($data[1] ?? '');

                        if (empty($da_number) || empty($da_listname)) {
                            // แจ้งเตือนข้อผิดพลาดหากขาดข้อมูลบังคับ
                            throw new Exception("แถวที่ {$rowNumber}: ข้อมูลไม่สมบูรณ์ (จำเป็นต้องระบุรหัสครุภัณฑ์และชื่อรายการ)");
                        }

                        // จัดการฟิลด์ Nullable: ถ้าว่างให้แปลงเป็น NULL ในฐานข้อมูล
                        $da_brand    = $toNull($data[2] ?? '');
                        $raw_class   = trim($data[3] ?? '');
                        $build_id    = $toNull($data[4] ?? '');
                        $classroom_id= $toNull($data[5] ?? '');
                        
                        // วิเคราะห์และตั้งค่า classificationID อัตโนมัติ (หรือระบุค่า Default 'C999')
                        $class_id = $detectClassification($raw_class, $da_number, $da_listname);
                        
                        // จัดการราคา (DA_Price): หากเว้นว่างให้เป็น NULL หากมีค่าให้ตัดจุลภาคออก
                        $raw_price = trim($data[6] ?? '');
                        if ($raw_price === '') {
                            $da_price = null;
                        } else {
                            $clean_price = str_replace(',', '', $raw_price);
                            $da_price = is_numeric($clean_price) ? (float)$clean_price : null;
                        }
                        
                        // สร้าง Primary Key (DA_ID)
                        $da_id = "DA" . time() . rand(1000, 9999);

                        // ตรวจสอบการมีอยู่ของข้อมูลเดิมในระบบ
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
                            // ถ้ายังไม่มี -> ให้เพิ่มข้อมูลใหม่ (INSERT) พร้อมผูกความเป็นเจ้าของ
                            $stmtInsert->execute([
                                ':DA_ID'                => $da_id,
                                ':DA_Number'            => $da_number,
                                ':DA_ListName'          => $da_listname,
                                ':DA_Brand'             => $da_brand,
                                ':classificationID'     => $class_id,
                                ':buildingID'           => $build_id,
                                ':classroomID'          => $classroom_id,
                                ':DA_Price'             => $da_price,
                                ':statusID'             => 's0001', // ค่าตั้งต้น: พร้อมใช้งาน
                                ':created_by'           => $currentUserID,
                                ':owner_department'     => $currentUserDept,
                                ':owner_useraccountID'  => $currentUserID,
                                ':useraccountID'        => $currentUserID
                            ]);
                            $successCount++;
                        }
                    }
                    
                    // ยืนยันการเปลี่ยนแปลงข้อมูลลง Database
                    $pdo->commit();
                    
                } catch (Exception $e) {
                    // หากเกิด Error กลางคัน ให้ยกเลิกคำสั่งทั้งหมด (Rollback)
                    $pdo->rollBack();
                    $errorCount++;
                    $errorMsg = $e->getMessage();
                }
                
                fclose($handle);
            } else {
                $errorCount++;
                $errorMsg = "ไม่สามารถเปิดอ่านไฟล์ที่อัปโหลดได้";
            }
        } else {
            $errorCount++;
            $errorMsg = "ไฟล์ที่อัปโหลดไม่ใช่ไฟล์ CSV (รองรับเฉพาะนามสกุล .csv เท่านั้น)";
        }
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
