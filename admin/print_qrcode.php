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
    $stmtBld = $pdo->query("SELECT buildingID, buildingName FROM building ORDER BY buildingName ASC");
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
     * สไตล์เฉพาะหน้าพิมพ์สติกเกอร์ (Sticker Labels CSS)
     * ===================================================================== */
    .filter-card {
        border: none;
        border-radius: 14px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }
    .preview-wrapper {
        background: #525659;
        padding: 30px 15px;
        border-radius: 12px;
        margin-top: 20px;
    }
    .a4-sheet {
        background: white;
        width: 210mm;
        min-height: 297mm;
        margin: 0 auto;
        padding: 10mm 8mm;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        box-sizing: border-box;
    }

    /* ตารางกริดสติกเกอร์: 3 คอลัมน์ x 4 แถว พอดีกับหน้ากระดาษ A4 */
    .sticker-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 4mm;
        box-sizing: border-box;
    }

    /* แต่ละดวงสติกเกอร์ (Sticker Label) */
    .sticker-item {
        box-sizing: border-box;
        height: 63mm; /* ความสูงเหมาะสมสำหรับ 4 แถวในกระดาษ A4 */
        padding: 3.5mm 3mm;
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
        padding-bottom: 1.5mm;
        margin-bottom: 1.5mm;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 8pt;
        color: #555;
    }
    .sticker-org-title {
        font-weight: 700;
        color: #0d6efd;
        letter-spacing: -0.2px;
    }
    .sticker-cat-badge {
        font-size: 7pt;
        color: #666;
        max-width: 90px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* รูป QR Code */
    .sticker-qrcode {
        display: flex;
        justify-content: center;
        align-items: center;
        margin: 1mm 0;
    }
    .sticker-qrcode canvas,
    .sticker-qrcode img {
        display: block;
        margin: 0 auto;
        width: 105px !important;
        height: 105px !important;
    }

    /* ส่วนท้ายข้อมูลครุภัณฑ์ */
    .sticker-footer {
        width: 100%;
        margin-top: 1mm;
    }
    .sticker-asset-number {
        font-family: 'Courier New', Courier, monospace;
        font-weight: bold;
        font-size: 9.5pt;
        color: #000;
        line-height: 1.2;
        word-break: break-all;
    }
    .sticker-asset-name {
        font-size: 8pt;
        color: #333;
        line-height: 1.25;
        margin-top: 1mm;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        text-overflow: ellipsis;
        max-height: 2.5em;
    }

    /* =====================================================================
     * สไตล์สำหรับการพิมพ์จริง (@media print)
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
        /* ซ่อนเมนูนำทาง แถบเครื่องมือ ฟอร์มค้นหา ปุ่มทั้งหมด */
        .no-print,
        nav,
        .navbar,
        .filter-card,
        .btn,
        footer,
        .alert,
        .preview-wrapper > .preview-title {
            display: none !important;
        }
        .preview-wrapper {
            background: transparent !important;
            padding: 0 !important;
            margin: 0 !important;
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
            grid-template-columns: repeat(3, 1fr) !important;
            gap: 4mm !important;
            page-break-inside: auto;
        }
        .sticker-item {
            box-sizing: border-box !important;
            height: 63mm !important;
            page-break-inside: avoid !important; /* ป้องกันการถูกตัดหน้ากลางดวงสติกเกอร์ */
            break-inside: avoid !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
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

            <!-- แถบสรุปผลลัพธ์ -->
            <div class="d-flex justify-content-between align-items-center mb-3 no-print">
                <div class="text-secondary">
                    พบครุภัณฑ์ทั้งหมด <strong><?php echo number_format($totalFound); ?></strong> รายการ 
                    (กำลังแสดง <strong><?php echo number_format(count($items)); ?></strong> ดวง 
                    &bull; คิดเป็นประมาณ <strong><?php echo ceil(count($items) / 12); ?></strong> หน้า A4)
                </div>
                <div>
                    <button onclick="window.print()" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">
                        <i class="fa-solid fa-print me-1"></i> พิมพ์หน้านี้ (Print Labels)
                    </button>
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
                            <div class="sticker-item <?php echo $borderClass; ?>" id="sticker-item-<?php echo $idx; ?>">
                                
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
                                <div class="sticker-footer">
                                    <div class="sticker-asset-number">
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
// สคริปต์สำหรับการ Generate QR Code ลงในแต่ละดวงสติกเกอร์
document.addEventListener("DOMContentLoaded", function() {
    var qrElements = document.querySelectorAll('.sticker-qrcode');
    
    qrElements.forEach(function(el, index) {
        var scanUrl = el.getAttribute('data-url');
        if (scanUrl && typeof QRCode !== 'undefined') {
            // ทำการล้างเนื้อหาเดิมก่อนสร้างใหม่
            el.innerHTML = '';
            
            new QRCode(el, {
                text: scanUrl,
                width: 105,
                height: 105,
                colorDark: "#000000",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.M
            });
        }
    });
});
</script>

<?php
// โหลดส่วนท้าย Layout หลัก (Scripts & Sticky Footer)
require_once __DIR__ . '/../component/admin_footer.php';
?>
