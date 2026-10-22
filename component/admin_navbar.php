<?php
/**
 * component/admin_navbar.php - แถบนำทางด้านบน (Top Navbar) และเปิดคอนเทนเนอร์เนื้อหาหลัก
 * 1. ปุ่ม Hamburger แสดงผลสำหรับทุกขนาดหน้าจอ (Desktop Slide Toggle / Mobile Offcanvas Drawer)
 * 2. ลบข้อมูลโปรไฟล์และปุ่ม Logout ออกจาก Navbar อย่างหมดจด เพื่อรวมศูนย์ไว้ในการ์ดล่างสุดของ Sidebar เท่านั้น
 */
?>
<!-- Main Content Wrapper ด้านขวา (Flex-grow 1, ความสูง 100%, overflow-hidden) -->
<div id="main-wrapper" class="d-flex flex-column flex-grow-1 h-100 overflow-hidden" style="min-width: 0;">

    <!-- แถบนำทางบน (Top Navbar คงที่อยู่ด้านบนเสมอ flex-shrink-0 ไม่เลื่อนตามเนื้อหา) -->
    <header id="top-navbar" class="d-flex align-items-center justify-content-between flex-shrink-0">
        
        <!-- ฝั่งซ้าย: ปุ่ม Hamburger Menu (สำหรับทุกขนาดหน้าจอ) และชื่อหน้าระบบ -->
        <div class="d-flex align-items-center">
            <!-- ปุ่ม Hamburger เมนู รองรับทั้ง Desktop Slide-in และ Mobile Offcanvas -->
            <button class="btn btn-light border me-2 rounded-3 text-secondary shadow-xs" 
                    type="button" 
                    id="btnSidebarToggle"
                    title="เปิด/ปิด เมนูนำทาง (Toggle Sidebar)"
                    aria-label="Toggle Navigation">
                <i class="fa-solid fa-bars fs-5"></i>
            </button>

            <div>
                <h5 class="fw-bold mb-0 text-dark d-none d-sm-block">
                    <?php echo htmlspecialchars($pageTitle ?? 'ระบบบริหารจัดการครุภัณฑ์'); ?>
                </h5>
                <h6 class="fw-bold mb-0 text-dark d-block d-sm-none text-truncate" style="max-width: 180px;">
                    <?php echo htmlspecialchars($pageTitle ?? 'Admin Panel'); ?>
                </h6>
            </div>
        </div>

        <!-- ฝั่งขวา: เครื่องมือและเมนูลัด (ลบข้อมูลผู้ใช้และ Logout ออกทั้งหมดตามคำสั่ง) -->
        <div class="d-flex align-items-center gap-2">
            <!-- ปุ่มลัดพิมพ์ QR Code -->
            <a href="print_qrcode.php" class="btn btn-outline-primary btn-sm rounded-pill d-none d-md-inline-flex align-items-center shadow-xs" title="พิมพ์สติกเกอร์ QR Code">
                <i class="fa-solid fa-qrcode me-1"></i> พิมพ์ QR Code
            </a>
        </div>
    </header>

    <!-- ส่วนเนื้อหาด้านขวา (ตาราง, กราฟ) เลื่อนได้อิสระด้วย overflow-y-auto โดยที่แถบเมนูด้านซ้ายไม่เลื่อนตาม -->
    <main id="main-content" class="flex-grow-1 overflow-y-auto d-flex flex-column">
        <div class="container-fluid px-0 px-md-1 flex-grow-1">
