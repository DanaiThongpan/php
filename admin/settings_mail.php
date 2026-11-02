<?php
/**
 * admin/settings_mail.php - หน้าจัดการตั้งค่าระบบส่งอีเมลผ่าน External REST API
 * Modern SaaS UI & Enterprise Architecture with Fetch API, SweetAlert2, and Overdue Reminder Frequency Management
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
$condb = $pdo;

// -----------------------------------------------------------------------------
// 3. ดึงข้อมูลการตั้งค่าเดิมจากฐานข้อมูล
// -----------------------------------------------------------------------------
try {
    $stmtConfig = $pdo->query("SELECT * FROM system_settings WHERE id = 1 LIMIT 1");
    $config = $stmtConfig ? $stmtConfig->fetch(PDO::FETCH_ASSOC) : null;

    if (!$config) {
        $pdo->exec("
            INSERT INTO system_settings (id, smtp_user, smtp_pass, sender_name, sender_email, reminder_frequency, reminder_days, is_active, updated_at)
            VALUES (1, 'esofficer@ubu.ac.th', '', 'งานบริหารทั่วไป กองวิชาการ', 'esofficer@ubu.ac.th', 'daily', 1, 1, CURRENT_TIMESTAMP)
        ");
        $stmtConfig = $pdo->query("SELECT * FROM system_settings WHERE id = 1 LIMIT 1");
        $config = $stmtConfig ? $stmtConfig->fetch(PDO::FETCH_ASSOC) : [];
    }
} catch (PDOException $e) {
    $config = [];
}

// ตัวแปรการตั้งค่า
$smtpUser          = $config['smtp_user'] ?? '';
$smtpPass          = $config['smtp_pass'] ?? '';
$senderName        = $config['sender_name'] ?? 'งานบริหารทั่วไป กองวิชาการ';
$senderEmail       = $config['sender_email'] ?? $smtpUser;
$reminderFreq      = $config['reminder_frequency'] ?? 'daily';
$reminderDays      = isset($config['reminder_days']) ? (int)$config['reminder_days'] : 1;
$isActive          = isset($config['is_active']) ? (int)$config['is_active'] : 1;
$isReady           = !empty($smtpUser) && !empty($smtpPass);
$updatedAt         = $config['updated_at'] ?? '-';

$customDaysVal = ($reminderFreq === 'custom') ? $reminderDays : 3;

$pageTitle = "ตั้งค่าระบบส่งอีเมล (REST Mail API & Overdue Reminders)";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>

<style>
    /* Modern SaaS Design Tokens */
    :root {
        --saas-card-radius: 16px;
        --saas-card-shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.05), 0 4px 6px -2px rgba(15, 23, 42, 0.02);
        --saas-card-border: 1px solid #e2e8f0;
        --saas-transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .saas-card {
        border-radius: var(--saas-card-radius);
        border: var(--saas-card-border);
        box-shadow: var(--saas-card-shadow);
        background: #ffffff;
        transition: var(--saas-transition);
        overflow: hidden;
    }
    .saas-card:hover {
        box-shadow: 0 15px 35px -5px rgba(15, 23, 42, 0.08), 0 6px 10px -2px rgba(15, 23, 42, 0.03);
    }
    .saas-card-header {
        background: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        padding: 1.25rem 1.5rem;
    }
    .saas-card-body {
        padding: 1.5rem;
    }
    .form-control-saas {
        border-radius: 10px;
        padding: 0.65rem 1rem;
        border: 1.5px solid #cbd5e1;
        font-size: 0.95rem;
        transition: var(--saas-transition);
    }
    .form-control-saas:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        background-color: #ffffff;
    }
    .input-group-text-saas {
        background-color: #f8fafc;
        border: 1.5px solid #cbd5e1;
        border-radius: 10px;
        color: #64748b;
    }

    /* Custom Frequency Tile Radio Buttons */
    .frequency-tile {
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        padding: 14px 16px;
        cursor: pointer;
        transition: var(--saas-transition);
        background-color: #ffffff;
        position: relative;
    }
    .frequency-tile:hover {
        border-color: #93c5fd;
        background-color: #f8fafc;
        transform: translateY(-2px);
    }
    .frequency-tile.active {
        border-color: #2563eb !important;
        background-color: #eff6ff !important;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
    }
    .frequency-tile input[type="radio"] {
        accent-color: #2563eb;
        width: 18px;
        height: 18px;
        margin-top: 2px;
    }

    /* Live Preview Box */
    .from-preview-badge {
        background-color: #f1f5f9;
        border: 1px dashed #94a3b8;
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 0.88rem;
    }

    /* Hero / Status Strip */
    .status-strip {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    }

    /* Pulse Indicator */
    .pulse-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 6px;
    }
    .pulse-green {
        background-color: #10b981;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25);
    }
    .pulse-gray {
        background-color: #94a3b8;
        box-shadow: 0 0 0 3px rgba(148, 163, 184, 0.25);
    }
</style>

<!-- Main Container -->
<div class="container-fluid px-0 pb-5">

    <!-- ========================================================================= -->
    <!-- Breadcrumb & Header Title -->
    <!-- ========================================================================= -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1 small text-muted">
                    <li class="breadcrumb-item"><a href="main.php" class="text-decoration-none text-muted"><i class="fa-solid fa-house me-1"></i>หน้าหลัก</a></li>
                    <li class="breadcrumb-item"><a href="admin_history.php" class="text-decoration-none text-muted">ระบบแจ้งเตือน</a></li>
                    <li class="breadcrumb-item active fw-semibold text-primary" aria-current="page">ตั้งค่าระบบส่งอีเมล (REST API)</li>
                </ol>
            </nav>
            <h3 class="fw-bold text-dark mb-0 d-flex align-items-center">
                <span class="rounded-3 bg-primary-subtle text-primary p-2 me-2 d-inline-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                    <i class="fa-solid fa-paper-plane fa-sm"></i>
                </span>
                ตั้งค่าระบบส่งอีเมล (External REST API)
            </h3>
            <p class="text-muted small mb-0 mt-1">
                เชื่อมต่อบริการส่งอีเมลผ่าน REST API ของมหาวิทยาลัยอุบลราชธานี พร้อมตั้งค่าความถี่ในการส่งเมลทวงคืนครุภัณฑ์อัตโนมัติ
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="admin_history.php" class="btn btn-outline-secondary rounded-pill px-3 shadow-sm btn-sm">
                <i class="fa-solid fa-arrow-left me-1"></i> กลับหน้ารายการยืม-คืน
            </a>
            <a href="main.php" class="btn btn-outline-primary rounded-pill px-3 shadow-sm btn-sm">
                <i class="fa-solid fa-gauge-high me-1"></i> แดชบอร์ด
            </a>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- Status Ribbon / Quick System Health Banner -->
    <!-- ========================================================================= -->
    <div class="card status-strip shadow-sm mb-4">
        <div class="card-body p-3 p-md-4">
            <div class="row align-items-center g-3">
                <div class="col-12 col-md-8 d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center <?= $isActive ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>" style="width: 52px; height: 52px; font-size: 24px;">
                        <i class="fa-solid <?= $isActive ? 'fa-envelope-circle-check' : 'fa-bell-slash' ?>"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="fw-bold mb-0 text-dark">
                                สถานะระบบอีเมล: 
                                <span class="<?= $isActive ? 'text-success' : 'text-secondary' ?>" id="systemStatusText">
                                    <?= $isActive ? 'เปิดใช้งาน (Active)' : 'ระงับการส่งชั่วคราว (Disabled)' ?>
                                </span>
                            </h5>
                            <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?> rounded-pill px-2 py-1 small">
                                <span class="pulse-dot <?= $isActive ? 'pulse-green' : 'pulse-gray' ?>"></span>
                                <?= $isActive ? 'ONLINE' : 'OFFLINE' ?>
                            </span>
                        </div>
                        <div class="text-muted small mt-1">
                            <span class="text-primary fw-semibold"><i class="fa-solid fa-cloud me-1"></i>API Endpoint:</span> 
                            <code class="bg-light px-2 py-1 rounded text-dark">https://admission.ubu.ac.th/mailsender/api/send-email</code>
                            <span class="mx-2">&bull;</span>
                            <span>อัปเดตล่าสุด: <span class="fw-semibold text-secondary"><?= htmlspecialchars($updatedAt) ?></span></span>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-4 text-md-end">
                    <button type="button" class="btn <?= $isActive ? 'btn-outline-danger' : 'btn-success' ?> rounded-pill px-4 fw-bold shadow-sm btn-toggle-status" data-status="<?= $isActive ? '0' : '1' ?>">
                        <i class="fa-solid <?= $isActive ? 'fa-ban' : 'fa-power-off' ?> me-1"></i>
                        <?= $isActive ? 'ปิดการส่งอีเมลชั่วคราว' : 'เปิดใช้งานระบบส่งอีเมล' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- 3 Main Cards Grid Layout (Enterprise SaaS Layout) -->
    <!-- ========================================================================= -->
    <div class="row g-4">
        
        <!-- ===================================================================== -->
        <!-- ฝั่งซ้าย: Card 1 (API Configuration) & Card 2 (Reminder Frequency) -->
        <!-- ===================================================================== -->
        <div class="col-lg-7 col-xl-7">
            
            <form id="mailSettingsForm">
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="is_active" id="is_active_input" value="<?= $isActive ?>">

                <!-- ============================================================= -->
                <!-- Card 1: API Configuration (การเชื่อมต่อ API หลัก) -->
                <!-- ============================================================= -->
                <div class="saas-card mb-4">
                    <div class="saas-card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <span class="rounded-circle bg-primary-subtle text-primary p-2 d-inline-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                                <i class="fa-solid fa-network-wired"></i>
                            </span>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">Card 1: API Configuration</h6>
                                <small class="text-muted">ข้อมูลการเชื่อมต่อ External REST API หลัก</small>
                            </div>
                        </div>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1">
                            <i class="fa-solid fa-shield-halved me-1"></i> UBU Secure API
                        </span>
                    </div>

                    <div class="saas-card-body">
                        
                        <!-- 1. SMTP_USER -->
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small mb-1">
                                <i class="fa-solid fa-user-shield text-primary me-1"></i> บัญชีผู้ส่ง (SMTP_USER) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text input-group-text-saas"><i class="fa-solid fa-envelope"></i></span>
                                <input type="email" class="form-control form-control-saas" name="smtp_user" id="smtp_user" 
                                       value="<?= htmlspecialchars($smtpUser) ?>" 
                                       placeholder="เช่น esofficer@ubu.ac.th" required>
                            </div>
                            <div class="form-text text-muted small">
                                บัญชีอีเมลองค์กร (เช่น @ubu.ac.th) ที่ได้รับอนุญาตให้ใช้ยิงคำขอผ่าน Mail Sender API
                            </div>
                        </div>

                        <!-- 2. SMTP_PASS -->
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small mb-1">
                                <i class="fa-solid fa-key text-warning me-1"></i> รหัสผ่าน / รหัสผ่านแอป (SMTP_PASS) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text input-group-text-saas"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" class="form-control form-control-saas font-monospace" name="smtp_pass" id="smtp_pass" 
                                       value="<?= htmlspecialchars($smtpPass) ?>" 
                                       placeholder="กรอกรหัสผ่านบัญชี หรือ รหัสผ่านสำหรับแอป (App Password)" autocomplete="new-password" required>
                                <button class="btn btn-outline-secondary input-group-text-saas" type="button" id="togglePassword" title="แสดง/ซ่อนรหัสผ่าน">
                                    <i class="fa-regular fa-eye" id="togglePasswordIcon"></i>
                                </button>
                            </div>
                            <div class="form-text text-muted small">
                                รหัสผ่านนี้จะถูกส่งแบบ HTTPS ไปตรวจสอบตัวตนที่ Admission Mail API โดยตรง
                            </div>
                        </div>

                        <!-- 3. Sender Name & Sender Email (2 คอลัมน์) -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark small mb-1">
                                    <i class="fa-solid fa-id-card text-success me-1"></i> ชื่อผู้ส่งที่แสดง (Sender Name) <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control form-control-saas" name="sender_name" id="sender_name" 
                                       value="<?= htmlspecialchars($senderName) ?>" 
                                       placeholder="เช่น งานบริหารทั่วไป กองวิชาการ" required>
                                <div class="form-text text-muted small">ชื่อหน่วยงานที่จะโชว์ในกล่องข้อความผู้รับ</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-dark small mb-1">
                                    <i class="fa-solid fa-at text-info me-1"></i> อีเมลผู้ส่ง (Sender Email) <span class="text-danger">*</span>
                                </label>
                                <input type="email" class="form-control form-control-saas" name="sender_email" id="sender_email" 
                                       value="<?= htmlspecialchars($senderEmail) ?>" 
                                       placeholder="เช่น esofficer@ubu.ac.th" required>
                                <div class="form-text text-muted small">อีเมลที่แสดงในช่อง From</div>
                            </div>
                        </div>

                        <!-- แสดงตัวอย่างค่า From ที่ส่งเข้า API -->
                        <div class="from-preview-badge">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div>
                                    <strong class="text-dark"><i class="fa-solid fa-eye me-1 text-primary"></i>ตัวอย่างฟิลด์ <code>from</code> ใน Payload:</strong>
                                    <div class="font-monospace text-primary fw-semibold mt-1" id="fromPreview">
                                        "<?= htmlspecialchars($senderName) ?> <?= htmlspecialchars($senderEmail) ?>"
                                    </div>
                                </div>
                                <span class="badge bg-secondary-subtle text-secondary border small">Auto-generated</span>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ============================================================= -->
                <!-- Card 2: Overdue Reminder Frequency (ตั้งค่าความถี่ในการทวง) -->
                <!-- ============================================================= -->
                <div class="saas-card mb-4">
                    <div class="saas-card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <span class="rounded-circle bg-warning-subtle text-warning-emphasis p-2 d-inline-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </span>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark">Card 2: Overdue Reminder Frequency</h6>
                                <small class="text-muted">กำหนดความถี่ในการส่งอีเมลทวงคืนครุภัณฑ์ที่เลยกำหนด</small>
                            </div>
                        </div>
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-3 py-1">
                            <i class="fa-solid fa-stopwatch me-1"></i> Cron Automation
                        </span>
                    </div>

                    <div class="saas-card-body">
                        <p class="text-muted small mb-3">
                            เลือกรอบระยะเวลาที่ระบบจะส่งอีเมลแจ้งเตือนซ้ำไปยังผู้ยืม เมื่อครุภัณฑ์มีสถานะเลยกำหนดส่งคืน (Overdue):
                        </p>

                        <!-- Tiles Radio Selection -->
                        <div class="row g-2" id="frequencyOptionsGroup">
                            
                            <!-- ตัวเลือก 1: ไม่ส่งแจ้งเตือน -->
                            <div class="col-sm-6 col-md-12">
                                <label class="frequency-tile d-flex align-items-center gap-3 w-100 <?= ($reminderFreq === 'none') ? 'active' : '' ?>">
                                    <input type="radio" name="reminder_frequency" value="none" <?= ($reminderFreq === 'none') ? 'checked' : '' ?>>
                                    <div class="rounded-circle bg-secondary-subtle text-secondary p-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                        <i class="fa-solid fa-bell-slash"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold text-dark">ไม่ส่งแจ้งเตือน (Disable)</div>
                                        <div class="text-muted small">ปิดระบบส่งอีเมลทวงคืนอัตโนมัติทั้งหมด</div>
                                    </div>
                                    <span class="badge bg-secondary-subtle text-secondary rounded-pill small">ปิดใช้งาน</span>
                                </label>
                            </div>

                            <!-- ตัวเลือก 2: วันละ 1 ครั้ง (แนะนำ) -->
                            <div class="col-sm-6 col-md-12">
                                <label class="frequency-tile d-flex align-items-center gap-3 w-100 <?= ($reminderFreq === 'daily') ? 'active' : '' ?>">
                                    <input type="radio" name="reminder_frequency" value="daily" <?= ($reminderFreq === 'daily') ? 'checked' : '' ?>>
                                    <div class="rounded-circle bg-success-subtle text-success p-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                        <i class="fa-solid fa-calendar-day"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold text-dark">วันละ 1 ครั้ง (Daily)</div>
                                        <div class="text-muted small">ส่งอีเมลแจ้งเตือนทุกวันจนกว่าผู้ยืมจะนำมาคืน</div>
                                    </div>
                                    <span class="badge bg-success text-white rounded-pill small"><i class="fa-solid fa-thumbs-up me-1"></i> แนะนำ</span>
                                </label>
                            </div>

                            <!-- ตัวเลือก 3: วันเว้นวัน -->
                            <div class="col-sm-6 col-md-12">
                                <label class="frequency-tile d-flex align-items-center gap-3 w-100 <?= ($reminderFreq === 'alternate') ? 'active' : '' ?>">
                                    <input type="radio" name="reminder_frequency" value="alternate" <?= ($reminderFreq === 'alternate') ? 'checked' : '' ?>>
                                    <div class="rounded-circle bg-info-subtle text-info p-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                        <i class="fa-solid fa-calendar-days"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold text-dark">วันเว้นวัน (Every 2 Days)</div>
                                        <div class="text-muted small">ส่งอีเมลแจ้งเตือนซ้ำทุกๆ 2 วัน</div>
                                    </div>
                                    <span class="badge bg-info-subtle text-info border rounded-pill small">ทุก 2 วัน</span>
                                </label>
                            </div>

                            <!-- ตัวเลือก 4: สัปดาห์ละ 1 ครั้ง -->
                            <div class="col-sm-6 col-md-12">
                                <label class="frequency-tile d-flex align-items-center gap-3 w-100 <?= ($reminderFreq === 'weekly') ? 'active' : '' ?>">
                                    <input type="radio" name="reminder_frequency" value="weekly" <?= ($reminderFreq === 'weekly') ? 'checked' : '' ?>>
                                    <div class="rounded-circle bg-primary-subtle text-primary p-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                        <i class="fa-solid fa-calendar-week"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold text-dark">สัปดาห์ละ 1 ครั้ง (Weekly)</div>
                                        <div class="text-muted small">ส่งอีเมลแจ้งเตือนสัปดาห์ละ 1 ครั้ง (ทุก 7 วัน)</div>
                                    </div>
                                    <span class="badge bg-primary-subtle text-primary border rounded-pill small">ทุก 7 วัน</span>
                                </label>
                            </div>

                            <!-- ตัวเลือก 5: อื่นๆ (กำหนดจำนวนวันเอง) -->
                            <div class="col-sm-6 col-md-12">
                                <label class="frequency-tile d-flex align-items-center gap-3 w-100 <?= ($reminderFreq === 'custom') ? 'active' : '' ?>">
                                    <input type="radio" name="reminder_frequency" value="custom" <?= ($reminderFreq === 'custom') ? 'checked' : '' ?>>
                                    <div class="rounded-circle bg-dark-subtle text-dark p-2 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                        <i class="fa-solid fa-sliders"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold text-dark">อื่นๆ (กำหนดจำนวนวันเอง)</div>
                                        <div class="text-muted small">ระบุรอบระยะเวลาจำนวนวันตามความต้องการของหน่วยงาน</div>
                                    </div>
                                    <span class="badge bg-secondary-subtle text-dark border rounded-pill small">กำหนดเอง</span>
                                </label>
                            </div>

                        </div>

                        <!-- ช่องกรอกจำนวนวัน กรณีเลือก "อื่นๆ" -->
                        <div id="customDaysContainer" class="mt-3 p-3 bg-light border border-warning-subtle rounded-3 <?= ($reminderFreq === 'custom') ? '' : 'd-none' ?>">
                            <label class="form-label fw-bold text-dark small mb-1">
                                <i class="fa-solid fa-stopwatch text-warning me-1"></i> ระบุจำนวนวันในการทวงซ้ำ:
                            </label>
                            <div class="input-group" style="max-width: 300px;">
                                <span class="input-group-text bg-white">ส่งซ้ำทุกๆ</span>
                                <input type="number" min="1" max="90" class="form-control form-control-saas text-center fw-bold text-primary" 
                                       name="reminder_custom_days" id="reminder_custom_days" 
                                       value="<?= htmlspecialchars((string)$customDaysVal) ?>" placeholder="3">
                                <span class="input-group-text bg-white">วัน</span>
                            </div>
                            <div class="form-text text-muted small mt-1">
                                ระบุตัวเลข 1 - 90 วัน เช่น กรอก <code>3</code> ระบบจะส่งอีเมลทวงซ้ำทุก 3 วันหลังเลยกำหนดคืน
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ปุ่มบันทึกการตั้งค่าทั้งหมด -->
                <div class="d-flex align-items-center gap-3 mb-4">
                    <button type="submit" class="btn btn-primary btn-lg rounded-pill px-4 fw-bold shadow flex-grow-1 py-3" id="btnSave">
                        <i class="fa-solid fa-floppy-disk me-2"></i> บันทึกการตั้งค่าทั้งหมด (Save Settings)
                    </button>
                </div>

            </form>

        </div>

        <!-- ===================================================================== -->
        <!-- ฝั่งขวา: Card 3 (Send Test Email) & System Integration Details -->
        <!-- ===================================================================== -->
        <div class="col-lg-5 col-xl-5">

            <!-- ================================================================= -->
            <!-- Card 3: Send Test Email (ทดสอบระบบ API) -->
            <!-- ================================================================= -->
            <div class="saas-card mb-4 border-primary">
                <div class="saas-card-header bg-primary bg-opacity-10 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <span class="rounded-circle bg-success text-white p-2 d-inline-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                            <i class="fa-solid fa-paper-plane"></i>
                        </span>
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Card 3: Send Test Email</h6>
                            <small class="text-muted">ทดสอบส่งอีเมลจริงผ่าน REST API</small>
                        </div>
                    </div>
                    <span class="badge bg-success rounded-pill px-3 py-1">
                        <i class="fa-solid fa-vial me-1"></i> Live Test
                    </span>
                </div>

                <div class="saas-card-body">
                    <p class="text-muted small mb-3">
                        กรอกอีเมลของคุณเพื่อทดสอบยิงคำขอไปยัง <code>https://admission.ubu.ac.th/mailsender/api/send-email</code> เพื่อตรวจสอบว่าข้อมูลผู้ส่งและรหัสผ่านทำงานได้จริง:
                    </p>

                    <form id="testEmailForm">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small mb-1">
                                <i class="fa-solid fa-envelope-open-text text-primary me-1"></i> อีเมลผู้รับทดสอบ (To Email) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text input-group-text-saas"><i class="fa-solid fa-at"></i></span>
                                <input type="email" class="form-control form-control-saas" id="test_to" name="test_to" 
                                       placeholder="เช่น your.email@ubu.ac.th หรือ gmail" required>
                            </div>
                            <div class="form-text text-muted small">ระบบจะจัดส่งจดหมายตัวอย่างไปยังอีเมลนี้ทันที</div>
                        </div>

                        <button type="submit" class="btn text-white w-100 py-3 fw-bold rounded-pill shadow-sm" id="btnSendTest" style="background: linear-gradient(135deg, #059669 0%, #10b981 100%);">
                            <i class="fa-solid fa-paper-plane me-2"></i> ส่งอีเมลทดสอบทันที
                        </button>
                    </form>

                    <!-- พื้นที่แสดงสถานะการทดสอบล่าสุด (Inline Status Box) -->
                    <div id="testResultBox" class="mt-3 d-none">
                        <!-- Dynamic rendered via JS -->
                    </div>

                    <div class="bg-light p-3 rounded-3 mt-3 border small">
                        <div class="fw-bold text-dark mb-1">
                            <i class="fa-solid fa-circle-info text-info me-1"></i> เคล็ดลับการทดสอบ:
                        </div>
                        <ul class="ps-3 mb-0 text-muted" style="line-height: 1.6;">
                            <li>สามารถกดส่งทดสอบได้ทันทีโดยระบบจะนำค่าจากฟอร์มด้านซ้ายไปทดสอบก่อน</li>
                            <li>หากไม่ได้รับอีเมลในกล่องจดหมายหลัก (Inbox) ให้ตรวจดูในโฟลเดอร์ Junk/Spam</li>
                            <li>ระบบมี <code>CURLOPT_TIMEOUT</code> ป้องกันหน้าค้างหากเครือข่ายตอบสนองช้า</li>
                        </ul>
                    </div>

                </div>
            </div>

            <!-- กล่องข้อมูลสเปก API & โครงสร้าง Payload (Documentation Card) -->
            <div class="saas-card mb-4">
                <div class="saas-card-header d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-2">
                        <span class="rounded-circle bg-dark-subtle text-dark p-2 d-inline-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                            <i class="fa-solid fa-code"></i>
                        </span>
                        <h6 class="fw-bold mb-0 text-dark">API Architecture & Payload</h6>
                    </div>
                    <span class="badge bg-light text-dark border">POST JSON</span>
                </div>
                <div class="saas-card-body p-3 small">
                    <div class="mb-2 text-muted">
                        รูปแบบข้อมูลที่ระบบแปลงผ่าน <code>json_encode()</code> และส่งผ่าน cURL:
                    </div>
                    <pre class="bg-dark text-light p-3 rounded-3 font-monospace mb-3" style="font-size: 0.78rem; overflow-x: auto;">
{
  "SMTP_USER": "<span class="text-warning"><?= htmlspecialchars($smtpUser ?: 'esofficer@ubu.ac.th') ?></span>",
  "SMTP_PASS": "<span class="text-warning">••••••••••••••••</span>",
  "from": "<span class="text-info"><?= htmlspecialchars($senderName) ?> <?= htmlspecialchars($senderEmail) ?></span>",
  "to": "<span class="text-success">[อีเมลผู้ยืม]</span>",
  "subject": "[แจ้งเตือน] ครุภัณฑ์เลยกำหนดส่งคืน",
  "html": "&lt;div&gt;...เนื้อหาแจ้งเตือน HTML...&lt;/div&gt;"
}</pre>
                    <div class="text-muted d-flex align-items-center gap-2">
                        <i class="fa-solid fa-shield-check text-success"></i>
                        <span>เข้ารหัส SSL/TLS ในการส่งทุกครั้ง ข้อมูลปลอดภัย 100%</span>
                    </div>
                </div>
            </div>

        </div>

    </div>

</div>

<!-- ========================================================================= -->
<!-- Scripts: AJAX (Fetch API) & SweetAlert2 & UI Interactivity -->
<!-- ========================================================================= -->
<script>
document.addEventListener('DOMContentLoaded', function() {

    // 1. ระบบเปิด/ปิด การแสดงรหัสผ่าน (Password Visibility Toggle)
    const togglePasswordBtn = document.getElementById('togglePassword');
    const passwordInput     = document.getElementById('smtp_pass');
    const togglePasswordIcon = document.getElementById('togglePasswordIcon');

    if (togglePasswordBtn && passwordInput) {
        togglePasswordBtn.addEventListener('click', function() {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            togglePasswordIcon.className = isPassword ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
        });
    }

    // 2. อัปเดตตัวอย่าง From Preview แบบเรียลไทม์
    const senderNameInput  = document.getElementById('sender_name');
    const senderEmailInput = document.getElementById('sender_email');
    const fromPreview      = document.getElementById('fromPreview');

    function updateFromPreview() {
        const name  = (senderNameInput ? senderNameInput.value.trim() : '') || 'งานบริหารทั่วไป กองวิชาการ';
        const email = (senderEmailInput ? senderEmailInput.value.trim() : '') || 'esofficer@ubu.ac.th';
        if (fromPreview) {
            fromPreview.textContent = `"${name} ${email}"`;
        }
    }

    if (senderNameInput) senderNameInput.addEventListener('input', updateFromPreview);
    if (senderEmailInput) senderEmailInput.addEventListener('input', updateFromPreview);

    // 3. จัดการการเลือกตัวเลือกความถี่ (Custom Radio Tile Interactivity)
    const frequencyTiles = document.querySelectorAll('.frequency-tile');
    const customDaysContainer = document.getElementById('customDaysContainer');

    frequencyTiles.forEach(tile => {
        tile.addEventListener('click', function() {
            frequencyTiles.forEach(t => t.classList.remove('active'));
            this.classList.add('active');

            const radio = this.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
                if (radio.value === 'custom') {
                    customDaysContainer.classList.remove('d-none');
                    document.getElementById('reminder_custom_days').focus();
                } else {
                    customDaysContainer.classList.add('d-none');
                }
            }
        });
    });

    // 4. สลับสถานะเปิด/ปิดระบบ (Quick Toggle Active/Inactive via Fetch API)
    document.querySelectorAll('.btn-toggle-status').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetStatus = parseInt(this.getAttribute('data-status'), 10);
            const isDisabling  = (targetStatus === 0);

            Swal.fire({
                title: isDisabling ? 'ยืนยันการปิดการส่งอีเมล?' : 'ยืนยันการเปิดใช้งานระบบส่งอีเมล?',
                html: isDisabling 
                    ? 'เมื่อปิดใช้งาน ระบบจะ<strong>หยุดส่งอีเมลแจ้งเตือนทั้งหมด</strong>ชั่วคราว<br><small class="text-muted">(ข้อมูลการตั้งค่า API จะยังคงถูกบันทึกไว้ตามเดิม)</small>' 
                    : 'ระบบจะกลับมาเปิดใช้งานการส่งอีเมลแจ้งเตือนตามปกติ',
                icon: isDisabling ? 'warning' : 'question',
                showCancelButton: true,
                confirmButtonColor: isDisabling ? '#dc2626' : '#10b981',
                cancelButtonColor: '#64748b',
                confirmButtonText: isDisabling ? '<i class="fa-solid fa-ban me-1"></i> ยืนยันปิดใช้งาน' : '<i class="fa-solid fa-power-off me-1"></i> ยืนยันเปิดใช้งาน',
                cancelButtonText: 'ยกเลิก',
                reverseButtons: true
            }).then((result) => {
                if (!result.isConfirmed) return;

                Swal.fire({
                    title: 'กำลังอัปเดตสถานะ...',
                    html: 'กรุณารอสักครู่ ระบบกำลังปรับปรุงการตั้งค่า',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });

                const formData = new FormData();
                formData.append('action', 'toggle_status');
                formData.append('is_active', targetStatus);

                fetch('process_mail_settings.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data && data.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'สำเร็จ!',
                            text: data.message,
                            confirmButtonColor: '#10b981'
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: data.message || 'ไม่สามารถอัปเดตสถานะได้',
                            confirmButtonColor: '#dc2626'
                        });
                    }
                })
                .catch(err => {
                    Swal.fire({
                        icon: 'error',
                        title: 'ข้อผิดพลาดเครือข่าย',
                        text: 'ไม่สามารถติดต่อเซิร์ฟเวอร์ได้: ' + err.message,
                        confirmButtonColor: '#dc2626'
                    });
                });
            });
        });
    });

    // 5. บันทึกการตั้งค่าผ่าน Fetch API (Save Settings AJAX)
    const mailSettingsForm = document.getElementById('mailSettingsForm');
    const btnSave          = document.getElementById('btnSave');

    if (mailSettingsForm && btnSave) {
        mailSettingsForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const originalBtnHtml = btnSave.innerHTML;
            btnSave.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> กำลังบันทึกการตั้งค่า...';
            btnSave.disabled = true;

            const formData = new FormData(mailSettingsForm);

            fetch('process_mail_settings.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'บันทึกสำเร็จ!',
                        text: data.message,
                        confirmButtonColor: '#10b981',
                        confirmButtonText: '<i class="fa-solid fa-check me-1"></i> ตกลง'
                    }).then(() => {
                        location.reload();
                    });
                } else if (data.status === 'warning') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'ข้อมูลไม่ครบถ้วน',
                        text: data.message,
                        confirmButtonColor: '#f59e0b'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: data.message || 'ไม่สามารถบันทึกข้อมูลได้',
                        confirmButtonColor: '#dc2626'
                    });
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'ข้อผิดพลาดในการเชื่อมต่อ',
                    text: 'ไม่สามารถส่งคำขอไปยัง process_mail_settings.php ได้ (' + error.message + ')',
                    confirmButtonColor: '#dc2626'
                });
            })
            .finally(() => {
                btnSave.innerHTML = originalBtnHtml;
                btnSave.disabled = false;
            });
        });
    }

    // 6. ส่งอีเมลทดสอบผ่าน Fetch API (Send Test Email AJAX)
    const testEmailForm = document.getElementById('testEmailForm');
    const btnSendTest   = document.getElementById('btnSendTest');
    const testToInput   = document.getElementById('test_to');
    const testResultBox = document.getElementById('testResultBox');

    if (testEmailForm && btnSendTest) {
        testEmailForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const testTo = testToInput.value.trim();
            if (!testTo) {
                Swal.fire({
                    icon: 'warning',
                    title: 'กรุณากรอกอีเมล',
                    text: 'กรุณากรอกอีเมลผู้รับทดสอบให้ถูกต้อง',
                    confirmButtonColor: '#f59e0b'
                });
                return;
            }

            const originalBtnHtml = btnSendTest.innerHTML;
            btnSendTest.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin me-2"></i> กำลังส่งอีเมลทดสอบผ่าน API...';
            btnSendTest.disabled = true;

            const formData = new FormData();
            formData.append('action', 'send_test');
            formData.append('test_to', testTo);

            // ดึงค่าสดจากฟอร์มด้านซ้ายส่งไปทดสอบด้วย (เผื่อผู้ใช้เพิ่งกรอกใหม่ยังไม่ได้กดบันทึก)
            const smtpUserVal = document.getElementById('smtp_user')?.value || '';
            const smtpPassVal = document.getElementById('smtp_pass')?.value || '';
            const senderNameVal = document.getElementById('sender_name')?.value || '';
            const senderEmailVal = document.getElementById('sender_email')?.value || '';

            formData.append('smtp_user', smtpUserVal);
            formData.append('smtp_pass', smtpPassVal);
            formData.append('sender_name', senderNameVal);
            formData.append('sender_email', senderEmailVal);

            fetch('process_mail_settings.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'ส่งอีเมลทดสอบสำเร็จ! 🎉',
                        html: `
                            <p class="mb-2 text-dark">${data.message}</p>
                            <div class="p-2 bg-light rounded text-muted small font-monospace">
                                ผู้รับ: <strong>${testTo}</strong>
                            </div>
                        `,
                        confirmButtonColor: '#10b981',
                        confirmButtonText: '<i class="fa-solid fa-check me-1"></i> เยี่ยมมาก'
                    });

                    if (testResultBox) {
                        testResultBox.className = 'mt-3 alert alert-success border-success-subtle p-3 rounded-3 small';
                        testResultBox.innerHTML = `
                            <div class="fw-bold text-success"><i class="fa-solid fa-circle-check me-1"></i> ผลการทดสอบล่าสุด: สำเร็จ</div>
                            <div class="text-muted mt-1">ส่งไปยัง <b>${testTo}</b> เมื่อสักครู่นี้ (${new Date().toLocaleTimeString('th-TH')})</div>
                        `;
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'ส่งอีเมลทดสอบไม่สำเร็จ',
                        html: `
                            <p class="mb-2 text-danger fw-semibold">${data.message}</p>
                            <div class="text-start p-3 bg-light rounded-3 text-muted small border">
                                <strong class="text-dark">คำแนะนำในการแก้ไข:</strong>
                                <ul class="ps-3 mb-0 mt-1">
                                    <li>ตรวจสอบว่าบัญชี <code>${smtpUserVal || 'SMTP_USER'}</code> และรหัสผ่านถูกต้อง</li>
                                    <li>หากเป็น Gmail ตรวจสอบว่าเปิด 2-Step Verification และสร้าง App Password 16 หลักแล้ว</li>
                                    <li>ตรวจสอบว่าเครื่องเซิร์ฟเวอร์สามารถเชื่อมต่ออินเทอร์เน็ตภายนอกได้</li>
                                </ul>
                            </div>
                        `,
                        confirmButtonColor: '#dc2626',
                        confirmButtonText: 'รับทราบ'
                    });

                    if (testResultBox) {
                        testResultBox.className = 'mt-3 alert alert-danger border-danger-subtle p-3 rounded-3 small';
                        testResultBox.innerHTML = `
                            <div class="fw-bold text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i> ผลการทดสอบล่าสุด: ไม่สำเร็จ</div>
                            <div class="text-muted mt-1">${data.message}</div>
                        `;
                    }
                }
            })
            .catch(error => {
                Swal.fire({
                    icon: 'error',
                    title: 'ข้อผิดพลาดเครือข่าย',
                    text: 'ไม่สามารถติดต่อ API ได้ (' + error.message + ')',
                    confirmButtonColor: '#dc2626'
                });
            })
            .finally(() => {
                btnSendTest.innerHTML = originalBtnHtml;
                btnSendTest.disabled = false;
            });
        });
    }

});
</script>

<?php require_once __DIR__ . '/../component/admin_footer.php'; ?>
