<?php
// migrate_data.php
// สคริปต์สำหรับดึงข้อมูลจาก MySQL (Production) มาใส่ใน SQLite (Development)

// 1. ตั้งค่าการเชื่อมต่อ MySQL (ต้นทาง - อ่านข้อมูล)
$mysql_host = "192.168.15.201";
$mysql_user = "tiw_dev";
$mysql_pass = "StrongPassword123!";
$mysql_db   = "daam";

// 2. ตั้งค่าการเชื่อมต่อ SQLite (ปลายทาง - เขียนข้อมูล)
// ชี้ไปที่ไฟล์ฐานข้อมูล SQLite ของระบบพัฒนา (ตรวจสอบ Path ให้ถูกต้อง)
$sqlite_file = __DIR__ . '/control/daam_dev.sqlite';

try {
    // สร้างการเชื่อมต่อ MySQL
    $pdo_mysql = new PDO("mysql:host=$mysql_host;dbname=$mysql_db;charset=utf8", $mysql_user, $mysql_pass);
    $pdo_mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo_mysql->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // สร้างการเชื่อมต่อ SQLite
    $pdo_sqlite = new PDO("sqlite:" . $sqlite_file);
    $pdo_sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ปิด Foreign Key ชั่วคราว (ถ้ามี) สำหรับ SQLite เพื่อให้การ Insert ไม่ติดข้อจำกัดความสัมพันธ์ระหว่างตาราง
    $pdo_sqlite->exec("PRAGMA foreign_keys = OFF;");

    // รายชื่อตารางทั้งหมดที่ต้องการคัดลอกข้อมูล
    $tables = [
        'useraccount',
        'status_active',
        'classification',
        'building',
        'building_classroom',
        'durable_articles',
        'borrow_back',
        'repair'
    ];

    echo "<div style='font-family: Arial, sans-serif; padding: 20px;'>";
    echo "<h2>เริ่มกระบวนการคัดลอกข้อมูล (Data Migration)</h2>";
    echo "<ul>";

    // วนลูปจัดการทีละตาราง
    foreach ($tables as $table) {
        echo "<li>กำลังจัดการตาราง <strong>{$table}</strong>... ";
        
        // ดึงชื่อคอลัมน์จาก MySQL (รองรับกรณีตารางไม่มีข้อมูล)
        $stmt_cols = $pdo_mysql->query("SHOW COLUMNS FROM `{$table}`");
        $cols_info = $stmt_cols->fetchAll();
        $columns = [];
        foreach ($cols_info as $col) {
            $columns[] = $col['Field'];
        }
        
        // ลบตารางเก่าทิ้งและสร้างใหม่แบบ Dynamic เพื่อให้คอลัมน์ตรงกับ MySQL 100%
        $pdo_sqlite->exec("DROP TABLE IF EXISTS `{$table}`");
        
        $createCols = [];
        foreach ($columns as $col) {
            $createCols[] = "`{$col}` VARCHAR";
        }
        $createTableSQL = "CREATE TABLE `{$table}` (" . implode(", ", $createCols) . ")";
        $pdo_sqlite->exec($createTableSQL);
        
        // ดึงข้อมูลทั้งหมดจาก MySQL
        $stmt_mysql = $pdo_mysql->query("SELECT * FROM `{$table}`");
        $rows = $stmt_mysql->fetchAll();
        
        if (count($rows) > 0) {
            // นำชื่อคอลัมน์มาใส่ Backtick สำหรับคำสั่ง INSERT
            $quotedCols = array_map(function($c) { return "`{$c}`"; }, $columns);
            $colNames = implode(", ", $quotedCols);
            
            // สร้าง Parameter ป้องกัน SQL Injection (เช่น :col1, :col2)
            $placeholders = [];
            foreach ($columns as $col) {
                $placeholders[] = ":" . $col;
            }
            $placeholdersStr = implode(", ", $placeholders);
            
            // เตรียมคำสั่ง INSERT
            $insertQuery = "INSERT INTO `{$table}` ({$colNames}) VALUES ({$placeholdersStr})";
            $stmt_sqlite = $pdo_sqlite->prepare($insertQuery);
            
            // เริ่ม Transaction ใน SQLite (การทำ Transaction จะช่วยให้ INSERT ข้อมูลหลายพันบรรทัดเสร็จในเสี้ยววินาที)
            $pdo_sqlite->beginTransaction();
            
            $insertCount = 0;
            // 3. วนลูปนำข้อมูลแต่ละแถวจาก MySQL มา Insert ลง SQLite
            foreach ($rows as $row) {
                foreach ($columns as $col) {
                    // กำหนดค่าให้แต่ละ Parameter (ถ้าข้อมูลเป็น null ใน MySQL ก็จะเป็น null ใน SQLite ด้วย)
                    $stmt_sqlite->bindValue(":$col", $row[$col]);
                }
                $stmt_sqlite->execute();
                $insertCount++;
            }
            
            // ยืนยันการบันทึกข้อมูล (Commit) เมื่อวนลูปเสร็จ
            $pdo_sqlite->commit();
            
            echo "<span style='color:green;'>คัดลอกสำเร็จ {$insertCount} รายการ</span></li>";
        } else {
            echo "<span style='color:orange;'>ไม่พบข้อมูลในตารางนี้</span></li>";
        }
    }

    // เปิด Foreign Key กลับมาใช้งานตามปกติ
    $pdo_sqlite->exec("PRAGMA foreign_keys = ON;");
    
    echo "</ul>";
    echo "<h3 style='color:blue;'>✅ กระบวนการ Data Migration เสร็จสมบูรณ์!</h3>";
    echo "<p><a href='admin/manage_data.php' style='padding: 10px 15px; background: #0d6efd; color: white; text-decoration: none; border-radius: 5px;'>คลิกที่นี่เพื่อไปดูผลลัพธ์ในหน้าจัดการข้อมูล</a></p>";
    echo "</div>";

} catch (PDOException $e) {
    // หากเกิด Error ระบบจะแจ้งเตือน
    echo "<div style='font-family: Arial, sans-serif; padding: 20px;'>";
    echo "<h3 style='color:red;'>❌ เกิดข้อผิดพลาดระหว่างการเชื่อมต่อหรือคัดลอกข้อมูล</h3>";
    echo "<p>รายละเอียด Error: " . $e->getMessage() . "</p>";
    echo "</div>";
    
    // หากเกิด Error ระหว่างทำ Transaction ให้ยกเลิกการกระทำทั้งหมด (Rollback) เพื่อป้องกันข้อมูลเข้าไม่ครบ
    if (isset($pdo_sqlite) && $pdo_sqlite->inTransaction()) {
        $pdo_sqlite->rollBack();
    }
}
?>
