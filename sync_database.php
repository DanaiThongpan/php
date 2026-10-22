<?php
/**
 * sync_database.php - Enterprise Database Synchronization & Data Masking Workflow
 * 
 * ระบบตรวจสอบและซิงโครไนซ์ฐานข้อมูลระหว่าง Production MySQL Dump (control/daam.sql)
 * และ Development SQLite (control/daam_dev.sqlite)
 * 
 * ความสามารถหลัก:
 * 1. ตรวจสอบการเปลี่ยนแปลงของไฟล์ daam.sql (SHA-256 Hash & Modification Time)
 * 2. แปลงโครงสร้าง (Schema) และข้อมูล (Data Type Mapping) จาก MySQL สู่ SQLite
 * 3. Conditional Data Hiding (Soft Delete): ตรวจจับครุภัณฑ์ "อาคารเรียนรวม" หรือ "CLB"
 *    และกำหนดค่า is_visible = 0 (ซ่อน) อัตโนมัติ พร้อมรองรับการกรองข้อมูล
 * 4. รักษาข้อมูลและโครงสร้างเฉพาะระบบ (Custom Columns & Tables เช่น admin_test, system_settings)
 * 5. ปลอดภัยด้วย SQLite Transaction (Atomic Commit / Rollback) และทำงานได้รวดเร็วระดับเสี้ยววินาที
 * 
 * การใช้งานผ่าน CLI:
 *   php sync_database.php           # ตรวจสอบและซิงค์หากพบการเปลี่ยนแปลง
 *   php sync_database.php --force   # บังคับซิงค์ใหม่ทันที
 *   php sync_database.php --help    # แสดงคู่มือการใช้งาน
 */

// ตรวจสอบโหมดการรัน (CLI หรือ Web Browser)
$isCli = (php_sapi_name() === 'cli');

// หากเรียกผ่าน Web Browser ให้ตรวจสอบ Session ป้องกัน Unauthorized Access
if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // เฉพาะ Super Admin หรือ Admin ที่ล็อกอินอยู่เท่านั้น
    if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1) {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: กรุณาเข้าสู่ระบบก่อนใช้งานฟังก์ชันนี้']);
        exit();
    }
}

// -----------------------------------------------------------------------------
// 1. กำหนดค่าและ Path ไฟล์ฐานข้อมูล
// -----------------------------------------------------------------------------
$baseDir    = __DIR__;
$sqlFile    = $baseDir . '/control/daam.sql';
$sqliteFile = $baseDir . '/control/daam_dev.sqlite';

// ตรวจสอบ Argument --force
$forceSync = false;
$verbose   = true;

if ($isCli) {
    global $argv;
    if (isset($argv) && is_array($argv)) {
        if (in_array('--force', $argv) || in_array('-f', $argv)) {
            $forceSync = true;
        }
        if (in_array('--help', $argv) || in_array('-h', $argv)) {
            echo "============================================================\n";
            echo "  ระบบ Database Synchronization & Data Masking (daam.sql -> SQLite)\n";
            echo "============================================================\n";
            echo "วิธีใช้งาน:\n";
            echo "  php sync_database.php         ตรวจสอบการเปลี่ยนแปลงและซิงค์เฉพาะเมื่อมีอัปเดต\n";
            echo "  php sync_database.php --force บังคับซิงค์ใหม่ทั้งหมดทันที (Force Re-sync)\n";
            echo "  php sync_database.php --help  แสดงข้อความช่วยเหลือนี้\n\n";
            exit(0);
        }
        if (in_array('--quiet', $argv) || in_array('-q', $argv)) {
            $verbose = false;
        }
    }
} else {
    if (isset($_GET['force']) && $_GET['force'] == '1') {
        $forceSync = true;
    }
}

// ฟังก์ชัน Log ข้อความ
function logMsg($msg, $type = 'info') {
    global $isCli, $verbose;
    if (!$verbose && $type === 'info') return;

    $colors = [
        'info'    => "\033[36m", // Cyan
        'success' => "\033[32m", // Green
        'warning' => "\033[33m", // Yellow
        'error'   => "\033[31m", // Red
        'reset'   => "\033[0m"
    ];

    if ($isCli) {
        $color = $colors[$type] ?? '';
        $reset = $colors['reset'] ?? '';
        echo "{$color}{$msg}{$reset}\n";
    }
}

logMsg("============================================================");
logMsg("  🚀 เริ่มต้นกระบวนการ Database Synchronization & Data Masking");
logMsg("============================================================");

// ตรวจสอบความมีอยู่ของไฟล์ daam.sql
if (!file_exists($sqlFile)) {
    logMsg("❌ ไม่พบไฟล์ต้นทาง MySQL Dump: {$sqlFile}", 'error');
    if (!$isCli) {
        echo json_encode(['status' => 'error', 'message' => "ไม่พบไฟล์ต้นทาง: {$sqlFile}"]);
    }
    exit(1);
}

// คำนวณ Metadata ของไฟล์ daam.sql
$fileSize  = filesize($sqlFile);
$fileMtime = filemtime($sqlFile);
$fileHash  = hash_file('sha256', $sqlFile);

logMsg("📄 ไฟล์ต้นทาง: control/daam.sql (" . number_format($fileSize / 1024, 2) . " KB)");
logMsg("🔑 SHA-256 Hash: {$fileHash}");
logMsg("🕒 อัปเดตล่าสุด: " . date('Y-m-d H:i:s', $fileMtime));

// -----------------------------------------------------------------------------
// 2. เชื่อมต่อ SQLite และตรวจสอบ Metadata การซิงค์ (_sync_meta)
// -----------------------------------------------------------------------------
try {
    $pdo = new PDO("sqlite:" . $sqliteFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // สร้างตารางบันทึก Metadata การซิงค์หากยังไม่มี
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS _sync_meta (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_file TEXT NOT NULL,
            file_hash TEXT NOT NULL,
            file_mtime INTEGER NOT NULL,
            file_size INTEGER NOT NULL,
            last_synced_at DATETIME NOT NULL,
            total_records INTEGER DEFAULT 0,
            hidden_records INTEGER DEFAULT 0,
            visible_records INTEGER DEFAULT 0,
            sync_status TEXT DEFAULT 'SUCCESS',
            note TEXT
        );
    ");

    // ตรวจสอบประวัติการซิงค์ล่าสุด
    $stmtLastSync = $pdo->query("SELECT * FROM _sync_meta ORDER BY id DESC LIMIT 1");
    $lastSync = $stmtLastSync->fetch();

    $isChanged = true;
    if ($lastSync && $lastSync['file_hash'] === $fileHash && !$forceSync) {
        $isChanged = false;
    }

    if (!$isChanged) {
        logMsg("------------------------------------------------------------");
        logMsg("✨ [SKIPPED] ไฟล์ daam.sql ไม่มีการเปลี่ยนแปลงจากครั้งก่อนหน้า", 'success');
        logMsg("   - ซิงค์ล่าสุดเมื่อ: {$lastSync['last_synced_at']}");
        logMsg("   - ครุภัณฑ์ทั้งหมด: " . number_format($lastSync['total_records']) . " รายการ");
        logMsg("   - ซ่อน (อาคารเรียนรวม/CLB): " . number_format($lastSync['hidden_records']) . " รายการ (is_visible = 0)");
        logMsg("   - ใช้งานปกติ: " . number_format($lastSync['visible_records']) . " รายการ (is_visible = 1)");
        logMsg("   💡 หากต้องการบังคับซิงค์ใหม่ ให้ระบุแฟล็ก --force (เช่น php sync_database.php --force)", 'warning');
        logMsg("============================================================");

        if (!$isCli) {
            echo json_encode([
                'status'  => 'skipped',
                'message' => 'ฐานข้อมูล SQLite เป็นเวอร์ชันล่าสุดแล้ว ไม่พบการเปลี่ยนแปลงใน daam.sql',
                'meta'    => $lastSync
            ]);
        }
        exit(0);
    }

    if ($forceSync && $lastSync && $lastSync['file_hash'] === $fileHash) {
        logMsg("⚡ ตรวจพบแฟล็ก --force: บังคับซิงค์ข้อมูลใหม่ทั้งหมดแม้ไฟล์จะไม่มีการเปลี่ยนแปลง", 'warning');
    } else {
        logMsg("🔄 ตรวจพบการเปลี่ยนแปลงใน daam.sql: เริ่มกระบวนการ Migration สู่ SQLite...", 'info');
    }

} catch (PDOException $e) {
    logMsg("❌ เกิดข้อผิดพลาดในการเชื่อมต่อ SQLite: " . $e->getMessage(), 'error');
    if (!$isCli) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit(1);
}

// -----------------------------------------------------------------------------
// 3. สำรองข้อมูลตารางเฉพาะของระบบ (Custom Tables & Data Preservation)
// -----------------------------------------------------------------------------
// ตารางเหล่านี้ไม่มีใน daam.sql ต้นฉบับ แต่ถูกใช้งานในแอปพลิเคชัน
// เช่น system_settings (ตั้งค่าเมล), borrowing, borrow_back (ประวัติการยืม-คืน), และ mock users
$preservedData = [];

try {
    // 3.1 สำรอง system_settings
    $hasSettings = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='system_settings'")->fetchColumn();
    if ($hasSettings) {
        $preservedData['system_settings'] = $pdo->query("SELECT * FROM system_settings")->fetchAll();
    }

    // 3.2 สำรอง borrowing
    $hasBorrowing = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='borrowing'")->fetchColumn();
    if ($hasBorrowing) {
        $preservedData['borrowing'] = $pdo->query("SELECT * FROM borrowing")->fetchAll();
    }

    // 3.3 สำรอง borrow_back (ถ้ามีข้อมูลใน SQLite อยู่ก่อนแล้ว เพื่อไม่ให้ประวัติที่ทดสอบหายไป)
    $hasBorrowBack = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='borrow_back'")->fetchColumn();
    if ($hasBorrowBack) {
        $existingBorrowBack = $pdo->query("SELECT * FROM borrow_back")->fetchAll();
        if (!empty($existingBorrowBack)) {
            $preservedData['borrow_back'] = $existingBorrowBack;
        }
    }

    // 3.4 สำรองข้อมูลผู้ใช้พิเศษ เช่น admin_test
    $hasUser = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='useraccount'")->fetchColumn();
    if ($hasUser) {
        $adminTest = $pdo->query("SELECT * FROM useraccount WHERE useraccountID = 'admin_test'")->fetch();
        if ($adminTest) {
            $preservedData['admin_test'] = $adminTest;
        }
    }
} catch (Exception $e) {
    logMsg("⚠️ คำเตือนระหว่างสำรองข้อมูลเสริม: " . $e->getMessage(), 'warning');
}

// รีเซ็ตการเชื่อมต่อ PDO เพื่อปิด Cursor ทั้งหมด ป้องกัน SQLite Table Lock
$pdo = null;
$pdo = new PDO("sqlite:" . $sqliteFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("PRAGMA busy_timeout = 5000;");

// -----------------------------------------------------------------------------
// 4. Parser สำหรับอ่านข้อมูล daam.sql
// -----------------------------------------------------------------------------
$t_start = microtime(true);
$sqlContent = file_get_contents($sqlFile);

/**
 * แยก Tuple ค่าใน VALUES (...), (...) ของ MySQL Dump ออกเป็น Array
 */
function parseValuesTuples($valuesBlock) {
    $rows = [];
    $len = strlen($valuesBlock);
    $inQuote = false;
    $quoteChar = '';
    $currentRow = [];
    $currentVal = '';
    $inRow = false;

    for ($i = 0; $i < $len; $i++) {
        $c = $valuesBlock[$i];

        if (!$inQuote) {
            if ($c === '(') {
                $inRow = true;
                $currentRow = [];
                $currentVal = '';
                continue;
            }
            if ($c === ')') {
                if ($inRow) {
                    $currentRow[] = trim($currentVal);
                    $rows[] = $currentRow;
                    $currentRow = [];
                    $currentVal = '';
                    $inRow = false;
                }
                continue;
            }
            if ($c === '\'' || $c === '"') {
                $inQuote = true;
                $quoteChar = $c;
                continue;
            }
            if ($c === ',' && $inRow) {
                $currentRow[] = trim($currentVal);
                $currentVal = '';
                continue;
            }
            if ($inRow) {
                $currentVal .= $c;
            }
        } else {
            // อยู่ในสตริง/Quote
            if ($c === '\\') {
                $next = ($i + 1 < $len) ? $valuesBlock[$i + 1] : '';
                $currentVal .= $next;
                $i++;
                continue;
            }
            if ($c === $quoteChar) {
                // รองรับ '' ใน SQL
                if ($i + 1 < $len && $valuesBlock[$i + 1] === $quoteChar) {
                    $currentVal .= $quoteChar;
                    $i++;
                    continue;
                }
                $inQuote = false;
                continue;
            }
            $currentVal .= $c;
        }
    }

    return $rows;
}

/**
 * แปลงประเภทข้อมูล MySQL ให้เป็น SQLite Data Types
 */
function mapMysqlTypeToSqlite($mysqlType) {
    $t = strtoupper(trim($mysqlType));
    if (preg_match('/^(INT|TINYINT|SMALLINT|MEDIUMINT|BIGINT)/i', $t)) {
        return 'INTEGER';
    }
    if (preg_match('/^(DECIMAL|NUMERIC|FLOAT|DOUBLE|REAL)/i', $t)) {
        return 'REAL';
    }
    if (preg_match('/^(BOOLEAN|BOOL)/i', $t)) {
        return 'INTEGER';
    }
    return 'TEXT';
}

// -----------------------------------------------------------------------------
// 5. ดำเนินการสร้าง Schema และ Seeding ข้อมูลลงใน SQLite
// -----------------------------------------------------------------------------
try {
    $pdo->exec("PRAGMA foreign_keys = OFF;");
    $pdo->exec("PRAGMA journal_mode = WAL;");
    $pdo->exec("PRAGMA synchronous = NORMAL;");

    // 5.1 รายชื่อตารางหลักและโครงสร้าง DDL สำหรับ SQLite พร้อมรองรับ Custom Columns
    $tableSchemas = [
        'status_active' => "
            CREATE TABLE `status_active` (
                `statusID` VARCHAR(10) PRIMARY KEY,
                `statusName` TEXT
            );
        ",
        'classification' => "
            CREATE TABLE `classification` (
                `classificationID` VARCHAR(20) PRIMARY KEY,
                `classificationName` TEXT
            );
        ",
        'building' => "
            CREATE TABLE `building` (
                `buildingID` VARCHAR(20) PRIMARY KEY,
                `buildingName` TEXT,
                `building_timestamp` TEXT,
                `building_URLImg` TEXT
            );
        ",
        'building_classroom' => "
            CREATE TABLE `building_classroom` (
                `classroomID` VARCHAR(20) PRIMARY KEY,
                `classroomName` TEXT,
                `buildingID` VARCHAR(20),
                `classroom_timestamp` TEXT,
                `classroom_URLImg` TEXT
            );
        ",
        'useraccount' => "
            CREATE TABLE `useraccount` (
                `useraccountID` VARCHAR(100) PRIMARY KEY,
                `useraccountName` TEXT NOT NULL,
                `userRank` TEXT DEFAULT NULL,
                `userDepartment` TEXT DEFAULT NULL,
                `userPassword` TEXT NOT NULL,
                `userActive` VARCHAR(10) DEFAULT '1',
                `useraccountType` VARCHAR(50) NOT NULL,
                `userEmail` VARCHAR(150) DEFAULT NULL
            );
        ",
        'usertype' => "
            CREATE TABLE `usertype` (
                `useraccountType` VARCHAR(50) PRIMARY KEY,
                `usertypeName` TEXT
            );
        ",
        'repair' => "
            CREATE TABLE `repair` (
                `repairID` VARCHAR(50) PRIMARY KEY,
                `repair_Hisory` TEXT,
                `repair_Price` REAL,
                `DA_ID` VARCHAR(50),
                `repair_Date` TEXT
            );
        ",
        'borrow_back' => "
            CREATE TABLE `borrow_back` (
                `IDborrow_back` VARCHAR(50) PRIMARY KEY,
                `DA_ID` VARCHAR(50),
                `Date_borrow` TEXT,
                `Name_borrow` TEXT,
                `Rank_borrow` TEXT,
                `Belongto_borrow` TEXT,
                `Detail_borrow` TEXT,
                `Deadline_borrow` TEXT,
                `useraccountID_borrow` TEXT,
                `timestamp_borrow` TEXT,
                `Date_back` TEXT,
                `Name_back` TEXT,
                `Rank_back` TEXT,
                `Detail_back` TEXT,
                `useraccountID_back` TEXT,
                `timestamp_back` TEXT,
                `userEmail` TEXT DEFAULT NULL,
                `due_date` DATE DEFAULT NULL,
                `status` TEXT DEFAULT 'borrowed',
                `last_reminded_at` DATETIME DEFAULT NULL
            );
        ",
        'durable_articles' => "
            CREATE TABLE `durable_articles` (
                `DA_ID` VARCHAR(50) PRIMARY KEY,
                `DA_ListName` TEXT NOT NULL,
                `DA_Brand` TEXT,
                `DA_Size` TEXT,
                `DA_Number` TEXT,
                `DA_Year` TEXT,
                `statusID` VARCHAR(20),
                `DA_Detail` TEXT,
                `useraccountID` VARCHAR(100),
                `classificationID` VARCHAR(50),
                `DA_URLImg` TEXT,
                `buildingID` VARCHAR(50),
                `classroomID` VARCHAR(50),
                `DA_Price` REAL,
                `DA_DetailLocation` TEXT,
                `created_by` VARCHAR(50) DEFAULT NULL,
                `owner_useraccountID` VARCHAR(50) DEFAULT NULL,
                `owner_department` VARCHAR(100) DEFAULT NULL,
                `is_approved` INTEGER DEFAULT 1,
                `is_visible` INTEGER DEFAULT 1
            );
        "
    ];

    // สร้างตารางใหม่ทั้งหมด
    foreach ($tableSchemas as $tableName => $createDDL) {
        $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`;");
        $pdo->exec($createDDL);
    }

    logMsg("📦 สร้างโครงสร้างตาราง (Schema Mapping) สำเร็จ 9 ตาราง");

    // เริ่ม Transaction สำหรับการนำเข้าข้อมูล (Bulk Inserts)
    $pdo->beginTransaction();

    // 5.2 สกัดคำสั่ง INSERT ทั้งหมดจาก daam.sql
    preg_match_all('/INSERT INTO\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)\s*VALUES\s*(.*?);/s', $sqlContent, $insertMatches, PREG_SET_ORDER);

    $insertedCounts = [];
    $totalDA = 0;
    $hiddenDA = 0;
    $visibleDA = 0;

    foreach ($insertMatches as $m) {
        $tableName = $m[1];
        if (!isset($tableSchemas[$tableName])) {
            continue;
        }

        // รายชื่อคอลัมน์จากคำสั่ง INSERT ใน SQL
        $rawCols = explode(',', $m[2]);
        $columns = array_map(function($c) {
            return trim(trim($c), "` \t\n\r");
        }, $rawCols);

        // แยก Tuple ข้อมูลออกมาเป็นแถว
        $rows = parseValuesTuples($m[3]);
        if (empty($rows)) continue;

        if (!isset($insertedCounts[$tableName])) {
            $insertedCounts[$tableName] = 0;
        }

        // กรณีตาราง durable_articles: ทำ Data Masking & Soft Delete
        if ($tableName === 'durable_articles') {
            $idxBuilding = array_search('buildingID', $columns);
            $idxLocation = array_search('DA_DetailLocation', $columns);
            $idxName     = array_search('DA_ListName', $columns);
            $idxDetail   = array_search('DA_Detail', $columns);

            // เพิ่มคอลัมน์พิเศษ is_visible, is_approved, owner_department ในการ Insert
            $insertCols = $columns;
            $insertCols[] = 'is_approved';
            $insertCols[] = 'is_visible';
            $insertCols[] = 'owner_department';

            $colList = implode(', ', array_map(function($c) { return "`{$c}`"; }, $insertCols));
            $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
            $insertSql = "INSERT OR REPLACE INTO `durable_articles` ({$colList}) VALUES ({$placeholders})";
            $stmtInsert = $pdo->prepare($insertSql);

            foreach ($rows as $row) {
                $bId = ($idxBuilding !== false && isset($row[$idxBuilding])) ? $row[$idxBuilding] : '';
                $loc = ($idxLocation !== false && isset($row[$idxLocation])) ? $row[$idxLocation] : '';
                $nam = ($idxName !== false && isset($row[$idxName])) ? $row[$idxName] : '';
                $det = ($idxDetail !== false && isset($row[$idxDetail])) ? $row[$idxDetail] : '';

                // ตัดเครื่องหมายคำพูดรอบข้อความ
                $bIdClean = trim($bId, "'\"");
                $locClean = trim($loc, "'\"");
                $namClean = trim($nam, "'\"");
                $detClean = trim($det, "'\"");

                // กฎ Conditional Data Hiding: ตรวจจับ "อาคารเรียนรวม" หรือ "CLB"
                $textToCheck = "{$bIdClean} {$locClean} {$namClean} {$detClean}";
                $isClbOrBuilding = (bool)preg_match('/(CLB|อาคารเรียนรวม)/ui', $textToCheck);

                $isVisible = $isClbOrBuilding ? 0 : 1;
                if ($isVisible === 0) {
                    $hiddenDA++;
                } else {
                    $visibleDA++;
                }
                $totalDA++;

                // ทำความสะอาดค่าแต่ละฟิลด์
                $cleanRow = [];
                foreach ($row as $val) {
                    $val = trim($val);
                    if ($val === 'NULL' || $val === 'null') {
                        $cleanRow[] = null;
                    } elseif (preg_match('/^[\'"](.*)[\'"]$/s', $val, $vm)) {
                        $cleanRow[] = str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], $vm[1]);
                    } else {
                        $cleanRow[] = is_numeric($val) ? $val : $val;
                    }
                }

                // ต่อท้ายด้วยคอลัมน์พิเศษ
                $cleanRow[] = 1; // is_approved (ข้อมูลใน Production ถือว่าได้รับการอนุมัติแล้ว)
                $cleanRow[] = $isVisible; // is_visible (0 = ซ่อนสำหรับอาคารเรียนรวม, 1 = แสดงปกติ)
                $cleanRow[] = 'ส่วนกลาง'; // owner_department เริ่มต้น

                $stmtInsert->execute($cleanRow);
                $insertedCounts[$tableName]++;
            }

        } else {
            // สำหรับตารางอื่นๆ Insert ข้อมูลตามปกติ
            $colList = implode(', ', array_map(function($c) { return "`{$c}`"; }, $columns));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $insertSql = "INSERT OR REPLACE INTO `{$tableName}` ({$colList}) VALUES ({$placeholders})";
            $stmtInsert = $pdo->prepare($insertSql);

            foreach ($rows as $row) {
                $cleanRow = [];
                foreach ($row as $val) {
                    $val = trim($val);
                    if ($val === 'NULL' || $val === 'null') {
                        $cleanRow[] = null;
                    } elseif (preg_match('/^[\'"](.*)[\'"]$/s', $val, $vm)) {
                        $cleanRow[] = str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], $vm[1]);
                    } else {
                        $cleanRow[] = is_numeric($val) ? $val : $val;
                    }
                }
                $stmtInsert->execute($cleanRow);
                $insertedCounts[$tableName]++;
            }
        }
    }

    // 5.3 คืนค่าตารางและข้อมูลจำลองเฉพาะระบบ (Data Preservation)
    // - บัญชี admin_test สำหรับล็อกอินทดสอบ
    $checkAdmin = $pdo->query("SELECT COUNT(*) FROM useraccount WHERE useraccountID = 'admin_test'")->fetchColumn();
    if ($checkAdmin == 0) {
        $hashedPassword = password_hash('123456', PASSWORD_DEFAULT);
        $stmtAdmin = $pdo->prepare("
            INSERT INTO useraccount (useraccountID, useraccountName, userPassword, userActive, useraccountType, userRank, userDepartment, userEmail)
            VALUES ('admin_test', 'ผู้ดูแลระบบ จำลอง', :pass, '1', 'UT00000001', 'หัวหน้างาน', 'ฝ่ายบริหาร', 'admin_test@example.com')
        ");
        $stmtAdmin->execute([':pass' => $hashedPassword]);
        logMsg("👤 สร้าง/กู้คืนบัญชีผู้ดูแลระบบจำลอง: admin_test (รหัสผ่าน: 123456)");
    }

    // - คืนค่า borrow_back หากใน daam.sql ไม่มีแถวข้อมูล
    if (empty($insertedCounts['borrow_back']) && !empty($preservedData['borrow_back'])) {
        $bCols = array_keys($preservedData['borrow_back'][0]);
        $colList = implode(', ', array_map(function($c) { return "`{$c}`"; }, $bCols));
        $placeholders = implode(', ', array_fill(0, count($bCols), '?'));
        $stmtB = $pdo->prepare("INSERT OR REPLACE INTO borrow_back ({$colList}) VALUES ({$placeholders})");

        foreach ($preservedData['borrow_back'] as $row) {
            $stmtB->execute(array_values($row));
        }
        $insertedCounts['borrow_back'] = count($preservedData['borrow_back']);
        logMsg("📋 กู้คืนข้อมูลการยืม-คืนเดิม: {$insertedCounts['borrow_back']} รายการ");
    }

    // - คืนค่า system_settings
    if (!empty($preservedData['system_settings'])) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                smtp_host TEXT NOT NULL DEFAULT 'smtp.gmail.com',
                smtp_port INTEGER NOT NULL DEFAULT 587,
                smtp_user TEXT DEFAULT '',
                smtp_pass TEXT DEFAULT '',
                sender_name TEXT DEFAULT 'ระบบบริหารจัดการครุภัณฑ์',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                oauth_client_id TEXT,
                oauth_client_secret TEXT,
                oauth_refresh_token TEXT,
                is_active INTEGER NOT NULL DEFAULT 1
            );
        ");
        $sCols = array_keys($preservedData['system_settings'][0]);
        $colList = implode(', ', array_map(function($c) { return "`{$c}`"; }, $sCols));
        $placeholders = implode(', ', array_fill(0, count($sCols), '?'));
        $stmtS = $pdo->prepare("INSERT OR REPLACE INTO system_settings ({$colList}) VALUES ({$placeholders})");
        foreach ($preservedData['system_settings'] as $row) {
            $stmtS->execute(array_values($row));
        }
    }

    // - คืนค่า borrowing
    if (!empty($preservedData['borrowing'])) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS borrowing (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                IDborrow_back VARCHAR(50),
                item_name VARCHAR(255),
                borrower_name VARCHAR(100),
                userEmail TEXT,
                borrow_date DATE,
                due_date DATE,
                status TEXT DEFAULT 'borrowed',
                last_reminded_at DATETIME
            );
        ");
        $bwCols = array_keys($preservedData['borrowing'][0]);
        $colList = implode(', ', array_map(function($c) { return "`{$c}`"; }, $bwCols));
        $placeholders = implode(', ', array_fill(0, count($bwCols), '?'));
        $stmtBw = $pdo->prepare("INSERT OR REPLACE INTO borrowing ({$colList}) VALUES ({$placeholders})");
        foreach ($preservedData['borrowing'] as $row) {
            $stmtBw->execute(array_values($row));
        }
    }

    // 5.4 บันทึก Metadata ประวัติการซิงค์ลงใน _sync_meta
    $stmtMeta = $pdo->prepare("
        INSERT INTO _sync_meta (
            source_file, file_hash, file_mtime, file_size, last_synced_at, 
            total_records, hidden_records, visible_records, sync_status, note
        ) VALUES (
            :file, :hash, :mtime, :size, :synced_at, 
            :total, :hidden, :visible, 'SUCCESS', :note
        )
    ");

    $nowTime = date('Y-m-d H:i:s');
    $stmtMeta->execute([
        ':file'      => 'control/daam.sql',
        ':hash'      => $fileHash,
        ':mtime'     => $fileMtime,
        ':size'      => $fileSize,
        ':synced_at' => $nowTime,
        ':total'     => $totalDA,
        ':hidden'    => $hiddenDA,
        ':visible'   => $visibleDA,
        ':note'      => $forceSync ? 'Forced synchronization' : 'Automatic file hash update'
    ]);

    // ยืนยันการทำ Transaction
    $pdo->commit();
    $pdo->exec("PRAGMA foreign_keys = ON;");

    $elapsed = round(microtime(true) - $t_start, 3);

    logMsg("------------------------------------------------------------");
    logMsg("✅ [SUCCESS] ซิงโครไนซ์ฐานข้อมูลสำเร็จใน {$elapsed} วินาที!", 'success');
    logMsg("📊 สรุปจำนวนข้อมูลที่นำเข้า (Seeding Summary):");
    foreach ($insertedCounts as $tbl => $cnt) {
        logMsg("   • ตาราง {$tbl}: " . number_format($cnt) . " แถว");
    }
    logMsg("------------------------------------------------------------");
    logMsg("🛡️ สรุปกฎการซ่อนข้อมูล (Data Masking / Soft Delete):");
    logMsg("   • ครุภัณฑ์ทั้งหมด: " . number_format($totalDA) . " รายการ");
    logMsg("   • ครุภัณฑ์อาคารเรียนรวม/CLB (ซ่อน: is_visible = 0): " . number_format($hiddenDA) . " รายการ (" . round(($hiddenDA/$totalDA)*100, 1) . "%)", 'warning');
    logMsg("   • ครุภัณฑ์ใช้งานปกติ (แสดง: is_visible = 1): " . number_format($visibleDA) . " รายการ (" . round(($visibleDA/$totalDA)*100, 1) . "%)", 'success');
    logMsg("============================================================");

    if (!$isCli) {
        echo json_encode([
            'status'          => 'success',
            'message'         => 'ซิงโครไนซ์ฐานข้อมูลและตั้งค่า Data Masking เรียบร้อยแล้ว',
            'elapsed_seconds' => $elapsed,
            'summary'         => [
                'total_records'   => $totalDA,
                'hidden_records'  => $hiddenDA,
                'visible_records' => $visibleDA,
                'tables'          => $insertedCounts
            ]
        ]);
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logMsg("❌ เกิดข้อผิดพลาดร้ายแรงระหว่างการซิงค์: " . $e->getMessage() . " at line " . $e->getLine(), 'error');

    if (!$isCli) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'เกิดข้อผิดพลาดในการซิงโครไนซ์: ' . $e->getMessage()
        ]);
    }
    exit(1);
}
