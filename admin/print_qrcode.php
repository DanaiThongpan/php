<?php
/**
 * admin/print_qrcode.php - ระบบสร้างและพิมพ์ QR Code ครุภัณฑ์แบบกลุ่ม (Batch QR Code Generator & Printing)
 * รองรับการเลือกหมวดหมู่, ปรับแต่ง Layout สติกเกอร์ A4 (3x4 ดวง/แผ่น), และพิมพ์แบบไร้เมนูนำทาง
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------------
// 1. ตรวจสอบสิทธิ์การเข้าถึง (ต้องเป็น Admin เท่านั้น)
// -----------------------------------------------------------------------------
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || ($_SESSION['useraccountType'] ?? '') !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

// โหลดไฟล์เชื่อมต่อฐานข้อมูล
require_once __DIR__ . '/../control/connectPDO_dev.php';

$useraccountName = $_SESSION['Sename'] ?? 'ผู้ดูแลระบบ';
$currentUserID   = $_SESSION['SeUser'] ?? '';
$useraccountType = $_SESSION['useraccountType'] ?? '';
$currentUserDept = $_SESSION['userDepartment'] ?? '';
$isSuperAdmin    = ($useraccountType === 'Super Admin' || $currentUserID === 'admin');

// -----------------------------------------------------------------------------
// 2. ตรวจหา Base URL สำหรับสร้างลิงก์ใน QR Code โดยอัตโนมัติ
// -----------------------------------------------------------------------------
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$scriptDir = dirname(dirname($_SERVER['PHP_SELF']));
$basePath = ($scriptDir === '/' || $scriptDir === '\\') ? '' : rtrim($scriptDir, '/\\');
$defaultScanBaseUrl = $protocol . $host . $basePath . '/borrow_request.php?asset_id=';

// รับค่าจาก Form คัดกรอง
$selectedCategory = trim($_GET['category_id'] ?? '');
$selectedBuilding = trim($_GET['building_id'] ?? '');
$searchKeyword    = trim($_GET['keyword'] ?? '');
$limitCount       = trim($_GET['limit'] ?? '24'); // ค่าเริ่มต้น 24 ดวง (2 แผ่น A4)
$scanBaseUrl      = trim($_GET['scan_base_url'] ?? $defaultScanBaseUrl);
$showBorder       = isset($_GET['show_border']) ? (int)$_GET['show_border'] : 1;

// -----------------------------------------------------------------------------
// 3. ดึงรายการหมวดหมู่ (Classification) และ อาคาร (Building) สำหรับ Dropdown
// -----------------------------------------------------------------------------
$categories = [];
try {
    // ใช้ Prepared Statements ดึงหมวดหมู่พร้อมจำนวนครุภัณฑ์
    $stmtCat = $pdo->query("
        SELECT c.classificationID, c.classificationName, COUNT(da.DA_ID) AS total_items
        FROM classification c
        LEFT JOIN durable_articles da ON c.classificationID = da.classificationID AND (da.is_visible = 1 OR da.is_visible IS NULL)
        GROUP BY c.classificationID, c.classificationName
        ORDER BY c.classificationID ASC
    ");
    $categories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = [];
}

$buildings = [];
try {
    $stmtBld = $pdo->query("SELECT buildingID, buildingName FROM building WHERE (is_active = 1 OR is_active IS NULL) ORDER BY buildingName ASC");
    $buildings = $stmtBld->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $buildings = [];
}

// -----------------------------------------------------------------------------
// 4. ดึงข้อมูลครุภัณฑ์ตามเงื่อนไขที่เลือก (Prepared Statements)
// -----------------------------------------------------------------------------
$items = [];
$totalFound = 0;

if (!empty($selectedCategory) || !empty($searchKeyword) || !empty($selectedBuilding)) {
    try {
        $conditions = ["(da.is_visible = 1 OR da.is_visible IS NULL)"];
        $params = [];

        // เงื่อนไขหมวดหมู่
        if (!empty($selectedCategory) && $selectedCategory !== 'all') {
            $conditions[] = "da.classificationID = :cat_id";
            $params[':cat_id'] = $selectedCategory;
        }

        // เงื่อนไขอาคารสถานที่
        if (!empty($selectedBuilding)) {
            $conditions[] = "da.buildingID = :bld_id";
            $params[':bld_id'] = $selectedBuilding;
        }

        // เงื่อนไขคำค้นหา (ชื่อ หรือ หมายเลขครุภัณฑ์)
        if (!empty($searchKeyword)) {
            $conditions[] = "(da.DA_Number LIKE :kw1 OR da.DA_ListName LIKE :kw2 OR da.DA_Brand LIKE :kw3)";
            $params[':kw1'] = "%{$searchKeyword}%";
            $params[':kw2'] = "%{$searchKeyword}%";
            $params[':kw3'] = "%{$searchKeyword}%";
        }

        $whereClause = implode(" AND ", $conditions);

        // ดึงจำนวนรายการทั้งหมดที่ตรงเงื่อนไข
        $countSql = "SELECT COUNT(*) FROM durable_articles da WHERE {$whereClause}";
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($params);
        $totalFound = (int)$stmtCount->fetchColumn();

        // Query ดึงรายการพร้อม Limit
        $sql = "SELECT da.DA_ID, 
                       da.DA_Number, 
                       da.DA_ListName, 
                       da.DA_Brand, 
                       da.DA_Year, 
                       c.classificationName, 
                       b.buildingName, 
                       bc.classroomName,
                       da.statusID
                FROM durable_articles da
                LEFT JOIN classification c ON da.classificationID = c.classificationID
                LEFT JOIN building b ON da.buildingID = b.buildingID
                LEFT JOIN building_classroom bc ON da.classroomID = bc.classroomID
                WHERE {$whereClause}
                ORDER BY da.DA_Number ASC";

        if ($limitCount !== 'all' && is_numeric($limitCount) && (int)$limitCount > 0) {
            $sql .= " LIMIT " . (int)$limitCount;
        }

        $stmtItems = $pdo->prepare($sql);
        $stmtItems->execute($params);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $items = [];
        $errorMsg = "Database Error: " . $e->getMessage();
    }
}

// โหลดส่วนประกอบ Layout หลัก (Responsive Header, Sidebar & Top Navbar)
$pageTitle = "พิมพ์สติกเกอร์ QR Code ครุภัณฑ์ (Batch QR Code)";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>
<style>
    /* =====================================================================
     * 1. CSS VARIABLES สำหรับควบคุมขนาดสติกเกอร์และ QR CODE แบบไดนามิก
     * ===================================================================== */
    :root {
        --sticker-width: 44mm;        /* ความกว้างสติกเกอร์ (ควบคุมจำนวนคอลัมน์อัตโนมัติ) */
        --sticker-height: 52mm;       /* ความสูงของแต่ละดวง */
        --qr-img-size: 75px;          /* ขนาดของ QR Code Image */
        --font-size-header: 7pt;      /* ขนาดฟอนต์ส่วนหัวสติกเกอร์ */
        --font-size-number: 8pt;      /* ขนาดฟอนต์รหัสครุภัณฑ์ */
        --font-size-main: 7.5pt;      /* ขนาดฟอนต์ชื่อครุภัณฑ์ */
        --sticker-padding: 3mm 2.5mm; /* ระยะขอบด้านใน */
        --sticker-gap: 3mm;           /* ระยะห่างระหว่างดวงสติกเกอร์ */
    }

    /* =====================================================================
     * 2. สไตล์หน้าจอปกติ (Screen Preview Styles)
     * ===================================================================== */
    .filter-card {
        border: none;
        border-radius: 14px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }
    .preview-wrapper {
        background: #525659;
        padding: 30px 15px;
        border-radius: 14px;
        margin-top: 15px;
        overflow-x: auto;
    }
    .a4-sheet {
        background: white;
        width: 210mm;
        min-height: 297mm;
        margin: 0 auto;
        padding: 10mm 8mm;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        box-sizing: border-box;
        transition: all 0.25s ease;
    }

    /* ตารางกริดสติกเกอร์: ใช้ CSS Variables ควบคุมจำนวนคอลัมน์แบบ Responsive อัตโนมัติ */
    .sticker-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(var(--sticker-width), 1fr));
        gap: var(--sticker-gap);
        box-sizing: border-box;
        width: 100%;
        transition: gap 0.2s ease;
    }

    /* แต่ละดวงสติกเกอร์ (Sticker Label): ใช้ Flexbox จัดกึ่งกลาง ขยาย/หดพอดี */
    .sticker-item {
        box-sizing: border-box;
        width: 100%;
        height: var(--sticker-height);
        padding: var(--sticker-padding);
        background: white;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        align-items: center;
        text-align: center;
        border-radius: 6px;
        position: relative;
        overflow: hidden;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        transition: height 0.2s ease, padding 0.2s ease;
    }
    .sticker-bordered {
        border: 1px dashed #b0b0b0;
    }
    .sticker-borderless {
        border: 1px solid transparent;
    }

    /* ส่วนหัวสติกเกอร์ */
    .sticker-header {
        width: 100%;
        border-bottom: 0.5px solid #e0e0e0;
        padding-bottom: 1mm;
        margin-bottom: 1mm;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: var(--font-size-header);
        color: #555;
        line-height: 1.1;
        overflow: hidden;
        flex-shrink: 0;
        transition: font-size 0.2s ease;
    }
    .sticker-org-title {
        font-weight: 700;
        color: #0d6efd;
        letter-spacing: -0.2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .sticker-cat-badge {
        font-size: calc(var(--font-size-header) * 0.95);
        color: #666;
        max-width: 50%;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* รูป QR Code: รองรับการขยาย/ย่อแบบไดนามิก */
    .sticker-qrcode {
        display: flex;
        justify-content: center;
        align-items: center;
        margin: auto 0;
        width: 100%;
        flex-shrink: 0;
    }
    .sticker-qrcode canvas,
    .sticker-qrcode img {
        display: block;
        margin: 0 auto;
        width: var(--qr-img-size) !important;
        height: var(--qr-img-size) !important;
        max-width: 100%;
        object-fit: contain;
        transition: width 0.2s ease, height 0.2s ease;
    }

    /* ส่วนท้ายข้อมูลครุภัณฑ์: ป้องกันข้อความล้นกรอบเมื่อปรับไซส์เล็ก */
    .sticker-footer {
        width: 100%;
        margin-top: 1mm;
        overflow: hidden;
        flex-shrink: 0;
    }
    .sticker-asset-number {
        font-family: 'Courier New', Courier, monospace;
        font-weight: bold;
        font-size: var(--font-size-number);
        color: #000;
        line-height: 1.2;
        width: 100%;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: font-size 0.2s ease;
    }
    .sticker-asset-name {
        font-size: var(--font-size-main);
        color: #333;
        line-height: 1.2;
        margin-top: 0.5mm;
        width: 100%;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: font-size 0.2s ease;
    }

    /* สไตล์ตกแต่ง Range Slider */
    .form-range::-webkit-slider-thumb {
        background: #0d6efd;
        box-shadow: 0 2px 6px rgba(13, 110, 253, 0.4);
    }
    .form-range::-moz-range-thumb {
        background: #0d6efd;
        box-shadow: 0 2px 6px rgba(13, 110, 253, 0.4);
    }

    /* =====================================================================
     * 3. สไตล์สำหรับการพิมพ์จริง (@media print)
     * ===================================================================== */
    @media print {
        @page {
            size: A4 portrait;
            margin: 10mm 8mm; /* ขอบกระดาษ A4 บน-ล่าง 10 มม., ซ้าย-ขวา 8 มม. */
        }
        body {
            background: white !important;
            margin: 0 !important;
            padding: 0 !important;
            color: black !important;
        }
        /* ซ่อนเมนูนำทาง แถบเครื่องมือ ฟอร์มค้นหา และคอนโทรลทั้งหมด */
        .no-print,
        nav,
        .navbar,
        .filter-card,
        .btn,
        footer,
        .alert,
        #sidebar,
        #sidebarOffcanvas,
        .sidebar-bottom-profile,
        .preview-wrapper > .preview-title {
            display: none !important;
        }
        .preview-wrapper {
            background: transparent !important;
            padding: 0 !important;
            margin: 0 !important;
            overflow: visible !important;
        }
        .a4-sheet {
            width: 100% !important;
            min-height: auto !important;
            padding: 0 !important;
            margin: 0 !important;
            box-shadow: none !important;
        }
        .sticker-grid {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(var(--sticker-width), 1fr)) !important;
            gap: var(--sticker-gap) !important;
            page-break-inside: auto;
        }
        .sticker-item {
            box-sizing: border-box !important;
            width: 100% !important;
            height: var(--sticker-height) !important;
            padding: var(--sticker-padding) !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        .sticker-qrcode canvas,
        .sticker-qrcode img {
            width: var(--qr-img-size) !important;
            height: var(--qr-img-size) !important;
        }
        .sticker-header {
            font-size: var(--font-size-header) !important;
        }
        .sticker-asset-number {
            font-size: var(--font-size-number) !important;
        }
        .sticker-asset-name {
            font-size: var(--font-size-main) !important;
        }
        .sticker-bordered {
            border: 0.5px dashed #999 !important;
        }
        .sticker-borderless {
            border: 0.5px solid transparent !important;
        }
    }
</style>

<div class="container-fluid px-0">

    <!-- แถบหัวเรื่อง (เฉพาะหน้าจอปกติ) -->
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <div>
            <h4 class="fw-bold mb-0 text-dark">
                <i class="fa-solid fa-print text-primary me-2"></i>พิมพ์สติกเกอร์ QR Code ครุภัณฑ์ (Batch QR Code Printing)
            </h4>
            <p class="text-muted small mb-0">เลือกหมวดหมู่เพื่อสร้างและจัดหน้ากระดาษ A4 สำหรับพิมพ์สติกเกอร์ลาเบล (3x4 ดวง/แผ่น)</p>
        </div>
        <?php if (!empty($items)): ?>
            <button onclick="window.print()" class="btn btn-primary btn-lg shadow rounded-pill px-4 fw-bold">
                <i class="fa-solid fa-print me-2"></i> สั่งพิมพ์ทันที (Print A4)
            </button>
        <?php endif; ?>
    </div>

    <!-- การ์ดตัวกรองและตัวเลือกการสร้าง QR Code -->
    <div class="card filter-card mb-4 no-print">
        <div class="card-body p-4">
            <form method="GET" action="" id="qrFilterForm">
                <div class="row g-3">
                    
                    <!-- 1. เลือกหมวดหมู่ (Category Dropdown ตาม Requirement) -->
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-secondary">
                            <i class="fa-solid fa-layer-group text-primary me-1"></i> หมวดหมู่ครุภัณฑ์ (Category) <span class="text-danger">*</span>
                        </label>
                        <select name="category_id" id="category_id" class="form-select form-select-lg" required>
                            <option value="">-- กรุณาเลือกหมวดหมู่ --</option>
                            <option value="all" <?php echo ($selectedCategory === 'all') ? 'selected' : ''; ?>>
                                ★ เลือกทุกหมวดหมู่ (All Categories)
                            </option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['classificationID']); ?>" 
                                    <?php echo ($selectedCategory === $cat['classificationID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['classificationName']); ?> 
                                    (<?php echo number_format($cat['total_items']); ?> รายการ)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 2. เลือกสถานที่/อาคาร (Building Filter - ตัวเลือกเสริม) -->
                    <div class="col-md-3">
                        <label class="form-label fw-bold text-secondary">
                            <i class="fa-solid fa-building text-info me-1"></i> อาคาร/สถานที่ (Building)
                        </label>
                        <select name="building_id" class="form-select form-select-lg">
                            <option value="">-- ทั้งหมดทุกอาคาร --</option>
                            <?php foreach ($buildings as $bld): ?>
                                <option value="<?php echo htmlspecialchars($bld['buildingID']); ?>" 
                                    <?php echo ($selectedBuilding === $bld['buildingID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($bld['buildingName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 3. ค้นหาคำค้นเพิ่มเติม (Keyword Search) -->
                    <div class="col-md-3">
                        <label class="form-label fw-bold text-secondary">
                            <i class="fa-solid fa-magnifying-glass me-1"></i> ค้นหาเพิ่มเติม (Search)
                        </label>
                        <input type="text" name="keyword" class="form-control form-control-lg" 
                               value="<?php echo htmlspecialchars($searchKeyword); ?>" 
                               placeholder="เลขครุภัณฑ์, ชื่อ, หรือยี่ห้อ">
                    </div>

                    <!-- 4. จำนวนที่ต้องการพิมพ์ต่อชุด (Limit per batch) -->
                    <div class="col-md-2">
                        <label class="form-label fw-bold text-secondary">
                            <i class="fa-solid fa-hashtag me-1"></i> จำนวนพิมพ์
                        </label>
                        <select name="limit" class="form-select form-select-lg">
                            <option value="12" <?php echo ($limitCount === '12') ? 'selected' : ''; ?>>12 ดวง (1 แผ่น)</option>
                            <option value="24" <?php echo ($limitCount === '24') ? 'selected' : ''; ?>>24 ดวง (2 แผ่น)</option>
                            <option value="48" <?php echo ($limitCount === '48') ? 'selected' : ''; ?>>48 ดวง (4 แผ่น)</option>
                            <option value="96" <?php echo ($limitCount === '96') ? 'selected' : ''; ?>>96 ดวง (8 แผ่น)</option>
                            <option value="all" <?php echo ($limitCount === 'all') ? 'selected' : ''; ?>>พิมพ์ทั้งหมด</option>
                        </select>
                    </div>

                    <!-- 5. ขั้นสูง: ปรับแต่ง Base URL สำหรับสแกน และแสดงเส้นประ -->
                    <div class="col-md-8">
                        <label class="form-label small text-muted fw-bold">
                            <i class="fa-solid fa-link me-1"></i> ลิงก์ URL สำหรับสแกน QR Code (Scan Base URL)
                        </label>
                        <input type="url" name="scan_base_url" class="form-control font-monospace form-control-sm" 
                               value="<?php echo htmlspecialchars($scanBaseUrl); ?>" 
                               placeholder="https://yourdomain.com/borrow_request.php?asset_id=">
                        <div class="form-text small">ระบบจะนำหมายเลขครุภัณฑ์ (Asset Number) ไปต่อท้าย URL นี้อัตโนมัติ</div>
                    </div>

                    <div class="col-md-4 d-flex align-items-center pt-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="show_border" value="1" id="borderSwitch" <?php echo ($showBorder == 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-bold text-secondary" for="borderSwitch">
                                แสดงเส้นประกรอบสติกเกอร์ (สำหรับช่วยตัด)
                            </label>
                        </div>
                    </div>

                    <!-- ปุ่มดำเนินการ -->
                    <div class="col-12 text-end pt-2 border-top">
                        <a href="print_qrcode.php" class="btn btn-outline-secondary me-2 rounded-pill px-3">
                            <i class="fa-solid fa-rotate-left me-1"></i> ล้างค่า
                        </a>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
                            <i class="fa-solid fa-wand-magic-sparkles me-1"></i> สร้าง QR Code (Generate)
                        </button>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <!-- ส่วนแสดงตัวอย่างและผลลัพธ์ -->
    <?php if (!empty($selectedCategory) || !empty($searchKeyword) || !empty($selectedBuilding)): ?>
        
        <?php if (empty($items)): ?>
            <div class="alert alert-warning text-center rounded-4 p-5 shadow-sm no-print">
                <i class="fa-solid fa-circle-exclamation fs-1 text-warning mb-3 d-block"></i>
                <h5 class="fw-bold">ไม่พบข้อมูลครุภัณฑ์ตามเงื่อนไขที่เลือก</h5>
                <p class="text-muted mb-0">กรุณาลองเปลี่ยนหมวดหมู่หรือคำค้นหาใหม่อีกครั้ง</p>
            </div>
        <?php else: ?>

            <!-- แผงควบคุมการพิมพ์และปรับขนาดสติกเกอร์ (Print & Dynamic Size Control Toolbar) -->
            <div class="card border-0 shadow-sm rounded-4 mb-3 no-print" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid #e2e8f0 !important;">
                <div class="card-body p-3 p-md-4">
                    <div class="row g-3 align-items-center">
                        
                        <!-- ฝั่งซ้าย: หัวข้อการปรับขนาด และ Badge แสดงสถานะปัจจุบัน -->
                        <div class="col-lg-4 col-md-12">
                            <div class="d-flex align-items-center mb-1">
                                <span class="badge rounded-circle p-2 bg-primary text-white me-2">
                                    <i class="fa-solid fa-sliders"></i>
                                </span>
                                <h6 class="fw-bold text-dark mb-0">ปรับขนาดสติกเกอร์ QR Code</h6>
                                <span class="badge ms-2" id="currentSizeBadge">Medium (กลาง)</span>
                            </div>
                            <div class="text-muted small ps-4 ms-2" id="currentSizeDetail">
                                แสดงผล 4-5 ดวง/แถว &bull; QR ~75px &bull; ขนาดมาตรฐานสำหรับครุภัณฑ์ทั่วไป
                            </div>
                        </div>

                        <!-- ฝั่งกลาง: ปุ่มเลือกขนาดลัด และแถบเลื่อน Range Slider -->
                        <div class="col-lg-5 col-md-7 col-sm-12">
                            <div class="d-flex flex-column gap-2">
                                <!-- ปุ่มเลือกขนาดลัด (Preset Buttons: Small, Medium, Large) -->
                                <div class="btn-group w-100 shadow-sm" role="group" aria-label="Preset Sizes">
                                    <button type="button" class="btn btn-outline-primary btn-preset-size fw-bold py-2" data-scale="1" onclick="applyQrScale(1)">
                                        <i class="fa-solid fa-compress me-1"></i> เล็ก (Small)
                                    </button>
                                    <button type="button" class="btn btn-primary btn-preset-size fw-bold py-2 active" data-scale="2" onclick="applyQrScale(2)">
                                        <i class="fa-solid fa-expand me-1"></i> กลาง (Medium)
                                    </button>
                                    <button type="button" class="btn btn-outline-primary btn-preset-size fw-bold py-2" data-scale="3" onclick="applyQrScale(3)">
                                        <i class="fa-solid fa-maximize me-1"></i> ใหญ่ (Large)
                                    </button>
                                </div>

                                <!-- แถบเลื่อนปรับขนาด (Range Slider: <input type="range">) -->
                                <div class="d-flex align-items-center gap-2 px-1 pt-1">
                                    <span class="text-secondary small fw-bold text-nowrap"><i class="fa-solid fa-minimize me-1 text-info"></i>เล็ก</span>
                                    <input type="range" class="form-range" id="qrScaleSlider" min="1" max="3" step="1" value="2" oninput="applyQrScale(this.value)" style="cursor: pointer;">
                                    <span class="text-secondary small fw-bold text-nowrap">ใหญ่<i class="fa-solid fa-maximize ms-1 text-warning"></i></span>
                                </div>
                            </div>
                        </div>

                        <!-- ฝั่งขวาสุด: ปุ่มสั่งพิมพ์ทันที (Print Button) -->
                        <div class="col-lg-3 col-md-5 col-sm-12 text-md-end text-center">
                            <button onclick="window.print()" class="btn btn-success btn-lg w-100 rounded-pill px-4 py-2 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2">
                                <i class="fa-solid fa-print fs-5"></i>
                                <span>สั่งพิมพ์ทันที (Print)</span>
                            </button>
                        </div>

                    </div>
                </div>
            </div>

            <!-- แถบสรุปผลลัพธ์ -->
            <div class="d-flex justify-content-between align-items-center mb-3 no-print">
                <div class="text-secondary small">
                    <i class="fa-solid fa-circle-info text-primary me-1"></i>
                    พบครุภัณฑ์ทั้งหมด <strong><?php echo number_format($totalFound); ?></strong> รายการ 
                    &bull; กำลังแสดงผล <strong><?php echo number_format(count($items)); ?></strong> ดวง 
                    <span id="pageEstimateText"></span>
                </div>
            </div>

            <!-- กล่องแสดงตัวอย่างกระดาษ A4 (A4 Sheet Preview Wrapper) -->
            <div class="preview-wrapper">
                <div class="a4-sheet">
                    <div class="sticker-grid" id="stickerContainer">
                        <?php foreach ($items as $idx => $it): ?>
                            <?php 
                            // สร้าง URL เต็มสำหรับบรรจุลงใน QR Code
                            $scanUrl = $scanBaseUrl . urlencode($it['DA_Number']);
                            $borderClass = ($showBorder == 1) ? 'sticker-bordered' : 'sticker-borderless';
                            ?>
                            <div class="sticker-item <?php echo $borderClass; ?> d-flex flex-column align-items-center justify-content-between" id="sticker-item-<?php echo $idx; ?>">
                                
                                <!-- ส่วนบน: ชื่อระบบ / สังกัด และ หมวดหมู่ -->
                                <div class="sticker-header">
                                    <span class="sticker-org-title">ASSET QR CODE</span>
                                    <span class="sticker-cat-badge" title="<?php echo htmlspecialchars($it['classificationName'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($it['classificationName'] ?? ''); ?>
                                    </span>
                                </div>

                                <!-- ส่วนกลาง: รูป QR Code ถูก Generate ด้วย JavaScript -->
                                <div class="sticker-qrcode" 
                                     id="qrcode-box-<?php echo $idx; ?>" 
                                     data-url="<?php echo htmlspecialchars($scanUrl); ?>">
                                </div>

                                <!-- ส่วนล่าง: หมายเลขครุภัณฑ์ และ ชื่อรายการ -->
                                <div class="sticker-footer w-100 text-center">
                                    <div class="sticker-asset-number" title="<?php echo htmlspecialchars($it['DA_Number']); ?>">
                                        <?php echo htmlspecialchars($it['DA_Number']); ?>
                                    </div>
                                    <div class="sticker-asset-name" title="<?php echo htmlspecialchars($it['DA_ListName']); ?>">
                                        <?php echo htmlspecialchars($it['DA_ListName']); ?>
                                    </div>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    <?php else: ?>
        <!-- ข้อความแนะนำเริ่มต้น -->
        <div class="card border-0 rounded-4 shadow-sm text-center py-5 no-print">
            <div class="card-body">
                <i class="fa-solid fa-qrcode text-primary fa-4x mb-3 opacity-75"></i>
                <h5 class="fw-bold">ยินดีต้อนรับสู่ระบบพิมพ์ QR Code แบบกลุ่ม</h5>
                <p class="text-muted mb-3">กรุณาเลือก <strong>"หมวดหมู่ (Category)"</strong> ในฟอร์มด้านบน แล้วกดปุ่ม <strong>"สร้าง QR Code"</strong> เพื่อเริ่มต้น</p>
                <span class="badge bg-light text-dark border px-3 py-2 rounded-pill">
                    <i class="fa-solid fa-file-lines me-1 text-danger"></i> ออกแบบขนาด 3 คอลัมน์ x 4 แถว (12 ดวงต่อกระดาษ A4)
                </span>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- โหลด qrcode.min.js จาก Local หากโหลดไม่ได้จะดึงจาก cdnjs CDN อัตโนมัติ -->
<script src="../js/qrcode.min.js"></script>
<script>
if (typeof QRCode === 'undefined') {
    document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"><\/script>');
}
</script>

<script>
/**
 * =============================================================================
 * [DYNAMIC QR SIZE ADJUSTMENT / การปรับขนาด QR Code แบบไดนามิก]
 * =============================================================================
 * จุดตั้งค่า Scale สำหรับปรับจูนขนาดสติกเกอร์ (Pixel, Millimeter, และ Font Size)
 * สามารถเพิ่มหรือปรับเปลี่ยนตัวเลขด้านล่างนี้ได้โดยตรง เพื่อความสะดวกในการจูนสัดส่วน
 */
const QR_SIZE_CONFIG = {
    // -------------------------------------------------------------------------
    // 1. ขนาดเล็ก (Small): QR ~46px สำหรับติดอุปกรณ์ชิ้นเล็ก (แสดงผล 5-7 ดวง/แถว)
    // -------------------------------------------------------------------------
    1: {
        label: 'Small (เล็ก)',
        badgeClass: 'bg-info-subtle text-info border-info-subtle',
        stickerWidth: '28mm',       // ความกว้างสติกเกอร์ (A4 กว้าง 194mm ได้ประมาณ 6 ดวง/แถว)
        stickerHeight: '38mm',      // ความสูงสติกเกอร์ (ได้ประมาณ 7 แถว = 42 ดวง/แผ่น)
        qrImgSize: '46px',          // ขนาดภาพ QR Code (พิกเซล)
        fontSizeHeader: '5.5pt',    // ขนาดฟอนต์ส่วนหัว
        fontSizeNumber: '6.5pt',    // ขนาดฟอนต์รหัสครุภัณฑ์
        fontSizeMain: '6pt',        // ขนาดฟอนต์ชื่อครุภัณฑ์
        stickerPadding: '2mm 1.5mm',// ระยะขอบด้านในสติกเกอร์
        stickerGap: '2.5mm',        // ระยะห่างระหว่างดวงสติกเกอร์
        desc: 'แสดงผล 5-7 ดวง/แถว &bull; QR ~46px &bull; เหมาะสำหรับติดอุปกรณ์ขนาดเล็ก',
        itemsPerPage: 42            // จำนวนดวงประมาณการต่อ 1 หน้า A4
    },
    // -------------------------------------------------------------------------
    // 2. ขนาดกลาง (Medium): QR ~75px สัดส่วนมาตรฐาน (แสดงผล 4-5 ดวง/แถว)
    // -------------------------------------------------------------------------
    2: {
        label: 'Medium (กลาง)',
        badgeClass: 'bg-primary-subtle text-primary border-primary-subtle',
        stickerWidth: '44mm',       // ความกว้างสติกเกอร์ (ได้ประมาณ 4 ดวง/แถว)
        stickerHeight: '52mm',      // ความสูงสติกเกอร์ (ได้ประมาณ 5 แถว = 20 ดวง/แผ่น)
        qrImgSize: '75px',          // ขนาดภาพ QR Code (พิกเซล)
        fontSizeHeader: '7pt',      // ขนาดฟอนต์ส่วนหัว
        fontSizeNumber: '8pt',      // ขนาดฟอนต์รหัสครุภัณฑ์
        fontSizeMain: '7.5pt',      // ขนาดฟอนต์ชื่อครุภัณฑ์
        stickerPadding: '3mm 2.5mm',// ระยะขอบด้านในสติกเกอร์
        stickerGap: '3mm',          // ระยะห่างระหว่างดวงสติกเกอร์
        desc: 'แสดงผล 4-5 ดวง/แถว &bull; QR ~75px &bull; ขนาดมาตรฐานสำหรับครุภัณฑ์ทั่วไป',
        itemsPerPage: 20            // จำนวนดวงประมาณการต่อ 1 หน้า A4
    },
    // -------------------------------------------------------------------------
    // 3. ขนาดใหญ่ (Large): QR ~125px สำหรับแปะหน้าห้องหรือครุภัณฑ์ขนาดใหญ่ (2-3 ดวง/แถว)
    // -------------------------------------------------------------------------
    3: {
        label: 'Large (ใหญ่)',
        badgeClass: 'bg-warning-subtle text-dark border-warning-subtle',
        stickerWidth: '62mm',       // ความกว้างสติกเกอร์ (ได้ประมาณ 3 ดวง/แถว)
        stickerHeight: '76mm',      // ความสูงสติกเกอร์ (ได้ประมาณ 3-4 แถว = 9-12 ดวง/แผ่น)
        qrImgSize: '125px',         // ขนาดภาพ QR Code (พิกเซล)
        fontSizeHeader: '8.5pt',    // ขนาดฟอนต์ส่วนหัว
        fontSizeNumber: '10.5pt',   // ขนาดฟอนต์รหัสครุภัณฑ์
        fontSizeMain: '9pt',        // ขนาดฟอนต์ชื่อครุภัณฑ์
        stickerPadding: '4mm 3mm',  // ระยะขอบด้านในสติกเกอร์
        stickerGap: '4mm',          // ระยะห่างระหว่างดวงสติกเกอร์
        desc: 'แสดงผล 2-3 ดวง/แถว &bull; QR ~125px &bull; ฟอนต์ใหญ่ชัดเจน สำหรับแปะหน้าห้อง/กล่องใหญ่',
        itemsPerPage: 12            // จำนวนดวงประมาณการต่อ 1 หน้า A4
    }
};

/**
 * ฟังก์ชันปรับขนาด CSS Variables และอัปเดต UI แบบ Real-time
 * @param {number|string} scaleLevel ระดับขนาด (1: Small, 2: Medium, 3: Large)
 */
function applyQrScale(scaleLevel) {
    scaleLevel = parseInt(scaleLevel) || 2;
    if (scaleLevel < 1) scaleLevel = 1;
    if (scaleLevel > 3) scaleLevel = 3;

    var config = QR_SIZE_CONFIG[scaleLevel];
    if (!config) return;

    // 1. อัปเดต CSS Variables ไปยังระดับ :root (document.documentElement)
    // รองรับทั้งบนหน้าจอ และเวลาสั่งพิมพ์ (@media print) นำค่าไปใช้อัตโนมัติ
    var root = document.documentElement;
    root.style.setProperty('--sticker-width', config.stickerWidth);
    root.style.setProperty('--sticker-height', config.stickerHeight);
    root.style.setProperty('--qr-img-size', config.qrImgSize);
    root.style.setProperty('--font-size-header', config.fontSizeHeader);
    root.style.setProperty('--font-size-number', config.fontSizeNumber);
    root.style.setProperty('--font-size-main', config.fontSizeMain);
    root.style.setProperty('--sticker-padding', config.stickerPadding);
    root.style.setProperty('--sticker-gap', config.stickerGap);

    // 2. ซิงก์ค่าให้กับ Slider
    var slider = document.getElementById('qrScaleSlider');
    if (slider && slider.value != scaleLevel) {
        slider.value = scaleLevel;
    }

    // 3. อัปเดตสถานะ Active ของปุ่ม Preset ทั้ง 3
    var presetButtons = document.querySelectorAll('.btn-preset-size');
    presetButtons.forEach(function(btn) {
        var btnScale = parseInt(btn.getAttribute('data-scale'));
        if (btnScale === scaleLevel) {
            btn.classList.add('active', 'btn-primary');
            btn.classList.remove('btn-outline-primary');
        } else {
            btn.classList.remove('active', 'btn-primary');
            btn.classList.add('btn-outline-primary');
        }
    });

    // 4. อัปเดตข้อความ Badge และคำอธิบาย
    var badge = document.getElementById('currentSizeBadge');
    var desc = document.getElementById('currentSizeDetail');
    if (badge) {
        badge.textContent = config.label;
        badge.className = 'badge border px-2.5 py-1 ' + config.badgeClass;
    }
    if (desc) {
        desc.innerHTML = config.desc;
    }

    // 5. คำนวณจำนวนหน้า A4 โดยประมาณแบบไดนามิก
    var pageText = document.getElementById('pageEstimateText');
    var totalItems = <?php echo !empty($items) ? (int)count($items) : 0; ?>;
    if (pageText && totalItems > 0) {
        var estPages = Math.ceil(totalItems / config.itemsPerPage);
        pageText.innerHTML = '&bull; คิดเป็นประมาณ <strong>' + estPages + '</strong> หน้า A4';
    }

    // 6. บันทึกค่าลงใน localStorage เพื่อจดจำขนาดสติกเกอร์ที่ผู้ใช้เลือกไว้
    try {
        localStorage.setItem('daam_qr_print_scale', scaleLevel);
    } catch(e) {}
}

// สคริปต์สำหรับการ Generate QR Code ลงในแต่ละดวงสติกเกอร์
document.addEventListener("DOMContentLoaded", function() {
    var qrElements = document.querySelectorAll('.sticker-qrcode');
    
    // สร้างภาพ QR Code คุณภาพสูง (250x250) เพื่อให้เมื่อขยาย/ย่อด้วย CSS จะคมชัดเสมอ ไม่แตก
    qrElements.forEach(function(el, index) {
        var scanUrl = el.getAttribute('data-url');
        if (scanUrl && typeof QRCode !== 'undefined') {
            el.innerHTML = '';
            
            new QRCode(el, {
                text: scanUrl,
                width: 250,
                height: 250,
                colorDark: "#000000",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });
        }
    });

    // คืนค่า Scale ที่เคยบันทึกไว้ใน localStorage (ค่าเริ่มต้นคือ 2: Medium)
    var savedScale = 2;
    try {
        savedScale = parseInt(localStorage.getItem('daam_qr_print_scale')) || 2;
    } catch(e) {}
    
    applyQrScale(savedScale);
});
</script>

<?php
// โหลดส่วนท้าย Layout หลัก (Scripts & Sticky Footer)
require_once __DIR__ . '/../component/admin_footer.php';
?>
