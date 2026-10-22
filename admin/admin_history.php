<?php
/**
 * admin/admin_history.php - หน้าจัดการและตรวจสอบประวัติการยืม-คืน พร้อมระบบส่งอีเมลทวงถามครุภัณฑ์เกินกำหนด
 * ปรับปรุงโครงสร้าง Layout ตามมาตรฐาน Modular Component (admin_header.php, admin_sidebar.php, admin_navbar.php, admin_footer.php)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------------
// 1. ตรวจสอบ Security Check สิทธิ์การเข้าถึง (Admin เท่านั้น)
// -----------------------------------------------------------------------------
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1) {
    header("Location: ../index.php");
    exit();
}

// -----------------------------------------------------------------------------
// 2. นำเข้าไฟล์เชื่อมต่อฐานข้อมูล PDO
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../db.php';

// ดึง Session Flash Messages
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
$alertMsg     = $_SESSION['alert_msg'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['alert_msg']);

$today = date('Y-m-d');

// -----------------------------------------------------------------------------
// 3. สิทธิ์การเข้าถึงข้อมูล (Authorization Rules)
// -----------------------------------------------------------------------------
$useraccountName = $_SESSION['Sename'] ?? 'ผู้ดูแลระบบ';
$currentUserID   = $_SESSION['SeUser'] ?? '';
$useraccountType = $_SESSION['useraccountType'] ?? '';

// ตรวจสอบเงื่อนไข Super Admin อย่างรัดกุม
$isSuperAdmin = ($useraccountType === 'Super Admin' 
              || strtolower($currentUserID) === 'admin' 
              || strtolower($currentUserID) === 'administrator' 
              || strtolower($_SESSION['username'] ?? '') === 'admin');

// ตัวแปร Session แผนก
$adminDept = $_SESSION['department_id'] 
          ?? $_SESSION['department_name'] 
          ?? $_SESSION['userDepartment'] 
          ?? '';

// หากไม่ใช่ Super Admin และใน Session ยังไม่มีแผนก ให้ดึงสดจาก useraccount
if (!$isSuperAdmin && empty($adminDept) && !empty($currentUserID)) {
    try {
        $stmtD = $pdo->prepare("SELECT userDepartment FROM useraccount WHERE useraccountID = :uid");
        $stmtD->execute([':uid' => $currentUserID]);
        $adminDept = $stmtD->fetchColumn() ?: '';
        $_SESSION['userDepartment'] = $adminDept;
    } catch (PDOException $e) {
        $adminDept = '';
    }
}
$currentUserDept = $adminDept;

// =============================================================================
// [ACTION] จัดการการกดปุ่ม "ส่งคืนแล้ว" (Mark as Returned) ผ่าน AJAX POST
// =============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_returned') {
    header('Content-Type: application/json; charset=UTF-8');
    
    $borrowId = trim($_POST['id'] ?? '');
    if (empty($borrowId)) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัสรายการยืม-คืน']);
        exit();
    }

    try {
        // ตรวจสอบข้อมูลรายการยืมและสิทธิ์ความเป็นเจ้าของ
        $stmtCheck = $pdo->prepare("
            SELECT b.IDborrow_back, b.DA_ID, b.Date_back, da.owner_department, da.created_by, da.DA_ListName
            FROM borrow_back b
            LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
            WHERE b.IDborrow_back = :id
            LIMIT 1
        ");
        $stmtCheck->execute([':id' => $borrowId]);
        $record = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลรายการยืมนี้ในระบบ']);
            exit();
        }

        // ตรวจสอบว่าเคยคืนไปแล้วหรือไม่
        if (!empty($record['Date_back'])) {
            echo json_encode(['status' => 'warning', 'message' => 'รายการนี้ได้รับการบันทึกส่งคืนแล้ว']);
            exit();
        }

        // ตรวจสอบสิทธิ์: ถ้าไม่ใช่ Super Admin ต้องเป็นเจ้าของครุภัณฑ์หรืออยู่ในแผนกตนเองเท่านั้น
        if (!$isSuperAdmin) {
            $isOwner = ($record['owner_department'] === $currentUserDept) 
                    || (empty($record['owner_department']) && $record['created_by'] === $currentUserID);
            if (!$isOwner) {
                echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์จัดการส่งคืนครุภัณฑ์ของแผนกอื่น']);
                exit();
            }
        }

        $nowTimestamp = date('Y-m-d H:i:s');
        $returnDate   = date('Y-m-d');
        $adminName    = $_SESSION['Sename'] ?? 'ผู้ดูแลระบบ';

        // อัปเดตสถานะในตาราง borrow_back เป็นคืนแล้ว
        $stmtUpdateB = $pdo->prepare("
            UPDATE borrow_back 
            SET Date_back = :return_date,
                timestamp_back = :return_time,
                Name_back = :admin_name,
                status = 'returned'
            WHERE IDborrow_back = :id
        ");
        $stmtUpdateB->execute([
            ':return_date' => $returnDate,
            ':return_time' => $nowTimestamp,
            ':admin_name'  => $adminName,
            ':id'          => $borrowId
        ]);

        // อัปเดต borrow_status เสริม (กรณีมีคอลัมน์นี้)
        try {
            $pdo->prepare("UPDATE borrow_back SET borrow_status = 'RETURNED' WHERE IDborrow_back = :id")->execute([':id' => $borrowId]);
        } catch (Exception $e) {}

        // ปรับสถานะครุภัณฑ์ในตาราง durable_articles ให้กลับมา "พร้อมใช้งาน (s0001)"
        if (!empty($record['DA_ID'])) {
            $stmtUpdateDA = $pdo->prepare("
                UPDATE durable_articles 
                SET statusID = 's0001' 
                WHERE DA_ID = :da_id
            ");
            $stmtUpdateDA->execute([':da_id' => $record['DA_ID']]);
        }

        echo json_encode([
            'status'      => 'success', 
            'message'     => 'บันทึกการส่งคืนครุภัณฑ์เรียบร้อยแล้ว',
            'return_date' => $returnDate,
            'item_name'   => $record['DA_ListName'] ?? 'ครุภัณฑ์'
        ]);
        exit();

    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการบันทึก: ' . $e->getMessage()]);
        exit();
    }
}

// กำหนดป้ายกำกับบอกระดับสิทธิ์ (Role Badge)
if ($isSuperAdmin) {
    $roleBadge = '<span class="badge bg-danger shadow-sm px-3 py-2 fs-6"><i class="fa-solid fa-crown me-1 text-warning"></i> Super Admin (จัดการทุกแผนก)</span>';
} else {
    $deptNameDisplay = !empty($currentUserDept) ? htmlspecialchars($currentUserDept) : 'ไม่ระบุสังกัด';
    $roleBadge = '<span class="badge bg-info text-dark shadow-sm px-3 py-2 fs-6"><i class="fa-solid fa-building me-1 text-primary"></i> แอดมินแผนก: ' . $deptNameDisplay . '</span>';
}

// ตรวจสอบสถานะการเปิดใช้งานระบบส่งอีเมล (is_active)
$isMailActive = 1;
try {
    $colCheck = $pdo->query("PRAGMA table_info(system_settings)")->fetchAll(PDO::FETCH_ASSOC);
    $hasIsActive = false;
    foreach ($colCheck as $col) {
        if ($col['name'] === 'is_active') {
            $hasIsActive = true;
            break;
        }
    }
    if ($hasIsActive) {
        $valActive = $pdo->query("SELECT is_active FROM system_settings WHERE id = 1 LIMIT 1")->fetchColumn();
        if ($valActive !== false && $valActive !== null) {
            $isMailActive = (int)$valActive;
        }
    }
} catch (Exception $e) {}

// -----------------------------------------------------------------------------
// 4. ดึงข้อมูลรายการยืม-คืนตามสิทธิ์การเข้าถึงข้อมูล
// -----------------------------------------------------------------------------
try {
    $sql = "
        SELECT 
            b.IDborrow_back AS id,
            b.DA_ID,
            COALESCE(da.DA_ListName, b.Detail_borrow, 'ครุภัณฑ์ไม่ระบุชื่อ') AS item_name,
            da.DA_Number AS asset_number,
            da.DA_Brand AS brand,
            da.owner_department,
            da.is_visible,
            COALESCE(b.Name_borrow, 'ผู้ใช้งาน') AS borrower_name,
            COALESCE(b.userEmail, u.userEmail, '') AS userEmail,
            b.Date_borrow AS borrow_date,
            COALESCE(b.due_date, b.Deadline_borrow) AS due_date,
            COALESCE(b.status, CASE WHEN b.Date_back IS NOT NULL AND b.Date_back != '' THEN 'returned' ELSE 'borrowed' END) AS status,
            b.last_reminded_at,
            b.Date_back
        FROM borrow_back b
        LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
        LEFT JOIN useraccount u ON b.useraccountID_borrow = u.useraccountID
    ";

    $params = [];

    // ถ้าไม่ใช่ Super Admin ให้กรองเฉพาะครุภัณฑ์ที่เป็นของแผนกตนเอง
    if (!$isSuperAdmin) {
        $sql .= " WHERE (da.owner_department = :dept OR (da.owner_department IS NULL AND da.created_by = :uid))";
        $params[':dept'] = $currentUserDept;
        $params[':uid']  = $currentUserID;
    }

    $sql .= " ORDER BY 
                CASE WHEN (b.Date_back IS NULL OR b.Date_back = '') AND COALESCE(b.due_date, b.Deadline_borrow) < '{$today}' THEN 0 ELSE 1 END,
                COALESCE(b.due_date, b.Deadline_borrow) ASC,
                b.IDborrow_back DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $borrowings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $borrowings = [];
    $fetchError = $e->getMessage();
}

// -----------------------------------------------------------------------------
// 5. สรุปยอดสถิติ (KPIs)
// -----------------------------------------------------------------------------
$totalCount         = count($borrowings);
$borrowedCount      = 0;
$overdueCount       = 0;
$returnedCount      = 0;
$remindedTodayCount = 0;

foreach ($borrowings as $item) {
    $isItemBorrowed = ($item['status'] === 'borrowed');
    $isItemReturned = ($item['status'] === 'returned');
    $itemDueDate    = $item['due_date'];

    if ($isItemReturned) {
        $returnedCount++;
    } elseif ($isItemBorrowed) {
        $borrowedCount++;
        if (!empty($itemDueDate) && $itemDueDate < $today) {
            $overdueCount++;
        }
    }

    if (!empty($item['last_reminded_at']) && date('Y-m-d', strtotime($item['last_reminded_at'])) === $today) {
        $remindedTodayCount++;
    }
}

// โหลดโครงสร้าง Layout หลัก (admin_header.php, admin_sidebar.php, admin_navbar.php)
$pageTitle = "ระบบแจ้งเตือนคืนครุภัณฑ์ (Overdue Reminder)";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>

<style>
    /* สไตล์ชีตเฉพาะหน้า admin_history.php */
    .card { 
        border: none;
        border-radius: 12px; 
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }
    .card-header {
        border-top-left-radius: 12px !important;
        border-top-right-radius: 12px !important;
    }
    .table th { 
        font-weight: 600; 
        font-size: 0.9rem; 
        background-color: #f8f9fa; 
    }
    .table td { 
        font-size: 0.9rem; 
        vertical-align: middle; 
    }
    .overdue-row {
        background-color: #fff5f5 !important;
    }
    .badge-overdue {
        background-color: #fee2e2;
        color: #b91c1c;
        border: 1px solid #fca5a5;
        font-weight: 700;
    }
    .badge-borrowed {
        background-color: #e0f2fe;
        color: #0369a1;
        border: 1px solid #bae6fd;
        font-weight: 600;
    }
    .badge-returned {
        background-color: #dcfce7;
        color: #15803d;
        border: 1px solid #bbf7d0;
        font-weight: 600;
    }

    /* ตกแต่ง Modal รูปแบบกระดาษจดหมายราชการ (Official Letter Paper Design) */
    .letter-paper {
        background-color: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
        padding: 2.25rem 2.5rem;
        position: relative;
    }
    .letter-paper::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0; height: 6px;
        background: linear-gradient(90deg, #dc2626 0%, #ef4444 50%, #f97316 100%);
        border-top-left-radius: 12px;
        border-top-right-radius: 12px;
    }
    .letter-header {
        border-bottom: 2px dashed #cbd5e1;
        padding-bottom: 1.25rem;
        margin-bottom: 1.5rem;
    }
    .letter-table {
        background-color: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        overflow: hidden;
    }
    .letter-table td {
        padding: 10px 16px;
        border-bottom: 1px solid #e2e8f0;
    }
    .letter-table tr:last-child td {
        border-bottom: none;
    }
</style>

<!-- Main Container -->
<div class="container-fluid px-0 mb-4">

    <!-- Flash Message แจ้งเตือนผลลัพธ์ -->
    <?php if (!empty($flashSuccess)): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 d-flex align-items-center mb-4" role="alert">
            <i class="fa-solid fa-circle-check fs-4 me-3 text-success"></i>
            <div class="flex-grow-1">
                <strong>สำเร็จ!</strong> <?php echo $flashSuccess; ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($flashError)): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 d-flex align-items-center mb-4" role="alert">
            <i class="fa-solid fa-triangle-exclamation fs-4 me-3 text-danger"></i>
            <div class="flex-grow-1">
                <strong>ข้อผิดพลาด!</strong> <?php echo $flashError; ?>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($isMailActive === 0): ?>
        <div class="alert alert-secondary border border-secondary border-opacity-25 shadow-sm d-flex flex-wrap align-items-center justify-content-between mb-4 rounded-3 p-3">
            <div class="d-flex align-items-center mb-2 mb-md-0">
                <i class="fa-solid fa-bell-slash text-danger fs-4 me-3"></i>
                <div>
                    <strong class="text-dark">ระบบส่งอีเมลแจ้งเตือนถูก "ปิดใช้งานชั่วคราว":</strong>
                    <div class="text-muted small">ผู้ดูแลระบบได้ปิดใช้งานระบบส่งอีเมลไว้ การกดส่งแจ้งเตือนจะไม่ทำงานจนกว่าจะเปิดใช้งานอีกครั้ง</div>
                </div>
            </div>
            <a href="settings_mail.php" class="btn btn-outline-danger btn-sm rounded-pill px-3 shadow-sm fw-bold">
                <i class="fa-solid fa-envelope-gear me-1"></i> ไปหน้าตั้งค่าอีเมล
            </a>
        </div>
    <?php endif; ?>

    <!-- ส่วนหัวหน้าจอ (Header Section with Role Badge & Action Buttons) -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-clock-rotate-left text-danger me-2"></i>ระบบแจ้งเตือนคืนครุภัณฑ์ (Overdue Reminder)
            </h4>
            <p class="text-muted small mb-0">
                ตรวจสอบรายการยืมครุภัณฑ์ คำนวณวันเกินกำหนด และส่งอีเมลแจ้งเตือนผู้ยืมผ่าน PHPMailer (Gmail SMTP)
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php echo $roleBadge; ?>
            <button type="button" class="btn btn-outline-dark btn-sm rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#cronModal">
                <i class="fa-solid fa-terminal me-1"></i> คำสั่ง Cron Job
            </button>
        </div>
    </div>

    <!-- สรุปสถิติ Summary Cards (KPIs) 4 กล่อง -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 h-100 bg-white shadow-sm border-0">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">รายการยืมทั้งหมด</div>
                        <h2 class="fw-bold mb-0 text-dark"><?php echo number_format($totalCount); ?></h2>
                    </div>
                    <div class="p-3 bg-secondary-subtle text-secondary rounded-circle">
                        <i class="fa-solid fa-boxes-stacked fa-xl"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 h-100 bg-white shadow-sm border-0">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">กำลังยืม (ยังไม่คืน)</div>
                        <h2 class="fw-bold mb-0 text-primary"><?php echo number_format($borrowedCount); ?></h2>
                    </div>
                    <div class="p-3 bg-primary-subtle text-primary rounded-circle">
                        <i class="fa-solid fa-hand-holding-hand fa-xl"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 h-100 bg-white shadow-sm border-0 border-start border-danger border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-danger small fw-bold">⚠️ เลยกำหนดส่งคืน (Overdue)</div>
                        <h2 class="fw-bold mb-0 text-danger"><?php echo number_format($overdueCount); ?></h2>
                    </div>
                    <div class="p-3 bg-danger-subtle text-danger rounded-circle">
                        <i class="fa-solid fa-triangle-exclamation fa-xl"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 h-100 bg-white shadow-sm border-0 border-start border-success border-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">ส่งแจ้งเตือนแล้ววันนี้</div>
                        <h2 class="fw-bold mb-0 text-success" id="kpi-reminded-count"><?php echo number_format($remindedTodayCount); ?></h2>
                    </div>
                    <div class="p-3 bg-success-subtle text-success rounded-circle">
                        <i class="fa-solid fa-envelope-circle-check fa-xl"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- การ์ดครอบตารางข้อมูล (Main Table Card) -->
    <div class="card shadow border-0">
        <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="fw-bold text-dark mb-0">
                <i class="fa-solid fa-table-list text-primary me-2"></i>ตารางประวัติการยืม-คืนครุภัณฑ์
            </h5>
            <!-- ปุ่มคัดกรองด่วน Quick Filters -->
            <div class="btn-group btn-group-sm" role="group" id="filterBtnGroup">
                <button type="button" class="btn btn-outline-secondary active" data-filter="">ทั้งหมด (<?php echo $totalCount; ?>)</button>
                <button type="button" class="btn btn-outline-danger" data-filter="เลยกำหนดคืน">เลยกำหนดคืน (<?php echo $overdueCount; ?>)</button>
                <button type="button" class="btn btn-outline-primary" data-filter="กำลังยืม">กำลังยืม (<?php echo $borrowedCount; ?>)</button>
                <button type="button" class="btn btn-outline-success" data-filter="คืนแล้ว">คืนแล้ว (<?php echo $returnedCount; ?>)</button>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle w-100" id="historyTable">
                    <thead class="table-light text-secondary">
                        <tr>
                            <th style="width: 45px;">#</th>
                            <th>รหัสรายการ</th>
                            <th>รายการครุภัณฑ์</th>
                            <th>ผู้ยืม & อีเมล</th>
                            <th>วันที่ยืม</th>
                            <th>กำหนดคืน (due_date)</th>
                            <th>สถานะ / การคำนวณวัน</th>
                            <th>แจ้งเตือนล่าสุด (last_reminded_at)</th>
                            <th class="text-center" style="min-width: 220px;">การจัดการ (Action)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $idx = 1; 
                        foreach ($borrowings as $row): 
                            $status         = $row['status'];
                            $isBorrowed     = ($status === 'borrowed');
                            $isReturned     = ($status === 'returned');
                            $dueDate        = $row['due_date'];
                            $isOverdue      = false;
                            $daysOverdue    = 0;

                            // คำนวณวันที่เลยกำหนด (Date Calculation: Current Date - due_date)
                            if (!empty($dueDate)) {
                                $dueDateObj  = new DateTime($dueDate);
                                $todayObj    = new DateTime($today);
                                if ($todayObj > $dueDateObj) {
                                    $isOverdue   = true;
                                    $diff        = $dueDateObj->diff($todayObj);
                                    $daysOverdue = (int)$diff->format('%a');
                                }
                            }

                            // Anti-Spam Logic: ตรวจสอบว่าส่งเตือนในวันนี้ไปแล้วหรือไม่
                            $remindedToday = false;
                            if (!empty($row['last_reminded_at'])) {
                                $lastDate = date('Y-m-d', strtotime($row['last_reminded_at']));
                                if ($lastDate === $today) {
                                    $remindedToday = true;
                                }
                            }

                            // ไฮไลท์แถวสีแดงอ่อนสำหรับรายการที่เลยกำหนด
                            $rowClass = ($isBorrowed && $isOverdue) ? 'overdue-row' : '';
                        ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td><?php echo $idx++; ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border font-monospace">
                                        <?php echo htmlspecialchars($row['id']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark">
                                        <?php echo htmlspecialchars($row['item_name']); ?>
                                        <?php if (isset($row['is_visible']) && $row['is_visible'] == 0): ?>
                                            <span class="badge bg-secondary-subtle text-secondary border ms-1" style="font-size: 0.72rem;" title="ครุภัณฑ์อาคารเรียนรวม (CLB) ถูกซ่อนไว้"><i class="fa-solid fa-eye-slash me-1"></i>ซ่อน (CLB)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-1 flex-wrap mt-1">
                                        <?php if (!empty($row['asset_number'])): ?>
                                            <small class="badge bg-light text-dark border font-monospace"><i class="fa-solid fa-barcode me-1"></i><?php echo htmlspecialchars($row['asset_number']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($row['owner_department'])): ?>
                                            <small class="badge bg-info-subtle text-info-emphasis border"><i class="fa-solid fa-building me-1"></i><?php echo htmlspecialchars($row['owner_department']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-secondary">
                                        <i class="fa-solid fa-user me-1 text-muted"></i><?php echo htmlspecialchars($row['borrower_name']); ?>
                                    </div>
                                    <div class="small">
                                        <?php if (!empty($row['userEmail'])): ?>
                                            <a href="mailto:<?php echo htmlspecialchars($row['userEmail']); ?>" class="text-decoration-none text-primary">
                                                <i class="fa-regular fa-envelope me-1"></i><?php echo htmlspecialchars($row['userEmail']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-danger small"><i class="fa-solid fa-triangle-exclamation me-1"></i>ไม่มีอีเมล</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($row['borrow_date'] ?? '-'); ?></td>
                                <td>
                                    <span class="font-monospace fw-semibold <?php echo ($isBorrowed && $isOverdue) ? 'text-danger' : 'text-dark'; ?>">
                                        <?php echo htmlspecialchars($dueDate ?? '-'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isReturned): ?>
                                        <span class="badge badge-returned rounded-pill px-3 py-2">
                                            <i class="fa-solid fa-circle-check me-1"></i> คืนแล้ว
                                        </span>
                                    <?php elseif ($isBorrowed && $isOverdue): ?>
                                        <div>
                                            <span class="badge badge-overdue rounded-pill px-3 py-2 mb-1">
                                                <i class="fa-solid fa-triangle-exclamation me-1"></i> เลยกำหนดคืน
                                            </span>
                                        </div>
                                        <div class="text-danger fw-bold small">
                                            <i class="fa-solid fa-clock me-1"></i>เลยกำหนดคืน <?php echo $daysOverdue; ?> วัน
                                        </div>
                                    <?php else: ?>
                                        <span class="badge badge-borrowed rounded-pill px-3 py-2">
                                            <i class="fa-solid fa-clock me-1"></i> กำลังยืม
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td id="last-reminded-<?php echo $row['id']; ?>">
                                    <?php if (!empty($row['last_reminded_at'])): ?>
                                        <div class="small fw-semibold text-dark">
                                            <i class="fa-regular fa-clock text-secondary me-1"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($row['last_reminded_at'])); ?>
                                        </div>
                                        <?php if ($remindedToday): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle mt-1" id="badge-reminded-<?php echo $row['id']; ?>">
                                                <i class="fa-solid fa-envelope-circle-check me-1"></i> แจ้งเตือนแล้ววันนี้
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">- ยังไม่เคยส่ง -</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" id="action-cell-<?php echo $row['id']; ?>">
                                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                                        
                                        <?php if ($isBorrowed): ?>
                                            <!-- ปุ่ม: ส่งคืนแล้ว (Mark as Returned) -->
                                            <button type="button" 
                                                    class="btn btn-success btn-sm rounded-pill px-2 py-1 shadow-sm btn-mark-returned"
                                                    title="บันทึกว่าส่งคืนครุภัณฑ์นี้แล้ว"
                                                    data-id="<?php echo htmlspecialchars($row['id']); ?>"
                                                    data-item="<?php echo htmlspecialchars($row['item_name']); ?>"
                                                    data-asset="<?php echo htmlspecialchars($row['asset_number'] ?? '-'); ?>"
                                                    data-borrower="<?php echo htmlspecialchars($row['borrower_name']); ?>">
                                                <i class="fa-solid fa-rotate-left me-1"></i> ส่งคืนแล้ว
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($isBorrowed && $isOverdue): ?>
                                            <!-- ปุ่ม: ดูตัวอย่างเนื้อหาอีเมล (Email Preview Modal) -->
                                            <button type="button" 
                                                    class="btn btn-outline-primary btn-sm rounded-pill px-2 py-1 btn-preview-email"
                                                    title="ดูตัวอย่างอีเมลทวงถาม"
                                                    data-id="<?php echo htmlspecialchars($row['id']); ?>"
                                                    data-item="<?php echo htmlspecialchars($row['item_name']); ?>"
                                                    data-asset="<?php echo htmlspecialchars($row['asset_number'] ?? '-'); ?>"
                                                    data-borrower="<?php echo htmlspecialchars($row['borrower_name']); ?>"
                                                    data-email="<?php echo htmlspecialchars($row['userEmail'] ?? ''); ?>"
                                                    data-borrowdate="<?php echo htmlspecialchars($row['borrow_date'] ?? '-'); ?>"
                                                    data-due="<?php echo htmlspecialchars($dueDate ?? '-'); ?>"
                                                    data-overdue="<?php echo $daysOverdue; ?>"
                                                    data-remindedtoday="<?php echo $remindedToday ? '1' : '0'; ?>">
                                                <i class="fa-regular fa-eye me-1"></i> ดูตัวอย่าง
                                            </button>

                                            <!-- ปุ่ม: ส่งอีเมลแจ้งเตือน (Send Reminder via AJAX) -->
                                            <span id="action-wrapper-<?php echo $row['id']; ?>">
                                                <?php if ($remindedToday): ?>
                                                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-2 py-1" disabled title="Reminded Today: ส่งอีเมลเตือนไปแล้วในวันนี้">
                                                        <i class="fa-solid fa-circle-check me-1"></i> เตือนแล้ว
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" 
                                                            class="btn btn-danger btn-sm rounded-pill px-2 py-1 shadow-sm btn-send-reminder fw-semibold"
                                                            data-id="<?php echo htmlspecialchars($row['id']); ?>"
                                                            data-name="<?php echo htmlspecialchars($row['borrower_name']); ?>"
                                                            data-email="<?php echo htmlspecialchars($row['userEmail'] ?? ''); ?>"
                                                            data-item="<?php echo htmlspecialchars($row['item_name']); ?>">
                                                        <i class="fa-solid fa-paper-plane me-1"></i> ส่งเตือน
                                                    </button>
                                                <?php endif; ?>
                                            </span>
                                        <?php elseif ($isReturned): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-2">
                                                <i class="fa-solid fa-check-double me-1"></i> ส่งคืนเรียบร้อย
                                            </span>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-light btn-sm text-muted rounded-pill px-2 py-1 border" disabled>
                                                <i class="fa-solid fa-clock me-1"></i> ยังไม่เกินกำหนด
                                            </button>
                                        <?php endif; ?>

                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal สำหรับแสดงตัวอย่างเนื้อหาอีเมลทวงถาม (Email Preview Modal) -->
<div class="modal fade" id="emailPreviewModal" tabindex="-1" aria-labelledby="emailPreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white py-3">
                <h5 class="modal-title fw-bold" id="emailPreviewModalLabel">
                    <i class="fa-solid fa-envelope-open-text text-danger me-2"></i>ตัวอย่างเนื้อหาอีเมลทวงถาม (Email Preview)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <!-- จำลองกระดาษจดหมายราชการ/ทางการ (Official Letter Paper Design) -->
                <div class="letter-paper">
                    <!-- หัวจดหมายและตราสัญลักษณ์ -->
                    <div class="letter-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <div class="p-3 bg-danger-subtle text-danger rounded-circle border border-danger-subtle">
                                <i class="fa-solid fa-building-columns fa-2x"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">ระบบบริหารจัดการครุภัณฑ์ (Asset Management System)</h6>
                                <small class="text-secondary">กลุ่มงานพัสดุและบริหารสินทรัพย์ ฝ่ายบริหารทั่วไป</small>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-danger text-white rounded-pill px-3 py-2 fw-semibold">
                                <i class="fa-solid fa-bell me-1"></i> หนังสือแจ้งเตือนเกินกำหนดคืน
                            </span>
                            <div class="small text-muted mt-1">วันที่: <span id="preview-current-date"><?php echo date('d/m/Y'); ?></span></div>
                        </div>
                    </div>

                    <!-- รายละเอียดหัวเรื่องและผู้รับ -->
                    <div class="letter-table mb-4">
                        <table class="w-100 mb-0">
                            <tr>
                                <td class="text-secondary fw-semibold" style="width: 140px;">เรียน (ผู้ยืม):</td>
                                <td>
                                    <strong class="text-dark" id="preview-borrower">-</strong>
                                    <span class="text-primary small ms-2" id="preview-email-wrapper">
                                        (&lt;<span id="preview-email">-</span>&gt;)
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-secondary fw-semibold">เรื่อง (Subject):</td>
                                <td>
                                    <strong class="text-danger" id="preview-subject">แจ้งเตือน: ครุภัณฑ์เกินกำหนดส่งคืน</strong>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-secondary fw-semibold">จาก (From):</td>
                                <td class="text-muted">
                                    ระบบบริหารจัดการครุภัณฑ์ผ่าน Gmail SMTP
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- เนื้อหาจดหมาย -->
                    <div class="letter-body text-dark" style="line-height: 1.8;">
                        <p class="mb-3">
                            เรียนคุณ <strong id="preview-borrower-salute" class="text-dark">-</strong>,
                        </p>
                        <p class="text-secondary mb-3">
                            ตามที่ท่านได้ดำเนินการยืมพัสดุ/ครุภัณฑ์จากระบบบริหารจัดการครุภัณฑ์ ขณะนี้รายการดังกล่าวได้ 
                            <strong class="text-danger">ครบกำหนดระยะเวลาการส่งคืนแล้ว</strong> 
                            แต่ยังไม่ปรากฏบันทึกการส่งคืนพัสดุในระบบ โดยมีรายละเอียดรายการยืมดังต่อไปนี้:
                        </p>

                        <!-- ตารางสรุปรายละเอียดรายการที่ยืม -->
                        <div class="card border border-danger-subtle bg-danger-subtle bg-opacity-10 mb-3 p-3">
                            <div class="row g-2 small">
                                <div class="col-sm-6">
                                    <span class="text-muted">รหัสรายการยืม:</span> 
                                    <span class="font-monospace fw-bold text-dark" id="preview-id">-</span>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted">หมายเลขครุภัณฑ์:</span> 
                                    <span class="font-monospace text-dark" id="preview-asset">-</span>
                                </div>
                                <div class="col-12">
                                    <span class="text-muted">รายการครุภัณฑ์:</span> 
                                    <strong class="text-dark" id="preview-item">-</strong>
                                </div>
                                <div class="col-sm-4">
                                    <span class="text-muted">วันที่ยืม:</span> 
                                    <span class="text-dark" id="preview-borrowdate">-</span>
                                </div>
                                <div class="col-sm-4">
                                    <span class="text-muted">กำหนดส่งคืน (Due Date):</span> 
                                    <span class="text-dark fw-bold" id="preview-duedate">-</span>
                                </div>
                                <div class="col-sm-4">
                                    <span class="text-muted">สถานะเกินกำหนด:</span> 
                                    <span class="badge bg-danger rounded-pill px-2" id="preview-overdue">เกินกำหนด - วัน</span>
                                </div>
                            </div>
                        </div>

                        <p class="text-secondary mb-4">
                            เพื่อความถูกต้องเรียบร้อยและไม่ให้ส่งผลกระทบต่อการใช้งานของหน่วยงานอื่น 
                            จึงใคร่ขอความอนุเคราะห์จากท่านโปรดนำครุภัณฑ์ดังกล่าวมาส่งมอบคืน ณ <strong>งานพัสดุและบริหารสินทรัพย์</strong> หรือติดต่อเจ้าหน้าที่ผู้ดูแลระบบโดยเร็วที่สุด เพื่อดำเนินการตามระเบียบพัสดุต่อไป
                        </p>

                        <div class="d-flex justify-content-end mt-4">
                            <div class="text-center" style="min-width: 220px;">
                                <div class="text-muted mb-1">ขอแสดงความนับถือ</div>
                                <div class="fw-bold text-dark">กลุ่มงานพัสดุและบริหารสินทรัพย์</div>
                                <div class="small text-secondary">ระบบบริหารจัดการครุภัณฑ์ (Asset Management System)</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-top d-flex justify-content-between align-items-center">
                <div>
                    <span id="preview-spam-warning" class="text-warning-emphasis small d-none">
                        <i class="fa-solid fa-circle-check text-success me-1"></i> รายการนี้ได้ส่งแจ้งเตือนในวันนี้ไปแล้ว
                    </span>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">
                        <i class="fa-solid fa-xmark me-1"></i> ปิดหน้าต่าง
                    </button>
                    <button type="button" class="btn btn-danger rounded-pill px-4 shadow-sm fw-semibold" id="modal-btn-send">
                        <i class="fa-solid fa-paper-plane me-1"></i> ส่งอีเมลฉบับนี้ทันที
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal สำหรับแสดงข้อมูล Cron Job CLI -->
<div class="modal fade" id="cronModal" tabindex="-1" aria-labelledby="cronModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold" id="cronModalLabel">
                    <i class="fa-solid fa-terminal text-warning me-2"></i>Automated Cron Job (Linux Crontab)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted">
                    คุณสามารถตั้งค่าให้เซิร์ฟเวอร์รันระบบแจ้งเตือนอีเมลอัตโนมัติทุกวันในเวลา <strong>08:00 น.</strong> โดยการเพิ่มคำสั่งด้านล่างนี้ลงใน Linux Crontab:
                </p>

                <label class="form-label fw-bold">คำสั่ง Crontab (ทุกวันเวลา 08:00 AM):</label>
                <div class="input-group mb-3">
                    <input type="text" class="form-control font-monospace bg-light" readonly 
                           value="0 8 * * * /usr/bin/php <?php echo realpath(__DIR__ . '/../cron_reminder.php'); ?> >> <?php echo realpath(__DIR__ . '/..'); ?>/logs/cron_reminder.log 2>&1" id="cronCode">
                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('cronCode').value); alert('คัดลอกคำสั่งเรียบร้อยแล้ว');">
                        <i class="fa-regular fa-copy"></i> คัดลอก
                    </button>
                </div>

                <div class="alert alert-info small mb-0">
                    <i class="fa-solid fa-circle-info me-1"></i> <strong>คำอธิบายพารามิเตอร์:</strong><br>
                    • <code>0 8 * * *</code>: กำหนดให้สคริปต์ทำงานทุกวันเวลา 08:00 น.<br>
                    • <code>cron_reminder.php</code>: สคริปต์ประมวลผลการส่งอีเมลสำหรับรายการที่เลยกำหนดคืนอัตโนมัติ<br>
                    • <code>logs/cron_reminder.log</code>: บันทึก Summary Log ประวัติการทำงานของ cron
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">ปิดหน้าต่าง</button>
            </div>
        </div>
    </div>
</div>

<!-- Custom Scripts สำหรับ DataTable, Modal และ AJAX Requests -->
<script>
$(document).ready(function() {
    // 1. กำหนดค่าเริ่มต้นให้กับ DataTables
    const table = $('#historyTable').DataTable({
        pageLength: 10,
        order: [], // เรียงลำดับตามที่ query มา (รายการเลยกำหนดขึ้นก่อน)
        responsive: true
    });

    // 2. คัดกรองตารางด้วยปุ่ม Quick Filter
    $('#filterBtnGroup button').on('click', function() {
        $('#filterBtnGroup button').removeClass('active');
        $(this).addClass('active');

        const filterVal = $(this).data('filter');
        // ค้นหาในคอลัมน์สถานะ (index 6)
        table.column(6).search(filterVal).draw();
    });

    // 3. ฟังก์ชันกลางสำหรับส่งอีเมลทวงถามผ่าน AJAX พร้อม SweetAlert2
    function executeSendReminder(itemId, borrowerEmail, borrowerName, itemName) {
        const isMailActive = <?php echo json_encode((int)$isMailActive); ?>;
        if (isMailActive === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'ระบบส่งอีเมลถูกปิดใช้งาน',
                html: 'ขณะนี้ระบบส่งอีเมลแจ้งเตือนถูก <strong>"ปิดใช้งานชั่วคราว"</strong> โดยผู้ดูแลระบบ<br><small class="text-muted">กรุณาไปเปิดใช้งานที่เมนู "ตั้งค่าอีเมล" ก่อนดำเนินการ</small>',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#64748b',
                confirmButtonText: '<i class="fa-solid fa-envelope-gear me-1"></i> ไปหน้าตั้งค่าอีเมล',
                cancelButtonText: 'ปิด'
            }).then((res) => {
                if (res.isConfirmed) {
                    window.location.href = 'settings_mail.php';
                }
            });
            return;
        }

        if (!borrowerEmail) {
            Swal.fire({
                icon: 'warning',
                title: 'ไม่พบอีเมลผู้ยืม',
                text: 'รายการนี้ไม่มีข้อมูลอีเมลผู้ยืมในระบบ ไม่สามารถส่งแจ้งเตือนได้',
                confirmButtonColor: '#64748b'
            });
            return;
        }

        Swal.fire({
            title: 'ยืนยันการส่งอีเมลเตือน?',
            html: `ระบบจะดำเนินการส่งอีเมลทวงถามผ่าน Gmail SMTP ไปยัง:<br><strong class="text-primary fs-6">${borrowerEmail}</strong><br><small class="text-muted">(ผู้ยืม: ${borrowerName} | ครุภัณฑ์: ${itemName})</small>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: '<i class="fa-solid fa-paper-plane me-1"></i> ส่งอีเมลทันที',
            cancelButtonText: 'ยกเลิก',
            reverseButtons: true,
            focusCancel: true
        }).then((result) => {
            if (!result.isConfirmed) return;

            // แสดงสถานะ Loading ระหว่างส่งอีเมล
            Swal.fire({
                title: 'กำลังส่งอีเมลผ่าน Gmail SMTP...',
                html: 'กรุณารอสักครู่ ระบบกำลังเชื่อมต่อ Gmail Server เพื่อส่งอีเมล',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            // ยิงคำสั่ง AJAX แบบ HTTP POST ไปยัง backend manual_remind.php
            $.ajax({
                url: 'manual_remind.php',
                type: 'POST',
                data: {
                    id: itemId,
                    ajax: 1
                },
                dataType: 'json',
                success: function(res) {
                    if (res && res.status === 'success') {
                        // แจ้งเตือนสำเร็จด้วย SweetAlert2
                        Swal.fire({
                            icon: 'success',
                            title: 'สำเร็จ!',
                            html: `ส่งอีเมลแจ้งเตือนไปยัง <strong>${res.user_email || borrowerEmail}</strong> ผ่าน Gmail SMTP เรียบร้อยแล้ว`,
                            confirmButtonColor: '#16a34a',
                            confirmButtonText: 'ตกลง'
                        });

                        // ปิดหน้าต่าง Modal ดูตัวอย่างหากกำลังเปิดอยู่
                        const previewModalEl = document.getElementById('emailPreviewModal');
                        const previewModalInstance = bootstrap.Modal.getInstance(previewModalEl);
                        if (previewModalInstance) {
                            previewModalInstance.hide();
                        }

                        // ปรับปรุง UI แถวในตารางทันที (Dynamic In-Place Update)
                        const actionContainer = $(`#action-wrapper-${itemId}`);
                        if (actionContainer.length) {
                            actionContainer.html(`
                                <button type="button" class="btn btn-secondary btn-sm rounded-pill px-3 py-1" disabled title="Reminded Today: ส่งอีเมลเตือนไปแล้วในวันนี้">
                                    <i class="fa-solid fa-circle-check me-1"></i> Reminded Today
                                </button>
                            `);
                        }

                        // อัปเดตคอลัมน์ "แจ้งเตือนล่าสุด" ในแถวนั้น
                        const lastRemindedCell = $(`#last-reminded-${itemId}`);
                        if (lastRemindedCell.length) {
                            const now = new Date();
                            const day = String(now.getDate()).padStart(2, '0');
                            const month = String(now.getMonth() + 1).padStart(2, '0');
                            const year = now.getFullYear();
                            const hour = String(now.getHours()).padStart(2, '0');
                            const min = String(now.getMinutes()).padStart(2, '0');
                            const formattedNow = `${day}/${month}/${year} ${hour}:${min}`;

                            lastRemindedCell.html(`
                                <div class="small fw-semibold text-dark">
                                    <i class="fa-regular fa-clock text-secondary me-1"></i> ${formattedNow}
                                </div>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle mt-1" id="badge-reminded-${itemId}">
                                    <i class="fa-solid fa-envelope-circle-check me-1"></i> แจ้งเตือนแล้ววันนี้
                                </span>
                            `);
                        }

                        // อัปเดตตัวเลข KPI กล่องที่ 4 (ส่งแจ้งเตือนแล้ววันนี้)
                        const kpiEl = $('#kpi-reminded-count');
                        if (kpiEl.length) {
                            let curCount = parseInt(kpiEl.text().replace(/,/g, ''), 10) || 0;
                            kpiEl.text((curCount + 1).toLocaleString());
                        }

                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'ไม่สามารถส่งอีเมลได้',
                            text: res.message || 'เกิดข้อผิดพลาดในการเชื่อมต่อกับ Gmail SMTP Server',
                            confirmButtonColor: '#dc2626'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX Error:", xhr.responseText);
                    let errText = 'ไม่สามารถติดต่อเซิร์ฟเวอร์ได้ กรุณาลองใหม่อีกครั้ง';
                    let isDebugLog = false;
                    try {
                        const parsed = JSON.parse(xhr.responseText);
                        if (parsed && parsed.message) {
                            errText = parsed.message;
                        }
                    } catch(e) {
                        if (xhr.responseText && xhr.responseText.trim().length > 0) {
                            const escapedText = $('<div>').text(xhr.responseText).html();
                            errText = `<div style="text-align:left; background:#0f172a; color:#38bdf8; padding:12px; border-radius:8px; font-family:monospace; font-size:12px; max-height:280px; overflow-y:auto; white-space:pre-wrap;">${escapedText}</div>`;
                            isDebugLog = true;
                        }
                    }
                    Swal.fire({
                        icon: isDebugLog ? 'info' : 'error',
                        title: isDebugLog ? 'ผลการตรวจสอบ SMTP Debug (Log)' : 'ข้อผิดพลาดเครือข่าย',
                        html: isDebugLog ? `<p class="mb-2 text-muted small text-start">ระบบแสดงข้อความการสื่อสารระหว่าง localhost กับ Gmail Server:</p>${errText}` : errText,
                        confirmButtonColor: '#dc2626',
                        width: isDebugLog ? '720px' : '500px'
                    });
                }
            });
        });
    }

    // 4. จัดการคลิกปุ่ม "ดูตัวอย่าง" (Email Preview) ด้วย jQuery Event Delegation
    $(document).on('click', '.btn-preview-email', function() {
        const btn = $(this);
        const itemId        = btn.data('id');
        const itemName      = btn.data('item');
        const assetNumber   = btn.data('asset');
        const borrowerName  = btn.data('borrower');
        const borrowerEmail = btn.data('email');
        const borrowDate    = btn.data('borrowdate');
        const dueDate       = btn.data('due');
        const daysOverdue   = btn.data('overdue');
        const remindedToday = String(btn.attr('data-remindedtoday') || btn.data('remindedtoday')) === '1';

        // กรอกข้อมูลลงใน Modal จดหมาย
        $('#preview-id').text(itemId);
        $('#preview-item').text(itemName);
        $('#preview-asset').text(assetNumber || '-');
        $('#preview-borrower').text(borrowerName);
        $('#preview-borrower-salute').text(borrowerName);
        $('#preview-borrowdate').text(borrowDate);
        $('#preview-duedate').text(dueDate);
        $('#preview-overdue').text(`เกินกำหนด ${daysOverdue} วัน`);

        if (borrowerEmail) {
            $('#preview-email').text(borrowerEmail);
            $('#preview-email-wrapper').removeClass('d-none');
        } else {
            $('#preview-email').text('ไม่มีอีเมลในระบบ');
            $('#preview-email-wrapper').addClass('d-none');
        }

        // สถานะแจ้งเตือนแล้ววันนี้
        if (remindedToday) {
            $('#preview-spam-warning').removeClass('d-none');
            $('#modal-btn-send').removeClass('btn-danger').addClass('btn-secondary').html('<i class="fa-solid fa-circle-check me-1"></i> ส่งเตือนแล้วในวันนี้');
        } else {
            $('#preview-spam-warning').addClass('d-none');
            $('#modal-btn-send').removeClass('btn-secondary').addClass('btn-danger').html('<i class="fa-solid fa-paper-plane me-1"></i> ส่งอีเมลฉบับนี้ทันที');
        }

        // แนบ Data attributes ไปที่ปุ่มส่งใน Modal
        $('#modal-btn-send').data({
            id: itemId,
            email: borrowerEmail,
            name: borrowerName,
            item: itemName
        });

        // เปิด Modal
        const modal = new bootstrap.Modal(document.getElementById('emailPreviewModal'));
        modal.show();
    });

    // 5. จัดการคลิกปุ่มส่งจากภายใน Modal
    $('#modal-btn-send').on('click', function() {
        const btn = $(this);
        const itemId        = btn.data('id');
        const borrowerEmail = btn.data('email');
        const borrowerName  = btn.data('name');
        const itemName      = btn.data('item');

        executeSendReminder(itemId, borrowerEmail, borrowerName, itemName);
    });

    // 6. จัดการคลิกปุ่ม "ส่งเตือน" จากในตารางโดยตรง
    $(document).on('click', '.btn-send-reminder', function() {
        const btn = $(this);
        const itemId        = btn.data('id');
        const borrowerEmail = btn.data('email');
        const borrowerName  = btn.data('name');
        const itemName      = btn.data('item');

        executeSendReminder(itemId, borrowerEmail, borrowerName, itemName);
    });

    // 7. จัดการคลิกปุ่ม "ส่งคืนแล้ว" (Mark as Returned via AJAX)
    $(document).on('click', '.btn-mark-returned', function() {
        const btn = $(this);
        const borrowId = btn.data('id');
        const itemName = btn.data('item');
        const assetNum = btn.data('asset');
        const borrower = btn.data('borrower');

        Swal.fire({
            title: 'ยืนยันการรับคืนครุภัณฑ์?',
            html: `คุณกำลังจะบันทึกการส่งคืนสำหรับรายการ:<br><strong class="text-primary fs-6">${itemName}</strong><br><span class="badge bg-light text-dark border font-monospace mt-1">${assetNum}</span><br><small class="text-muted">ผู้ยืม: ${borrower}</small>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#16a34a',
            cancelButtonColor: '#64748b',
            confirmButtonText: '<i class="fa-solid fa-rotate-left me-1"></i> ยืนยันว่าส่งคืนแล้ว',
            cancelButtonText: 'ยกเลิก',
            reverseButtons: true,
            focusCancel: true
        }).then((result) => {
            if (!result.isConfirmed) return;

            Swal.fire({
                title: 'กำลังบันทึกข้อมูล...',
                html: 'กรุณารอสักครู่ ระบบกำลังปรับปรุงสถานะเป็นคืนแล้ว',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: {
                    action: 'mark_returned',
                    id: borrowId
                },
                dataType: 'json',
                success: function(res) {
                    if (res && res.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'บันทึกสำเร็จ!',
                            text: res.message || 'บันทึกการส่งคืนครุภัณฑ์เรียบร้อยแล้ว',
                            confirmButtonColor: '#16a34a',
                            confirmButtonText: 'ตกลง'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: res.status === 'warning' ? 'warning' : 'error',
                            title: 'แจ้งเตือน',
                            text: res.message || 'ไม่สามารถบันทึกได้',
                            confirmButtonColor: '#dc2626'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'ข้อผิดพลาดเครือข่าย',
                        text: 'ไม่สามารถติดต่อเซิร์ฟเวอร์ได้ กรุณาลองใหม่อีกครั้ง',
                        confirmButtonColor: '#dc2626'
                    });
                }
            });
        });
    });
});
</script>

<?php require_once __DIR__ . '/../component/admin_footer.php'; ?>
