        </div><!-- /.container-fluid -->

        <!-- ส่วนท้ายหน้าเว็บ (Sticky Footer อยู่ล่างสุดของเนื้อหาเสมอ ไม่ลอย ไม่ทับหน้าจอ) -->
        <footer id="app-footer" class="no-print mt-auto flex-shrink-0">
            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2">
                <div>
                    &copy; <?php echo date('Y'); ?> <strong>ระบบบริหารจัดการครุภัณฑ์ (Asset Management System)</strong>
                </div>
                <div class="text-secondary small">
                    <span>Enterprise Responsive Layout &bull; Version 2.5</span>
                </div>
            </div>
        </footer>
    </main><!-- /#main-content (พื้นที่เลื่อนอิสระ Independent Scrolling) -->

</div><!-- /#main-wrapper -->
</div><!-- /#app-layout -->

<!-- ========================================================================= -->
<!-- CORE JAVASCRIPT LIBRARIES -->
<!-- ========================================================================= -->
<!-- jQuery 3.6 (ตรวจสอบหากยังไม่ได้โหลด) -->
<script>
if (typeof jQuery === 'undefined') {
    document.write('<script src="https://code.jquery.com/jquery-3.6.0.min.js"><\/script>');
}
</script>

<!-- Bootstrap 5.3 Bundle JS (รวม Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- DataTables 1.13.4 JS -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>

<!-- DataTables Responsive 2.4.1 Extension (สำหรับยุบคอลัมน์ซ่อนใต้ปุ่ม + บนมือถือ) -->
<script src="https://cdn.datatables.net/responsive/2.4.1/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.4.1/js/responsive.bootstrap5.min.js"></script>

<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- กำหนดค่า DataTables Global Defaults สำหรับรองรับ Responsive และภาษาไทยทุกตาราง -->
<script>
if (typeof $.fn.dataTable !== 'undefined') {
    $.extend(true, $.fn.dataTable.defaults, {
        responsive: true,
        language: {
            url: "//cdn.datatables.net/plug-ins/1.13.4/i18n/th.json"
        }
    });
}

/**
 * =============================================================================
 * สคริปต์ควบคุมการพับ/สไลด์ Sidebar สำหรับทุกขนาดหน้าจอ (Toggleable Slide-in Sidebar)
 * 1. บนจอคอมพิวเตอร์ Desktop (>= 992px): กดแล้วสไลด์ Sidebar เข้า-ออก พร้อมดัน Main Content
 * 2. บนจอมือถือและแท็บเล็ต (< 992px): กดแล้วเปิด Offcanvas Drawer
 * 3. บันทึกสถานะการพับไว้ใน localStorage เพื่อให้จำค่าข้ามหน้า
 * =============================================================================
 */
$(document).ready(function() {
    const $btnToggle = $('#btnSidebarToggle');
    const $appLayout = $('#app-layout');
    const offcanvasEl = document.getElementById('sidebarOffcanvas');
    let offcanvasInstance = null;

    if (offcanvasEl && typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
        offcanvasInstance = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
    }

    $btnToggle.on('click', function(e) {
        e.preventDefault();
        if ($(window).width() >= 992) {
            // จอคอมพิวเตอร์ Desktop: สไลด์พับเก็บ/ขยาย Sidebar
            $appLayout.toggleClass('sidebar-collapsed');
            const isCollapsed = $appLayout.hasClass('sidebar-collapsed');
            localStorage.setItem('daam_sidebar_collapsed', isCollapsed ? '1' : '0');
        } else {
            // จอมือถือและแท็บเล็ต: สไลด์ Drawer แบบ Offcanvas
            if (offcanvasInstance) {
                offcanvasInstance.toggle();
            }
        }
    });

    // คืนค่าสถานะ Sidebar ที่ผู้ใช้เคยย่อไว้บน Desktop
    if ($(window).width() >= 992 && localStorage.getItem('daam_sidebar_collapsed') === '1') {
        $appLayout.addClass('sidebar-collapsed');
    }

    // Initialize Bootstrap Tooltips globally for all elements with data-bs-toggle="tooltip" or [title]
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"], [title]:not([data-bs-toggle])');
        tooltipTriggerList.forEach(el => {
            if (!bootstrap.Tooltip.getInstance(el)) {
                new bootstrap.Tooltip(el, {
                    trigger: 'hover',
                    container: 'body'
                });
            }
        });
    }
});
</script>

</body>
</html>
