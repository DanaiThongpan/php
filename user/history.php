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

$today = date('Y-m-d');

// 3. ดึงข้อมูลประวัติการยืม-คืนทั้งหมดของผู้ใช้คนนี้
try {
    $sqlHistory = "SELECT b.*, da.DA_Number, da.DA_ListName, da.DA_Brand, da.DA_Size, 
                          c.classificationName, bg.buildingName, cr.classroomName,
                          u_back.useraccountName AS receiverName
                   FROM borrow_back b
                   LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
                   LEFT JOIN classification c ON da.classificationID = c.classificationID
                   LEFT JOIN building bg ON da.buildingID = bg.buildingID
                   LEFT JOIN building_classroom cr ON da.classroomID = cr.classroomID
                   LEFT JOIN useraccount u_back ON b.useraccountID_back = u_back.useraccountID
                   WHERE b.useraccountID_borrow = :uid
                   ORDER BY b.Date_borrow DESC, b.timestamp_borrow DESC";
    $stmtH = $pdo->prepare($sqlHistory);
    $stmtH->execute([':uid' => $useraccountID]);
    $historyList = $stmtH->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $historyList = [];
    $errorMsg = $e->getMessage();
}

// นับสถิติสำหรับสรุปผล
$totalCount    = count($historyList);
$borrowingCount = 0;
$returnedCount  = 0;
$overdueCount   = 0;

foreach ($historyList as $item) {
    $isReturned = (!empty($item['Date_back']));
    if ($isReturned) {
        $returnedCount++;
    } else {
        $borrowingCount++;
        if ($item['Deadline_borrow'] < $today) {
            $overdueCount++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประวัติการยืม-คืนครุภัณฑ์ของฉัน | Asset Management</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- DataTables Bootstrap 5 CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css" />
    <link rel="stylesheet" href="../css/admin_responsive.css">
    
    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { border-radius: 12px; }
        .card-header { border-top-left-radius: 12px !important; border-top-right-radius: 12px !important; }
        
        .badge-returned {
            background-color: #d1e7dd;
            color: #0f5132;
            border: 1px solid #badbcc;
            font-weight: 600;
        }
        .badge-borrowing {
            background-color: #cff4fc;
            color: #055160;
            border: 1px solid #b6effb;
            font-weight: 600;
        }
        .badge-overdue {
            background-color: #f8d7da;
            color: #842029;
            border: 1px solid #f5c2c7;
            font-weight: 600;
        }
        .filter-btn.active {
            box-shadow: inset 0 3px 5px rgba(0, 0, 0, 0.125);
            font-weight: bold;
        }
    </style>
</head>
<body>

<!-- แถบนำทาง (Navbar เมนูหลักสำหรับ User) -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm sticky-top">
    <div class="container">
        <a class="navbar-brand fw-bold" href="main.php">
            <i class="fa-solid fa-user-astronaut me-2"></i>User Panel
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#userNavbar">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="userNavbar">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="main.php">
                        <i class="fa-solid fa-house me-1"></i> หน้าแรกแดชบอร์ด
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active fw-bold" href="history.php">
                        <i class="fa-solid fa-clock-rotate-left me-1"></i> ประวัติการยืม-คืนของฉัน
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="request_item.php">
                        <i class="fa-solid fa-file-circle-plus me-1"></i> เสนอขึ้นทะเบียนครุภัณฑ์
                    </a>
                </li>
            </ul>
            <div class="ms-auto text-light d-flex align-items-center flex-wrap gap-2">
                <span class="me-2 small">
                    <i class="fa-solid fa-circle-user text-warning me-1"></i> 
                    ผู้ใช้งาน: <strong><?php echo htmlspecialchars($useraccountName); ?></strong> 
                    (<?php echo htmlspecialchars($userDepartment); ?>)
                </span>
                <a href="../logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                    <i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="container mt-4 mb-5">

    <!-- ส่วนหัวหน้าจอ -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>ประวัติการยืม - คืนครุภัณฑ์ของฉัน (Borrow & Return History)
            </h4>
            <p class="text-muted small mb-0">
                ตรวจสอบบันทึกการทำรายการยืมและคืนครุภัณฑ์ทั้งหมดในบัญชีของคุณ
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="main.php#available-section" class="btn btn-outline-primary rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-box-open me-1"></i> ยืมครุภัณฑ์เพิ่ม
            </a>
            <a href="main.php" class="btn btn-secondary rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับแดชบอร์ด
            </a>
        </div>
    </div>

    <!-- สรุปสถิติประวัติการยืม-คืน -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm bg-white p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">ทำรายการทั้งหมด</div>
                        <h3 class="fw-bold text-dark mb-0"><?php echo $totalCount; ?> <span class="fs-6 fw-normal text-muted">ครั้ง</span></h3>
                    </div>
                    <div class="p-3 bg-primary-subtle text-primary rounded-circle"><i class="fa-solid fa-list-check fa-lg"></i></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm bg-white p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">กำลังยืม (ยังไม่คืน)</div>
                        <h3 class="fw-bold text-info mb-0"><?php echo $borrowingCount; ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                    <div class="p-3 bg-info-subtle text-info rounded-circle"><i class="fa-solid fa-hand-holding-hand fa-lg"></i></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm bg-white p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">คืนเรียบร้อยแล้ว</div>
                        <h3 class="fw-bold text-success mb-0"><?php echo $returnedCount; ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                    <div class="p-3 bg-success-subtle text-success rounded-circle"><i class="fa-solid fa-circle-check fa-lg"></i></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm bg-white p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small fw-semibold">เลยกำหนดส่งคืน</div>
                        <h3 class="fw-bold text-danger mb-0"><?php echo $overdueCount; ?> <span class="fs-6 fw-normal text-muted">รายการ</span></h3>
                    </div>
                    <div class="p-3 bg-danger-subtle text-danger rounded-circle"><i class="fa-solid fa-triangle-exclamation fa-lg"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ตารางประวัติการยืม-คืน (DataTables) -->
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-white border-0 pt-4 pb-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="fw-bold text-dark mb-0">
                    <i class="fa-solid fa-table-list text-primary me-2"></i> บันทึกรายการยืม - คืนทั้งหมด
                </h5>
                <!-- ปุ่มคัดกรองอย่างรวดเร็ว -->
                <div class="btn-group btn-group-sm" role="group" id="statusFilterButtons">
                    <button type="button" class="btn btn-outline-secondary active" data-filter="">ทั้งหมด (<?php echo $totalCount; ?>)</button>
                    <button type="button" class="btn btn-outline-info" data-filter="กำลังยืม">กำลังยืม (<?php echo $borrowingCount; ?>)</button>
                    <button type="button" class="btn btn-outline-success" data-filter="คืนแล้ว">คืนแล้ว (<?php echo $returnedCount; ?>)</button>
                    <button type="button" class="btn btn-outline-danger" data-filter="เลยกำหนด">เลยกำหนด (<?php echo $overdueCount; ?>)</button>
                </div>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle w-100" id="borrowHistoryTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 45px;">ลำดับ</th>
                            <th>หมายเลขครุภัณฑ์</th>
                            <th>ชื่อรายการครุภัณฑ์</th>
                            <th>วันที่ยืม</th>
                            <th class="text-center" style="width: 120px;">จำนวนวันยืม</th>
                            <th>กำหนดคืน</th>
                            <th>วันที่คืนจริง</th>
                            <th class="text-center" style="width: 130px;">สถานะ</th>
                            <th class="text-center" style="width: 110px;">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($historyList) > 0): ?>
                            <?php $i = 1; foreach ($historyList as $row): 
                                $isReturned = !empty($row['Date_back']);
                                $isOverdue  = (!$isReturned && $row['Deadline_borrow'] < $today);

                                // คำนวณจำนวนวันที่ยืม (Date Diff)
                                $borrowDays = 0;
                                if (!empty($row['Date_borrow'])) {
                                    $borrowDate = new DateTime($row['Date_borrow']);
                                    if ($isReturned && !empty($row['Date_back'])) {
                                        // กรณีคืนแล้ว: คำนวณจำนวนวันที่ยืมจริง (วันที่คืน - วันที่ยืม)
                                        $endDate = new DateTime($row['Date_back']);
                                        $diff = $borrowDate->diff($endDate);
                                        $borrowDays = (int)$diff->format('%a');
                                        $borrowDaysBadge = '<span class="badge bg-light text-secondary border" title="ยืมเป็นเวลา ' . $borrowDays . ' วัน"><i class="fa-solid fa-check text-success me-1"></i>' . $borrowDays . ' วัน</span>';
                                    } else {
                                        // กรณียังไม่คืน: เปรียบเทียบวันที่ยืมกับวันที่ปัจจุบัน
                                        $todayDate = new DateTime($today);
                                        if ($todayDate >= $borrowDate) {
                                            $diff = $borrowDate->diff($todayDate);
                                            $borrowDays = (int)$diff->format('%a');
                                        } else {
                                            $borrowDays = 0;
                                        }

                                        if ($isOverdue) {
                                            $borrowDaysBadge = '<span class="badge bg-danger-subtle text-danger border border-danger fw-bold" title="ยืมมาแล้ว ' . $borrowDays . ' วัน (เลยกำหนด)"><i class="fa-solid fa-clock me-1"></i>' . $borrowDays . ' วัน</span>';
                                        } else {
                                            $borrowDaysBadge = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold" title="ยืมมาแล้ว ' . $borrowDays . ' วัน"><i class="fa-regular fa-clock me-1"></i>' . $borrowDays . ' วัน</span>';
                                        }
                                    }
                                } else {
                                    $borrowDaysBadge = '<span class="text-muted">-</span>';
                                }
                            ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border font-monospace">
                                            <?php echo htmlspecialchars($row['DA_Number'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-secondary"><?php echo htmlspecialchars($row['DA_ListName'] ?? 'ไม่พบชื่อรายการ'); ?></div>
                                        <?php if (!empty($row['DA_Brand'])): ?>
                                            <small class="text-muted"><i class="fa-solid fa-tag me-1"></i><?php echo htmlspecialchars($row['DA_Brand']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($row['Detail_borrow'])): ?>
                                            <div class="small text-muted text-truncate" style="max-width: 220px;" title="<?php echo htmlspecialchars($row['Detail_borrow']); ?>">
                                                <i class="fa-regular fa-comment-dots me-1"></i>วัตถุประสงค์: <?php echo htmlspecialchars($row['Detail_borrow']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['Date_borrow'] ?? '-'); ?></td>
                                    <td class="text-center">
                                        <?php echo $borrowDaysBadge; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($row['Deadline_borrow'] ?? '-'); ?>
                                        <?php if ($isOverdue): ?>
                                            <span class="badge bg-danger ms-1">เกินกำหนด</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isReturned): ?>
                                            <span class="text-success fw-semibold"><i class="fa-solid fa-check me-1"></i><?php echo htmlspecialchars($row['Date_back']); ?></span>
                                            <?php if (!empty($row['receiverName'])): ?>
                                                <div class="small text-muted">(ผู้รับคืน: <?php echo htmlspecialchars($row['receiverName']); ?>)</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($isReturned): ?>
                                            <span class="badge badge-returned rounded-pill px-3 py-2">
                                                <i class="fa-solid fa-circle-check me-1"></i> คืนแล้ว
                                            </span>
                                        <?php elseif ($isOverdue): ?>
                                            <span class="badge badge-overdue rounded-pill px-3 py-2">
                                                <i class="fa-solid fa-triangle-exclamation me-1"></i> เลยกำหนด
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-borrowing rounded-pill px-3 py-2">
                                                <i class="fa-solid fa-clock me-1"></i> กำลังยืม
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!$isReturned): ?>
                                            <a href="return_form.php?IDborrow=<?php echo urlencode($row['IDborrow_back']); ?>" class="btn btn-sm btn-warning rounded-pill px-3 shadow-sm fw-semibold">
                                                <i class="fa-solid fa-rotate-left me-1"></i> คืนของ
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-light text-muted rounded-pill px-3 border" disabled>
                                                <i class="fa-solid fa-check"></i> เรียบร้อย
                                            </button>
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
<script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    const table = $('#borrowHistoryTable').DataTable({
        language: {
            url: "//cdn.datatables.net/plug-ins/1.13.4/i18n/th.json"
        },
        pageLength: 10,
        order: [[0, 'asc']],
        responsive: true
    });

    // คัดกรองตารางตามปุ่มสถานะ
    $('#statusFilterButtons button').on('click', function() {
        $('#statusFilterButtons button').removeClass('active');
        $(this).addClass('active');

        const filterVal = $(this).data('filter');
        table.column(7).search(filterVal).draw();
    });
});
</script>
</body>
</html>
