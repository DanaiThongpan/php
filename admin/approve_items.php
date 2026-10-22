<?php
session_start();

// 1. ตรวจสอบสิทธิ์การเข้าถึง (ต้องเป็น Admin เท่านั้น)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

// 2. โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO
require_once '../control/connectPDO_dev.php';

$useraccountName = $_SESSION['Sename'] ?? 'ไม่ทราบชื่อ';
$currentUserID   = $_SESSION['SeUser'] ?? '';
$currentUserDept = $_SESSION['userDepartment'] ?? '';

// ตรวจสอบสถานะ Super Admin
$isSuperAdmin = ($currentUserID === 'admin');

// หากไม่ใช่ Super Admin และยังไม่มี userDepartment ใน Session ให้ดึงสดจาก useraccount
if (!$isSuperAdmin && empty($currentUserDept) && !empty($currentUserID)) {
    try {
        $stmtDept = $pdo->prepare("SELECT userDepartment FROM useraccount WHERE useraccountID = :uid");
        $stmtDept->execute([':uid' => $currentUserID]);
        $currentUserDept = $stmtDept->fetchColumn() ?: '';
        $_SESSION['userDepartment'] = $currentUserDept;
    } catch (PDOException $e) {
        $currentUserDept = '';
    }
}

// สร้างป้ายกำกับบอกสิทธิ์ (Role Badge)
if ($isSuperAdmin) {
    $roleBadge = '<span class="badge bg-danger shadow-sm px-3 py-2 fs-6"><i class="fa-solid fa-crown me-1 text-warning"></i> Super Admin (สิทธิ์สูงสุด มองเห็นและอนุมัติคำขอทุกแผนก)</span>';
    $roleBadgeSm = '<span class="badge bg-danger px-2 py-1"><i class="fa-solid fa-crown text-warning me-1"></i> Super Admin</span>';
} else {
    $deptNameDisplay = !empty($currentUserDept) ? htmlspecialchars($currentUserDept) : 'ไม่ระบุสังกัด';
    $roleBadge = '<span class="badge bg-info text-dark shadow-sm px-3 py-2 fs-6"><i class="fa-solid fa-building me-1 text-primary"></i> แอดมินประจำแผนก: ' . $deptNameDisplay . ' (อนุมัติเฉพาะคำขอในแผนกตนเอง)</span>';
    $roleBadgeSm = '<span class="badge bg-info text-dark px-2 py-1"><i class="fa-solid fa-building text-primary me-1"></i> แอดมิน: ' . $deptNameDisplay . '</span>';
}

$alertMsg = '';
$alertType = '';

// 3. จัดการการอนุมัติ / ปฏิเสธคำขอ (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $da_id = trim($_POST['da_id'] ?? '');
    $action = $_POST['action'];

    if (empty($da_id)) {
        $alertMsg = "ไม่พบรหัสครุภัณฑ์ที่ต้องการดำเนินการ";
        $alertType = "danger";
    } else {
        try {
            // ตรวจสอบสิทธิ์ว่ามีสิทธิ์จัดการรายการนี้หรือไม่
            if ($isSuperAdmin) {
                $stmtCheck = $pdo->prepare("SELECT * FROM durable_articles WHERE DA_ID = :id AND is_approved = 0");
                $stmtCheck->execute([':id' => $da_id]);
            } else {
                $stmtCheck = $pdo->prepare("SELECT * FROM durable_articles WHERE DA_ID = :id AND is_approved = 0 AND (owner_department = :my_dept OR (owner_department IS NULL AND created_by = :my_uid))");
                $stmtCheck->execute([
                    ':id'      => $da_id,
                    ':my_dept' => $currentUserDept,
                    ':my_uid'  => $currentUserID
                ]);
            }
            $targetItem = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$targetItem) {
                $alertMsg = "ไม่พบรายการครุภัณฑ์นี้ หรือคุณไม่มีสิทธิ์จัดการคำขอของแผนกอื่น";
                $alertType = "warning";
            } else {
                if ($action === 'approve') {
                    // อนุมัติ: ตั้ง is_approved = 1 และ statusID = 's0001' (พร้อมใช้งาน)
                    $stmtApprove = $pdo->prepare("UPDATE durable_articles SET is_approved = 1, statusID = 's0001' WHERE DA_ID = :id");
                    $stmtApprove->execute([':id' => $da_id]);

                    $alertMsg = "✅ อนุมัติครุภัณฑ์ [{$targetItem['DA_Number']} - {$targetItem['DA_ListName']}] เรียบร้อยแล้ว! รายการพร้อมใช้งานในระบบ";
                    $alertType = "success";
                } elseif ($action === 'reject') {
                    // ปฏิเสธคำขอ: ลบรายการคำขอนั้นออกจากระบบ
                    $stmtReject = $pdo->prepare("DELETE FROM durable_articles WHERE DA_ID = :id");
                    $stmtReject->execute([':id' => $da_id]);

                    $alertMsg = "🗑️ ปฏิเสธและลบคำขอครุภัณฑ์ [{$targetItem['DA_Number']}] เรียบร้อยแล้ว";
                    $alertType = "info";
                }
            }
        } catch (PDOException $e) {
            $alertMsg = "เกิดข้อผิดพลาดในการประมวลผล: " . $e->getMessage();
            $alertType = "danger";
        }
    }
}

// 4. ดึงรายการคำขอรออนุมัติ (is_approved = 0) ตามระดับสิทธิ์
try {
    if ($isSuperAdmin) {
        $sqlPending = "SELECT da.*, c.classificationName, b.buildingName, cr.classroomName, 
                              u.useraccountName AS creatorName, u.userDepartment AS creatorDept 
                       FROM durable_articles da
                       LEFT JOIN classification c ON da.classificationID = c.classificationID
                       LEFT JOIN building b ON da.buildingID = b.buildingID
                       LEFT JOIN building_classroom cr ON da.classroomID = cr.classroomID
                       LEFT JOIN useraccount u ON da.created_by = u.useraccountID
                       WHERE da.is_approved = 0
                       ORDER BY da.DA_ID DESC";
        $stmtP = $pdo->query($sqlPending);
        $pendingItems = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    } else {
        if (!empty($currentUserDept)) {
            $sqlPending = "SELECT da.*, c.classificationName, b.buildingName, cr.classroomName, 
                                  u.useraccountName AS creatorName, u.userDepartment AS creatorDept 
                           FROM durable_articles da
                           LEFT JOIN classification c ON da.classificationID = c.classificationID
                           LEFT JOIN building b ON da.buildingID = b.buildingID
                           LEFT JOIN building_classroom cr ON da.classroomID = cr.classroomID
                           LEFT JOIN useraccount u ON da.created_by = u.useraccountID
                           WHERE da.is_approved = 0 
                             AND (da.owner_department = :my_dept OR (da.owner_department IS NULL AND da.created_by = :my_uid))
                           ORDER BY da.DA_ID DESC";
            $stmtP = $pdo->prepare($sqlPending);
            $stmtP->execute([
                ':my_dept' => $currentUserDept,
                ':my_uid'  => $currentUserID
            ]);
            $pendingItems = $stmtP->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $pendingItems = [];
        }
    }
} catch (PDOException $e) {
    $pendingItems = [];
}

// โหลดโครงสร้าง Layout หลัก (Responsive Header, Sidebar & Top Navbar)
$pageTitle = "อนุมัติคำขอขึ้นทะเบียนครุภัณฑ์ (Approval)";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>

<!-- Main Container -->
<div class="container-fluid px-0">

    <!-- Header Section -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-clipboard-check text-warning me-2"></i>ตรวจสอบและอนุมัติคำขอขึ้นทะเบียนครุภัณฑ์
            </h4>
            <p class="text-muted small mb-0">พิจารณาคำขอเสนอขึ้นทะเบียนครุภัณฑ์จากผู้ใช้งาน เพื่ออนุมัติให้พร้อมใช้งานในระบบ</p>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($alertMsg)): ?>
        <div class="alert alert-<?php echo $alertType; ?> alert-dismissible fade show shadow-sm rounded-3 mb-4" role="alert">
            <i class="fa-solid <?php echo ($alertType === 'success') ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger'; ?> me-2 fs-5 align-middle"></i>
            <?php echo htmlspecialchars($alertMsg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Box -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm bg-warning text-dark p-3">
                <div class="card-body d-flex justify-content-between align-items-center p-1">
                    <div>
                        <h6 class="text-dark-50 mb-1 fw-bold">คำขอที่รอการอนุมัติ</h6>
                        <h2 class="fw-bold mb-0"><?php echo count($pendingItems); ?> <span class="fs-6 fw-normal">รายการ</span></h2>
                    </div>
                    <i class="fa-solid fa-hourglass-half fa-2x opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card border-0 shadow-sm bg-white p-3 h-100">
                <div class="card-body d-flex align-items-center p-1">
                    <div>
                        <h6 class="fw-bold text-secondary mb-1"><i class="fa-solid fa-circle-info text-primary me-2"></i>กติกาการอนุมัติข้อมูล (Approval Policy)</h6>
                        <p class="small text-muted mb-0">
                            - <strong>อนุมัติ:</strong> เปลี่ยนสถานะเป็น <code>พร้อมใช้งาน (s0001)</code> และเปิดให้ผู้ใช้งานสามารถยืมได้ในระบบ<br>
                            - <strong>ปฏิเสธ:</strong> ปฏิเสธและลบคำขอที่ไม่ผ่านเกณฑ์ออกจากระบบอย่างถาวร
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Approval Table Card -->
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-white border-0 pt-4 pb-2">
            <h5 class="fw-bold text-dark mb-0">
                <i class="fa-solid fa-list-check text-primary me-2"></i> รายการคำขอรอการพิจารณา (Pending Requests)
            </h5>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle w-100" id="approvalTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 40px;">ลำดับ</th>
                            <th>หมายเลขครุภัณฑ์</th>
                            <th>ชื่อรายการ / ยี่ห้อ</th>
                            <th>หมวดหมู่</th>
                            <th>แผนกสังกัด</th>
                            <th>ผู้ยื่นคำขอ</th>
                            <th>สถานที่ตั้ง</th>
                            <th>ราคา</th>
                            <th class="text-center" style="width: 170px;">การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($pendingItems) > 0): ?>
                            <?php $i = 1; foreach ($pendingItems as $item): ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark border fw-bold">
                                            <?php echo htmlspecialchars($item['DA_Number']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-secondary"><?php echo htmlspecialchars($item['DA_ListName']); ?></div>
                                        <?php if (!empty($item['DA_Brand'])): ?>
                                            <small class="text-muted"><i class="fa-solid fa-tag me-1"></i><?php echo htmlspecialchars($item['DA_Brand']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($item['DA_Detail'])): ?>
                                            <div class="small text-muted text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($item['DA_Detail']); ?>">
                                                <i class="fa-regular fa-comment-dots me-1"></i><?php echo htmlspecialchars($item['DA_Detail']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['classificationName'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge bg-light text-primary border">
                                            <i class="fa-solid fa-building me-1"></i><?php echo htmlspecialchars($item['owner_department'] ?? '-'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-dark">
                                            <?php echo htmlspecialchars($item['creatorName'] ?: $item['created_by'] ?: 'ไม่ระบุ'); ?>
                                        </div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($item['created_by'] ?? ''); ?></div>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php 
                                            $loc = trim(($item['buildingName'] ?? '') . ' ' . ($item['classroomName'] ?? ''));
                                            echo !empty($loc) ? htmlspecialchars($loc) : '-'; 
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php echo !empty($item['DA_Price']) ? number_format((float)$item['DA_Price'], 2) . ' ฿' : '-'; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex gap-1 justify-content-center">
                                            <!-- ปุ่มอนุมัติ -->
                                            <form method="POST" action="approve_items.php" class="d-inline" onsubmit="return confirm('ยืนยันการอนุมัติครุภัณฑ์ [<?php echo htmlspecialchars($item['DA_Number']); ?>] เข้าสู่ระบบ?');">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="da_id" value="<?php echo htmlspecialchars($item['DA_ID']); ?>">
                                                <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm">
                                                    <i class="fa-solid fa-check me-1"></i> อนุมัติ
                                                </button>
                                            </form>

                                            <!-- ปุ่มปฏิเสธ -->
                                            <form method="POST" action="approve_items.php" class="d-inline" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการปฏิเสธและลบคำขอนี้?');">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="da_id" value="<?php echo htmlspecialchars($item['DA_ID']); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2 shadow-sm" title="ปฏิเสธ / ลบคำขอ">
                                                    <i class="fa-solid fa-xmark"></i> ปฏิเสธ
                                                </button>
                                            </form>
                                        </div>
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

<script>
$(document).ready(function() {
    $('#approvalTable').DataTable({
        responsive: true,
        language: {
            url: "//cdn.datatables.net/plug-ins/1.13.4/i18n/th.json"
        },
        pageLength: 10,
        order: [[0, 'asc']]
    });
});
</script>

<?php
// โหลดส่วนท้าย Layout หลัก (Scripts & Sticky Footer)
require_once __DIR__ . '/../component/admin_footer.php';
?>
