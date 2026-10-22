<?php
/**
 * component/admin_sidebar.php - ส่วนเมนูข้างของระบบ Admin (Desktop Sidebar & Mobile Offcanvas Drawer)
 * ออกแบบตาม Requirement Enterprise UI/UX:
 * 1. Unified User Profile & Logout อยู่ที่การ์ดล่างสุดของ Sidebar เท่านั้น
 * 2. Fallback แผนก: ถ้าไม่มีค่า/เป็น NULL ให้แสดงคำว่า "ไม่ระบุ"
 * 3. ป้าย Super Admin แสดงเพียงที่เดียวภายในการ์ดด้านล่างนี้
 * 4. Full Height 100vh ไม่มีช่องว่างสีขาวด้านล่าง (Fix gap issue)
 */

// ตรวจสอบชื่อไฟล์ปัจจุบันเพื่อ Hilight เมนูที่ Active อัตโนมัติ
$currentScript = basename($_SERVER['PHP_SELF']);

// =============================================================================
// [PHP LOGIC: ดึงข้อมูลชื่อผู้ใช้และตรวจสอบสิทธิ์ Super Admin]
// =============================================================================
$displayUsername = $_SESSION['username'] 
                ?? $_SESSION['Sename'] 
                ?? 'User';

// ตรวจสอบสถานะ Super Admin
$isSuperAdmin = (($_SESSION['useraccountType'] ?? '') === 'Super Admin' || ($_SESSION['SeUser'] ?? '') === 'admin');

// ดึงยอดแจ้งเตือนคำขอรออนุมัติ และ ครุภัณฑ์เลยกำหนดส่งคืน
$pendingCount = 0;
$overdueCount = 0;

try {
    // 1. คำขอรออนุมัติ
    $stmtP = $pdo->query("SELECT COUNT(*) FROM durable_articles WHERE is_approved = 0");
    $pendingCount = (int)$stmtP->fetchColumn();

    // 2. ครุภัณฑ์เลยกำหนดส่งคืน
    $todayDate = date('Y-m-d');
    $stmtO = $pdo->prepare("SELECT COUNT(*) FROM borrow_back WHERE (Date_back IS NULL OR Date_back = '') AND Deadline_borrow < :today");
    $stmtO->execute([':today' => $todayDate]);
    $overdueCount = (int)$stmtO->fetchColumn();
} catch (PDOException $e) {
    // กรณีตารางยังไม่พร้อม
}

/**
 * ฟังก์ชันสร้างรายการเมนูหลัก (นำเมนูออกจากระบบแบบเก่าออกทั้งหมดแล้ว)
 */
function renderSidebarNavItems($currentScript, $pendingCount, $overdueCount, $isSuperAdmin) {
    $navs = [
        ['url' => 'main.php', 'icon' => 'fa-house', 'label' => 'หน้าแรก (Dashboard)', 'badge' => ''],
        ['url' => 'manage_data.php', 'icon' => 'fa-boxes-stacked', 'label' => 'จัดการครุภัณฑ์', 'badge' => ''],
        ['url' => 'manage_users.php', 'icon' => 'fa-users-gear', 'label' => 'จัดการข้อมูลผู้ใช้', 'badge' => ''],
        ['url' => 'print_qrcode.php', 'icon' => 'fa-qrcode', 'label' => 'พิมพ์ QR Code (A4)', 'badge' => ''],
        ['url' => 'approve_items.php', 'icon' => 'fa-clipboard-check', 'label' => 'อนุมัติคำขอขึ้นทะเบียน', 'badge' => ($pendingCount > 0) ? '<span class="badge bg-danger rounded-pill">' . $pendingCount . '</span>' : ''],
        ['url' => 'admin_history.php', 'icon' => 'fa-clock-rotate-left', 'label' => 'แจ้งเตือนคืนครุภัณฑ์', 'badge' => ($overdueCount > 0) ? '<span class="badge bg-danger rounded-pill">' . $overdueCount . '</span>' : ''],
        ['url' => 'import_csv.php', 'icon' => 'fa-file-csv', 'label' => 'นำเข้าข้อมูล (CSV)', 'badge' => ''],
        ['url' => 'page_Repair_AddEdit.php', 'icon' => 'fa-wrench', 'label' => 'บันทึกซ่อมบำรุง', 'badge' => ''],
        ['url' => 'report_details.php', 'icon' => 'fa-chart-pie', 'label' => 'รายงานและสถิติ', 'badge' => ''],
    ];

    if ($isSuperAdmin) {
        $navs[] = ['url' => 'settings_mail.php', 'icon' => 'fa-envelope-gear', 'label' => 'ตั้งค่าอีเมลแจ้งเตือน', 'badge' => ''];
    }

    echo '<ul class="sidebar-nav">';
    echo '<li class="nav-header">เมนูหลัก</li>';
    foreach ($navs as $n) {
        $isActive = ($currentScript === $n['url']) ? 'active' : '';
        echo '<li>';
        echo '<a href="' . $n['url'] . '" class="nav-link ' . $isActive . '">';
        echo '  <span class="nav-left"><i class="fa-solid ' . $n['icon'] . '"></i> ' . htmlspecialchars($n['label']) . '</span>';
        if (!empty($n['badge'])) {
            echo '  <span>' . $n['badge'] . '</span>';
        }
        echo '</a>';
        echo '</li>';
    }
    echo '</ul>';
}

/**
 * ฟังก์ชันสร้างแถบการ์ดข้อมูลผู้ใช้และปุ่มออกจากระบบ (Unified User Profile & Logout)
 * จัดวางไว้ด้านล่างสุดของ Sidebar (Fixed Bottom / mt-auto) ขอบโค้งมน กลมกลืนกับพื้นหลัง
 * ปรับปรุงการ์ดตาม Requirement: 2 บรรทัดกระชับ ตัดชื่อแผนกออกโดยสิ้นเชิง และปุ่ม Power Logout
 */
function renderSidebarUserCard($displayUsername, $isSuperAdmin) {
?>
    <!-- แถบการ์ดข้อมูลผู้ใช้ด้านล่างสุดของ Sidebar (Fixed Bottom / mt-auto & flex-shrink-0) -->
    <div class="sidebar-user-card px-3 py-2 mx-3 mb-3">
        <div class="d-flex align-items-center justify-content-between">
            
            <!-- ฝั่งซ้าย: รูปโปรไฟล์ (Avatar) วงกลม มีจุดไฟสถานะออนไลน์สีเขียว (🟢 active dot) ที่มุมรูป -->
            <div class="avatar-circle position-relative me-2.5 flex-shrink-0">
                <svg class="avatar-svg" viewBox="0 0 40 40" width="38" height="38" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="20" cy="20" r="19" fill="#f1f5f9"/>
                    <!-- Neck -->
                    <rect x="17.5" y="19" width="5" height="7" fill="#fbd38d"/>
                    <!-- Suit Jacket / Torso -->
                    <path d="M7 36 C7 28 13 25 20 25 C27 25 33 28 33 36 Z" fill="#1e293b"/>
                    <!-- Shirt Collar -->
                    <polygon points="17,25 20,31 23,25" fill="#ffffff"/>
                    <!-- Red Tie -->
                    <polygon points="19,26.5 21,26.5 21.8,31 20,36 18.2,31" fill="#ef4444"/>
                    <!-- Head -->
                    <ellipse cx="20" cy="15" rx="6.5" ry="7.5" fill="#fbd38d"/>
                    <!-- Ears -->
                    <circle cx="13.5" cy="15" r="1.5" fill="#fbd38d"/>
                    <circle cx="26.5" cy="15" r="1.5" fill="#fbd38d"/>
                    <!-- Hair -->
                    <path d="M13.5 14.5 C13 10 16 7 20 7 C24 7 27 10 26.5 14.5 C25.5 11.5 23.5 10 20 10.5 C16.5 10 14.5 11.5 13.5 14.5 Z" fill="#334155"/>
                    <path d="M16 9.5 C18.5 8.5 21.5 8.5 24 9.5 C22.5 8.7 17.5 8.7 16 9.5 Z" fill="#1e293b"/>
                </svg>
                <!-- จุดเขียวบอกสถานะออนไลน์ (🟢 Active Dot) -->
                <span class="user-status-dot position-absolute" title="สถานะ: ออนไลน์" data-bs-toggle="tooltip"></span>
            </div>
            
            <!-- ฝั่งกลาง (จัดเรียง 2 ชั้นแนวตั้ง): ชื่อผู้ใช้งาน + ป้ายบทบาทสิทธิ์ใช้งาน -->
            <div class="flex-grow-1 overflow-hidden me-2" style="min-width: 0;">
                <!-- บรรทัดที่ 1: ชื่อผู้ใช้งานตัวหนาสีขาวเห็นชัด เช่น administrator -->
                <div class="user-display-name fw-bold text-white text-truncate" title="<?= htmlspecialchars($_SESSION['username'] ?? $displayUsername) ?>" data-bs-toggle="tooltip" data-bs-placement="top"><?= htmlspecialchars($_SESSION['username'] ?? $displayUsername) ?></div>

                <!-- บรรทัดที่ 2: ป้ายบทบาทสิทธิ์ใช้งานสุดหรู เช่น Super Admin หรือ Admin เป็น Badge ขอบมนขนาดเล็ก -->
                <div class="mt-1 d-flex align-items-center">
                    <?php if ($isSuperAdmin): ?>
                        <span class="badge badge-super-admin d-inline-flex align-items-center">
                            <i class="fa-solid fa-crown text-warning me-1"></i>Super Admin
                        </span>
                    <?php else: ?>
                        <span class="badge badge-admin d-inline-flex align-items-center">
                            <i class="fa-solid fa-user-gear me-1"></i>Admin
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ฝั่งขวาสุด: ปุ่มไอคอนออกจากระบบ (Logout) รูป Power (ปิดเครื่อง) สีแดง/ส้ม พร้อม Hover & Tooltip ยืนยัน -->
            <a href="../logout.php" class="btn-sidebar-power flex-shrink-0" title="ออกจากระบบ (คลิกเพื่อยืนยัน)" aria-label="ออกจากระบบ" data-bs-toggle="tooltip" data-bs-placement="top" onclick="return confirm('คุณต้องการออกจากระบบหรือไม่?');">
                <i class="fa-solid fa-power-off"></i>
            </a>

        </div>
    </div>
<?php
}
?>

<!-- ========================================================================= -->
<!-- 1. DESKTOP SIDEBAR (ความสูงคงที่ 100% / สไลด์พับเก็บได้ / เมนูเลื่อนแยกอิสระ) -->
<!-- ========================================================================= -->
<aside id="sidebar" class="d-none d-lg-flex flex-column h-100 flex-shrink-0">
    <!-- โลโก้และชื่อระบบ (คงที่อยู่ด้านบนสุด ไม่เลื่อนหนี) -->
    <a href="main.php" class="sidebar-brand flex-shrink-0">
        <i class="fa-solid fa-screwdriver-wrench text-warning me-2 fs-5"></i>
        <span>Admin Panel</span>
    </a>

    <!-- ส่วนรายการเมนูต่างๆ ครอบด้วย div flex-grow-1 overflow-y-auto เพื่อให้เฉพาะเมนูเลื่อนได้ -->
    <div class="sidebar-menu-scroll flex-grow-1 overflow-y-auto">
        <?php renderSidebarNavItems($currentScript, $pendingCount, $overdueCount, $isSuperAdmin); ?>
    </div>

    <!-- แถบการ์ดโปรไฟล์ & Logout: ดันติดล่างสุดเสมอด้วย mt-auto และ flex-shrink-0 ไม่เลื่อนตามเมนู -->
    <div class="sidebar-bottom-profile mt-auto flex-shrink-0">
        <?php renderSidebarUserCard($displayUsername, $isSuperAdmin); ?>
    </div>
</aside>

<!-- ========================================================================= -->
<!-- 2. MOBILE & TABLET OFFCANVAS (เมนูพับได้แบบ Drawer สำหรับจอ < 992px) -->
<!-- ========================================================================= -->
<div class="offcanvas offcanvas-start offcanvas-sidebar d-lg-none" tabindex="-1" id="sidebarOffcanvas" aria-labelledby="sidebarOffcanvasLabel">
    <div class="offcanvas-header border-bottom border-secondary border-opacity-25 py-3 flex-shrink-0">
        <h5 class="offcanvas-title text-white fw-bold d-flex align-items-center" id="sidebarOffcanvasLabel">
            <i class="fa-solid fa-screwdriver-wrench text-warning me-2"></i> Admin Panel
        </h5>
        <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    
    <div class="offcanvas-body p-0 d-flex flex-column h-100 overflow-hidden">
        <!-- รายการเมนูภายใน Offcanvas เลื่อนได้อิสระ -->
        <div class="sidebar-menu-scroll flex-grow-1 overflow-y-auto">
            <?php renderSidebarNavItems($currentScript, $pendingCount, $overdueCount, $isSuperAdmin); ?>
        </div>

        <!-- แถบการ์ดข้อมูลผู้ใช้และปุ่มออกจากระบบที่ด้านล่างสุดของ Offcanvas (mt-auto flex-shrink-0) -->
        <div class="sidebar-bottom-profile mt-auto flex-shrink-0">
            <?php renderSidebarUserCard($displayUsername, $isSuperAdmin); ?>
        </div>
    </div>
</div>
