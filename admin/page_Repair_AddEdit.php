<?php
session_start();

// ตรวจสอบสิทธิ์การเข้าถึง (ต้องเป็น Admin เท่านั้น)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

require_once '../control/connectPDO_dev.php';

$useraccountName = $_SESSION['Sename'] ?? 'Admin';
$currentUserID   = $_SESSION['SeUser'] ?? '';
$currentUserDept = $_SESSION['userDepartment'] ?? '';
$isSuperAdmin    = ($currentUserID === 'admin');

// รับรหัสครุภัณฑ์ (รองรับทั้ง DAID และ da_id)
$da_id = trim($_GET['DAID'] ?? $_GET['da_id'] ?? $_POST['da_id'] ?? '');

// =============================================================================
// จัดการคำขอ AJAX สำหรับ Repair (Add / Update / Delete)
// =============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    if (empty($da_id)) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัสครุภัณฑ์ (DA_ID)']);
        exit();
    }

    try {
        if ($action === 'add') {
            $repairDate    = trim($_POST['repair_Date'] ?? date('Y-m-d'));
            $repairHistory = trim($_POST['repair_Hisory'] ?? '');
            $repairPrice   = (float)($_POST['repair_Price'] ?? 0);

            if (empty($repairHistory) || empty($repairDate)) {
                echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกวันที่และรายการซ่อมบำรุงให้ครบถ้วน']);
                exit();
            }

            // คำนวณ repairID ถัดไป
            $nextId = (int)$pdo->query("SELECT MAX(CAST(repairID AS INTEGER)) FROM repair")->fetchColumn() + 1;
            if ($nextId < 1) $nextId = 1;

            $stmt = $pdo->prepare("
                INSERT INTO repair (repairID, repair_Hisory, repair_Price, DA_ID, repair_Date) 
                VALUES (:repairID, :repair_Hisory, :repair_Price, :DA_ID, :repair_Date)
            ");
            $stmt->execute([
                ':repairID'       => (string)$nextId,
                ':repair_Hisory'  => $repairHistory,
                ':repair_Price'   => $repairPrice,
                ':DA_ID'          => $da_id,
                ':repair_Date'    => $repairDate
            ]);

            echo json_encode(['status' => 'success', 'message' => 'บันทึกประวัติการซ่อมบำรุงเรียบร้อยแล้ว']);
            exit();

        } elseif ($action === 'update') {
            $repairID      = trim($_POST['repairID'] ?? '');
            $repairDate    = trim($_POST['repair_Date'] ?? date('Y-m-d'));
            $repairHistory = trim($_POST['repair_Hisory'] ?? '');
            $repairPrice   = (float)($_POST['repair_Price'] ?? 0);

            if (empty($repairID) || empty($repairHistory) || empty($repairDate)) {
                echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
                exit();
            }

            $stmt = $pdo->prepare("
                UPDATE repair 
                SET repair_Hisory = :repair_Hisory, repair_Price = :repair_Price, repair_Date = :repair_Date 
                WHERE repairID = :repairID AND DA_ID = :DA_ID
            ");
            $stmt->execute([
                ':repair_Hisory' => $repairHistory,
                ':repair_Price'  => $repairPrice,
                ':repair_Date'   => $repairDate,
                ':repairID'      => $repairID,
                ':DA_ID'         => $da_id
            ]);

            echo json_encode(['status' => 'success', 'message' => 'แก้ไขข้อมูลประวัติการซ่อมบำรุงเรียบร้อยแล้ว']);
            exit();

        } elseif ($action === 'delete') {
            $repairID = trim($_POST['repairID'] ?? '');
            if (empty($repairID)) {
                echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัสรายการซ่อมที่ต้องการลบ']);
                exit();
            }

            $stmt = $pdo->prepare("DELETE FROM repair WHERE repairID = :repairID AND DA_ID = :DA_ID");
            $stmt->execute([
                ':repairID' => $repairID,
                ':DA_ID'    => $da_id
            ]);

            echo json_encode(['status' => 'success', 'message' => 'ลบรายการประวัติการซ่อมเรียบร้อยแล้ว']);
            exit();
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
}

// ตรวจสอบว่ามีรหัสครุภัณฑ์หรือไม่ (หากไม่ระบุ ให้ไปที่หน้ารวมบันทึกการซ่อม)
if (empty($da_id)) {
    header("Location: manage_repairs.php");
    exit();
}

// =============================================================================
// ดึงข้อมูลครุภัณฑ์เป้าหมาย
// =============================================================================
$stmtDA = $pdo->prepare("
    SELECT 
        da.*, 
        c.classificationName,
        bd.buildingName,
        cr.classroomName,
        sa.statusName
    FROM durable_articles da
    LEFT JOIN classification c ON c.classificationID = da.classificationID 
    LEFT JOIN status_active sa ON sa.statusID = da.statusID
    LEFT JOIN building bd ON bd.buildingID = da.buildingID
    LEFT JOIN building_classroom cr ON cr.classroomID = da.classroomID
    WHERE da.DA_ID = :id
");
$stmtDA->execute([':id' => $da_id]);
$article = $stmtDA->fetch(PDO::FETCH_ASSOC);

if (!$article) {
    die("ไม่พบข้อมูลครุภัณฑ์รหัส {$da_id} ในระบบ <a href='manage_data.php'>กลับสู่หน้ารายการ</a>");
}

// ตรวจสอบสิทธิ์แผนก (ยกเว้น Super Admin)
if (!$isSuperAdmin && !empty($currentUserDept)) {
    if ($article['owner_department'] !== $currentUserDept && $article['created_by'] !== $currentUserID) {
        die("คุณไม่มีสิทธิ์เข้าถึงหรือจัดการประวัติการซ่อมของครุภัณฑ์ต่างแผนก <a href='manage_data.php'>กลับสู่หน้ารายการ</a>");
    }
}

// คำนวณอายุการใช้งาน
$YearNow   = (int)date("Y") + 543;
$cleanYear = trim(str_replace("'", "", $article['DA_Year'] ?? ''));
$lifeSpan  = ($cleanYear === '0000' || empty($cleanYear) || !is_numeric($cleanYear)) ? 'ไม่ทราบอายุใช้งาน' : (($YearNow - (int)$cleanYear) . ' ปี');

// ดึงรายการประวัติการซ่อมทั้งหมดของครุภัณฑ์นี้
$stmtRepairs = $pdo->prepare("SELECT * FROM repair WHERE DA_ID = :id ORDER BY repair_Date DESC, CAST(repairID AS INTEGER) DESC");
$stmtRepairs->execute([':id' => $da_id]);
$repairsList = $stmtRepairs->fetchAll(PDO::FETCH_ASSOC);

$totalRepairCost = 0.0;
foreach ($repairsList as $r) {
    $totalRepairCost += (float)($r['repair_Price'] ?? 0);
}
$pageTitle = "ประวัติการซ่อมบำรุง - " . ($article['DA_ListName'] ?? $da_id);
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>
<style>
    .card { border-radius: 12px; }
    .detail-label { font-size: 0.82rem; font-weight: 600; color: #64748b; text-transform: uppercase; }
    .detail-value { font-size: 0.95rem; font-weight: 500; color: #1e293b; }
</style>

<div class="container-fluid px-0 mb-5">
    
    <!-- Top Action Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <a href="manage_data.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> ย้อนกลับ
            </a>
            <h4 class="fw-bold text-dark mb-0"><i class="fa-solid fa-screwdriver-wrench text-warning me-2"></i>ประวัติการซ่อมบำรุงครุภัณฑ์</h4>
        </div>
        <div class="d-flex gap-2">
            <a href="edit_durable_article.php?da_id=<?= urlencode($da_id) ?>" class="btn btn-outline-warning btn-sm rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-pen-to-square me-1"></i> แก้ไขข้อมูลครุภัณฑ์นี้
            </a>
            <a href="print_qr.php?da_id=<?= urlencode($da_id) ?>" target="_blank" class="btn btn-outline-dark btn-sm rounded-pill px-3 shadow-sm">
                <i class="fa-solid fa-qrcode me-1"></i> พิมพ์ QR Code
            </a>
        </div>
    </div>

    <!-- ข้อมูลสรุปครุภัณฑ์ (Asset Summary Card) -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <div class="fw-bold text-primary">
                <i class="fa-solid fa-circle-info me-1"></i> ข้อมูลจำเพาะของครุภัณฑ์
            </div>
            <span class="badge bg-light text-secondary border font-monospace">ID: <?= htmlspecialchars($article['DA_ID']) ?></span>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-3 col-sm-6">
                    <div class="detail-label">หมายเลขครุภัณฑ์</div>
                    <div class="detail-value font-monospace fw-bold text-primary"><?= htmlspecialchars(trim($article['DA_Number'] ?? '', "'\"")) ?></div>
                </div>
                <div class="col-md-5 col-sm-6">
                    <div class="detail-label">รายการครุภัณฑ์</div>
                    <div class="detail-value fw-bold text-dark"><?= htmlspecialchars($article['DA_ListName']) ?></div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <div class="detail-label">หมวดหมู่</div>
                    <div class="detail-value"><span class="badge bg-info-subtle text-info-emphasis border"><?= htmlspecialchars($article['classificationName'] ?? 'ไม่ระบุ') ?></span></div>
                </div>

                <div class="col-md-3 col-sm-6">
                    <div class="detail-label">ยี่ห้อ / รุ่น</div>
                    <div class="detail-value"><?= htmlspecialchars($article['DA_Brand'] ?: '-') ?></div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <div class="detail-label">ขนาด / มิติ</div>
                    <div class="detail-value"><?= htmlspecialchars($article['DA_Size'] ?: '-') ?></div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <div class="detail-label">สถานที่ติดตั้ง</div>
                    <div class="detail-value">
                        <i class="fa-solid fa-location-dot text-danger me-1"></i>
                        <?= htmlspecialchars(trim(($article['buildingName'] ?? ($article['buildingID'] ?: '')) . ' ' . ($article['classroomName'] ?? ''))) ?>
                        <?php if (!empty($article['DA_DetailLocation'])): ?>
                            <small class="text-muted">(<?= htmlspecialchars($article['DA_DetailLocation']) ?>)</small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="detail-label">ราคาจัดซื้อ</div>
                    <div class="detail-value font-monospace text-success fw-bold">฿<?= number_format((float)$article['DA_Price'], 2) ?> บาท</div>
                </div>

                <div class="col-md-3 col-sm-6">
                    <div class="detail-label">เริ่มใช้งาน (พ.ศ.)</div>
                    <div class="detail-value font-monospace"><?= htmlspecialchars($cleanYear ?: 'ไม่ทราบปี') ?></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="detail-label">อายุการใช้งาน</div>
                    <div class="detail-value font-monospace text-primary fw-bold"><?= htmlspecialchars($lifeSpan) ?></div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="detail-label">สถานะปัจจุบัน</div>
                    <div class="detail-value">
                        <span class="badge <?= ($article['statusID'] === 's0001') ? 'bg-success' : 'bg-danger' ?>">
                            <?= htmlspecialchars($article['statusName'] ?? 'ไม่ระบุ') ?>
                        </span>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="detail-label">แผนกเจ้าของ</div>
                    <div class="detail-value"><span class="badge bg-light text-dark border"><?= htmlspecialchars($article['owner_department'] ?? 'ส่วนกลาง') ?></span></div>
                </div>

                <?php if (!empty($article['DA_Detail'])): ?>
                <div class="col-12">
                    <div class="detail-label">หมายเหตุ / รายละเอียดเพิ่มเติม</div>
                    <div class="detail-value small text-secondary bg-light p-2 rounded border"><?= nl2br(htmlspecialchars($article['DA_Detail'])) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ฟอร์มเพิ่ม / แก้ไขประวัติการซ่อมบำรุง -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom">
            <h6 class="fw-bold text-dark mb-0" id="formTitle">
                <i class="fa-solid fa-file-circle-plus text-success me-2"></i>เพิ่มบันทึกประวัติการซ่อมบำรุง
            </h6>
        </div>
        <div class="card-body p-4">
            <form id="repairForm">
                <input type="hidden" name="da_id" id="txt_daID" value="<?= htmlspecialchars($da_id) ?>">
                <input type="hidden" name="repairID" id="txt_repairID" value="">
                <input type="hidden" name="action" id="txt_action" value="add">

                <div class="row g-3">
                    <div class="col-md-3 col-sm-12">
                        <label class="form-label fw-bold text-secondary small mb-1">
                            <i class="fa-solid fa-calendar me-1"></i> วันที่ซ่อมบำรุง: <span class="text-danger">*</span>
                        </label>
                        <input type="date" class="form-control form-control-sm" id="txt_DateRepairHisory" name="repair_Date" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="col-md-5 col-sm-12">
                        <label class="form-label fw-bold text-secondary small mb-1">
                            <i class="fa-solid fa-wrench me-1"></i> รายการซ่อมบำรุง / อาการเสีย: <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control form-control-sm" id="txt_RepairHisory" name="repair_Hisory" placeholder="ระบุอาการเสีย หรือรายการที่ดำเนินการซ่อม..." required>
                    </div>

                    <div class="col-md-2 col-sm-12">
                        <label class="form-label fw-bold text-secondary small mb-1">
                            <i class="fa-solid fa-money-bill-wave me-1"></i> ค่าใช้จ่าย (บาท):
                        </label>
                        <input type="number" step="0.01" class="form-control form-control-sm" id="txt_RepairPrice" name="repair_Price" placeholder="0.00" value="0.00">
                    </div>

                    <div class="col-md-2 col-sm-12 d-flex align-items-end gap-2">
                        <button type="submit" id="btnSubmitRepair" class="btn btn-success btn-sm flex-grow-1 shadow-sm fw-semibold">
                            <i class="fa-solid fa-save me-1"></i> บันทึก
                        </button>
                        <button type="button" id="btnResetRepair" onclick="resetRepairForm()" class="btn btn-outline-secondary btn-sm shadow-sm" title="ล้างฟอร์ม">
                            <i class="fa-solid fa-rotate-left"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ตารางแสดงรายการประวัติการซ่อม -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="fw-bold text-dark mb-0">
                <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>รายการประวัติการซ่อมบำรุงทั้งหมด
            </h6>
            <span class="badge bg-primary rounded-pill"><?= count($repairsList) ?> รายการ</span>
        </div>
        <div class="card-body p-4">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle border" id="repairsTable">
                    <thead class="table-light text-center">
                        <tr>
                            <th style="width: 70px;">ลำดับ</th>
                            <th style="width: 130px;">วันที่ซ่อม</th>
                            <th>รายการซ่อมบำรุง / รายละเอียด</th>
                            <th class="text-end" style="width: 160px;">ค่าใช้จ่าย (บาท)</th>
                            <th style="width: 140px;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($repairsList)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="fa-solid fa-circle-check fs-3 text-success mb-2 d-block"></i>
                                    ยังไม่มีประวัติการซ่อมบำรุงสำหรับครุภัณฑ์ชิ้นนี้
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $idx = 1; foreach ($repairsList as $rep): ?>
                            <tr>
                                <td class="text-center font-monospace text-muted"><?= $idx++ ?></td>
                                <td class="text-center font-monospace"><?= htmlspecialchars($rep['repair_Date'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($rep['repair_Hisory']) ?></td>
                                <td class="text-end font-monospace fw-bold text-dark">
                                    ฿<?= number_format((float)$rep['repair_Price'], 2) ?>
                                </td>
                                <td class="text-center">
                                    <button type="button" 
                                            class="btn btn-outline-warning btn-sm rounded-pill px-2 py-0" 
                                            onclick="editRepairRow('<?= htmlspecialchars($rep['repairID']) ?>', '<?= htmlspecialchars($rep['repair_Hisory'], ENT_QUOTES) ?>', '<?= (float)$rep['repair_Price'] ?>', '<?= htmlspecialchars($rep['repair_Date']) ?>')"
                                            title="แก้ไขรายการ">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <button type="button" 
                                            class="btn btn-outline-danger btn-sm rounded-pill px-2 py-0 ms-1" 
                                            onclick="deleteRepairRow('<?= htmlspecialchars($rep['repairID']) ?>')"
                                            title="ลบรายการ">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3" class="text-end fw-bold">รวมค่าใช้จ่ายในการซ่อมบำรุงทั้งสิ้น:</th>
                            <th class="text-end font-monospace text-danger fw-bold fs-6">
                                ฿<?= number_format($totalRepairCost, 2) ?> บาท
                            </th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Scripts -->
<script>
    $(document).ready(function() {
        <?php if (!empty($repairsList)): ?>
        $('#repairsTable').DataTable({
            "language": { "url": "//cdn.datatables.net/plug-ins/1.13.4/i18n/th.json" },
            "pageLength": 10,
            "order": [] // เรียงลำดับจากใหม่ไปเก่าตาม SQL (repair_Date DESC)
        });
        <?php endif; ?>

        // บันทึก / แก้ไขข้อมูลผ่าน AJAX
        $('#repairForm').on('submit', function(e) {
            e.preventDefault();
            const formData = $(this).serialize();

            Swal.fire({
                title: 'กำลังบันทึกข้อมูล...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            $.ajax({
                url: 'page_Repair_AddEdit.php',
                type: 'POST',
                dataType: 'json',
                data: formData,
                success: function(resp) {
                    if (resp.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'สำเร็จ!',
                            text: resp.message,
                            timer: 1300,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: resp.message || 'ไม่สามารถบันทึกข้อมูลได้'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาดในการเชื่อมต่อ',
                        text: error
                    });
                }
            });
        });
    });

    function resetRepairForm() {
        $('#txt_repairID').val('');
        $('#txt_action').val('add');
        $('#txt_RepairHisory').val('');
        $('#txt_RepairPrice').val('0.00');
        $('#txt_DateRepairHisory').val('<?= date('Y-m-d') ?>');
        $('#formTitle').html('<i class="fa-solid fa-file-circle-plus text-success me-2"></i>เพิ่มบันทึกประวัติการซ่อมบำรุง');
        $('#btnSubmitRepair').removeClass('btn-warning text-dark').addClass('btn-success text-white').html('<i class="fa-solid fa-save me-1"></i> บันทึก');
    }

    function editRepairRow(repairID, repairHistory, repairPrice, repairDate) {
        $('#txt_repairID').val(repairID);
        $('#txt_action').val('update');
        $('#txt_RepairHisory').val(repairHistory);
        $('#txt_RepairPrice').val(repairPrice);
        $('#txt_DateRepairHisory').val(repairDate);
        $('#formTitle').html('<i class="fa-solid fa-pen-to-square text-warning me-2"></i>แก้ไขรายการซ่อมบำรุง (รหัส: ' + repairID + ')');
        $('#btnSubmitRepair').removeClass('btn-success text-white').addClass('btn-warning text-dark').html('<i class="fa-solid fa-pen-to-square me-1"></i> อัปเดตข้อมูล');
        
        $('html, body').animate({
            scrollTop: $("#repairForm").offset().top - 100
        }, 300);
    }

    function deleteRepairRow(repairID) {
        Swal.fire({
            title: 'ยืนยันการลบรายการซ่อม?',
            text: 'คุณต้องการลบประวัติการซ่อมบำรุงนี้ใช่หรือไม่? การกระทำนี้ไม่สามารถย้อนกลับได้',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'ใช่, ลบรายการ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'กำลังลบข้อมูล...',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });

                $.ajax({
                    url: 'page_Repair_AddEdit.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'delete',
                        repairID: repairID,
                        da_id: '<?= htmlspecialchars($da_id) ?>'
                    },
                    success: function(resp) {
                        if (resp.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'ลบสำเร็จ!',
                                text: resp.message,
                                timer: 1300,
                                showConfirmButton: false
                            }).then(() => {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'เกิดข้อผิดพลาด',
                                text: resp.message || 'ไม่สามารถลบข้อมูลได้'
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาดในการเชื่อมต่อ',
                            text: error
                        });
                    }
                });
            }
        });
    }
</script>
<?php require_once __DIR__ . '/../component/admin_footer.php'; ?>
