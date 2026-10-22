<?php
/**
 * admin/settings_mail.php - หน้าจัดการตั้งค่าระบบส่งอีเมล (Gmail SMTP & App Password)
 * Modern & Professional UI with AJAX + SweetAlert2 + PDO พร้อมระบบเปิด/ปิดการใช้งานระบบส่งอีเมล
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------------------------
// 1. ตรวจสอบสิทธิ์การเข้าถึง (Security Check: เฉพาะ Super Admin / Admin)
// -----------------------------------------------------------------------------
$isAdmin = isset($_SESSION['Selogin']) && $_SESSION['Selogin'] == 1 
        && (($_SESSION['useraccountType'] ?? '') === 'UT00000001' || ($_SESSION['useraccountType'] ?? '') === 'Admin');

if (!$isAdmin && empty($_SESSION['SeUser'])) {
    header("Location: ../index.php");
    exit();
}

$useraccountName = $_SESSION['Sename'] ?? 'ผู้ดูแลระบบ';
$currentUserID   = $_SESSION['SeUser'] ?? '';
$isSuperAdmin    = ($currentUserID === 'admin' || $currentUserID === 'admin_test');

// -----------------------------------------------------------------------------
// 2. นำเข้าไฟล์เชื่อมต่อฐานข้อมูล PDO
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../db.php';
$condb = $pdo; // รองรับทั้งตัวแปร $condb และ $pdo

// -----------------------------------------------------------------------------
// 3. ดึงข้อมูลการตั้งค่าเดิมมาแสดงในฟอร์ม
// -----------------------------------------------------------------------------
try {
    $stmtConfig = $pdo->query("SELECT smtp_user, smtp_pass, sender_name, smtp_port, is_active FROM system_settings WHERE id = 1 LIMIT 1");
    $config = $stmtConfig ? $stmtConfig->fetch(PDO::FETCH_ASSOC) : null;

    if (!$config) {
        $pdo->exec("
            INSERT INTO system_settings (id, smtp_host, smtp_port, smtp_user, smtp_pass, sender_name, is_active, updated_at)
            VALUES (1, 'smtp.gmail.com', 465, '', '', 'ระบบบริหารจัดการครุภัณฑ์', 1, CURRENT_TIMESTAMP)
        ");
        $stmtConfig = $pdo->query("SELECT smtp_user, smtp_pass, sender_name, smtp_port, is_active FROM system_settings WHERE id = 1 LIMIT 1");
        $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $config = [];
}

// เช็คสถานะการเปิดใช้งานและความพร้อมของบัญชี
$isActive    = isset($config['is_active']) ? (int)$config['is_active'] : 1;
$isReady     = !empty($config['smtp_user']) && !empty($config['smtp_pass']);
$currentPort = !empty($config['smtp_port']) ? (string)$config['smtp_port'] : '465';
$pageTitle = "ตั้งค่าระบบส่งอีเมล (Gmail SMTP)";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>
<style>
    .card {
        border: none;
        border-radius: 14px;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.04);
    }
    .card-header {
        border-bottom: 1px solid #f1f5f9;
        background-color: #ffffff;
        border-top-left-radius: 14px !important;
        border-top-right-radius: 14px !important;
        padding: 16px 22px;
    }
    .form-label {
        font-size: 0.92rem;
        color: #1e293b;
    }
    .form-control {
        border-radius: 8px;
        padding: 10px 14px;
        border: 1px solid #cbd5e1;
    }
    .form-control:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }
    .step-number {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background-color: #ea4335;
        color: #ffffff;
        font-size: 12px;
        font-weight: bold;
        margin-right: 8px;
        flex-shrink: 0;
    }
    .form-check.active-port {
        background-color: #eff6ff !important;
        border-color: #2563eb !important;
    }
</style>

<!-- Main Container -->
<div class="container-fluid px-0 pb-5">
    
    <!-- Breadcrumb & Title -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="main.php" class="text-decoration-none">หน้าหลัก</a></li>
                    <li class="breadcrumb-item"><a href="admin_history.php" class="text-decoration-none">ระบบแจ้งเตือน</a></li>
                    <li class="breadcrumb-item active" aria-current="page">ตั้งค่าระบบส่งอีเมล</li>
                </ol>
            </nav>
            <h3 class="fw-bold mb-0 text-slate-800">
                <i class="fa-solid fa-envelope-circle-check text-primary me-2"></i>ตั้งค่าระบบส่งอีเมล (Gmail SMTP)
            </h3>
            <p class="text-muted small mb-0 mt-1">กำหนดค่าบัญชีอีเมลสำหรับส่งแจ้งเตือนครุภัณฑ์เกินกำหนดส่งคืน พร้อมปุ่มเปิด-ปิดใช้งานระบบ</p>
        </div>
        <div class="mt-2 mt-md-0">
            <a href="admin_history.php" class="btn btn-outline-secondary rounded-pill px-3 btn-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้ารายการยืม
            </a>
        </div>
    </div>

    <div class="row">
        <!-- ฝั่งซ้าย: ฟอร์มตั้งค่า -->
        <div class="col-lg-7 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fa-solid fa-sliders me-2"></i> ข้อมูลการส่งอีเมล (Gmail SMTP)
                    </h6>
                    <div id="statusBadgeContainer">
                        <?php if(!$isActive): ?>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary px-3 py-2 rounded-pill fw-semibold">
                                <i class="fa-solid fa-ban me-1"></i> ปิดใช้งานระบบ
                            </span>
                        <?php elseif($isReady): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success px-3 py-2 rounded-pill fw-semibold">
                                <i class="fa-solid fa-circle-check me-1"></i> เปิดใช้งาน (พร้อมส่ง)
                            </span>
                        <?php else: ?>
                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-3 py-2 rounded-pill fw-semibold">
                                <i class="fa-solid fa-circle-xmark me-1"></i> ยังไม่ตั้งค่า
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-4">

                    <!-- แถบสถานะระบบส่งอีเมล พร้อมปุ่มเปิด/ปิดใช้งานทันที -->
                    <div class="card mb-4 border <?= $isActive ? 'border-success-subtle bg-success bg-opacity-10' : 'border-secondary-subtle bg-secondary bg-opacity-10' ?> shadow-none rounded-3" id="statusCard">
                        <div class="card-body p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle d-flex align-items-center justify-content-center me-3 <?= $isActive ? 'bg-success text-white' : 'bg-secondary text-white' ?>" style="width: 44px; height: 44px; font-size: 20px;" id="statusIconBox">
                                    <i class="fa-solid <?= $isActive ? 'fa-envelope-circle-check' : 'fa-bell-slash' ?>"></i>
                                </div>
                                <div>
                                    <div class="fw-bold fs-6 <?= $isActive ? 'text-success' : 'text-secondary' ?>" id="statusLabel">
                                        สถานะระบบ: <span><?= $isActive ? 'เปิดใช้งานอยู่ (Active)' : 'ปิดใช้งานอยู่ (Disabled)' ?></span>
                                    </div>
                                    <div class="text-muted small" id="statusDesc">
                                        <?= $isActive 
                                            ? 'ระบบพร้อมส่งอีเมลแจ้งเตือนเมื่อกดส่งในหน้าประวัติการยืม' 
                                            : 'ระบบถูกระงับการส่งอีเมลชั่วคราว การกดส่งแจ้งเตือนในระบบจะไม่ทำงาน' ?>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($isActive): ?>
                                    <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3 py-1 fw-bold btn-toggle-status" data-status="0">
                                        <i class="fa-solid fa-ban me-1"></i> ปิดใช้งานระบบ
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-success btn-sm rounded-pill px-3 py-1 fw-bold shadow-sm btn-toggle-status" data-status="1">
                                        <i class="fa-solid fa-power-off me-1"></i> เปิดใช้งานระบบ
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <form id="mailSettingsForm">
                        <input type="hidden" name="is_active" id="is_active_input" value="<?= $isActive ?>">

                        <!-- 1. บัญชีอีเมลผู้ส่ง -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">1. บัญชีอีเมลผู้ส่ง (Gmail) <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="smtp_user" id="smtp_user" 
                                   value="<?= htmlspecialchars($config['smtp_user'] ?? '') ?>" 
                                   placeholder="เช่น yourname@gmail.com หรือ contact@organization.com" required>
                            <div class="form-text text-muted">อีเมล Gmail หรือ Google Workspace ที่จะใช้เป็นผู้ส่งจดหมายแจ้งเตือน</div>
                        </div>

                        <!-- 2. รหัสผ่านสำหรับแอป -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">2. รหัสผ่านสำหรับแอป (App Password 16 หลัก) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control font-monospace" id="smtp_pass" name="smtp_pass" 
                                       value="<?= htmlspecialchars($config['smtp_pass'] ?? '') ?>" 
                                       placeholder="เช่น abcd efgh ijkl mnop (16 หลัก)" autocomplete="new-password" required>
                                <button class="btn btn-outline-secondary" type="button" id="togglePassword" title="แสดง/ซ่อนรหัสผ่าน">
                                    <i class="fa-regular fa-eye"></i>
                                </button>
                            </div>
                            <div class="form-text text-danger">* รหัสผ่านเฉพาะแอป 16 ตัวอักษรที่สร้างจากบัญชี Google (ไม่ใช่รหัสผ่านเข้าสู่ระบบ Gmail ทั่วไป)</div>
                        </div>

                        <!-- 3. ชื่อผู้ส่ง -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">3. ชื่อผู้ส่งที่แสดงในอีเมล (Sender Name) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="sender_name" id="sender_name" 
                                   value="<?= htmlspecialchars($config['sender_name'] ?? 'ระบบบริหารจัดการครุภัณฑ์') ?>" 
                                   placeholder="เช่น งานบริหารจัดการพัสดุและครุภัณฑ์ หรือ ระบบห้องสมุด" required>
                            <div class="form-text text-muted">ชื่อองค์กรหรือหน่วยงานที่จะแสดงเป็นชื่อผู้ส่งในกล่องข้อความของผู้รับ</div>
                        </div>

                        <!-- 4. พอร์ตการเชื่อมต่อ -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">4. พอร์ตการเชื่อมต่อ (SMTP Port)</label>
                            <div class="form-text text-muted mb-2">แนะนำพอร์ต 465 สำหรับเครือข่ายที่มีไฟร์วอลล์ (เช่น เน็ตมหาวิทยาลัยหรือหอพัก)</div>
                            
                            <!-- พอร์ต 465 (SSL) -->
                            <div class="form-check border rounded p-3 mb-2 port-box <?= ($currentPort === '465') ? 'active-port' : 'bg-light' ?>" style="cursor: pointer;">
                                <input class="form-check-input ms-1" type="radio" name="smtp_port" id="port465" value="465" <?= ($currentPort === '465') ? 'checked' : '' ?>>
                                <label class="form-check-label ms-2 w-100" for="port465" style="cursor: pointer;">
                                    <strong class="text-dark">พอร์ต 465 (SSL)</strong> <span class="badge bg-success ms-1">แนะนำ</span><br>
                                    <small class="text-muted">เข้ารหัส SSL ทันที แก้ปัญหาพอร์ต 587 ถูกบล็อกในเครือข่ายองค์กร</small>
                                </label>
                            </div>

                            <!-- พอร์ต 587 (TLS) -->
                            <div class="form-check border rounded p-3 port-box <?= ($currentPort === '587') ? 'active-port' : 'bg-light' ?>" style="cursor: pointer;">
                                <input class="form-check-input ms-1" type="radio" name="smtp_port" id="port587" value="587" <?= ($currentPort === '587') ? 'checked' : '' ?>>
                                <label class="form-check-label ms-2 w-100" for="port587" style="cursor: pointer;">
                                    <strong class="text-dark">พอร์ต 587 (TLS / STARTTLS)</strong><br>
                                    <small class="text-muted">พอร์ตมาตรฐาน (เน็ตมหาลัยบางที่อาจบล็อกการเชื่อมต่อ)</small>
                                </label>
                            </div>
                        </div>

                        <hr class="my-4">
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1 py-2 fw-bold rounded-pill shadow-sm" id="btnSave">
                                <i class="fa-solid fa-floppy-disk me-1"></i> บันทึกการตั้งค่า
                            </button>
                            <?php if ($isActive): ?>
                                <button type="button" class="btn btn-outline-danger py-2 px-3 fw-bold rounded-pill btn-toggle-status" data-status="0" title="ปิดใช้งานระบบส่งอีเมลชั่วคราว">
                                    <i class="fa-solid fa-ban me-1"></i> ปิดใช้งาน
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-success py-2 px-3 fw-bold rounded-pill btn-toggle-status" data-status="1" title="เปิดใช้งานระบบส่งอีเมล">
                                    <i class="fa-solid fa-power-off me-1"></i> เปิดใช้งาน
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="text-center mt-3 small text-muted">
                            <i class="fa-solid fa-bolt text-warning me-1"></i> บันทึกเสร็จแล้วส่งได้ทันที ไม่ต้องยืนยันตัวตนซ้ำ
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ฝั่งขวา: คำแนะนำวิธีขอ App Password & แก้ไข Error -->
        <div class="col-lg-5 mb-4">
            <div class="card shadow-sm border-0 border-start border-danger border-4 h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-dark">
                        <i class="fa-brands fa-google text-danger me-2"></i> วิธีขอรหัสผ่านแอป 16 หลักจาก Google
                    </h6>
                </div>
                <div class="card-body p-4 small" style="line-height: 1.8;">
                    <p class="text-muted mb-3">
                        Google กำหนดให้โปรแกรมภายนอกใช้ <strong>"รหัสผ่านสำหรับแอป" (App Password)</strong> แทนรหัสผ่านปกติ เพื่อความปลอดภัยสูงสุด:
                    </p>

                    <div class="mb-3">
                        <div class="d-flex align-items-start mb-2">
                            <span class="step-number">1</span>
                            <div>
                                เข้าสู่ระบบบัญชี Google ของท่านที่ <br>
                                <a href="https://myaccount.google.com/security" target="_blank" class="fw-bold text-decoration-none">
                                    myaccount.google.com/security <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i>
                                </a>
                            </div>
                        </div>

                        <div class="d-flex align-items-start mb-2">
                            <span class="step-number">2</span>
                            <div>
                                ตรวจสอบว่าได้เปิด <strong>"การยืนยันแบบ 2 ขั้นตอน (2-Step Verification)"</strong> เรียบร้อยแล้ว (หากยังไม่เปิดให้เปิดก่อน)
                            </div>
                        </div>

                        <div class="d-flex align-items-start mb-2">
                            <span class="step-number">3</span>
                            <div>
                                ค้นหาคำว่า <strong>"รหัสผ่านสำหรับแอป" (App passwords)</strong> ในช่องค้นหาด้านบน หรือกดที่ลิงก์ตรง: <br>
                                <a href="https://myaccount.google.com/apppasswords" target="_blank" class="fw-bold text-danger text-decoration-none">
                                    myaccount.google.com/apppasswords <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i>
                                </a>
                            </div>
                        </div>

                        <div class="d-flex align-items-start mb-2">
                            <span class="step-number">4</span>
                            <div>
                                ตั้งชื่อแอป เช่น <code>ระบบครุภัณฑ์</code> แล้วกดปุ่ม <strong>"สร้าง" (Create)</strong>
                            </div>
                        </div>

                        <div class="d-flex align-items-start">
                            <span class="step-number">5</span>
                            <div>
                                Google จะแสดง <strong>รหัสผ่าน 16 ตัวอักษร</strong> ในแถบสีเหลือง (เช่น <code>abcd efgh ijkl mnop</code>) ให้คัดลอกมาวางในช่อง <strong>"2. รหัสผ่านสำหรับแอป"</strong> ในแบบฟอร์มด้านซ้าย แล้วกดบันทึก
                            </div>
                        </div>
                    </div>

                    <!-- กล่องข้อความแนะนำแก้ปัญหา Socket Error 61 -->
                    <div class="alert alert-warning border-0 p-3 small mb-3 rounded-3">
                        <div class="fw-bold text-dark mb-1">
                            <i class="fa-solid fa-triangle-exclamation text-danger me-1"></i> หากเจอ Socket error 61 (Connection refused):
                        </div>
                        <ul class="ps-3 mb-0" style="line-height: 1.6;">
                            <li><strong>เปลี่ยนเป็นพอร์ต 465 (SSL):</strong> เลือกพอร์ต 465 ในฟอร์มด้านซ้ายแล้วกดบันทึก</li>
                            <li><strong>แชร์เน็ตมือถือ (Hotspot):</strong> หากต่อ Wi-Fi มหาวิทยาลัยหรือหอพักที่บล็อกพอร์ตอีเมล ให้ลองต่อเน็ตมือถือทดสอบชั่วคราว</li>
                        </ul>
                    </div>

                    <div class="alert alert-success border-0 p-3 small mb-0 rounded-3">
                        <i class="fa-solid fa-circle-check text-success me-1"></i>
                        <strong>เรียบร้อย!:</strong> เมื่อบันทึกแล้ว ระบบจะสามารถส่งอีเมลแจ้งเตือนผู้ยืมครุภัณฑ์ได้ทันทีโดยไม่ต้องเชื่อมต่อ Google Cloud ใดๆ
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Scripts สำหรับ AJAX และจัดการ UI -->
<script>
$(document).ready(function() {
    // ระบบเปิด-ปิดตารหัสผ่าน
    $('#togglePassword').click(function() {
        let passInput = $('#smtp_pass');
        let icon = $(this).find('i');
        if (passInput.attr('type') === 'password') {
            passInput.attr('type', 'text');
            icon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            passInput.attr('type', 'password');
            icon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });

    // ไฮไลต์กรอบเวลากดเลือก Port
    $('input[name="smtp_port"]').change(function() {
        $('.port-box').removeClass('active-port').addClass('bg-light');
        $(this).closest('.port-box').removeClass('bg-light').addClass('active-port');
    });

    // คลิกที่กรอบเพื่อเลือก Radio Button ได้ด้วย
    $('.port-box').click(function(e) {
        if (e.target.tagName !== 'INPUT') {
            $(this).find('input[type="radio"]').prop('checked', true).trigger('change');
        }
    });

    // จัดการคลิกปุ่ม "ปิดใช้งานระบบ" / "เปิดใช้งานระบบ"
    $('.btn-toggle-status').click(function() {
        const targetStatus = parseInt($(this).data('status'), 10);
        const isDisabling  = (targetStatus === 0);

        Swal.fire({
            title: isDisabling ? 'ยืนยันการปิดใช้งานระบบส่งอีเมล?' : 'ยืนยันการเปิดใช้งานระบบส่งอีเมล?',
            html: isDisabling 
                ? 'เมื่อปิดใช้งาน ระบบจะ<strong>หยุดส่งอีเมลแจ้งเตือนทั้งหมด</strong>ชั่วคราว<br><small class="text-muted">(ข้อมูลบัญชีและการตั้งค่าจะยังคงถูกบันทึกไว้ตามเดิม)</small>' 
                : 'ระบบจะกลับมาเปิดใช้งานการส่งอีเมลแจ้งเตือนตามปกติ',
            icon: isDisabling ? 'warning' : 'question',
            showCancelButton: true,
            confirmButtonColor: isDisabling ? '#dc2626' : '#16a34a',
            cancelButtonColor: '#64748b',
            confirmButtonText: isDisabling ? '<i class="fa-solid fa-ban me-1"></i> ยืนยันปิดใช้งาน' : '<i class="fa-solid fa-power-off me-1"></i> ยืนยันเปิดใช้งาน',
            cancelButtonText: 'ยกเลิก',
            reverseButtons: true
        }).then((result) => {
            if (!result.isConfirmed) return;

            Swal.fire({
                title: 'กำลังอัปเดตสถานะ...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: 'process_mail_settings.php',
                type: 'POST',
                data: {
                    action: 'toggle_status',
                    is_active: targetStatus
                },
                dataType: 'json',
                success: function(response) {
                    if (response && response.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'สำเร็จ!',
                            text: response.message,
                            confirmButtonColor: '#16a34a'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: response.message || 'ไม่สามารถเปลี่ยนสถานะได้',
                            confirmButtonColor: '#dc2626'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX Error:", xhr.responseText);
                    Swal.fire({
                        icon: 'error',
                        title: 'ข้อผิดพลาดเครือข่าย',
                        text: 'ไม่สามารถติดต่อเซิร์ฟเวอร์เพื่อบันทึกสถานะได้ กรุณาลองใหม่อีกครั้ง',
                        confirmButtonColor: '#dc2626'
                    });
                }
            });
        });
    });

    // ส่งข้อมูลแบบ AJAX เมื่อกดบันทึก
    $('#mailSettingsForm').submit(function(e) {
        e.preventDefault();
        let btn = $('#btnSave');
        let originalText = btn.html();

        // เปลี่ยนปุ่มเป็นสถานะโหลด
        btn.html('<i class="fa-solid fa-spinner fa-spin me-1"></i> กำลังบันทึก...').prop('disabled', true);

        $.ajax({
            url: 'process_mail_settings.php',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'สำเร็จ!',
                        text: response.message,
                        confirmButtonColor: '#198754'
                    }).then(() => {
                        location.reload();
                    });
                } else if (response.status === 'warning') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'แจ้งเตือน',
                        text: response.message,
                        confirmButtonColor: '#f59e0b'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: response.message || 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ',
                        confirmButtonColor: '#dc2626'
                    });
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX Error:", xhr.responseText);
                Swal.fire({
                    icon: 'error',
                    title: 'ข้อผิดพลาดเครือข่าย',
                    text: 'ไม่สามารถเชื่อมต่อกับ process_mail_settings.php ได้ กรุณาลองใหม่อีกครั้ง',
                    confirmButtonColor: '#dc2626'
                });
            },
            complete: function() {
                btn.html(originalText).prop('disabled', false);
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../component/admin_footer.php'; ?>
