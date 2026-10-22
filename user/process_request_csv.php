<?php
session_start();

// 1. ตรวจสอบสิทธิ์การเข้าถึง (ต้องเป็น User ทั่วไปเท่านั้น)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000002') {
    header("Location: ../index.php");
    exit();
}

// 2. โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO
require_once __DIR__ . '/../control/connectPDO_dev.php';

$currentUserID   = $_SESSION['SeUser'] ?? '';
$currentUserDept = $_SESSION['userDepartment'] ?? '';

// หากใน Session ไม่มีแผนกสังกัด ให้ดึงสดจากตาราง useraccount
if (empty($currentUserDept) && !empty($currentUserID)) {
    try {
        $stmtDept = $pdo->prepare("SELECT userDepartment FROM useraccount WHERE useraccountID = :uid");
        $stmtDept->execute([':uid' => $currentUserID]);
        $currentUserDept = $stmtDept->fetchColumn() ?: 'ไม่ระบุแผนก';
        $_SESSION['userDepartment'] = $currentUserDept;
    } catch (PDOException $e) {
        $currentUserDept = 'ไม่ระบุแผนก';
    }
}

// ตรวจสอบว่าเป็นการส่งไฟล์ผ่าน POST หรือไม่
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['csvFile'])) {
    header("Location: request_item.php?tab=csv");
    exit();
}

$file = $_FILES['csvFile'];

// 3. ตรวจสอบความถูกต้องของไฟล์ที่อัปโหลด
if ($file['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['flash_msg'] = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์ (Error Code: " . $file['error'] . ")";
    $_SESSION['flash_type'] = "danger";
    header("Location: request_item.php?tab=csv");
    exit();
}

$fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($fileExtension !== 'csv') {
    $_SESSION['flash_msg'] = "ไฟล์ที่อัปโหลดไม่ใช่ไฟล์ CSV (รองรับเฉพาะไฟล์นามสกุล .csv เท่านั้น)";
    $_SESSION['flash_type'] = "danger";
    header("Location: request_item.php?tab=csv");
    exit();
}

// 4. เปิดอ่านไฟล์ CSV
if (($handle = fopen($file['tmp_name'], "r")) === FALSE) {
    $_SESSION['flash_msg'] = "ไม่สามารถเปิดอ่านไฟล์ CSV ได้ กรุณาลองใหม่อีกครั้ง";
    $_SESSION['flash_type'] = "danger";
    header("Location: request_item.php?tab=csv");
    exit();
}

// ตรวจสอบและตัด UTF-8 BOM ออกหากมี
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

// อ่านหัวคอลัมน์ (Header) แถวแรก
$headers = fgetcsv($handle, 1000, ",");
if (!$headers || count($headers) < 2) {
    fclose($handle);
    $_SESSION['flash_msg'] = "โครงสร้างไฟล์ CSV ไม่ถูกต้อง หรือไม่มีแถวหัวตาราง (Header)";
    $_SESSION['flash_type'] = "danger";
    header("Location: request_item.php?tab=csv");
    exit();
}

// ทำความสะอาดชื่อ Header เพื่อรองรับการจับคู่คอลัมน์แบบยืดหยุ่น
$headersClean = array_map(function($h) {
    return trim(str_replace(["\xEF\xBB\xBF", '"', "'"], '', $h));
}, $headers);

// ค้นหาตำแหน่งคอลัมน์
$colNum   = array_search('DA_Number', $headersClean);
$colName  = array_search('DA_ListName', $headersClean);
$colBrand = array_search('DA_Brand', $headersClean);
$colClass = array_search('classificationID', $headersClean);
$colBuild = array_search('buildingID', $headersClean);
$colRoom  = array_search('classroomID', $headersClean);
$colPrice = array_search('DA_Price', $headersClean);

// ตรวจสอบว่ามีคอลัมน์สำคัญ (DA_Number, DA_ListName) หรือไม่
if ($colNum === false || $colName === false) {
    // กรณีหัวคอลัมน์ไม่ตรงชื่อเป๊ะๆ ให้ใช้ตำแหน่งตามลำดับมาตรฐาน 0=DA_Number, 1=DA_ListName
    $colNum   = 0;
    $colName  = 1;
    $colBrand = 2;
    $colClass = 3;
    $colBuild = 4;
    $colRoom  = 5;
    $colPrice = 6;
}

$successCount   = 0;
$duplicateCount = 0;
$errorCount     = 0;
$skippedRows    = [];

// โหลดตารางหมวดหมู่ทั้งหมดเข้าหน่วยความจำเพื่อความรวดเร็วในการวิเคราะห์
try {
    $stmtAllClass = $pdo->query("SELECT classificationID, classificationName FROM classification");
    $classList = $stmtAllClass->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $classList = [];
}

// ฟังก์ชันช่วยวิเคราะห์และจับคู่ classificationID อัตโนมัติ (Smart Auto-detection)
$detectClassification = function($inputClass, $daNumber, $daName) use ($classList) {
    $inputClass = trim($inputClass ?? '');
    
    // 1. ถ้าใน CSV ระบุรหัสหรือชื่อหมวดหมู่มา ให้ค้นหาเทียบก่อน
    if (!empty($inputClass)) {
        foreach ($classList as $c) {
            if (strcasecmp($c['classificationID'], $inputClass) === 0) {
                return $c['classificationID'];
            }
            if (mb_strpos($c['classificationName'], $inputClass) !== false || mb_strpos($inputClass, $c['classificationName']) !== false) {
                return $c['classificationID'];
            }
        }
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
    
    // 3. วิเคราะห์จากชื่อรายการ (DA_ListName) ตามคำสำคัญ
    $nameLower = mb_strtolower($daName);
    if (preg_match('/(คอมพิวเตอร์|โน้ตบุ๊ก|notebook|laptop|pc|เซิร์ฟเวอร์|server|สวิตช์|switch|เราเตอร์|router|จอภาพ|monitor|ups|เครื่องสำรองไฟ)/iu', $nameLower)) {
        return 'c0000000004'; // ครุภัณฑ์คอมพิวเตอร์
    }
    if (preg_match('/(เครื่องพิมพ์|printer|สแกนเนอร์|scanner|โปรเจคเตอร์|projector|เครื่องถ่ายเอกสาร)/iu', $nameLower)) {
        return 'c0000000003'; // ครุภัณฑ์สำนักงาน
    }
    if (preg_match('/(โทรทัศน์|ทีวี|tv|กล้อง|ลำโพง|ไมโครโฟน|เครื่องเสียง|แอมป์)/iu', $nameLower)) {
        return 'c0000000001'; // ครุภัณฑ์ไฟฟ้าและวิทยุ
    }
    if (preg_match('/(โต๊ะ|เก้าอี้|ตู้|ชั้นวาง|โซฟา|เคาน์เตอร์|บอร์ด)/iu', $nameLower)) {
        return 'c0000000003'; // ครุภัณฑ์สำนักงาน
    }
    
    // 4. หากไม่พบคำเฉพาะ ให้คืนค่าว่างเพื่อให้เป็น NULL หรือระบุ C999
    return 'C999';
};

// ฟังก์ชันแปลงค่าว่างเป็น NULL
$toNull = function($val) {
    $val = trim($val ?? '');
    return ($val === '') ? null : $val;
};

// เริ่มต้น Transaction เพื่อความปลอดภัยของข้อมูล
$pdo->beginTransaction();

try {
    // คำสั่ง SQL ตรวจสอบรหัสครุภัณฑ์ซ้ำ
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM durable_articles WHERE DA_Number = :DA_Number");

    // คำสั่ง SQL สำหรับ INSERT คำขอขึ้นทะเบียนครุภัณฑ์
    // [สำคัญมาก] บังคับ is_approved = 0, statusID = 's0002', created_by = $currentUserID, owner_department = $currentUserDept
    $stmtInsert = $pdo->prepare("INSERT INTO durable_articles 
        (DA_ID, DA_Number, DA_ListName, DA_Brand, classificationID, buildingID, classroomID, DA_Price, 
         statusID, is_approved, created_by, owner_department, owner_useraccountID, useraccountID) 
        VALUES 
        (:DA_ID, :DA_Number, :DA_ListName, :DA_Brand, :classificationID, :buildingID, :classroomID, :DA_Price, 
         's0002', 0, :created_by, :owner_department, :owner_useraccountID, :useraccountID)");

    $rowNumber = 1;
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $rowNumber++;

        // ข้ามแถวที่ไม่มีข้อมูล
        if (empty(array_filter($data, fn($v) => trim($v) !== ''))) {
            continue;
        }

        // ดึงค่าตามคอลัมน์
        $da_number   = isset($data[$colNum]) ? trim($data[$colNum]) : '';
        $da_listname = isset($data[$colName]) ? trim($data[$colName]) : '';
        $da_brand    = isset($data[$colBrand]) ? $toNull($data[$colBrand]) : null;
        $class_raw   = isset($data[$colClass]) ? trim($data[$colClass]) : '';
        $build_id    = isset($data[$colBuild]) ? $toNull($data[$colBuild]) : null;
        $classroom_id= isset($data[$colRoom]) ? $toNull($data[$colRoom]) : null;
        $price_raw   = isset($data[$colPrice]) ? trim($data[$colPrice]) : '';

        // ตรวจสอบฟิลด์บังคับ: DA_Number และ DA_ListName ต้องไม่ว่าง
        if (empty($da_number) || empty($da_listname)) {
            $errorCount++;
            $skippedRows[] = "แถวที่ {$rowNumber}: ไม่ได้ระบุหมายเลขครุภัณฑ์หรือชื่อรายการ";
            continue;
        }

        // ตรวจสอบหมายเลขครุภัณฑ์ซ้ำในระบบ
        $stmtCheck->execute([':DA_Number' => $da_number]);
        if ($stmtCheck->fetchColumn() > 0) {
            $duplicateCount++;
            $skippedRows[] = "แถวที่ {$rowNumber}: หมายเลข [{$da_number}] มีอยู่ในระบบแล้ว (ข้ามการบันทึก)";
            continue;
        }

        // ประมวลผลราคา
        $clean_price = preg_replace('/[^0-9.]/', '', $price_raw);
        $da_price = (is_numeric($clean_price) && $clean_price !== '') ? (float)$clean_price : null;

        // ประมวลผลหมวดหมู่
        $class_id = $toNull($detectClassification($class_raw, $da_number, $da_listname));

        // สร้าง Primary Key ประจำแถว
        $da_id = "DA" . time() . rand(1000, 9999);

        // บันทึกคำขอลงฐานข้อมูล
        $stmtInsert->execute([
            ':DA_ID'                => $da_id,
            ':DA_Number'            => $da_number,
            ':DA_ListName'          => $da_listname,
            ':DA_Brand'             => $da_brand,
            ':classificationID'     => $class_id,
            ':buildingID'           => $build_id,
            ':classroomID'          => $classroom_id,
            ':DA_Price'             => $da_price,
            ':created_by'           => $currentUserID,
            ':owner_department'     => $currentUserDept,
            ':owner_useraccountID'  => $currentUserID,
            ':useraccountID'        => $currentUserID
        ]);

        $successCount++;
    }

    // ยืนยันการบันทึกข้อมูล
    $pdo->commit();

    // สร้างข้อความแจ้งผลลัพธ์
    $msgParts = [];
    $msgParts[] = "🎉 นำเข้าคำขอขึ้นทะเบียนผ่านไฟล์ CSV สำเร็จ {$successCount} รายการ (ส่งไปยังแอดมินแผนกเพื่อรออนุมัติแล้ว)";
    if ($duplicateCount > 0) {
        $msgParts[] = "⚠️ ข้ามรายการที่มีหมายเลขซ้ำ {$duplicateCount} รายการ";
    }
    if ($errorCount > 0) {
        $msgParts[] = "❌ เกิดข้อผิดพลาด/ข้อมูลไม่สมบูรณ์ {$errorCount} แถว";
    }

    $_SESSION['flash_msg'] = implode("<br>", $msgParts);
    $_SESSION['flash_type'] = ($successCount > 0) ? "success" : "warning";

} catch (Exception $e) {
    // หากเกิด Error ให้ Rollback ทันที
    $pdo->rollBack();
    $_SESSION['flash_msg'] = "เกิดข้อผิดพลาดในการนำเข้าข้อมูล: " . $e->getMessage();
    $_SESSION['flash_type'] = "danger";
}

fclose($handle);

// ส่งกลับไปยังหน้า request_item.php แท็บ CSV
header("Location: request_item.php?tab=csv");
exit();
