<?php
session_start();

// ตรวจสอบสิทธิ์ (ต้องเป็น Admin)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

require_once '../control/connectPDO_dev.php';

$useraccountName = $_SESSION['Sename'] ?? 'Admin';
$da_id = $_GET['da_id'] ?? $_GET['DAID'] ?? '';

if (empty($da_id)) {
    echo "<script>alert('ไม่พบรหัสครุภัณฑ์'); window.location.href='manage_data.php';</script>";
    exit();
}

// 1. ดึงข้อมูลครุภัณฑ์ที่ต้องการแก้ไข
$stmtDA = $pdo->prepare("
    SELECT da.*, c.classificationName, bd.buildingName, cr.classroomName 
    FROM durable_articles da
    LEFT JOIN classification c ON c.classificationID = da.classificationID
    LEFT JOIN building bd ON bd.buildingID = da.buildingID
    LEFT JOIN building_classroom cr ON cr.classroomID = da.classroomID
    WHERE da.DA_ID = :da_id
");
$stmtDA->execute([':da_id' => $da_id]);
$item = $stmtDA->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    echo "<script>alert('ไม่พบข้อมูลครุภัณฑ์รหัส: " . htmlspecialchars($da_id) . "'); window.location.href='manage_data.php';</script>";
    exit();
}

// ล้างเครื่องหมายคำพูด (stray quotes) ที่อาจติดมาจากการนำเข้า
$cleanNumber   = trim($item['DA_Number'] ?? '', "'\"");
$cleanYear     = trim($item['DA_Year'] ?? '', "'\"");
$cleanBrand    = trim($item['DA_Brand'] ?? '', "'\"");
$cleanSize     = trim($item['DA_Size'] ?? '', "'\"");
$cleanLocation = trim($item['DA_DetailLocation'] ?? '', "'\"");
$cleanDetail   = trim($item['DA_Detail'] ?? '', "'\"");
$priceVal      = is_numeric($item['DA_Price']) ? (float)$item['DA_Price'] : 0.00;

// คำนวณอายุการใช้งาน
$YearNow = (int)date("Y") + 543;
$ageStr = '-';
if (!empty($cleanYear) && is_numeric($cleanYear) && (int)$cleanYear > 2400) {
    $diff = $YearNow - (int)$cleanYear;
    $ageStr = ($diff >= 0 ? $diff : 0) . ' ปี';
}

$errorMsg   = '';
$successMsg = '';

// 2. เมื่อกดปุ่มบันทึก (POST)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $da_number         = trim($_POST['DA_Number'] ?? '');
    $da_listname       = trim($_POST['DA_ListName'] ?? '');
    $da_brand          = trim($_POST['DA_Brand'] ?? '');
    $da_size           = trim($_POST['DA_Size'] ?? '');
    $class_id          = trim($_POST['classificationID'] ?? '');
    $build_id          = trim($_POST['buildingID'] ?? '');
    $classroom_id      = trim($_POST['classroomID'] ?? '');
    $da_detail_loc     = trim($_POST['DA_DetailLocation'] ?? '');
    $da_year           = trim($_POST['DA_Year'] ?? '');
    $da_price_raw      = str_replace(',', '', trim($_POST['DA_Price'] ?? '0'));
    $da_price          = is_numeric($da_price_raw) ? (float)$da_price_raw : 0.00;
    $status_id         = trim($_POST['statusID'] ?? 's0001');
    $da_detail         = trim($_POST['DA_Detail'] ?? '');
    $owner_department  = trim($_POST['owner_department'] ?? 'ส่วนกลาง');
    $is_visible        = isset($_POST['is_visible']) ? (int)$_POST['is_visible'] : 1;

    // จัดการอัปโหลดรูปภาพใหม่ (ถ้ามี)
    $imgFileName = $item['DA_URLImg'] ?? '';
    if (isset($_FILES['imgDA']) && $_FILES['imgDA']['error'] === UPLOAD_ERR_OK) {
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $fileInfo    = pathinfo($_FILES['imgDA']['name']);
        $ext         = strtolower($fileInfo['extension'] ?? '');

        if (in_array($ext, $allowedExts)) {
            $uploadDir = '../upload_imgDA/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }
            $newFileName = 'DA_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $targetPath  = $uploadDir . $newFileName;

            if (move_uploaded_file($_FILES['imgDA']['tmp_name'], $targetPath)) {
                $imgFileName = $newFileName;
            } else {
                $errorMsg = "ไม่สามารถบันทึกไฟล์รูปภาพไปยังโฟลเดอร์ upload_imgDA ได้";
            }
        } else {
            $errorMsg = "ไฟล์รูปภาพต้องเป็นนามสกุล jpg, jpeg, png, gif หรือ webp เท่านั้น";
        }
    }

    if (empty($errorMsg)) {
        try {
            $sqlUp = "UPDATE durable_articles 
                      SET DA_Number         = :num, 
                          DA_ListName       = :lname, 
                          DA_Brand          = :brand, 
                          DA_Size           = :size,
                          classificationID  = :class, 
                          buildingID        = :build, 
                          classroomID       = :classroom,
                          DA_DetailLocation = :loc,
                          DA_Year           = :year,
                          DA_Price          = :price,
                          statusID          = :status,
                          DA_Detail         = :detail,
                          owner_department  = :dept,
                          is_visible        = :vis,
                          DA_URLImg         = :img
                      WHERE DA_ID = :da_id";

            $stmtUp = $pdo->prepare($sqlUp);
            $stmtUp->execute([
                ':num'       => $da_number,
                ':lname'     => $da_listname,
                ':brand'     => $da_brand,
                ':size'      => $da_size,
                ':class'     => $class_id,
                ':build'     => $build_id,
                ':classroom' => $classroom_id,
                ':loc'       => $da_detail_loc,
                ':year'      => $da_year,
                ':price'     => $da_price,
                ':status'    => $status_id,
                ':detail'    => $da_detail,
                ':dept'      => $owner_department,
                ':vis'       => $is_visible,
                ':img'       => $imgFileName,
                ':da_id'     => $da_id
            ]);

            echo "<script>alert('✅ บันทึกการแก้ไขข้อมูลครุภัณฑ์เรียบร้อยแล้ว!'); window.location.href='manage_data.php';</script>";
            exit();
        } catch (PDOException $e) {
            $errorMsg = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
        }
    }
}

// 3. ดึงตัวเลือกสำหรับ Dropdown ต่างๆ
$classifications = $pdo->query("SELECT * FROM classification ORDER BY classificationName ASC")->fetchAll(PDO::FETCH_ASSOC);
$buildings       = $pdo->query("SELECT * FROM building ORDER BY buildingName ASC")->fetchAll(PDO::FETCH_ASSOC);
$statusList      = $pdo->query("SELECT * FROM status_active ORDER BY statusID ASC")->fetchAll(PDO::FETCH_ASSOC);
$allClassrooms   = $pdo->query("SELECT * FROM building_classroom ORDER BY classroomName ASC")->fetchAll(PDO::FETCH_ASSOC);

// รูปภาพปัจจุบัน
$currentImgSrc = '../img/noimg.jpg';
if (!empty($item['DA_URLImg']) && $item['DA_URLImg'] !== 'noimg.jpg') {
    $cleanImg = basename($item['DA_URLImg']);
    if (file_exists('../upload_imgDA/' . $cleanImg)) {
        $currentImgSrc = '../upload_imgDA/' . $cleanImg;
    }
}
$pageTitle = "แก้ไขข้อมูลครุภัณฑ์ - " . ($item['DA_ListName'] ?? $da_id);
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>
<style>
    .card { border-radius: 12px; }
    .form-label { font-weight: 500; color: #334155; margin-bottom: 6px; }
    .preview-img-container {
        max-width: 300px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        padding: 4px;
        background: #ffffff;
        margin-top: 10px;
    }
</style>

<div class="container-fluid px-0 mb-5">
    <div class="row justify-content-center">
        <div class="col-lg-11 col-xl-10">
            <!-- ปุ่มย้อนกลับด้านบน -->
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <a href="manage_data.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 shadow-xs">
                    <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับไปจัดการครุภัณฑ์
                </a>
                <div class="d-flex gap-2">
                    <a href="page_Repair_AddEdit.php?DAID=<?= urlencode($da_id) ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3 shadow-xs">
                        <i class="fa-solid fa-screwdriver-wrench me-1"></i> ประวัติการซ่อมบำรุง
                    </a>
                    <a href="print_qr.php?da_id=<?= urlencode($da_id) ?>" target="_blank" class="btn btn-outline-dark btn-sm rounded-pill px-3 shadow-xs">
                        <i class="fa-solid fa-qrcode me-1"></i> พิมพ์ QR Code
                    </a>
                </div>
            </div>

            <!-- ฟอร์มแก้ไขข้อมูลครุภัณฑ์แบบดั้งเดิม (Traditional Form Layout) -->
            <div class="card shadow-sm border-0 bg-white p-4 mb-4">
        
        <div class="d-flex justify-content-between align-items-center pb-3 mb-4 border-bottom">
            <h5 class="fw-bold text-dark mb-0">
                <i class="fa-solid fa-address-book text-primary me-2"></i>แก้ไขข้อมูลครุภัณฑ์
            </h5>
            <div class="text-muted small">
                รหัสระบบ: <span class="font-monospace fw-bold text-primary"><?= htmlspecialchars($da_id) ?></span>
                <?php if ($ageStr !== '-'): ?>
                    | อายุการใช้งาน: <span class="badge bg-info-subtle text-info-emphasis border"><?= $ageStr ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($errorMsg)): ?>
            <div class="alert alert-danger shadow-sm border-danger rounded-3 py-3 px-4 mb-4">
                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data" id="fromdurableArticles">
            <input type="hidden" value="<?= htmlspecialchars($da_id); ?>" id="txt_ID_DA" name="txt_ID_DA">

            <!-- 1. หมวดหมู่ -->
            <div class="mb-3">
                <label class="form-label">หมวดหมู่ <span class="text-danger">*</span></label>
                <select class="form-select" id="selectClassification" name="classificationID" required>
                    <option value="">-- เลือกหมวดหมู่ --</option>
                    <?php foreach($classifications as $c): ?>
                        <option value="<?= htmlspecialchars($c['classificationID']); ?>" <?= ($item['classificationID'] == $c['classificationID']) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($c['classificationName']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 2. อาคาร -->
            <div class="mb-3">
                <label class="form-label">อาคาร <span class="text-danger">*</span></label>
                <select class="form-select" id="selectBuilding" name="buildingID" onchange="filterClassrooms();" required>
                    <option value="">-- เลือกอาคาร --</option>
                    <?php foreach($buildings as $b): ?>
                        <option value="<?= htmlspecialchars($b['buildingID']); ?>" <?= ($item['buildingID'] == $b['buildingID']) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($b['buildingName']); ?> (<?= htmlspecialchars($b['buildingID']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 3. ห้อง -->
            <div class="mb-3">
                <label class="form-label">ห้อง</label>
                <select class="form-select" id="selectClassroom" name="classroomID">
                    <option value="">-- เลือกห้อง --</option>
                    <?php foreach($allClassrooms as $cr): ?>
                        <option value="<?= htmlspecialchars($cr['classroomID']); ?>" 
                                data-building="<?= htmlspecialchars($cr['buildingID']); ?>"
                                <?= ($item['classroomID'] == $cr['classroomID']) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($cr['classroomName']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 4. รายละเอียดสถานที่ -->
            <div class="mb-3">
                <label class="form-label">รายละเอียดสถานที่</label>
                <input type="text" class="form-control" id="daDetailLocatin" name="DA_DetailLocation" placeholder="กรุณากรอก รายละเอียดสถานที่" value="<?= htmlspecialchars($cleanLocation); ?>">
            </div>

            <!-- 5. รายการ -->
            <div class="mb-3">
                <label class="form-label">รายการ <span class="text-danger">*</span></label>
                <input type="text" class="form-control fw-semibold" id="daListName" name="DA_ListName" placeholder="กรุณากรอกรายการ" value="<?= htmlspecialchars($item['DA_ListName'] ?? ''); ?>" required>
            </div>

            <!-- 6. ยี่ห้อ / รุ่น -->
            <div class="mb-3">
                <label class="form-label">ยี่ห้อ / รุ่น</label>
                <input type="text" class="form-control" id="daBrand" name="DA_Brand" placeholder="กรุณากรอก ยี่ห้อ / รุ่น" value="<?= htmlspecialchars($cleanBrand); ?>">
            </div>

            <!-- 7. ขนาด -->
            <div class="mb-3">
                <label class="form-label">ขนาด</label>
                <input type="text" class="form-control" id="daSize" name="DA_Size" placeholder="กรุณากรอก ขนาด Size" value="<?= htmlspecialchars($cleanSize); ?>">
            </div>

            <!-- 8. หมายเลขครุภัณฑ์ -->
            <div class="mb-3">
                <label class="form-label">หมายเลขครุภัณฑ์ <span class="text-danger">*</span></label>
                <input type="text" class="form-control font-monospace fw-bold text-primary" id="daNumber" name="DA_Number" placeholder="กรุณากรอก หมายเลขครุภัณฑ์" value="<?= htmlspecialchars($cleanNumber); ?>" required>
            </div>

            <!-- 9. ปีเริ่มใช้งาน -->
            <div class="mb-3">
                <label class="form-label">ปีเริ่มใช้งาน</label>
                <select class="form-select font-monospace" id="daYear" name="DA_Year">
                    <option value="">-- เลือกปี --</option>
                    <?php for ($i = 0; $i <= 50; $i++): ?>
                        <?php $y = (int)date("Y") - $i + 543; ?>
                        <option value="<?= $y ?>" <?= ($cleanYear == (string)$y) ? 'selected' : ''; ?>>
                            พ.ศ. <?= $y ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <!-- 10. ราคา -->
            <div class="mb-3">
                <label class="form-label">ราคา (บาท)</label>
                <input type="number" step="0.01" class="form-control font-monospace" id="daPrice" name="DA_Price" placeholder="กรุณากรอก ราคาครุภัณฑ์" value="<?= ($priceVal > 0) ? number_format($priceVal, 2, '.', '') : ''; ?>">
            </div>

            <!-- 11. สถานะ -->
            <div class="mb-3">
                <label class="form-label">สถานะ <span class="text-danger">*</span></label>
                <select class="form-select" id="selectStatusActive" name="statusID" required>
                    <?php foreach($statusList as $s): ?>
                        <option value="<?= htmlspecialchars($s['statusID']); ?>" <?= ($item['statusID'] == $s['statusID']) ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($s['statusName']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 12. แผนกผู้รับผิดชอบ -->
            <div class="mb-3">
                <label class="form-label">แผนกผู้รับผิดชอบ</label>
                <input type="text" class="form-control" name="owner_department" value="<?= htmlspecialchars($item['owner_department'] ?? 'ส่วนกลาง') ?>" placeholder="เช่น ส่วนกลาง หรือ ฝ่ายบริหาร">
            </div>

            <!-- 13. โหมดการแสดงผล (Visibility) -->
            <div class="mb-3">
                <label class="form-label">การแสดงผล (Visibility)</label>
                <select class="form-select" name="is_visible">
                    <option value="1" <?= (($item['is_visible'] ?? 1) == 1) ? 'selected' : ''; ?>>🟢 แสดงผลปกติ (Active)</option>
                    <option value="0" <?= (($item['is_visible'] ?? 1) == 0) ? 'selected' : ''; ?>>⚪ ซ่อนข้อมูล (CLB/Inactive)</option>
                </select>
            </div>

            <!-- 14. หมายเหตุ -->
            <div class="mb-3">
                <label class="form-label">หมายเหตุ</label>
                <textarea class="form-control" id="daDetail" name="DA_Detail" rows="3" placeholder="หมายเหตุหรือรายละเอียดเพิ่มเติม"><?= htmlspecialchars($cleanDetail); ?></textarea>
            </div>

            <!-- 15. อัพโหลดรูปภาพครุภัณฑ์ -->
            <div class="mb-4">
                <label class="form-label">อัพโหลดรูปภาพครุภัณฑ์</label>
                <input class="form-control" type="file" id="imgDA" name="imgDA" accept="image/*" onchange="previewUploadImage(this);">
                
                <div class="d-flex align-items-center gap-3 mt-3 flex-wrap">
                    <div>
                        <div class="small text-muted mb-1">รูปภาพปัจจุบัน:</div>
                        <div class="preview-img-container shadow-sm text-center">
                            <img id="previewImg" src="<?= htmlspecialchars($currentImgSrc) ?>" class="img-fluid rounded" style="max-height: 200px;" alt="รูปภาพครุภัณฑ์" onerror="this.src='../img/noimg.jpg';">
                        </div>
                    </div>
                    <div>
                        <div class="small text-muted mb-1">QR Code:</div>
                        <div class="preview-img-container shadow-sm text-center p-2">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode($cleanNumber ?: $da_id) ?>" alt="QR Code" class="img-fluid" style="width: 120px; height: 120px;">
                            <div class="font-monospace small text-muted mt-1"><?= htmlspecialchars($cleanNumber ?: $da_id) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ปุ่มบันทึกข้อมูล -->
            <div class="pt-3 border-top d-flex gap-2">
                <button type="submit" class="btn btn-success px-4 py-2 fw-semibold">
                    <i class="fa-solid fa-save me-1"></i> บันทึกการแก้ไขข้อมูล
                </button>
                <a href="manage_data.php" class="btn btn-outline-secondary px-4 py-2">
                    ยกเลิก
                </a>
            </div>

        </form>

            </div><!-- /.card -->
        </div><!-- /.col -->
    </div><!-- /.row -->
</div><!-- /.container-fluid -->

<!-- Scripts -->
<script>
    // แสดงตัวอย่างรูปภาพเมื่อเลือกไฟล์ใหม่
    function previewUploadImage(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('previewImg').src = e.target.result;
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    // กรองตัวเลือกห้องเรียนตามอาคารที่เลือก
    function filterClassrooms() {
        const bldSelect = document.getElementById('selectBuilding');
        const crSelect  = document.getElementById('selectClassroom');
        const selectedBuilding = bldSelect.value;
        const currentSelectedRoom = "<?= htmlspecialchars($item['classroomID'] ?? '') ?>";

        const options = crSelect.querySelectorAll('option');
        options.forEach(opt => {
            if (opt.value === '') {
                opt.style.display = '';
                return;
            }
            const optBld = opt.getAttribute('data-building');
            if (!selectedBuilding || optBld === selectedBuilding) {
                opt.style.display = '';
            } else {
                opt.style.display = 'none';
            }
        });
    }

    // เรียกทำงานเมื่อโหลดหน้าเสร็จ
    document.addEventListener('DOMContentLoaded', function() {
        filterClassrooms();
    });
</script>
<?php require_once __DIR__ . '/../component/admin_footer.php'; ?>
