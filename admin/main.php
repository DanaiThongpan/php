<?php
/**
 * main.php - Admin Command Center Dashboard (Enterprise Edition)
 * สถาปัตยกรรม High-Performance & Asynchronous Non-blocking Rendering
 * รองรับ Skeleton Loading, Chart.js Drill-down, Micro-interactions, และ Error Handling สมบูรณ์แบบ
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. ตรวจสอบ Security Check สิทธิ์การเข้าถึง (ต้องเป็น Admin: UT00000001 เท่านั้น)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || ($_SESSION['useraccountType'] ?? '') !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

// 2. โหลดไฟล์เชื่อมต่อฐานข้อมูล PDO
require_once __DIR__ . '/../control/connectPDO_dev.php';

// 3. ข้อมูลผู้ใช้งานและสิทธิ์ใน Session
$adminDept = $_SESSION['department_id'] 
          ?? $_SESSION['department_name'] 
          ?? $_SESSION['userDepartment'] 
          ?? '';

$useraccountName = $_SESSION['Sename'] ?? 'ผู้ดูแลระบบ';
$currentUserID   = $_SESSION['SeUser'] ?? '';
$useraccountType = $_SESSION['useraccountType'] ?? '';
$isSuperAdmin    = (
    $useraccountType === 'Super Admin' ||
    in_array(strtolower($currentUserID), ['admin', 'admin_test']) ||
    strtolower($_SESSION['username'] ?? '') === 'admin' ||
    strtolower($useraccountName) === 'administrator' ||
    empty($adminDept) ||
    $adminDept === 'ส่วนกลาง'
);

// หากใน Session ยังไม่มีแผนก ให้ดึงจากฐานข้อมูล
if (empty($adminDept) && !empty($currentUserID)) {
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

// ป้ายกำกับสิทธิ์ Role Badge
if ($isSuperAdmin) {
    $roleBadge = '<span class="badge bg-danger shadow-sm px-3 py-2 fs-6"><i class="fa-solid fa-crown me-1 text-warning"></i> Super Admin (ทุกแผนก)</span>';
} else {
    $deptNameDisplay = !empty($currentUserDept) ? htmlspecialchars($currentUserDept) : 'ไม่ระบุสังกัด';
    $roleBadge = '<span class="badge bg-info text-dark shadow-sm px-3 py-2 fs-6"><i class="fa-solid fa-building me-1 text-primary"></i> แผนก: ' . $deptNameDisplay . '</span>';
}

// 4. จัดการโหมดการแสดงผลข้อมูล (Visibility Rules: normal, all, hidden)
$viewMode = $_GET['view_mode'] ?? 'normal';

// 5. ดึง Metadata การซิงค์ฐานข้อมูลล่าสุด (_sync_meta) สำหรับแถบควบคุมด้านบน
$syncMeta = null;
try {
    $stmtSync = $pdo->query("SELECT * FROM _sync_meta ORDER BY id DESC LIMIT 1");
    $syncMeta = $stmtSync->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// โหลดโครงสร้าง Layout หลัก (Responsive Header, Sidebar & Top Navbar)
$pageTitle = "ศูนย์ควบคุมและบริหารจัดการระบบ (Command Center)";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>

<!-- โหลด CSS ประจำหน้าแดชบอร์ด (Enterprise Design System & Skeleton Loading) -->
<link rel="stylesheet" href="../css/admin_dashboard.css">
<style>
@keyframes flashGreen {
    0% { background-color: rgba(16, 185, 129, 0.45); }
    100% { background-color: transparent; }
}
.flash-highlight {
    animation: flashGreen 2.5s ease-out;
}
.status-legend-card {
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    border-color: #e2e8f0 !important;
}
.status-legend-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08) !important;
    border-color: #cbd5e1 !important;
}
.status-legend-card:hover .fa-angle-right {
    transform: translateX(3px);
    transition: transform 0.2s ease;
}
</style>

<!-- คอนเทนเนอร์หลักของหน้าแดชบอร์ด ควบคุมสถานะการโหลดด้วยคลาส is-loading / is-ready -->
<div class="container-fluid px-0 is-loading" id="dashboardContainer">

    <!-- ========================================================================= -->
    <!-- 1. ส่วนหัวของหน้า (Dashboard Header & Action Bar) -->
    <!-- ========================================================================= -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <h4 class="fw-bold text-dark mb-0">
                    <i class="fa-solid fa-network-wired text-primary me-2"></i>ศูนย์ควบคุมและบริหารจัดการระบบ
                </h4>
                <span class="badge bg-primary-subtle text-primary border px-2 py-1 small rounded-pill">
                    Enterprise Dashboard
                </span>
            </div>
            <p class="text-muted small mb-0">
                ภาพรวมสถานะครุภัณฑ์ การยืม-คืน และคำขอขึ้นทะเบียนในระบบ
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge glass-panel text-secondary px-3 py-2 rounded-pill">
                <i class="fa-regular fa-calendar-days text-primary me-1"></i> วันที่: <?php echo date('d/m/Y'); ?>
            </span>
            <?php if ($syncMeta): ?>
                <span class="badge bg-white text-muted border px-3 py-2 font-monospace shadow-xs" style="font-size: 0.8rem;" title="SHA-256: <?= htmlspecialchars($syncMeta['file_hash']) ?>">
                    <i class="fa-solid fa-database text-primary me-1"></i>ซิงค์: <?= date('d/m/Y H:i', strtotime($syncMeta['last_synced_at'])) ?>
                </span>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 shadow-sm" id="btnRefreshDashboard" title="โหลดข้อมูลล่าสุดทันที">
                <i class="fa-solid fa-arrows-rotate me-1" id="refreshIcon"></i> รีเฟรชข้อมูล
            </button>
            <?php if ($isSuperAdmin): ?>
                <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-bold shadow-sm" id="btnSyncDb" title="ซิงค์ข้อมูลล่าสุดจาก control/daam.sql สู่ SQLite">
                    <i class="fa-solid fa-rotate me-1"></i> ซิงค์ฐานข้อมูล
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- 3. ส่วนจัดการข้อผิดพลาด (Error State Container) -->
    <!-- ========================================================================= -->
    <div class="dash-error-state mb-4" id="dashboardErrorState">
        <div class="text-danger mb-2"><i class="fa-solid fa-triangle-exclamation fa-3x"></i></div>
        <h5 class="fw-bold text-dark mb-1">ไม่สามารถโหลดข้อมูลสถิติได้ในขณะนี้</h5>
        <p class="text-muted small mb-3" id="dashboardErrorMessage">เกิดปัญหาในการเชื่อมต่อกับเซิร์ฟเวอร์หรือฐานข้อมูล</p>
        <button type="button" class="btn btn-danger btn-sm rounded-pill px-4 shadow-sm" id="btnRetryFetch">
            <i class="fa-solid fa-rotate me-1"></i> ลองใหม่อีกครั้ง (Retry)
        </button>
    </div>

    <!-- ========================================================================= -->
    <!-- 4. แถบแจ้งเตือนด่วน (Dynamic Alerts Container) -->
    <!-- ========================================================================= -->
    <div id="dynamicAlertsContainer" class="row g-3 mb-4 d-none">
        <!-- Rendered dynamically via JavaScript -->
    </div>

    <!-- ========================================================================= -->
    <!-- 5. การ์ดสรุปข้อมูลสถิติ 4 ใบระดับพรีเมียม (Premium Enterprise Stat Cards) -->
    <!-- Liquid Grid: 1 คอลัมน์บนมือถือ, 2 คอลัมน์บนแท็บเล็ต, 4 คอลัมน์บน Desktop -->
    <!-- ========================================================================= -->
    <div class="row g-4 mb-4">
        
        <!-- การ์ดที่ 1: ครุภัณฑ์ทั้งหมด (สีน้ำเงิน Enterprise Blue) -->
        <div class="col-12 col-md-6 col-xl-3">
            <a href="manage_data.php?filter_visibility=<?= ($viewMode === 'hidden') ? 'hidden' : (($viewMode === 'all') ? 'all' : 'visible') ?>" class="stat-card-enterprise stat-card-blue h-100">
                <div class="content-skeleton">
                    <div class="skeleton-shimmer-card skeleton-text-sm mb-2"></div>
                    <div class="skeleton-shimmer-card skeleton-text-lg mb-2"></div>
                    <div class="skeleton-shimmer-card" style="height: 24px; width: 55%; border-radius: 9999px;"></div>
                </div>
                <div class="content-real position-relative z-1">
                    <span class="stat-label">ครุภัณฑ์ทั้งหมด (<?= ($viewMode === 'hidden') ? 'เฉพาะที่ซ่อน' : (($viewMode === 'all') ? 'รวมทั้งหมด' : 'ใช้งานปกติ') ?>)</span>
                    <div class="stat-number" id="metricTotalDA">0</div>
                    <span class="stat-badge">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> ดูรายการทั้งหมด <i class="fa-solid fa-angle-right ms-1"></i>
                    </span>
                </div>
                <i class="fa-solid fa-boxes-stacked watermark-icon"></i>
            </a>
        </div>

        <!-- การ์ดที่ 2: กำลังถูกยืม (สีเหลืองอำพัน Amber Warning) -> รีไดเร็กไปหน้า admin_history.php -->
        <div class="col-12 col-md-6 col-xl-3">
            <a href="admin_history.php" class="stat-card-enterprise stat-card-amber h-100">
                <div class="content-skeleton">
                    <div class="skeleton-shimmer-card skeleton-text-sm mb-2"></div>
                    <div class="skeleton-shimmer-card skeleton-text-lg mb-2"></div>
                    <div class="skeleton-shimmer-card" style="height: 24px; width: 50%; border-radius: 9999px;"></div>
                </div>
                <div class="content-real position-relative z-1">
                    <span class="stat-label">กำลังถูกยืมใช้งาน</span>
                    <div class="stat-number" id="metricBorrowedDA">0</div>
                    <span class="stat-badge">
                        <i class="fa-solid fa-clock-rotate-left me-1"></i> ประวัติการยืม-คืน <i class="fa-solid fa-angle-right ms-1"></i>
                    </span>
                </div>
                <i class="fa-solid fa-hand-holding-hand watermark-icon"></i>
            </a>
        </div>

        <!-- การ์ดที่ 3: เลยกำหนดส่งคืน (สีแดง Rose Red) -->
        <div class="col-12 col-md-6 col-xl-3">
            <a href="admin_history.php" class="stat-card-enterprise stat-card-red h-100">
                <div class="content-skeleton">
                    <div class="skeleton-shimmer-card skeleton-text-sm mb-2"></div>
                    <div class="skeleton-shimmer-card skeleton-text-lg mb-2"></div>
                    <div class="skeleton-shimmer-card" style="height: 24px; width: 55%; border-radius: 9999px;"></div>
                </div>
                <div class="content-real position-relative z-1">
                    <span class="stat-label">เลยกำหนดส่งคืน (Overdue)</span>
                    <div class="stat-number" id="metricOverdueDA">0</div>
                    <span class="stat-badge">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i> เกินกำหนดเวลา <i class="fa-solid fa-angle-right ms-1"></i>
                    </span>
                </div>
                <i class="fa-solid fa-circle-exclamation watermark-icon"></i>
            </a>
        </div>

        <!-- การ์ดที่ 4: คำขอรออนุมัติ (สีเขียวเอเมอรัลด์ Emerald Success) -->
        <div class="col-12 col-md-6 col-xl-3">
            <a href="approve_items.php" class="stat-card-enterprise stat-card-green h-100">
                <div class="content-skeleton">
                    <div class="skeleton-shimmer-card skeleton-text-sm mb-2"></div>
                    <div class="skeleton-shimmer-card skeleton-text-lg mb-2"></div>
                    <div class="skeleton-shimmer-card" style="height: 24px; width: 50%; border-radius: 9999px;"></div>
                </div>
                <div class="content-real position-relative z-1">
                    <span class="stat-label">คำขอรออนุมัติขึ้นทะเบียน</span>
                    <div class="stat-number" id="metricPendingDA">0</div>
                    <span class="stat-badge">
                        <i class="fa-solid fa-clipboard-check me-1"></i> รอการพิจารณา <i class="fa-solid fa-angle-right ms-1"></i>
                    </span>
                </div>
                <i class="fa-solid fa-file-circle-check watermark-icon"></i>
            </a>
        </div>

    </div>

    <!-- ========================================================================= -->
    <!-- 6. ส่วน CHARTS (กราฟโดนัท & กราฟแท่ง Chart.js พร้อม Skeleton Loaders) -->
    <!-- ========================================================================= -->
    <div class="row g-4 mb-4">
        
        <!-- Col-6 ด้านซ้าย: กราฟวงกลม (Doughnut Chart) แสดงสถานะครุภัณฑ์ -->
        <div class="col-lg-6">
            <div class="dashboard-card h-100 p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-chart-pie text-primary me-2"></i>สัดส่วนสถานะครุภัณฑ์ (Asset Status)
                        </h5>
                        <small class="text-muted">เปรียบเทียบสัดส่วน: พร้อมใช้งาน / กำลังถูกยืม / ชำรุดหรือไม่พร้อมใช้</small>
                    </div>
                    <a href="report_details.php?view_mode=<?= urlencode($viewMode) ?>" class="badge bg-primary-subtle text-primary border rounded-pill px-3 py-1 small text-decoration-none shadow-sm" title="คลิกดูรายงานสรุปสถานะครุภัณฑ์เชิงลึก">
                        <i class="fa-solid fa-arrow-pointer me-1"></i>คลิกดูรายงาน
                    </a>
                </div>
                
                <div class="content-skeleton">
                    <div class="skeleton-shimmer skeleton-chart-box"></div>
                </div>
                
                <div class="content-real pt-2">
                    <div class="row g-3 align-items-center">
                        <!-- ฝั่งซ้าย: กราฟโดนัท (Doughnut Chart) -->
                        <div class="col-sm-5 col-12 d-flex justify-content-center">
                            <div style="position: relative; height: 210px; width: 210px; max-width: 100%;">
                                <canvas id="statusDoughnutChart"></canvas>
                            </div>
                        </div>

                        <!-- ฝั่งขวา: การระบุสถานะสีและความหมาย พร้อมตัวเลขและเปอร์เซ็นต์ชัดเจน -->
                        <div class="col-sm-7 col-12">
                            <div class="d-flex flex-column gap-2">
                                <!-- 1. สีเขียว: พร้อมใช้งาน -->
                                <a href="report_details.php?filter_status=พร้อมใช้งาน&view_mode=<?= urlencode($viewMode) ?>" 
                                   class="status-legend-card text-decoration-none p-2 rounded-3 border bg-white shadow-xs d-block"
                                   title="คลิกเพื่อดูครุภัณฑ์ที่พร้อมใช้งาน">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="status-dot rounded-circle bg-success d-inline-block shadow-xs" style="width: 12px; height: 12px;"></span>
                                            <span class="fw-bold text-dark small">สีเขียว: พร้อมใช้งาน</span>
                                        </div>
                                        <div class="text-end">
                                            <span class="fw-bold text-success" id="statValAvailable">0</span>
                                            <small class="text-muted ms-1">(<span id="statPctAvailable" class="fw-semibold">0%</span>)</small>
                                        </div>
                                    </div>
                                    <div class="progress" style="height: 5px;">
                                        <div class="progress-bar bg-success" id="progAvailable" role="progressbar" style="width: 0%;"></div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                        <small class="text-muted" style="font-size: 0.72rem;">สภาพสมบูรณ์ พร้อมให้ยืม</small>
                                        <small class="text-success fw-semibold" style="font-size: 0.72rem;">ดูรายงาน <i class="fa-solid fa-angle-right"></i></small>
                                    </div>
                                </a>

                                <!-- 2. สีเหลือง: กำลังถูกยืม -->
                                <a href="admin_history.php" 
                                   class="status-legend-card text-decoration-none p-2 rounded-3 border bg-white shadow-xs d-block" 
                                   title="คลิกเพื่อไปหน้าประวัติการยืม-คืน">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="status-dot rounded-circle bg-warning d-inline-block shadow-xs" style="width: 12px; height: 12px;"></span>
                                            <span class="fw-bold text-dark small">สีเหลือง: กำลังถูกยืม</span>
                                        </div>
                                        <div class="text-end">
                                            <span class="fw-bold text-warning-emphasis" id="statValBorrowed">0</span>
                                            <small class="text-muted ms-1">(<span id="statPctBorrowed" class="fw-semibold">0%</span>)</small>
                                        </div>
                                    </div>
                                    <div class="progress" style="height: 5px;">
                                        <div class="progress-bar bg-warning" id="progBorrowed" role="progressbar" style="width: 0%;"></div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                        <small class="text-muted" style="font-size: 0.72rem;">อยู่ระหว่างการยืมใช้งาน</small>
                                        <small class="text-warning fw-semibold" style="font-size: 0.72rem;">ดูประวัติ <i class="fa-solid fa-angle-right"></i></small>
                                    </div>
                                </a>

                                <!-- 3. สีแดง: ชำรุด / ไม่พร้อมใช้ -->
                                <a href="report_details.php?filter_status=<?= urlencode('ชำรุด / ไม่พร้อมใช้') ?>&view_mode=<?= urlencode($viewMode) ?>" 
                                   class="status-legend-card text-decoration-none p-2 rounded-3 border bg-white shadow-xs d-block" 
                                   title="คลิกเพื่อดูครุภัณฑ์ชำรุดหรือไม่พร้อมใช้งาน">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="status-dot rounded-circle bg-danger d-inline-block shadow-xs" style="width: 12px; height: 12px;"></span>
                                            <span class="fw-bold text-dark small">สีแดง: ชำรุด / ไม่พร้อมใช้</span>
                                        </div>
                                        <div class="text-end">
                                            <span class="fw-bold text-danger" id="statValMaintenance">0</span>
                                            <small class="text-muted ms-1">(<span id="statPctMaintenance" class="fw-semibold">0%</span>)</small>
                                        </div>
                                    </div>
                                    <div class="progress" style="height: 5px;">
                                        <div class="progress-bar bg-danger" id="progMaintenance" role="progressbar" style="width: 0%;"></div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                        <small class="text-muted" style="font-size: 0.72rem;">ส่งซ่อม หรือระงับการใช้งาน</small>
                                        <small class="text-danger fw-semibold" style="font-size: 0.72rem;">ดูรายการ <i class="fa-solid fa-angle-right"></i></small>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Col-6 ด้านขวา: กราฟแท่ง (Bar Chart) แสดงจำนวนครุภัณฑ์แยกตามหมวดหมู่ -->
        <div class="col-lg-6">
            <div class="dashboard-card h-100 p-3 p-md-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-chart-simple text-success me-2"></i>จำนวนครุภัณฑ์ตามหมวดหมู่ (Category Distribution)
                        </h5>
                        <small class="text-muted">จำนวนครุภัณฑ์ในระบบจัดกลุ่มตาม 6 หมวดหมู่หลัก</small>
                    </div>
                    <a href="report_details.php?tab=analytics&view_mode=<?= urlencode($viewMode) ?>" class="badge bg-success-subtle text-success border rounded-pill px-3 py-1 small text-decoration-none shadow-sm" title="คลิกดูรายงานสถิติตามหมวดหมู่">
                        <i class="fa-solid fa-arrow-pointer me-1"></i>คลิกดูรายงาน
                    </a>
                </div>

                <div class="content-skeleton">
                    <div class="skeleton-shimmer skeleton-chart-box"></div>
                </div>

                <div class="content-real pt-2">
                    <div style="position: relative; height: 280px; width: 100%;">
                        <canvas id="categoryBarChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ========================================================================= -->
    <!-- 7. ส่วน RECENT ACTIVITIES & QUICK ACTIONS (แถวล่างสุด) -->
    <!-- ========================================================================= -->
    <div class="row g-4">
        
        <!-- Col-8: ตารางความเคลื่อนไหวล่าสุด (Recent Activities Table) -->
        <div class="col-lg-8">
            <div class="dashboard-card h-100">
                <div class="p-4 pb-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>ความเคลื่อนไหวล่าสุดในระบบ (Recent Activities)
                        </h5>
                        <small class="text-muted">บันทึกรายการยืมและคืนครุภัณฑ์ 5 รายการล่าสุด</small>
                    </div>
                    <a href="manage_data.php" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-xs">
                        ดูทั้งหมด <i class="fa-solid fa-arrow-right ms-1"></i>
                    </a>
                </div>

                <div class="p-4 pt-2">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle table-activity mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 45px;">#</th>
                                    <th>หมายเลขครุภัณฑ์</th>
                                    <th>รายการ</th>
                                    <th>ผู้ทำรายการ</th>
                                    <th>วันที่ยืม</th>
                                    <th class="text-center">สถานะ</th>
                                </tr>
                            </thead>
                            <tbody id="recentActivitiesTbody">
                                <!-- Skeleton Rows Placeholder ระหว่างรอ AJAX -->
                                <tr class="skeleton-row">
                                    <td><div class="skeleton-shimmer" style="height: 16px; width: 18px;"></div></td>
                                    <td><div class="skeleton-shimmer" style="height: 24px; width: 90px; border-radius: 6px;"></div></td>
                                    <td><div class="skeleton-shimmer" style="height: 18px; width: 160px;"></div></td>
                                    <td><div class="skeleton-shimmer" style="height: 18px; width: 100px;"></div></td>
                                    <td><div class="skeleton-shimmer" style="height: 16px; width: 75px;"></div></td>
                                    <td class="text-center"><div class="skeleton-shimmer" style="height: 24px; width: 70px; border-radius: 9999px;"></div></td>
                                </tr>
                                <tr class="skeleton-row">
                                    <td><div class="skeleton-shimmer" style="height: 16px; width: 18px;"></div></td>
                                    <td><div class="skeleton-shimmer" style="height: 24px; width: 90px; border-radius: 6px;"></div></td>
                                    <td><div class="skeleton-shimmer" style="height: 18px; width: 140px;"></div></td>
                                    <td><div class="skeleton-shimmer" style="height: 18px; width: 110px;"></div></td>
                                    <td><div class="skeleton-shimmer" style="height: 16px; width: 75px;"></div></td>
                                    <td class="text-center"><div class="skeleton-shimmer" style="height: 24px; width: 70px; border-radius: 9999px;"></div></td>
                                </tr>
                                <tr class="skeleton-row">
                                    <td><div class="skeleton-shimmer" style="height: 16px; width: 18px;"></div></td>
                                    <td><div class="skeleton-shimmer" style="height: 24px; width: 90px; border-radius: 6px;"></div></td>
                                    <td><div class="skeleton-shimmer" style="height: 18px; width: 170px;"></div></td>
                                    <td><div class="skeleton-shimmer" style="height: 18px; width: 95px;"></div></td>
                                    <td><div class="skeleton-shimmer" style="height: 16px; width: 75px;"></div></td>
                                    <td class="text-center"><div class="skeleton-shimmer" style="height: 24px; width: 70px; border-radius: 9999px;"></div></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Col-4: เมนูลัดการจัดการด่วน (Quick Actions) -->
        <div class="col-lg-4">
            <div class="dashboard-card h-100 p-4">
                <div class="mb-3">
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="fa-solid fa-bolt text-warning me-2"></i>เมนูลัดการจัดการ (Quick Actions)
                    </h5>
                    <small class="text-muted">เข้าถึงงานสำคัญและเครื่องมืออย่างรวดเร็ว</small>
                </div>

                <div class="py-1">
                    <!-- เมนูลัด 1: พิจารณาอนุมัติคำขอ -->
                    <a href="approve_items.php" class="quick-action-item">
                        <div class="d-flex align-items-center">
                            <div class="quick-action-icon-box bg-warning-subtle text-warning">
                                <i class="fa-solid fa-clipboard-check"></i>
                            </div>
                            <div>
                                <div class="text-dark fw-bold">พิจารณาอนุมัติคำขอ</div>
                                <small class="text-muted" style="font-size: 0.78rem;">คำขอขึ้นทะเบียนใหม่</small>
                            </div>
                        </div>
                        <span class="badge bg-danger rounded-pill d-none" id="quickPendingBadge">0 ใหม่</span>
                        <i class="fa-solid fa-chevron-right text-muted small" id="quickPendingArrow"></i>
                    </a>

                    <!-- เมนูลัด 2: พิมพ์สติกเกอร์ QR Code -->
                    <a href="print_qrcode.php" class="quick-action-item">
                        <div class="d-flex align-items-center">
                            <div class="quick-action-icon-box bg-info-subtle text-info">
                                <i class="fa-solid fa-qrcode"></i>
                            </div>
                            <div>
                                <div class="text-dark fw-bold">พิมพ์สติกเกอร์ QR Code</div>
                                <small class="text-muted" style="font-size: 0.78rem;">สร้าง QR Code ตามหมวดหมู่ (A4)</small>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted small"></i>
                    </a>

                    <!-- เมนูลัด 3: นำเข้าไฟล์ CSV -->
                    <a href="import_csv.php" class="quick-action-item">
                        <div class="d-flex align-items-center">
                            <div class="quick-action-icon-box bg-success-subtle text-success">
                                <i class="fa-solid fa-file-csv"></i>
                            </div>
                            <div>
                                <div class="text-dark fw-bold">นำเข้าข้อมูลครุภัณฑ์ (CSV)</div>
                                <small class="text-muted" style="font-size: 0.78rem;">เพิ่มข้อมูลแบบกลุ่ม Batch</small>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted small"></i>
                    </a>

                    <!-- เมนูลัด 4: จัดการข้อมูลครุภัณฑ์ -->
                    <a href="manage_data.php" class="quick-action-item">
                        <div class="d-flex align-items-center">
                            <div class="quick-action-icon-box bg-primary-subtle text-primary">
                                <i class="fa-solid fa-boxes-stacked"></i>
                            </div>
                            <div>
                                <div class="text-dark fw-bold">จัดการข้อมูลครุภัณฑ์</div>
                                <small class="text-muted" style="font-size: 0.78rem;">ค้นหา แก้ไข สถานะ ซ่อน/แสดงผล</small>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted small"></i>
                    </a>

                    <!-- เมนูลัด 5: จัดการผู้ใช้งาน -->
                    <a href="manage_users.php" class="quick-action-item">
                        <div class="d-flex align-items-center">
                            <div class="quick-action-icon-box bg-purple-subtle text-purple" style="background-color: #f3e8ff; color: #7e22ce;">
                                <i class="fa-solid fa-users-gear"></i>
                            </div>
                            <div>
                                <div class="text-dark fw-bold">จัดการผู้ใช้งานระบบ</div>
                                <small class="text-muted" style="font-size: 0.78rem;">สิทธิ์ สังกัดแผนก บัญชีสมาชิก</small>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-muted small"></i>
                    </a>

                    <!-- แถบข้อมูลสนับสนุนความปลอดภัย -->
                    <div class="glass-panel p-3 mt-3 border d-flex align-items-center gap-2">
                        <i class="fa-solid fa-shield-halved text-success fs-5"></i>
                        <span class="text-secondary small">ระบบสิทธิ์ Multi-Tenant ป้องกันการเข้าถึงข้อมูลข้ามแผนก</span>
                    </div>

                </div>
            </div>
        </div>

    </div>

</div>

<!-- Scripts: Chart.js สำหรับการเรนเดอร์กราฟ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
/**
 * =============================================================================
 * สคริปต์สถาปัตยกรรม Non-blocking Asynchronous Dashboard Loader
 * อธิบายเทคนิคสำคัญ:
 * 1. Skeleton Loading: คอนเทนเนอร์หลักครอบด้วยคลาส .is-loading ซึ่งจะแสดง
 *    กรอบเงา Shimmer สีเทาเพื่อให้ผู้ใช้สัมผัสถึงความเร็ว (Fast Perceived Speed)
 * 2. AJAX Fetching: ใช้ fetch() ยิงไปยัง api_dashboard_data.php โดยไม่บล็อก
 *    การแสดงผลของหน้าเว็บหลัก
 * 3. CountUp Animation: เมื่อตัวเลขมาถึง จะมีแอนิเมชันนับเลขขึ้นอย่างสมูท
 * 4. Error Handling: หากหลังบ้านสะดุด จะแสดงกล่องข้อความและปุ่ม Retry ชัดเจน
 * =============================================================================
 */

document.addEventListener('DOMContentLoaded', function() {
    
    let currentViewMode = <?= json_encode($viewMode) ?>;
    const dashboardContainer = document.getElementById('dashboardContainer');
    const errorState = document.getElementById('dashboardErrorState');
    const errorMessage = document.getElementById('dashboardErrorMessage');
    const refreshBtn = document.getElementById('btnRefreshDashboard');
    const refreshIcon = document.getElementById('refreshIcon');
    const retryBtn = document.getElementById('btnRetryFetch');

    // เก็บอ็อบเจกต์ Chart ไว้ เพื่อสั่ง destroy() หรือ update() ป้องกัน Canvas ซ้ำ
    let statusDoughnutChart = null;
    let categoryBarChart = null;
    let lastSeenTopActivityId = 0;
    let lastRenderedActivityHash = '';

    /**
     * ฟังก์ชันนับตัวเลขแบบนุ่มนวล (CountUp Animation) จากค่าปัจจุบันไปยังค่าเป้าหมาย
     * @param {HTMLElement} element อิลิเมนต์เป้าหมาย
     * @param {number} target ค่าตัวเลขปลายทาง
     * @param {number} duration ระยะเวลาแอนิเมชัน (ms)
     */
    function animateCountUp(element, target, duration = 600) {
        if (!element) return;
        target = Number(target) || 0;
        const currentVal = parseInt((element.textContent || '0').replace(/,/g, ''), 10) || 0;
        if (currentVal === target) {
            element.textContent = target.toLocaleString();
            return;
        }

        const startTime = performance.now();
        function frame(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            // Easing Function: easeOutCubic
            const ease = 1 - Math.pow(1 - progress, 3);
            const current = Math.floor(currentVal + (target - currentVal) * ease);
            element.textContent = current.toLocaleString();

            if (progress < 1) {
                requestAnimationFrame(frame);
            } else {
                element.textContent = target.toLocaleString();
            }
        }
        requestAnimationFrame(frame);
    }

    /**
     * ฟังก์ชันปรับปรุงข้อมูลแดชบอร์ดทั้งหมด (Single UI Updater สำหรับทั้ง REST และ SSE)
     */
    function applyDashboardData(data) {
        if (!data) return;
        const stats = data.stats || {};
        const charts = data.charts || {};
        const activities = data.recentActivities || [];

        // 1. อัปเดตตัวเลขในการ์ดสรุปผล 4 ใบ พร้อม Smooth CountUp
        animateCountUp(document.getElementById('metricTotalDA'), stats.totalDA);
        animateCountUp(document.getElementById('metricBorrowedDA'), stats.borrowedDA);
        animateCountUp(document.getElementById('metricOverdueDA'), stats.overdueDA);
        animateCountUp(document.getElementById('metricPendingDA'), stats.pendingDA);

        // 2. อัปเดตตัวเลขและเปอร์เซ็นต์ในกล่องแสดงสถานะสี (Status Legend Cards)
        const total = Number(stats.totalDA || 0);
        const avail = Number(stats.availableDA || 0);
        const borrow = Number(stats.borrowedDA || 0);
        const maint = Number(stats.maintenanceDA || 0);

        const pctAvail = total > 0 ? ((avail / total) * 100).toFixed(1) : '0';
        const pctBorrow = total > 0 ? ((borrow / total) * 100).toFixed(1) : '0';
        const pctMaint = total > 0 ? ((maint / total) * 100).toFixed(1) : '0';

        const elValAvail = document.getElementById('statValAvailable');
        const elPctAvail = document.getElementById('statPctAvailable');
        const elProgAvail = document.getElementById('progAvailable');
        if (elValAvail) animateCountUp(elValAvail, avail);
        if (elPctAvail) elPctAvail.textContent = pctAvail + '%';
        if (elProgAvail) elProgAvail.style.width = pctAvail + '%';

        const elValBorrow = document.getElementById('statValBorrowed');
        const elPctBorrow = document.getElementById('statPctBorrowed');
        const elProgBorrow = document.getElementById('progBorrowed');
        if (elValBorrow) animateCountUp(elValBorrow, borrow);
        if (elPctBorrow) elPctBorrow.textContent = pctBorrow + '%';
        if (elProgBorrow) elProgBorrow.style.width = Math.max(borrow > 0 ? 3 : 0, parseFloat(pctBorrow)) + '%';

        const elValMaint = document.getElementById('statValMaintenance');
        const elPctMaint = document.getElementById('statPctMaintenance');
        const elProgMaint = document.getElementById('progMaintenance');
        if (elValMaint) animateCountUp(elValMaint, maint);
        if (elPctMaint) elPctMaint.textContent = pctMaint + '%';
        if (elProgMaint) elProgMaint.style.width = Math.max(maint > 0 ? 3 : 0, parseFloat(pctMaint)) + '%';

        // 3. อัปเดต Badge แจ้งเตือนเมนูลัด Quick Actions
        const quickBadge = document.getElementById('quickPendingBadge');
        const quickArrow = document.getElementById('quickPendingArrow');
        if (stats.pendingDA > 0) {
            if (quickBadge) {
                quickBadge.textContent = `${stats.pendingDA} ใหม่`;
                quickBadge.classList.remove('d-none');
            }
            if (quickArrow) quickArrow.classList.add('d-none');
        } else {
            if (quickBadge) quickBadge.classList.add('d-none');
            if (quickArrow) quickArrow.classList.remove('d-none');
        }

        // 4. แสดงแถบ Alert แจ้งเตือนด่วนหากมีงานค้าง
        renderUrgentAlerts(stats);

        // 5. เรนเดอร์กราฟโดนัทสถานะ (Doughnut Chart)
        renderDoughnutChart(charts.status);

        // 6. เรนเดอร์กราฟแท่งหมวดหมู่ (Bar Chart)
        renderBarChart(charts.category);

        // 7. เติมข้อมูลแถวในตารางความเคลื่อนไหวล่าสุด (Recent Activities Table)
        renderRecentActivities(activities);

        // 8. สลับสถานะเป็น Ready: ซ่อน Skeleton และแสดง Real Data อย่างนุ่มนวล
        dashboardContainer.classList.remove('is-loading');
        dashboardContainer.classList.add('is-ready');
    }

    /**
     * ฟังก์ชันหลักในการดึงข้อมูลจาก API แบบ Asynchronous (Fallback & Manual Refresh)
     */
    async function loadDashboardData() {
        dashboardContainer.classList.remove('is-ready');
        dashboardContainer.classList.add('is-loading');
        errorState.classList.remove('show');
        if (refreshIcon) refreshIcon.classList.add('fa-spin');

        try {
            const response = await fetch(`api_dashboard_data.php?view_mode=${encodeURIComponent(currentViewMode)}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP Error ${response.status}: เซิร์ฟเวอร์ตอบสนองผิดพลาด`);
            }

            const result = await response.json();

            if (result.status !== 'success' || !result.data) {
                throw new Error(result.message || 'โครงสร้างข้อมูลจากเซิร์ฟเวอร์ไม่ถูกต้อง');
            }

            applyDashboardData(result.data);

        } catch (error) {
            console.error('[Dashboard Telemetry Error]:', error);
            dashboardContainer.classList.remove('is-ready');
            dashboardContainer.classList.add('is-loading');
            if (errorMessage) {
                errorMessage.textContent = error.message || 'ไม่สามารถติดต่อเซิร์ฟเวอร์เพื่อโหลดข้อมูลได้';
            }
            errorState.classList.add('show');
        } finally {
            if (refreshIcon) refreshIcon.classList.remove('fa-spin');
        }
    }

    /**
     * แสดง Alert เตือนด่วนกรณีมีคำขอรออนุมัติหรือมีของเลยกำหนด
     */
    function renderUrgentAlerts(stats) {
        const container = document.getElementById('dynamicAlertsContainer');
        if (!container) return;

        const pending = Number(stats.pendingDA) || 0;
        const overdue = Number(stats.overdueDA) || 0;

        if (pending === 0 && overdue === 0) {
            container.classList.add('d-none');
            container.innerHTML = '';
            return;
        }

        let html = '';
        const colClass = (pending > 0 && overdue > 0) ? 'col-md-6' : 'col-12';

        if (pending > 0) {
            html += `
                <div class="${colClass}">
                    <div class="alert alert-warning border-0 shadow-sm rounded-4 d-flex justify-content-between align-items-center p-3 mb-0">
                        <div class="d-flex align-items-center">
                            <div class="p-2 bg-warning-subtle text-dark rounded-circle me-3"><i class="fa-solid fa-bell fa-lg"></i></div>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">มีคำขอเสนอขึ้นทะเบียนรอการอนุมัติ ${pending.toLocaleString()} รายการ!</h6>
                                <small class="text-muted">กรุณาตรวจสอบเพื่อเปิดให้ครุภัณฑ์พร้อมใช้งาน</small>
                            </div>
                        </div>
                        <a href="approve_items.php" class="btn btn-dark btn-sm rounded-pill px-3 fw-semibold">
                            ไปหน้าอนุมัติ <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            `;
        }

        if (overdue > 0) {
            html += `
                <div class="${colClass}">
                    <div class="alert alert-danger border-0 shadow-sm rounded-4 d-flex justify-content-between align-items-center p-3 mb-0">
                        <div class="d-flex align-items-center">
                            <div class="p-2 bg-danger-subtle text-danger rounded-circle me-3"><i class="fa-solid fa-triangle-exclamation fa-lg"></i></div>
                            <div>
                                <h6 class="fw-bold mb-0 text-danger">มีครุภัณฑ์เลยกำหนดส่งคืน ${overdue.toLocaleString()} รายการ!</h6>
                                <small class="text-muted">ตรวจพบรายการยืมที่ยังไม่คืนและเลยกำหนดเวลา</small>
                            </div>
                        </div>
                        <a href="admin_history.php" class="btn btn-danger btn-sm rounded-pill px-3 fw-semibold">
                            ตรวจสอบ & แจ้งเตือน <i class="fa-solid fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            `;
        }

        container.innerHTML = html;
        container.classList.remove('d-none');
    }

    /**
     * ฟังก์ชันคำนวณสัดส่วนการวาด Doughnut Chart ให้เหมาะสมตามเปอร์เซ็นต์จริง (Visual Arc Balance)
     * หากสถานะใดมีจำนวน > 0 รายการ จะรักษาส่วนโค้งขั้นต่ำ ~3.5% เพื่อให้เห็นเป็นแถบสีบนวงกลมชัดเจน ไม่ถูกเส้นขอบกลืนหาย
     * ขณะที่สถานะส่วนใหญ่ (เช่น พร้อมใช้งาน 99%+) จะครองพื้นที่กว่า 93% ตามสัดส่วนเปอร์เซ็นต์จริงอย่างสมดุล
     */
    function calculateVisualSlices(rawValues, minPercent = 0.035) {
        const totalRaw = rawValues.reduce((a, b) => a + b, 0);
        if (totalRaw === 0) return [0, 0, 0];
        
        const nonZeroCount = rawValues.filter(v => v > 0).length;
        if (nonZeroCount <= 1) return [...rawValues];
        
        let allocatedPercent = 0;
        let remainingRawSum = 0;
        const isSmall = rawValues.map(v => {
            if (v <= 0) return false;
            const p = v / totalRaw;
            if (p < minPercent) {
                allocatedPercent += minPercent;
                return true;
            }
            remainingRawSum += v;
            return false;
        });
        
        const remainingPercent = Math.max(0.1, 1.0 - allocatedPercent);
        
        return rawValues.map((v, i) => {
            if (v <= 0) return 0;
            if (isSmall[i]) return minPercent * totalRaw;
            return (v / remainingRawSum) * remainingPercent * totalRaw;
        });
    }

    /**
     * เรนเดอร์กราฟวงกลม Doughnut Chart พร้อม Drill-down
     */
    function renderDoughnutChart(chartData) {
        const canvas = document.getElementById('statusDoughnutChart');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');

        const labels = chartData?.labels || ['พร้อมใช้งาน', 'กำลังถูกยืม', 'ชำรุด / ไม่พร้อมใช้'];
        const rawValues = chartData?.values || [0, 0, 0];
        const visualValues = calculateVisualSlices(rawValues, 0.035);

        if (statusDoughnutChart) {
            statusDoughnutChart.rawValues = rawValues;
            statusDoughnutChart.data.labels = labels;
            statusDoughnutChart.data.datasets[0].data = visualValues;
            statusDoughnutChart.update();
            return;
        }

        const centerTextPlugin = {
            id: 'statusCenterText',
            beforeDraw: function(chart) {
                if (chart.config.type !== 'doughnut') return;
                const ctx = chart.ctx;
                const raw = chart.rawValues || rawValues || [];
                const total = raw.reduce((a, b) => a + b, 0);
                
                const chartArea = chart.chartArea;
                if (!chartArea) return;
                const centerX = (chartArea.left + chartArea.right) / 2;
                const centerY = (chartArea.top + chartArea.bottom) / 2;
                
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                
                // จำนวนทั้งหมด
                ctx.font = "700 20px 'Prompt', 'Sarabun', sans-serif";
                ctx.fillStyle = '#1e293b';
                ctx.fillText(total.toLocaleString(), centerX, centerY - 9);
                
                // ป้ายกำกับ
                ctx.font = "500 11px 'Prompt', 'Sarabun', sans-serif";
                ctx.fillStyle = '#64748b';
                ctx.fillText('ครุภัณฑ์ทั้งหมด', centerX, centerY + 13);
                
                ctx.restore();
            }
        };

        statusDoughnutChart = new Chart(ctx, {
            type: 'doughnut',
            plugins: [centerTextPlugin],
            data: {
                labels: labels,
                datasets: [{
                    data: visualValues,
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    hoverBackgroundColor: ['#059669', '#d97706', '#dc2626'],
                    borderWidth: 1.5,
                    borderColor: '#ffffff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                onClick: (event, activeElements, chart) => {
                    if (activeElements && activeElements.length > 0) {
                        const index = activeElements[0].index;
                        const statusLabel = chart.data.labels[index];
                        if (statusLabel) {
                            if (statusLabel.includes('ยืม')) {
                                window.location.href = 'admin_history.php';
                            } else {
                                window.location.href = `report_details.php?filter_status=${encodeURIComponent(statusLabel)}&view_mode=${encodeURIComponent(currentViewMode)}`;
                            }
                        }
                    }
                },
                onHover: (event, chartElement) => {
                    event.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const index = context.dataIndex;
                                const colorTitles = ['🟢 สีเขียว (พร้อมใช้งาน)', '🟡 สีเหลือง (กำลังถูกยืม)', '🔴 สีแดง (ชำรุด / ไม่พร้อมใช้)'];
                                const label = colorTitles[index] || labels[index] || '';
                                const raw = (statusDoughnutChart?.rawValues || rawValues)[index] || 0;
                                const total = (statusDoughnutChart?.rawValues || rawValues).reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((raw / total) * 100).toFixed(1) : 0;
                                const actionText = index === 1 ? 'คลิกดูประวัติการยืม-คืน' : 'คลิกดูรายงาน';
                                return ` ${label}: ${raw.toLocaleString()} รายการ (${pct}%) - ${actionText}`;
                            }
                        }
                    }
                }
            }
        });
        statusDoughnutChart.rawValues = rawValues;
    }

    /**
     * เรนเดอร์กราฟแท่ง Bar Chart พร้อม Drill-down
     */
    function renderBarChart(chartData) {
        const canvas = document.getElementById('categoryBarChart');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');

        const labels = chartData?.labels || [];
        const values = chartData?.values || [];

        if (categoryBarChart) {
            categoryBarChart.data.labels = labels;
            categoryBarChart.data.datasets[0].data = values;
            categoryBarChart.update();
            return;
        }

        categoryBarChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'จำนวนครุภัณฑ์ (ชิ้น)',
                    data: values,
                    backgroundColor: 'rgba(30, 60, 114, 0.85)',
                    hoverBackgroundColor: '#1e3c72',
                    borderRadius: 8,
                    barPercentage: 0.65
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                onClick: (event, activeElements, chart) => {
                    if (activeElements && activeElements.length > 0) {
                        const index = activeElements[0].index;
                        const catLabel = chart.data.labels[index];
                        if (catLabel) {
                            window.location.href = `report_details.php?filter_category=${encodeURIComponent(catLabel)}&view_mode=${encodeURIComponent(currentViewMode)}`;
                        }
                    }
                },
                onHover: (event, chartElement) => {
                    event.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return ` จำนวน: ${context.parsed.y.toLocaleString()} รายการ (คลิกดูรายงาน)`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { family: "'Prompt', 'Sarabun', sans-serif", size: 11 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 0, 0, 0.05)' },
                        ticks: {
                            font: { family: "'Prompt', 'Sarabun', sans-serif", size: 11 },
                            callback: function(val) { return val.toLocaleString(); }
                        }
                    }
                }
            }
        });
    }

    /**
     * เติมแถวในตารางความเคลื่อนไหวล่าสุด (พร้อม Flash Highlight สำหรับรายการใหม่ที่เข้ามาแบบ Real-time)
     */
    function renderRecentActivities(activities) {
        const tbody = document.getElementById('recentActivitiesTbody');
        if (!tbody) return;

        // [SECURITY & PERFORMANCE PATCH: SSE Memory Leak Prevention]
        // ตรวจสอบความเปลี่ยนแปลงของข้อมูลกิจกรรมผ่าน JSON Hash หากไม่มีการเปลี่ยนแปลงให้ข้ามการวาด DOM ทันที
        const currentHash = JSON.stringify(activities || []);
        if (currentHash === lastRenderedActivityHash) {
            return; // ประหยัด RAM และป้องกัน DOM Thrashing จากการเชื่อมต่อ SSE ตลอด 24 ชม.
        }
        lastRenderedActivityHash = currentHash;

        if (!activities || activities.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        <i class="fa-regular fa-folder-open fa-2x mb-2 d-block opacity-50"></i>
                        ยังไม่มีประวัติการยืม-คืนในรอบนี้
                    </td>
                </tr>
            `;
            return;
        }

        const currentTopId = activities[0]?.IDborrow_back || '';
        const isFirstRun = (!lastSeenTopActivityId);

        let html = '';
        activities.forEach((item, index) => {
            let statusBadge = '';
            if (item.isReturned) {
                statusBadge = '<span class="badge bg-success-subtle text-success rounded-pill px-3 py-1"><i class="fa-solid fa-check me-1"></i> คืนแล้ว</span>';
            } else if (item.isPendingReturn) {
                statusBadge = '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning rounded-pill px-3 py-1"><i class="fa-solid fa-hourglass-half me-1"></i> รออนุมัติคืน</span>';
            } else if (item.isOverdue) {
                statusBadge = '<span class="badge bg-danger-subtle text-danger rounded-pill px-3 py-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> เลยกำหนด</span>';
            } else {
                statusBadge = '<span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1"><i class="fa-solid fa-clock me-1"></i> กำลังยืม</span>';
            }

            const isNewRow = (!isFirstRun && item.IDborrow_back > lastSeenTopActivityId);
            const rowClass = isNewRow ? 'flash-highlight' : '';

            html += `
                <tr class="${rowClass}">
                    <td class="text-muted fw-semibold">${index + 1}</td>
                    <td>
                        <span class="badge bg-light text-dark border font-monospace">
                            ${escapeHtml(item.DA_Number)}
                        </span>
                    </td>
                    <td>
                        <div class="fw-semibold text-secondary text-truncate" style="max-width: 220px;" title="${escapeHtml(item.DA_ListName)}">
                            ${escapeHtml(item.DA_ListName)}
                        </div>
                    </td>
                    <td>
                        <small class="fw-bold text-dark">
                            <i class="fa-solid fa-user me-1 text-muted"></i>
                            ${escapeHtml(item.Name_borrow)}
                        </small>
                    </td>
                    <td><small class="text-muted">${escapeHtml(item.Date_borrow)}</small></td>
                    <td class="text-center">${statusBadge}</td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
        lastSeenTopActivityId = currentTopId;
    }

    // ฟังก์ชัน Escape HTML เพื่อความปลอดภัย (XSS Prevention)
    function escapeHtml(text) {
        if (!text) return '-';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ผูก Event ปุ่มรีเฟรช และปุ่ม Retry
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => loadDashboardData());
    }
    if (retryBtn) {
        retryBtn.addEventListener('click', () => loadDashboardData());
    }

    // เริ่มต้นดึงข้อมูลแดชบอร์ดทันทีเมื่อเปิดหน้า (Direct Asynchronous Fetch)
    loadDashboardData();

    // รองรับปุ่ม Back / Forward ของเบราว์เซอร์
    window.addEventListener('popstate', function() {
        loadDashboardData();
    });

    // =========================================================================
    // ฟังก์ชันการซิงค์ฐานข้อมูล (Database Sync Trigger via SweetAlert2)
    // =========================================================================
    const btnSyncDb = document.getElementById('btnSyncDb');
    if (btnSyncDb) {
        btnSyncDb.addEventListener('click', function(e) {
            e.preventDefault();
            Swal.fire({
                title: 'ซิงค์ฐานข้อมูล MySQL -> SQLite?',
                html: '<p class="text-secondary small mb-0">ระบบจะตรวจสอบและนำเข้าข้อมูลล่าสุดจาก <code>control/daam.sql</code> เข้าสู่ SQLite พร้อมคัดกรองซ่อนครุภัณฑ์อาคารเรียนรวม (CLB) อัตโนมัติ</p>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#1e3c72',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fa-solid fa-rotate me-1"></i> เริ่มซิงค์ทันที',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'กำลังซิงโครไนซ์ข้อมูล...',
                        html: 'กรุณารอสักครู่ ระบบกำลังอ่าน Schema และประมวลผล Data Masking...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    fetch('sync_now.php')
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'success') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'ซิงโครไนซ์สำเร็จ!',
                                    html: `
                                        <div class="text-start p-3 bg-light rounded-3 font-monospace small border">
                                            <div class="text-success fw-bold mb-2"><i class="fa-solid fa-circle-check me-1"></i>${data.message}</div>
                                            ${data.summary ? `
                                                <div>• ครุภัณฑ์ทั้งหมด: <b>${Number(data.summary.total_records).toLocaleString()}</b> รายการ</div>
                                                <div class="text-warning">• ซ่อนอาคารเรียนรวม/CLB: <b>${Number(data.summary.hidden_records).toLocaleString()}</b> รายการ</div>
                                                <div class="text-primary">• ใช้งานปกติ: <b>${Number(data.summary.visible_records).toLocaleString()}</b> รายการ</div>
                                                <div class="text-muted mt-1">• ระยะเวลาประมวลผล: ${data.elapsed_seconds} วินาที</div>
                                            ` : ''}
                                        </div>
                                    `,
                                    confirmButtonText: 'ตกลง'
                                }).then(() => {
                                    loadDashboardData();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'เกิดข้อผิดพลาดในการซิงค์',
                                    text: data.message || 'ไม่สามารถดำเนินการซิงค์ข้อมูลได้'
                                });
                            }
                        })
                        .catch(err => {
                            Swal.fire({
                                icon: 'error',
                                title: 'เกิดข้อผิดพลาดในการเชื่อมต่อ',
                                text: err.message
                            });
                        });
                }
            });
        });
    }

});
</script>

<?php
// โหลดส่วนท้าย Layout หลัก (Scripts & Sticky Footer)
require_once __DIR__ . '/../component/admin_footer.php';
?>
