<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

require_once __DIR__ . '/../control/connectPDO_dev.php';

$successCount = 0;
$updateCount  = 0;
$errorCount   = 0;
$errorMsg     = '';
$importedRows = [];

$currentUserID   = $_SESSION['SeUser'] ?? 'admin';
$currentUserDept = $_SESSION['userDepartment'] ?? '';

// หากใน Session ไม่มี userDepartment ให้ลอง query สดจาก useraccount
if (empty($currentUserDept) && !empty($currentUserID)) {
    try {
        $stmtDept = $pdo->prepare("SELECT userDepartment FROM useraccount WHERE useraccountID = :uid");
        $stmtDept->execute([':uid' => $currentUserID]);
        $currentUserDept = $stmtDept->fetchColumn() ?: 'ส่วนกลาง';
        $_SESSION['userDepartment'] = $currentUserDept;
    } catch (PDOException $e) {
        $currentUserDept = 'ส่วนกลาง';
    }
}
if (empty($currentUserDept)) {
    $currentUserDept = 'ส่วนกลาง';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csvFile'])) {
    $file = $_FILES['csvFile'];
    
    // ตรวจสอบความถูกต้องของไฟล์ที่อัปโหลด
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorCount++;
        $errorMsg = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์ (Error Code: " . $file['error'] . ")";
    } else {
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($fileExtension === 'csv') {
            
            // เปิดไฟล์เพื่ออ่านข้อมูล
            if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
                
                // ตรวจสอบและตัด UTF-8 BOM ออกหากมี
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }

                // อ่านหัวคอลัมน์ (Header ของ CSV)
                $headers = fgetcsv($handle, 1000, ",", '"', "\\");
                
                if (!$headers || count($headers) < 2) {
                    $errorCount++;
                    $errorMsg = "ไฟล์ CSV ไม่มีหัวคอลัมน์หรือข้อมูลไม่ถูกต้อง";
                } else {
                    // ทำความสะอาดชื่อ Header เพื่อรองรับการจับคู่คอลัมน์แบบยืดหยุ่น
                    $headersClean = array_map(function($h) {
                        return trim(str_replace(["\xEF\xBB\xBF", '"', "'", "\r", "\n"], '', $h));
                    }, $headers);

                    // ฟังก์ชันค้นหา Index คอลัมน์จากหลายชื่อที่เป็นไปได้
                    $findCol = function($candidates) use ($headersClean) {
                        foreach ($candidates as $c) {
                            $idx = array_search($c, $headersClean);
                            if ($idx !== false) return $idx;
                            // ลองค้นหาแบบไม่สนใจตัวพิมพ์เล็ก-ใหญ่
                            foreach ($headersClean as $k => $h) {
                                if (strcasecmp($h, $c) === 0) return $k;
                            }
                        }
                        return false;
                    };

                    $colNum   = $findCol(['DA_Number', 'da_number', 'รหัสครุภัณฑ์', 'หมายเลขครุภัณฑ์', 'barcode']);
                    $colName  = $findCol(['DA_ListName', 'da_listname', 'ชื่อรายการ', 'รายการครุภัณฑ์', 'item_name']);
                    $colBrand = $findCol(['DA_Brand', 'da_brand', 'ยี่ห้อ', 'brand', 'รุ่น']);
                    $colClass = $findCol(['classificationID', 'classification_id', 'หมวดหมู่', 'category', 'classificationName']);
                    $colBuild = $findCol(['buildingID', 'building_id', 'อาคาร', 'สถานที่', 'buildingName', 'location']);
                    $colRoom  = $findCol(['classroomID', 'classroom_id', 'ห้อง', 'room', 'classroomName']);
                    $colPrice = $findCol(['DA_Price', 'da_price', 'ราคา', 'price']);
                    $colYear  = $findCol(['DA_Year', 'da_year', 'ปีงบประมาณ', 'ปี', 'year']);
                    $colDept  = $findCol(['owner_department', 'department', 'สังกัด', 'หน่วยงาน']);

                    // กรณีชื่อหัวคอลัมน์ไม่ตรงชื่อ ให้ใช้ลำดับตำแหน่งมาตรฐาน
                    if ($colNum === false || $colName === false) {
                        $colNum   = 0;
                        $colName  = 1;
                        $colBrand = 2;
                        $colClass = 3;
                        $colBuild = 4;
                        $colRoom  = 5;
                        $colPrice = 6;
                        $colYear  = 7;
                        $colDept  = 8;
                    }

                    // เริ่ม Transaction เพื่อความปลอดภัยของข้อมูล
                    $pdo->beginTransaction();
                    
                    try {
                        // โหลดข้อมูล Master Data ทั้งหมดเข้าหน่วยความจำเพื่อความรวดเร็ว
                        $stmtAllClass = $pdo->query("SELECT classificationID, classificationName FROM classification");
                        $classList    = $stmtAllClass->fetchAll(PDO::FETCH_ASSOC);

                        $stmtAllBuild = $pdo->query("SELECT buildingID, buildingName, is_active FROM building");
                        $buildList    = $stmtAllBuild->fetchAll(PDO::FETCH_ASSOC);

                        $stmtAllRoom  = $pdo->query("SELECT classroomID, classroomName, buildingID FROM building_classroom");
                        $roomList     = $stmtAllRoom->fetchAll(PDO::FETCH_ASSOC);

                        // เตรียม Prepared Statements
                        // 1. ตรวจสอบข้อมูลซ้ำจากรหัสครุภัณฑ์ (DA_Number)
                        $stmtCheck = $pdo->prepare("SELECT DA_ID, statusID, is_visible FROM durable_articles WHERE DA_Number = :DA_Number");
                        
                        // 2. คำสั่ง INSERT
                        $stmtInsert = $pdo->prepare("INSERT INTO durable_articles 
                            (DA_ID, DA_Number, DA_ListName, DA_Brand, classificationID, buildingID, classroomID, DA_Price, DA_Year, statusID, created_by, owner_department, owner_useraccountID, useraccountID, is_approved, is_visible) 
                            VALUES 
                            (:DA_ID, :DA_Number, :DA_ListName, :DA_Brand, :classificationID, :buildingID, :classroomID, :DA_Price, :DA_Year, 's0001', :created_by, :owner_department, :owner_useraccountID, :useraccountID, 1, :is_visible)");
                        
                        // 3. คำสั่ง UPDATE
                        $stmtUpdate = $pdo->prepare("UPDATE durable_articles 
                            SET DA_ListName      = :DA_ListName, 
                                DA_Brand         = :DA_Brand, 
                                classificationID = :classificationID, 
                                buildingID       = :buildingID, 
                                classroomID      = :classroomID, 
                                DA_Price         = :DA_Price,
                                DA_Year          = COALESCE(:DA_Year, DA_Year),
                                owner_department = COALESCE(:owner_department, owner_department),
                                is_visible       = :is_visible
                            WHERE DA_Number = :DA_Number");

                        // ฟังก์ชันแปลงค่าว่างเป็น NULL
                        $toNull = function($val) {
                            $val = trim($val ?? '');
                            return ($val === '') ? null : $val;
                        };

                        // ฟังก์ชันช่วยวิเคราะห์และจับคู่ classificationID อัตโนมัติ
                        $detectClassification = function($inputClass, $daNumber, $daName) use ($classList) {
                            $inputClass = trim($inputClass ?? '');
                            
                            // 1. ตรวจสอบจากค่าที่ระบุมา (ID หรือ ชื่อ)
                            if (!empty($inputClass)) {
                                foreach ($classList as $c) {
                                    if (strcasecmp($c['classificationID'], $inputClass) === 0) {
                                        return $c['classificationID'];
                                    }
                                    if (mb_strpos($c['classificationName'], $inputClass) !== false || mb_strpos($inputClass, $c['classificationName']) !== false) {
                                        return $c['classificationID'];
                                    }
                                }
                                if (preg_match('/^c[0-9]+$/i', $inputClass)) {
                                    return strtolower($inputClass);
                                }
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
                            
                            // 3. วิเคราะห์อัจฉริยะจากชื่อรายการ (DA_ListName)
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
                            
                            return 'c0000000003'; // Default: ครุภัณฑ์สำนักงาน
                        };

                        // ฟังก์ชันช่วยจับคู่ buildingID
                        $detectBuilding = function($inputBuild) use ($buildList) {
                            $inputBuild = trim($inputBuild ?? '');
                            if (empty($inputBuild)) return null;

                            foreach ($buildList as $b) {
                                if (strcasecmp($b['buildingID'], $inputBuild) === 0) {
                                    return $b['buildingID'];
                                }
                                if (mb_strpos($b['buildingName'], $inputBuild) !== false || mb_strpos($inputBuild, $b['buildingName']) !== false) {
                                    return $b['buildingID'];
                                }
                            }
                            return $inputBuild;
                        };

                        // ฟังก์ชันช่วยจับคู่ classroomID
                        $detectClassroom = function($inputRoom) use ($roomList) {
                            $inputRoom = trim($inputRoom ?? '');
                            if (empty($inputRoom)) return null;

                            foreach ($roomList as $r) {
                                if (strcasecmp($r['classroomID'], $inputRoom) === 0) {
                                    return $r['classroomID'];
                                }
                                if (mb_strpos($r['classroomName'], $inputRoom) !== false || mb_strpos($inputRoom, $r['classroomName']) !== false) {
                                    return $r['classroomID'];
                                }
                            }
                            return $inputRoom;
                        };

                        // ฟังก์ชันช่วยวิเคราะห์ปีงบประมาณ (DA_Year)
                        $detectYear = function($inputYear, $daNumber) {
                            $inputYear = trim($inputYear ?? '');
                            if (!empty($inputYear) && preg_match('/^[0-9]{4}$/', $inputYear)) {
                                return $inputYear;
                            }
                            // ดึงจากรหัสครุภัณฑ์ เช่น /43 -> 2543, /45 -> 2545, /66 -> 2566
                            if (preg_match('/\/([0-9]{2})(?:\(|$)/', $daNumber, $m)) {
                                return '25' . $m[1];
                            }
                            // ดึง 2 หลักแรก เช่น 571004... -> 2557, 631004... -> 2563
                            if (preg_match('/^([4-7][0-9])[0-9]{4}/', $daNumber, $m)) {
                                return '25' . $m[1];
                            }
                            // ค่าตั้งต้น: ปี พ.ศ. ปัจจุบัน
                            return (string)(date('Y') + 543);
                        };

                        // วนลูปอ่านข้อมูลทีละแถวจากไฟล์ CSV
                        $rowNumber = 1;
                        while (($data = fgetcsv($handle, 1000, ",", '"', "\\")) !== FALSE) {
                            $rowNumber++;
                            
                            // ข้ามแถวที่ว่างทั้งหมด
                            if (empty(array_filter($data, fn($v) => trim($v ?? '') !== ''))) {
                                continue;
                            }

                            // ตรวจสอบฟิลด์บังคับ: DA_Number และ DA_ListName
                            $da_number   = trim($data[$colNum] ?? '');
                            $da_listname = trim($data[$colName] ?? '');

                            if (empty($da_number) || empty($da_listname)) {
                                throw new Exception("แถวที่ {$rowNumber}: ข้อมูลไม่สมบูรณ์ (จำเป็นต้องระบุรหัสครุภัณฑ์และชื่อรายการ)");
                            }

                            // จัดการฟิลด์ Nullable
                            $da_brand     = $toNull($colBrand !== false ? ($data[$colBrand] ?? '') : '');
                            $raw_class    = $colClass !== false ? ($data[$colClass] ?? '') : '';
                            $raw_build    = $colBuild !== false ? ($data[$colBuild] ?? '') : '';
                            $raw_room     = $colRoom !== false ? ($data[$colRoom] ?? '') : '';
                            $raw_price    = $colPrice !== false ? ($data[$colPrice] ?? '') : '';
                            $raw_year     = $colYear !== false ? ($data[$colYear] ?? '') : '';
                            $raw_dept     = $colDept !== false ? ($data[$colDept] ?? '') : '';

                            // ประมวลผลและแปลงค่า
                            $class_id     = $detectClassification($raw_class, $da_number, $da_listname);
                            $build_id     = $detectBuilding($raw_build);
                            $classroom_id = $detectClassroom($raw_room);
                            $da_year      = $detectYear($raw_year, $da_number);
                            $owner_dept   = !empty(trim($raw_dept)) ? trim($raw_dept) : $currentUserDept;

                            // จัดการราคา (DA_Price)
                            $clean_price = str_replace(',', '', trim($raw_price));
                            $da_price    = (is_numeric($clean_price)) ? (float)$clean_price : null;

                            // กำหนดการซ่อน/แสดง (is_visible): หากเป็นอาคารเรียนรวม CLB ให้ซ่อนไว้ตามนโยบายระบบ
                            $is_visible = (str_starts_with((string)$build_id, 'CLB')) ? 0 : 1;

                            // สร้าง Primary Key (DA_ID)
                            $da_id = "DA" . date('YmdHis') . rand(100, 999);

                            // ตรวจสอบข้อมูลเดิมในฐานข้อมูล
                            $stmtCheck->execute([':DA_Number' => $da_number]);
                            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                            if ($existing) {
                                // ถ้ามีแล้ว -> ให้ทำการอัปเดตข้อมูล (UPDATE)
                                $stmtUpdate->execute([
                                    ':DA_Number'        => $da_number,
                                    ':DA_ListName'      => $da_listname,
                                    ':DA_Brand'         => $da_brand,
                                    ':classificationID' => $class_id,
                                    ':buildingID'       => $build_id,
                                    ':classroomID'      => $classroom_id,
                                    ':DA_Price'         => $da_price,
                                    ':DA_Year'          => $da_year,
                                    ':owner_department' => $owner_dept,
                                    ':is_visible'       => $is_visible
                                ]);
                                $updateCount++;
                                $importedRows[] = [
                                    'action' => 'update',
                                    'da_number' => $da_number,
                                    'da_name' => $da_listname,
                                    'category' => $class_id,
                                    'building' => $build_id ?: '-',
                                    'year' => $da_year
                                ];
                            } else {
                                // ถ้ายังไม่มี -> เพิ่มข้อมูลใหม่ (INSERT)
                                $stmtInsert->execute([
                                    ':DA_ID'                => $da_id,
                                    ':DA_Number'            => $da_number,
                                    ':DA_ListName'          => $da_listname,
                                    ':DA_Brand'             => $da_brand,
                                    ':classificationID'     => $class_id,
                                    ':buildingID'           => $build_id,
                                    ':classroomID'          => $classroom_id,
                                    ':DA_Price'             => $da_price,
                                    ':DA_Year'              => $da_year,
                                    ':created_by'           => $currentUserID,
                                    ':owner_department'     => $owner_dept,
                                    ':owner_useraccountID'  => $currentUserID,
                                    ':useraccountID'        => $currentUserID,
                                    ':is_visible'           => $is_visible
                                ]);
                                $successCount++;
                                $importedRows[] = [
                                    'action' => 'insert',
                                    'da_number' => $da_number,
                                    'da_name' => $da_listname,
                                    'category' => $class_id,
                                    'building' => $build_id ?: '-',
                                    'year' => $da_year
                                ];
                            }
                        }
                        
                        // ยืนยันการเปลี่ยนแปลงข้อมูลลง Database
                        $pdo->commit();
                        
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $errorCount++;
                        $errorMsg = $e->getMessage();
                    }
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

$pageTitle = "ผลลัพธ์การนำเข้าข้อมูลครุภัณฑ์";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>

<div class="container-fluid px-0">
    <div class="row justify-content-center">
        <div class="col-lg-11 col-xl-10">
            <div class="card shadow border-0">
                <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="fa-solid fa-file-circle-check text-primary me-2"></i>ผลลัพธ์การนำเข้าข้อมูลครุภัณฑ์ (CSV Import Result)
                    </h5>
                    <a href="import_csv.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                        <i class="fa-solid fa-arrow-left me-1"></i> นำเข้าไฟล์อื่น
                    </a>
                </div>
                <div class="card-body p-4 p-md-5">
                    <?php if ($errorCount > 0 && empty($successCount) && empty($updateCount)): ?>
                        <div class="text-center py-4">
                            <div class="mb-3">
                                <i class="fa-solid fa-circle-xmark text-danger fa-4x"></i>
                            </div>
                            <h3 class="fw-bold text-danger">การนำเข้าข้อมูลล้มเหลว</h3>
                            <p class="text-muted mt-2 fs-6">เกิดข้อผิดพลาด: <?php echo htmlspecialchars($errorMsg); ?></p>
                            <div class="mt-4">
                                <a href="import_csv.php" class="btn btn-primary rounded-pill px-4 me-2">
                                    <i class="fa-solid fa-rotate-right me-1"></i> ลองใหม่อีกครั้ง
                                </a>
                                <a href="manage_data.php" class="btn btn-outline-secondary rounded-pill px-4">
                                    กลับหน้าจัดการข้อมูล
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center mb-4">
                            <div class="mb-2">
                                <i class="fa-solid fa-circle-check text-success fa-4x"></i>
                            </div>
                            <h3 class="fw-bold text-success">ประมวลผลการนำเข้าข้อมูลเสร็จสมบูรณ์!</h3>
                            <p class="text-muted">ข้อมูลครุภัณฑ์ถูกซิงค์และบันทึกลงในฐานข้อมูลเรียบร้อยแล้ว</p>
                        </div>

                        <!-- กล่องสรุปผล KPI -->
                        <div class="row g-3 justify-content-center mb-4">
                            <div class="col-sm-6 col-md-4">
                                <div class="card bg-success-subtle border-success border-opacity-25 h-100 text-center p-3 shadow-xs">
                                    <div class="text-success small fw-semibold">เพิ่มครุภัณฑ์ใหม่</div>
                                    <h2 class="fw-bold text-success mb-0"><?php echo number_format($successCount); ?></h2>
                                    <div class="font-xs text-muted mt-1">รายการที่เพิ่มใหม่ (INSERT)</div>
                                </div>
                            </div>
                            <div class="col-sm-6 col-md-4">
                                <div class="card bg-warning-subtle border-warning border-opacity-25 h-100 text-center p-3 shadow-xs">
                                    <div class="text-warning-emphasis small fw-semibold">อัปเดตข้อมูลเดิม</div>
                                    <h2 class="fw-bold text-warning-emphasis mb-0"><?php echo number_format($updateCount); ?></h2>
                                    <div class="font-xs text-muted mt-1">มีในระบบแล้ว (UPDATE)</div>
                                </div>
                            </div>
                            <?php if ($errorCount > 0): ?>
                                <div class="col-sm-6 col-md-4">
                                    <div class="card bg-danger-subtle border-danger border-opacity-25 h-100 text-center p-3 shadow-xs">
                                        <div class="text-danger small fw-semibold">ข้อผิดพลาด</div>
                                        <h2 class="fw-bold text-danger mb-0"><?php echo number_format($errorCount); ?></h2>
                                        <div class="font-xs text-muted mt-1"><?php echo htmlspecialchars($errorMsg); ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ตารางตัวอย่างรายการที่ประมวลผล -->
                        <?php if (!empty($importedRows)): ?>
                            <h6 class="fw-bold text-dark mb-3">
                                <i class="fa-solid fa-list-check text-primary me-2"></i>รายการที่ประมวลผล (แสดงสูงสุด 10 รายการแรก)
                            </h6>
                            <div class="table-responsive rounded border mb-4">
                                <table class="table table-hover align-middle mb-0 text-center">
                                    <thead class="table-light text-secondary small">
                                        <tr>
                                            <th>การดำเนินการ</th>
                                            <th>รหัสครุภัณฑ์</th>
                                            <th class="text-start">ชื่อรายการ</th>
                                            <th>รหัสหมวดหมู่</th>
                                            <th>อาคาร</th>
                                            <th>ปีงบประมาณ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($importedRows, 0, 10) as $row): ?>
                                            <tr>
                                                <td>
                                                    <?php if ($row['action'] === 'insert'): ?>
                                                        <span class="badge bg-success-subtle text-success border border-success font-xs"><i class="fa-solid fa-plus me-1"></i>เพิ่มใหม่</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning font-xs"><i class="fa-solid fa-pen me-1"></i>อัปเดต</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="font-monospace text-primary fw-bold"><?php echo htmlspecialchars($row['da_number']); ?></td>
                                                <td class="text-start text-dark"><?php echo htmlspecialchars($row['da_name']); ?></td>
                                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['category']); ?></span></td>
                                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['building']); ?></span></td>
                                                <td><span class="badge bg-secondary-subtle text-secondary"><?php echo htmlspecialchars($row['year']); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <div class="text-center mt-4">
                            <a href="manage_data.php" class="btn btn-primary rounded-pill px-4 me-2 shadow-sm">
                                <i class="fa-solid fa-table me-1"></i> ไปยังหน้าจัดการครุภัณฑ์
                            </a>
                            <a href="import_csv.php" class="btn btn-outline-secondary rounded-pill px-4">
                                <i class="fa-solid fa-cloud-arrow-up me-1"></i> นำเข้าไฟล์ถัดไป
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../component/admin_footer.php'; ?>
