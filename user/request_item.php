<?php
session_start();

// 1. ตรวจสอบสิทธิ์การเข้าถึง (ต้องเป็น User ทั่วไปเท่านั้น)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000002') {
    header("Location: ../index.php");
    exit();
}

// 2. โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO
require_once __DIR__ . '/../control/connectPDO_dev.php';

$useraccountName = $_SESSION['Sename'] ?? 'ไม่ทราบชื่อ';
$useraccountID   = $_SESSION['SeUser'] ?? '';
$userDepartment  = $_SESSION['userDepartment'] ?? '';

// หากใน Session ไม่มีแผนก ให้ดึงสดจากฐานข้อมูล
if (empty($userDepartment) && !empty($useraccountID)) {
    try {
        $stmtDept = $pdo->prepare("SELECT userDepartment FROM useraccount WHERE useraccountID = :uid");
        $stmtDept->execute([':uid' => $useraccountID]);
        $userDepartment = $stmtDept->fetchColumn() ?: 'ไม่ระบุแผนก';
        $_SESSION['userDepartment'] = $userDepartment;
    } catch (PDOException $e) {
        $userDepartment = 'ไม่ระบุแผนก';
    }
}

// ดึงข้อความแจ้งเตือน Flash Message (ถ้ามีจากการอัปโหลด CSV)
$alertMsg = '';
$alertType = '';
if (isset($_SESSION['flash_msg'])) {
    $alertMsg = $_SESSION['flash_msg'];
    $alertType = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// 3. จัดการการส่งฟอร์มเสนอขอขึ้นทะเบียนครุภัณฑ์แบบเดี่ยว (Single Request POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_item_single') {
    $daNumber   = trim($_POST['DA_Number'] ?? '');
    $daListName = trim($_POST['DA_ListName'] ?? '');
    $daBrand    = trim($_POST['DA_Brand'] ?? '');
    $classID    = trim($_POST['classificationID'] ?? '');
    $buildingID = trim($_POST['buildingID'] ?? '');
    $classRoomID= trim($_POST['classroomID'] ?? '');
    $daPrice    = trim($_POST['DA_Price'] ?? '');
    $daDetail   = trim($_POST['DA_Detail'] ?? '');

    // ตรวจสอบข้อมูลจำเป็น
    if (empty($daNumber) || empty($daListName)) {
        $alertMsg = "กรุณากรอกหมายเลขครุภัณฑ์และชื่อรายการให้ครบถ้วน";
        $alertType = "danger";
    } else {
        try {
            // ตรวจสอบหมายเลขครุภัณฑ์ซ้ำในระบบ
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM durable_articles WHERE DA_Number = :num");
            $stmtCheck->execute([':num' => $daNumber]);
            if ($stmtCheck->fetchColumn() > 0) {
                $alertMsg = "หมายเลขครุภัณฑ์ '{$daNumber}' มีอยู่ในระบบแล้ว กรุณาตรวจสอบอีกครั้ง";
                $alertType = "warning";
            } else {
                // สร้าง Primary Key อัตโนมัติ
                $daID = "DA" . time() . rand(1000, 9999);
                
                // จัดการค่าว่างเป็น NULL
                $valBrand     = !empty($daBrand) ? $daBrand : null;
                $valClassID   = !empty($classID) ? $classID : null;
                $valBuildingID= !empty($buildingID) ? $buildingID : null;
                $valClassRoom = !empty($classRoomID) ? $classRoomID : null;
                $valPrice     = (is_numeric($daPrice) && $daPrice !== '') ? (float)$daPrice : null;
                $valDetail    = !empty($daDetail) ? $daDetail : null;

                // บันทึกคำขอลงตาราง durable_articles (is_approved = 0 รออนุมัติ)
                $sqlInsert = "INSERT INTO durable_articles 
                    (DA_ID, DA_Number, DA_ListName, DA_Brand, classificationID, buildingID, classroomID, DA_Price, DA_Detail, 
                     statusID, is_approved, created_by, owner_department, owner_useraccountID, useraccountID) 
                    VALUES 
                    (:da_id, :num, :lname, :brand, :cid, :bid, :rid, :price, :detail, 
                     's0002', 0, :created_by, :owner_dept, :owner_uid, :uid)";

                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute([
                    ':da_id'      => $daID,
                    ':num'        => $daNumber,
                    ':lname'      => $daListName,
                    ':brand'      => $valBrand,
                    ':cid'        => $valClassID,
                    ':bid'        => $valBuildingID,
                    ':rid'        => $valClassRoom,
                    ':price'      => $valPrice,
                    ':detail'     => $valDetail,
                    ':created_by' => $useraccountID,
                    ':owner_dept' => $userDepartment,
                    ':owner_uid'  => $useraccountID,
                    ':uid'        => $useraccountID
                ]);

                $alertMsg = "ส่งคำขอเสนอขึ้นทะเบียนครุภัณฑ์สำเร็จ! ข้อมูลถูกส่งไปยังแอดมินประจำแผนก ({$userDepartment}) เพื่อรอการอนุมัติ";
                $alertType = "success";
            }
        } catch (PDOException $e) {
            $alertMsg = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
            $alertType = "danger";
        }
    }
}

// 4. ดึงข้อมูลตัวเลือกสำหรับ Dropdown และประวัติคำขอของผู้ใช้งาน
try {
    $classList = $pdo->query("SELECT classificationID, classificationName FROM classification ORDER BY classificationName ASC")->fetchAll(PDO::FETCH_ASSOC);
    $buildingList = $pdo->query("SELECT buildingID, buildingName FROM building WHERE (is_active = 1 OR is_active IS NULL) ORDER BY buildingName ASC")->fetchAll(PDO::FETCH_ASSOC);
    $classroomList = $pdo->query("SELECT classroomID, classroomName, buildingID FROM building_classroom ORDER BY classroomName ASC")->fetchAll(PDO::FETCH_ASSOC);

    // ดึงประวัติคำขอทั้งหมดที่ผู้ใช้คนนี้เป็นผู้สร้าง (created_by = :uid)
    $stmtHistory = $pdo->prepare("SELECT da.*, c.classificationName, b.buildingName, cr.classroomName 
                                  FROM durable_articles da
                                  LEFT JOIN classification c ON da.classificationID = c.classificationID
                                  LEFT JOIN building b ON da.buildingID = b.buildingID
                                  LEFT JOIN building_classroom cr ON da.classroomID = cr.classroomID
                                  WHERE da.created_by = :uid
                                  ORDER BY da.DA_ID DESC");
    $stmtHistory->execute([':uid' => $useraccountID]);
    $myRequests = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $classList = [];
    $buildingList = [];
    $classroomList = [];
    $myRequests = [];
}

// สรุปยอดคำขอของตนเอง
$totalMyRequests = count($myRequests);
$pendingMyRequests = count(array_filter($myRequests, fn($r) => (int)$r['is_approved'] === 0));
$approvedMyRequests = count(array_filter($myRequests, fn($r) => (int)$r['is_approved'] === 1));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เสนอขอขึ้นทะเบียนครุภัณฑ์ (Asset Registration Request)</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- DataTables Bootstrap 5 CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" />
    
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { border-radius: 12px; }
        .card-header { border-top-left-radius: 12px !important; border-top-right-radius: 12px !important; }
        .form-label { font-weight: 600; color: #495057; font-size: 0.95rem; }
        
        /* สไตล์แท็บ Nav tabs */
        .nav-tabs .nav-link {
            font-weight: 600;
            color: #6c757d;
            border: none;
            border-bottom: 3px solid transparent;
            padding: 0.85rem 1.5rem;
            transition: all 0.2s ease-in-out;
        }
        .nav-tabs .nav-link:hover {
            color: #0d6efd;
            border-color: transparent;
        }
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            background-color: transparent;
            border-bottom: 3px solid #0d6efd;
        }
        
        /* Drag and Drop Upload Area */
        .upload-dropzone {
            border: 2px dashed #0d6efd;
            border-radius: 12px;
            background-color: #f8fbff;
            padding: 2.5rem 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: background-color 0.2s, border-color 0.2s;
        }
        .upload-dropzone:hover {
            background-color: #eef5ff;
            border-color: #0b5ed7;
        }
        .upload-dropzone i {
            font-size: 3rem;
            color: #0d6efd;
        }
        
        .badge-pending {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
            font-weight: 600;
        }
        .badge-approved {
            background-color: #d1e7dd;
            color: #0f5132;
            border: 1px solid #badbcc;
            font-weight: 600;
        }
        .table-preview th { font-size: 0.82rem; font-weight: 600; vertical-align: middle; }
        .table-preview td { font-size: 0.82rem; vertical-align: middle; }
    </style>
</head>
<body>

<!-- แถบนำทาง (Navbar สำหรับ User) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-bold" href="main.php"><i class="fa-solid fa-user-astronaut me-2"></i>User Panel</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#userNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="userNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="main.php"><i class="fa-solid fa-house me-1"></i> หน้าแรกแดชบอร์ด</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="main.php#available-section"><i class="fa-solid fa-box-open me-1"></i> ยืมครุภัณฑ์</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="history.php"><i class="fa-solid fa-clock-rotate-left me-1"></i> ประวัติการยืม-คืน</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active fw-bold" href="request_item.php"><i class="fa-solid fa-file-circle-plus me-1"></i> เสนอขึ้นทะเบียนครุภัณฑ์</a>
                </li>
            </ul>
            <div class="ms-auto text-light d-flex align-items-center flex-wrap gap-2">
                <span class="me-2 small"><i class="fa-solid fa-circle-user text-warning me-1"></i> ผู้ใช้งาน: <strong><?php echo htmlspecialchars($useraccountName); ?></strong> (<?php echo htmlspecialchars($userDepartment); ?>)</span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3"><i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ</a>
            </div>
        </div>
    </div>
</nav>

<div class="container mt-4 mb-5">

    <!-- ส่วนหัวหน้าจอ -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-boxes-packing text-primary me-2"></i>เสนอขอขึ้นทะเบียนครุภัณฑ์ (Asset Registration Request)
            </h4>
            <p class="text-muted small mb-0">
                ยื่นคำขอเสนอขึ้นทะเบียนครุภัณฑ์ใหม่เพื่อส่งให้แอดมินประจำแผนก (<strong><?php echo htmlspecialchars($userDepartment); ?></strong>) พิจารณาอนุมัติเข้าสู่ระบบ
            </p>
        </div>
        <div>
            <a href="main.php" class="btn btn-secondary rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้าหลัก
            </a>
        </div>
    </div>

    <!-- กล่องแจ้งเตือนผลลัพธ์ (Alert Message) -->
    <?php if (!empty($alertMsg)): ?>
        <div class="alert alert-<?php echo $alertType; ?> alert-dismissible fade show shadow-sm rounded-4 mb-4 p-3" role="alert">
            <div class="d-flex align-items-center">
                <i class="fa-solid <?php echo ($alertType === 'success') ? 'fa-circle-check text-success fs-4 me-3' : (($alertType === 'warning') ? 'fa-triangle-exclamation text-warning fs-4 me-3' : 'fa-circle-xmark text-danger fs-4 me-3'); ?>"></i>
                <div>
                    <div><?php echo $alertMsg; ?></div>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- สรุปสถิติคำขอของฉัน -->
    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="card border-0 shadow-sm bg-white p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">คำขอทั้งหมดของคุณ</div>
                        <h3 class="fw-bold text-dark mb-0"><?php echo $totalMyRequests; ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                    <div class="p-3 bg-primary-subtle text-primary rounded-circle"><i class="fa-solid fa-folder-open fa-lg"></i></div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card border-0 shadow-sm bg-white p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">รอการอนุมัติ</div>
                        <h3 class="fw-bold text-warning mb-0"><?php echo $pendingMyRequests; ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                    <div class="p-3 bg-warning-subtle text-warning rounded-circle"><i class="fa-solid fa-clock fa-lg"></i></div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card border-0 shadow-sm bg-white p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">อนุมัติแล้ว/พร้อมใช้</div>
                        <h3 class="fw-bold text-success mb-0"><?php echo $approvedMyRequests; ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                    <div class="p-3 bg-success-subtle text-success rounded-circle"><i class="fa-solid fa-circle-check fa-lg"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ส่วนที่ 1: ส่วนฟอร์มยื่นคำขอ (Forms Section with Nav Tabs) -->
    <div class="card shadow-sm border-0 mb-5">
        <div class="card-header bg-white border-bottom pt-3 pb-0">
            <ul class="nav nav-tabs card-header-tabs" id="requestTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="single-tab" data-bs-toggle="tab" data-bs-target="#single-content" type="button" role="tab" aria-controls="single-content" aria-selected="true">
                        <i class="fa-solid fa-pen-to-square me-2 text-primary"></i> กรอกทีละรายการ (Single Request)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="csv-tab" data-bs-toggle="tab" data-bs-target="#csv-content" type="button" role="tab" aria-controls="csv-content" aria-selected="false">
                        <i class="fa-solid fa-file-csv me-2 text-success"></i> อัปโหลดไฟล์ CSV (Bulk Request)
                    </button>
                </li>
            </ul>
        </div>
        <div class="card-body p-4">
            <div class="tab-content" id="requestTabContent">
                
                <!-- แท็บ 1: กรอกทีละรายการ (Single Request) -->
                <div class="tab-pane fade show active" id="single-content" role="tabpanel" aria-labelledby="single-tab">
                    <form method="POST" action="request_item.php">
                        <input type="hidden" name="action" value="request_item_single">

                        <div class="row g-3">
                            <!-- หมายเลขครุภัณฑ์ -->
                            <div class="col-md-6">
                                <label for="DA_Number" class="form-label">หมายเลขครุภัณฑ์ <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fa-solid fa-barcode text-secondary"></i></span>
                                    <input type="text" class="form-control" id="DA_Number" name="DA_Number" placeholder="เช่น 631004-001/67" required>
                                </div>
                                <div class="form-text">ระบุรหัสหรือหมายเลขประจำตัวครุภัณฑ์</div>
                            </div>

                            <!-- ชื่อรายการครุภัณฑ์ -->
                            <div class="col-md-6">
                                <label for="DA_ListName" class="form-label">ชื่อรายการครุภัณฑ์ <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fa-solid fa-tag text-secondary"></i></span>
                                    <input type="text" class="form-control" id="DA_ListName" name="DA_ListName" placeholder="เช่น เครื่องคอมพิวเตอร์พกพา, เก้าอี้ทำงาน" required>
                                </div>
                            </div>

                            <!-- ยี่ห้อ / รุ่น -->
                            <div class="col-md-6">
                                <label for="DA_Brand" class="form-label">ยี่ห้อ / รุ่น</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fa-solid fa-certificate text-secondary"></i></span>
                                    <input type="text" class="form-control" id="DA_Brand" name="DA_Brand" placeholder="เช่น Dell, ASUS, Lenovo, Modernform (เว้นว่างได้)">
                                </div>
                            </div>

                            <!-- หมวดหมู่ครุภัณฑ์ -->
                            <div class="col-md-6">
                                <label for="classificationID" class="form-label">หมวดหมู่ครุภัณฑ์</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fa-solid fa-layer-group text-secondary"></i></span>
                                    <select class="form-select" id="classificationID" name="classificationID">
                                        <option value="">-- เลือกหมวดหมู่ (หรือเว้นว่างไว้) --</option>
                                        <?php foreach ($classList as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat['classificationID']); ?>">
                                                <?php echo htmlspecialchars($cat['classificationName']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- อาคาร -->
                            <div class="col-md-6">
                                <label for="buildingID" class="form-label">อาคาร / สถานที่ตั้ง</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fa-solid fa-building text-secondary"></i></span>
                                    <select class="form-select" id="buildingID" name="buildingID">
                                        <option value="">-- เลือกอาคาร (หรือเว้นว่างไว้) --</option>
                                        <?php foreach ($buildingList as $bld): ?>
                                            <option value="<?php echo htmlspecialchars($bld['buildingID']); ?>">
                                                <?php echo htmlspecialchars($bld['buildingName']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- ห้องประจำอาคาร -->
                            <div class="col-md-6">
                                <label for="classroomID" class="form-label">ห้อง / สถานที่ระบุ</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fa-solid fa-door-open text-secondary"></i></span>
                                    <select class="form-select" id="classroomID" name="classroomID">
                                        <option value="">-- เลือกห้อง (หรือเว้นว่างไว้) --</option>
                                        <?php foreach ($classroomList as $rm): ?>
                                            <option value="<?php echo htmlspecialchars($rm['classroomID']); ?>" data-building="<?php echo htmlspecialchars($rm['buildingID']); ?>">
                                                <?php echo htmlspecialchars($rm['classroomName']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- ราคาครุภัณฑ์ -->
                            <div class="col-md-6">
                                <label for="DA_Price" class="form-label">ราคาประเมิน / จัดซื้อ (บาท)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fa-solid fa-baht-sign text-secondary"></i></span>
                                    <input type="number" step="0.01" min="0" class="form-control" id="DA_Price" name="DA_Price" placeholder="เช่น 25000 (เว้นว่างได้)">
                                </div>
                            </div>

                            <!-- แผนกสังกัด (Readonly ผูกกับผู้ใช้) -->
                            <div class="col-md-6">
                                <label class="form-label">แผนกสังกัดที่ผูกข้อมูล</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="fa-solid fa-sitemap text-secondary"></i></span>
                                    <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($userDepartment); ?>" readonly>
                                </div>
                                <div class="form-text text-muted">ระบบจะผูกสังกัดกับแผนกของคุณโดยอัตโนมัติ</div>
                            </div>

                            <!-- รายละเอียด / หมายเหตุ -->
                            <div class="col-12">
                                <label for="DA_Detail" class="form-label">รายละเอียดเพิ่มเติม / สเปก / หมายเหตุ</label>
                                <textarea class="form-control" id="DA_Detail" name="DA_Detail" rows="3" placeholder="ระบุรายละเอียดเพิ่มเติม เช่น ซีเรียลนัมเบอร์ สเปก หรือเหตุผลในการขอขึ้นทะเบียน (เว้นว่างได้)"></textarea>
                            </div>

                            <!-- ปุ่มส่งข้อมูล -->
                            <div class="col-12 pt-3 text-end">
                                <button type="reset" class="btn btn-light rounded-pill px-4 me-2 border">
                                    <i class="fa-solid fa-rotate-left me-1"></i> ล้างค่า
                                </button>
                                <button type="submit" class="btn btn-primary rounded-pill px-5 shadow-sm fw-bold">
                                    <i class="fa-solid fa-paper-plane me-2"></i> ส่งคำขอขึ้นทะเบียน
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- แท็บ 2: อัปโหลดไฟล์ CSV (Bulk Request) -->
                <div class="tab-pane fade" id="csv-content" role="tabpanel" aria-labelledby="csv-tab">
                    
                    <!-- ส่วนแนะนำและดาวน์โหลด Template -->
                    <div class="alert alert-light border rounded-3 p-3 mb-4">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                            <div>
                                <h6 class="fw-bold text-dark mb-1">
                                    <i class="fa-solid fa-circle-info text-primary me-2"></i>คำแนะนำในการเตรียมไฟล์ CSV
                                </h6>
                                <p class="text-muted small mb-0">กรุณาจัดเตรียมไฟล์ <code>.csv</code> โดยมีหัวคอลัมน์ภาษาอังกฤษตามรูปแบบ และบันทึกแบบ UTF-8</p>
                            </div>
                            <div>
                                <a href="template_request_items.csv" class="btn btn-outline-success btn-sm rounded-pill px-3 shadow-sm" download>
                                    <i class="fa-solid fa-download me-1"></i> ดาวน์โหลด Template (.csv)
                                </a>
                            </div>
                        </div>

                        <!-- ตารางตัวอย่างโครงสร้างไฟล์ CSV -->
                        <div class="table-responsive rounded border bg-white">
                            <table class="table table-sm table-hover table-striped table-preview text-center mb-0">
                                <thead class="table-light text-primary">
                                    <tr>
                                        <th>DA_Number<br><span class="badge bg-danger-subtle text-danger fw-normal">จำเป็น</span></th>
                                        <th>DA_ListName<br><span class="badge bg-danger-subtle text-danger fw-normal">จำเป็น</span></th>
                                        <th>DA_Brand<br><span class="badge bg-secondary-subtle text-secondary fw-normal">เว้นว่างได้</span></th>
                                        <th>classificationID<br><span class="badge bg-success-subtle text-success fw-normal">ตรวจจับอัตโนมัติ</span></th>
                                        <th>buildingID<br><span class="badge bg-secondary-subtle text-secondary fw-normal">เว้นว่างได้</span></th>
                                        <th>classroomID<br><span class="badge bg-secondary-subtle text-secondary fw-normal">เว้นว่างได้</span></th>
                                        <th>DA_Price<br><span class="badge bg-secondary-subtle text-secondary fw-normal">เว้นว่างได้</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="font-monospace text-primary">1206-001</td>
                                        <td>คอมพิวเตอร์โน้ตบุ๊ก</td>
                                        <td>ASUS</td>
                                        <td><span class="text-muted small"><em>(เว้นว่างไว้)</em></span></td>
                                        <td>ACB</td>
                                        <td>CLB3100</td>
                                        <td>25000.00</td>
                                    </tr>
                                    <tr>
                                        <td class="font-monospace text-primary">1206-002</td>
                                        <td>โต๊ะทำงานพร้อมลิ้นชัก</td>
                                        <td>Modernform</td>
                                        <td><span class="text-muted small"><em>(เว้นว่างไว้)</em></span></td>
                                        <td>CLB3</td>
                                        <td>CLB3100</td>
                                        <td>6500.00</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ฟอร์มอัปโหลดไฟล์ CSV -->
                    <form method="POST" action="process_request_csv.php" enctype="multipart/form-data" id="formUploadCsv">
                        <div class="upload-dropzone mb-3" onclick="document.getElementById('csvFile').click()">
                            <i class="fa-solid fa-cloud-arrow-up mb-2"></i>
                            <h5 class="fw-bold text-dark mb-1">คลิกเพื่อเลือกไฟล์ หรือลากไฟล์ CSV มาวางที่นี่</h5>
                            <p class="text-muted small mb-0">รองรับไฟล์นามสกุล <code>.csv</code> เท่านั้น (ไม่เกิน 10MB)</p>
                            <input type="file" class="d-none" id="csvFile" name="csvFile" accept=".csv" required onchange="showSelectedFilename(this)">
                            <div id="fileSelectedDisplay" class="mt-3 text-primary fw-bold small"></div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-2">
                            <span class="text-muted small">
                                <i class="fa-solid fa-shield-halved text-success me-1"></i> ทุกรายการที่อัปโหลดจะถูกผูกกับแผนก: <strong><?php echo htmlspecialchars($userDepartment); ?></strong>
                            </span>
                            <button type="submit" class="btn btn-success rounded-pill px-5 shadow-sm fw-bold">
                                <i class="fa-solid fa-upload me-2"></i> นำเข้าไฟล์และส่งคำขอแบบกลุ่ม (Batch Submit)
                            </button>
                        </div>
                    </form>

                </div>

            </div>
        </div>
    </div>

    <!-- ส่วนที่ 2: ตารางดูข้อมูลประวัติการขอขึ้นทะเบียนของตัวเอง (My Requests Table) -->
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-white border-0 pt-4 pb-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="fw-bold text-dark mb-0">
                    <i class="fa-solid fa-clipboard-list text-primary me-2"></i> ตารางประวัติการเสนอขอขึ้นทะเบียนของคุณ (My Requests)
                </h5>
                <span class="badge bg-light text-secondary border px-3 py-2">
                    <i class="fa-solid fa-user me-1"></i> แสดงเฉพาะคำขอที่คุณสร้าง (<?php echo $totalMyRequests; ?> รายการ)
                </span>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle w-100" id="myRequestsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">ลำดับ</th>
                            <th>หมายเลขครุภัณฑ์</th>
                            <th>ชื่อรายการ / ยี่ห้อ</th>
                            <th>หมวดหมู่</th>
                            <th>แผนกสังกัด</th>
                            <th>สถานที่ตั้ง</th>
                            <th>ราคา</th>
                            <th class="text-center" style="width: 140px;">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($myRequests) > 0): ?>
                            <?php $i = 1; foreach ($myRequests as $req): ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border font-monospace">
                                            <?php echo htmlspecialchars($req['DA_Number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-secondary"><?php echo htmlspecialchars($req['DA_ListName']); ?></span>
                                        <?php if (!empty($req['DA_Brand'])): ?>
                                            <span class="badge bg-light text-muted border ms-1"><?php echo htmlspecialchars($req['DA_Brand']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($req['DA_Detail'])): ?>
                                            <div class="small text-muted text-truncate" style="max-width: 180px;" title="<?php echo htmlspecialchars($req['DA_Detail']); ?>">
                                                <i class="fa-regular fa-comment-dots me-1"></i><?php echo htmlspecialchars($req['DA_Detail']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($req['classificationName'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge bg-light text-primary border">
                                            <?php echo htmlspecialchars($req['owner_department'] ?? $userDepartment); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php 
                                            $loc = trim(($req['buildingName'] ?? '') . ' ' . ($req['classroomName'] ?? ''));
                                            echo !empty($loc) ? htmlspecialchars($loc) : '-'; 
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php echo !empty($req['DA_Price']) ? number_format((float)$req['DA_Price'], 2) . ' ฿' : '-'; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ((int)$req['is_approved'] === 1): ?>
                                            <span class="badge badge-approved rounded-pill px-3 py-2">
                                                <i class="fa-solid fa-circle-check me-1"></i> อนุมัติแล้ว
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-pending rounded-pill px-3 py-2">
                                                <i class="fa-solid fa-clock me-1"></i> รออนุมัติ
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<script>
// ฟังก์ชันแสดงชื่อไฟล์ที่เลือกใน Dropzone
function showSelectedFilename(input) {
    const display = document.getElementById('fileSelectedDisplay');
    if (input.files && input.files[0]) {
        display.innerHTML = '<i class="fa-solid fa-file-csv me-1 text-success"></i> ไฟล์ที่เลือก: ' + input.files[0].name + ' (' + (input.files[0].size / 1024).toFixed(1) + ' KB)';
    } else {
        display.innerHTML = '';
    }
}

$(document).ready(function() {
    // กำหนด DataTables ภาษาไทย
    $('#myRequestsTable').DataTable({
        language: {
            url: "//cdn.datatables.net/plug-ins/1.13.4/i18n/th.json"
        },
        pageLength: 10,
        order: [[0, 'desc']],
        responsive: true
    });

    // สลับไปยังแท็บ CSV อัตโนมัติหากมี parameter ?tab=csv
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('tab') === 'csv') {
        const csvTabTrigger = document.querySelector('#csv-tab');
        if (csvTabTrigger) {
            bootstrap.Tab.getOrCreateInstance(csvTabTrigger).show();
        }
    }

    // กรองตัวเลือกห้องตามอาคารที่เลือกในแบบฟอร์มเดี่ยว
    const $building = $('#buildingID');
    const $classroom = $('#classroomID');
    const allRooms = $classroom.find('option').clone();

    $building.on('change', function() {
        const selectedBuild = $(this).val();
        $classroom.empty();
        $classroom.append('<option value="">-- เลือกห้อง (หรือเว้นว่างไว้) --</option>');

        allRooms.each(function() {
            const bId = $(this).data('building');
            if (!bId || bId === selectedBuild || selectedBuild === '') {
                if ($(this).val() !== '') {
                    $classroom.append($(this).clone());
                }
            }
        });
    });
});
</script>
</body>
</html>
