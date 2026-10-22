<?php
session_start();

// ตรวจสอบสิทธิ์การเข้าถึง (ต้องเป็น Admin เท่านั้น)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

require_once '../control/connectPDO_dev.php';

$useraccountName = $_SESSION['Sename'];
$currentUserID   = $_SESSION['SeUser'] ?? '';
$currentUserDept = $_SESSION['userDepartment'] ?? '';

// 1. ตรวจสอบเงื่อนไข Super Admin เพียงคนเดียว: ต้องเป็นไอดี 'admin' เท่านั้น
$isSuperAdmin = ($currentUserID === 'admin');

// ดึงข้อมูลแผนกสดจากตาราง useraccount เพื่อความแม่นยำหาก Session ยังไม่ถูกรีเฟรช
if (empty($currentUserDept) && !empty($currentUserID)) {
    $stmtDept = $pdo->prepare("SELECT userDepartment FROM useraccount WHERE useraccountID = :uid");
    $stmtDept->execute([':uid' => $currentUserID]);
    $currentUserDept = $stmtDept->fetchColumn() ?: '';
}

// 2. ตรวจสอบว่าแอดมินระดับแผนกมีการระบุสังกัด/แผนกหรือไม่
$hasDepartment = !empty(trim($currentUserDept));

// 3. สร้างตัวแปร $roleBadge และ $roleBadgeSm (HTML Badge ตามสิทธิ์จริงแบบไดนามิก)
if ($isSuperAdmin) {
    $roleBadge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 rounded-pill fs-6 shadow-sm">
                    <i class="fa-solid fa-crown text-warning me-1"></i> 👑 Super Admin (สิทธิ์สูงสุด มองเห็นข้อมูลทุกแผนก)
                  </span>';
    $roleBadgeSm = '<span class="badge bg-danger text-white rounded-pill"><i class="fa-solid fa-crown me-1"></i> Super Admin</span>';
} else {
    $deptDisplayName = $hasDepartment ? htmlspecialchars($currentUserDept) : '<span class="text-danger fw-bold">ไม่ระบุแผนก</span>';
    $roleBadge = '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-3 py-2 rounded-pill fs-6 shadow-sm">
                    <i class="fa-solid fa-building me-1"></i> 🏢 แอดมินประจำแผนก: ' . $deptDisplayName . '
                  </span>';
    $roleBadgeSm = '<span class="badge bg-info text-dark rounded-pill"><i class="fa-solid fa-building me-1"></i> แผนก: ' . ($hasDepartment ? htmlspecialchars($currentUserDept) : 'ไม่ระบุ') . '</span>';
}

// 4. ดึงข้อมูลผู้ใช้งาน (useraccount) ตามระดับสิทธิ์
try {
    if ($isSuperAdmin) {
        // Super Admin: ดึงรายชื่อผู้ใช้งานทั้งหมดในระบบ
        $stmtUser = $pdo->query("SELECT * FROM useraccount ORDER BY useraccountType ASC, useraccountName ASC");
        $users = $stmtUser->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Department Admin: ดึงเฉพาะผู้ใช้งานที่อยู่ในแผนกเดียวกันกับตนเอง
        if ($hasDepartment) {
            $stmtUser = $pdo->prepare("SELECT * FROM useraccount WHERE userDepartment = :my_dept ORDER BY useraccountType ASC, useraccountName ASC");
            $stmtUser->execute([':my_dept' => $currentUserDept]);
            $users = $stmtUser->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $users = [];
        }
    }
} catch (PDOException $e) {
    $users = [];
}

// โหลดส่วนประกอบ Layout หลัก (Responsive Header, Sidebar & Top Navbar)
$pageTitle = "จัดการข้อมูลผู้ใช้งาน (User Management)";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>

<!-- Main Container -->
<div class="container-fluid px-0">
    
    <!-- Header Section with Role Badge (Dynamic) -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold text-dark mb-1"><i class="fa-solid fa-users-gear text-primary me-2"></i>ระบบจัดการข้อมูลผู้ใช้งาน (User Management)</h4>
            <p class="text-muted small mb-0">เรียกดู ตรวจสอบ สิทธิ์การใช้งาน และแก้ไขข้อมูลบัญชีผู้ใช้งานในระบบ</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="manage_data.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-boxes-stacked me-1"></i> ไปที่จัดการครุภัณฑ์
            </a>
        </div>
    </div>

    <!-- Main Card -->
    <div class="card shadow border-0">
        <div class="card-body p-4">
            
            <?php if (!$isSuperAdmin && !$hasDepartment): ?>
                <div class="alert alert-warning border-warning shadow-sm rounded-3 py-3 px-4 mb-4">
                    <h6 class="alert-heading fw-bold mb-1"><i class="fa-solid fa-triangle-exclamation me-2"></i>ไม่สามารถแสดงข้อมูลผู้ใช้งานได้</h6>
                    <p class="mb-0 small text-secondary">บัญชีผู้ดูแลระบบของคุณยังไม่ได้ระบุ <strong>"สังกัด / แผนก"</strong> ในระบบ จึงไม่สามารถเข้าถึงข้อมูลสมาชิกในแผนกได้ กรุณาติดต่อ Super Admin เพื่อตั้งค่าสังกัดให้บัญชีของคุณ</p>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <h6 class="fw-bold text-secondary mb-0"><i class="fa-solid fa-list me-1"></i> รายชื่อบัญชีผู้ใช้ในระบบ <?php echo (!$isSuperAdmin && $hasDepartment) ? '(เฉพาะแผนก ' . htmlspecialchars($currentUserDept) . ')' : ''; ?></h6>
                    <span class="badge bg-primary rounded-pill"><?php echo count($users); ?> รายการ</span>
                </div>
                <a href="../register.php" class="btn btn-success btn-sm rounded-pill px-3 shadow-sm">
                    <i class="fa-solid fa-plus me-1"></i> เพิ่มผู้ใช้งานใหม่
                </a>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle border" id="userTable">
                    <thead class="text-center">
                        <tr>
                            <th>ไอดี (Username)</th>
                            <th>ชื่อ - นามสกุล</th>
                            <th>ตำแหน่ง</th>
                            <th>สังกัด / แผนก</th>
                            <th>อีเมล</th>
                            <th>ระดับสิทธิ์</th>
                            <th>สถานะ</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($users as $u): ?>
                        <tr>
                            <td class="text-center font-monospace fw-bold text-primary"><?php echo htmlspecialchars($u['useraccountID']); ?></td>
                            <td><?php echo htmlspecialchars($u['useraccountName']); ?></td>
                            <td><?php echo htmlspecialchars($u['userRank'] ?? '-'); ?></td>
                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($u['userDepartment'] ?? 'ไม่ระบุ'); ?></span></td>
                            <td class="text-center">
                                <?php if (!empty($u['userEmail'])): ?>
                                    <?= htmlspecialchars($u['userEmail']); ?>
                                <?php else: ?>
                                    <span class="text-muted">- ไม่ระบุ -</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($u['useraccountID'] === 'admin'): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 shadow-sm">
                                        <i class="fa-solid fa-crown text-warning me-1"></i> Super Admin
                                    </span>
                                <?php elseif ($u['useraccountType'] === 'UT00000001'): ?>
                                    <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1 shadow-sm">
                                        <i class="fa-solid fa-user-gear me-1"></i> Dept Admin
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1">
                                        <i class="fa-solid fa-user me-1"></i> User
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($u['userActive'] == '1'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa-solid fa-circle-check me-1"></i> ใช้งานได้</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fa-solid fa-circle-xmark me-1"></i> ระงับใช้งาน</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <a href="edit_user.php?uid=<?php echo urlencode($u['useraccountID']); ?>" class="btn btn-warning btn-sm btn-action text-white" title="แก้ไขข้อมูลผู้ใช้">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
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

<script>
    $(document).ready(function() {
        $('#userTable').DataTable({
            responsive: true,
            pageLength: 10,
            order: [[ 5, "asc" ]]
        });
    });
</script>

<?php
// โหลดส่วนท้าย Layout หลัก (Scripts & Sticky Footer)
require_once __DIR__ . '/../component/admin_footer.php';
?>
