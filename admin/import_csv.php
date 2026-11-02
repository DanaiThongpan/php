<?php
$pageTitle = "นำเข้าข้อมูลครุภัณฑ์ด้วย CSV (CSV Import)";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';

// ดึงข้อมูล Master Data จากฐานข้อมูลจริง เพื่อนำมาแสดงเป็นคู่มืออ้างอิงให้แอดมิน
try {
    // 1. หมวดหมู่ครุภัณฑ์จริงในระบบ
    $stmtClass = $pdo->query("SELECT classificationID, classificationName FROM classification ORDER BY classificationID ASC");
    $dbClasses = $stmtClass->fetchAll(PDO::FETCH_ASSOC);

    // 2. อาคาร/สถานที่จริงในระบบ
    $stmtBuild = $pdo->query("SELECT buildingID, buildingName, is_active FROM building ORDER BY buildingID ASC");
    $dbBuildings = $stmtBuild->fetchAll(PDO::FETCH_ASSOC);

    // 3. ตัวอย่างห้องตามอาคาร
    $stmtRooms = $pdo->query("SELECT classroomID, classroomName, buildingID FROM building_classroom ORDER BY buildingID, classroomID ASC LIMIT 20");
    $dbRooms = $stmtRooms->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbClasses   = [];
    $dbBuildings = [];
    $dbRooms     = [];
}
?>
<style>
    .card { border-radius: 14px; }
    .alert-custom { border-left: 5px solid #0d6efd; }
    .table-preview th { font-size: 0.85rem; font-weight: 600; vertical-align: middle; }
    .table-preview td { font-size: 0.85rem; vertical-align: middle; }
    .nav-tabs .nav-link { font-weight: 600; color: #64748b; border: none; border-bottom: 2px solid transparent; }
    .nav-tabs .nav-link.active { color: #0d6efd; border-bottom: 2px solid #0d6efd; background: transparent; }
    .badge-col-req { background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; font-size: 0.72rem; }
    .badge-col-opt { background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-size: 0.72rem; }
    .badge-col-auto { background-color: #dcfce7; color: #15803d; border: 1px solid #86efac; font-size: 0.72rem; }
    .dropzone-box {
        border: 2px dashed #cbd5e1;
        border-radius: 12px;
        background-color: #f8fafc;
        transition: all 0.2s ease-in-out;
    }
    .dropzone-box:hover {
        border-color: #0d6efd;
        background-color: #f0f7ff;
    }
</style>

<!-- Main Content -->
<div class="container-fluid px-0">
    <div class="row justify-content-center">
        <div class="col-lg-11 col-xl-10">
            <div class="card shadow border-0">
                <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="mb-0 fw-bold text-dark">
                            <i class="fa-solid fa-cloud-arrow-up text-primary me-2"></i> ระบบนำเข้าข้อมูลครุภัณฑ์ด้วย CSV (CSV Import)
                        </h5>
                        <small class="text-muted">นำเข้าข้อมูลครุภัณฑ์แบบกลุ่ม โดยจับคู่ฟิลด์ตรงกับโครงสร้างฐานข้อมูล <code>durable_articles</code></small>
                    </div>
                    <a href="template_durable_articles.csv" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-xs" download>
                        <i class="fa-solid fa-download me-1"></i> โหลดไฟล์ต้นแบบ (Template CSV)
                    </a>
                </div>
                <div class="card-body p-4 p-md-5">
                    
                    <!-- กล่องคำแนะนำและตัวอย่างข้อมูล -->
                    <div class="alert alert-light shadow-sm alert-custom mb-4" role="alert">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <h6 class="alert-heading fw-bold text-dark mb-0">
                                <i class="fa-solid fa-circle-info text-primary me-2"></i> รูปแบบโครงสร้างข้อมูลที่ตรงกับฐานข้อมูลจริง
                            </h6>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-xs">
                                ตาราง: durable_articles
                            </span>
                        </div>
                        <p class="text-muted small mb-3">
                            จัดเตรียมไฟล์ข้อมูลในรูปแบบ <code>.csv</code> (เข้ารหัส UTF-8) โดยระบุชื่อหัวคอลัมน์ในแถวแรกตามโครงสร้างฐานข้อมูลด้านล่างนี้ ระบบจะทำการตรวจสอบและบันทึกข้อมูลให้อัตโนมัติ:
                        </p>
                        
                        <!-- ตารางตัวอย่างข้อมูลจริง (Realistic Data Preview) -->
                        <div class="table-responsive rounded border mb-3">
                            <table class="table table-hover table-striped table-preview text-center bg-white mb-0 align-middle">
                                <thead class="table-light text-secondary">
                                    <tr>
                                        <th style="min-width: 140px;">
                                            DA_Number<br>
                                            <span class="text-muted small fw-normal">(รหัสครุภัณฑ์)</span><br>
                                            <span class="badge badge-col-req">จำเป็น</span>
                                        </th>
                                        <th style="min-width: 170px;">
                                            DA_ListName<br>
                                            <span class="text-muted small fw-normal">(ชื่อรายการ)</span><br>
                                            <span class="badge badge-col-req">จำเป็น</span>
                                        </th>
                                        <th style="min-width: 110px;">
                                            DA_Brand<br>
                                            <span class="text-muted small fw-normal">(ยี่ห้อ/รุ่น)</span><br>
                                            <span class="badge badge-col-opt">เว้นว่างได้</span>
                                        </th>
                                        <th style="min-width: 150px;">
                                            classificationID<br>
                                            <span class="text-muted small fw-normal">(รหัสหมวดหมู่)</span><br>
                                            <span class="badge badge-col-auto">ตรวจจับอัตโนมัติ</span>
                                        </th>
                                        <th style="min-width: 110px;">
                                            buildingID<br>
                                            <span class="text-muted small fw-normal">(รหัสอาคาร)</span><br>
                                            <span class="badge badge-col-opt">เว้นว่างได้</span>
                                        </th>
                                        <th style="min-width: 110px;">
                                            classroomID<br>
                                            <span class="text-muted small fw-normal">(รหัสห้อง)</span><br>
                                            <span class="badge badge-col-opt">เว้นว่างได้</span>
                                        </th>
                                        <th style="min-width: 100px;">
                                            DA_Price<br>
                                            <span class="text-muted small fw-normal">(ราคา/บาท)</span><br>
                                            <span class="badge badge-col-opt">เว้นว่างได้</span>
                                        </th>
                                        <th style="min-width: 100px;">
                                            DA_Year<br>
                                            <span class="text-muted small fw-normal">(ปีงบประมาณ)</span><br>
                                            <span class="badge badge-col-auto">ตรวจจับอัตโนมัติ</span>
                                        </th>
                                        <th style="min-width: 120px;">
                                            owner_department<br>
                                            <span class="text-muted small fw-normal">(สังกัด)</span><br>
                                            <span class="badge badge-col-auto">ค่าเริ่มต้นสังกัดตนเอง</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="font-monospace text-primary fw-bold">6310040000730</td>
                                        <td class="text-start">ตู้ล็อกเกอร์ ขนาด 9 ประตู</td>
                                        <td>Modernform</td>
                                        <td><span class="badge bg-light text-dark border font-monospace">c0000000003</span><br><small class="text-muted">(สำนักงาน)</small></td>
                                        <td><span class="badge bg-light text-dark border font-monospace">PB</span></td>
                                        <td><span class="badge bg-light text-dark border font-monospace">PB101</span></td>
                                        <td class="font-monospace">8500.00</td>
                                        <td><span class="badge bg-secondary-subtle text-secondary font-monospace">2566</span></td>
                                        <td>ส่วนกลาง</td>
                                    </tr>
                                    <tr>
                                        <td class="font-monospace text-primary fw-bold">6310130000105</td>
                                        <td class="text-start">เครื่องคอมพิวเตอร์ All-in-One</td>
                                        <td>Dell</td>
                                        <td><span class="badge bg-light text-dark border font-monospace">c0000000004</span><br><small class="text-muted">(คอมพิวเตอร์)</small></td>
                                        <td><span class="badge bg-light text-dark border font-monospace">PB</span></td>
                                        <td><span class="badge bg-light text-dark border font-monospace">PB201</span></td>
                                        <td class="font-monospace">32000.00</td>
                                        <td><span class="badge bg-secondary-subtle text-secondary font-monospace">2567</span></td>
                                        <td>ส่วนกลาง</td>
                                    </tr>
                                    <tr>
                                        <td class="font-monospace text-primary fw-bold">6310060000121</td>
                                        <td class="text-start">ชุดไมค์คาดศรีษะและลำโพงขยายเสียง</td>
                                        <td>Sherman</td>
                                        <td><span class="badge bg-light text-dark border font-monospace">c0000000001</span><br><small class="text-muted">(ไฟฟ้า/วิทยุ)</small></td>
                                        <td><span class="badge bg-light text-dark border font-monospace">CLB3</span></td>
                                        <td><span class="badge bg-light text-dark border font-monospace">CLB3100</span></td>
                                        <td class="font-monospace">4500.00</td>
                                        <td><span class="badge bg-secondary-subtle text-secondary font-monospace">2565</span></td>
                                        <td>ส่วนกลาง</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- คำอธิบายเพิ่มเติมสำหรับระบบ Auto-detect -->
                        <div class="row g-2 small text-muted">
                            <div class="col-md-6">
                                <i class="fa-solid fa-robot text-primary me-1"></i>
                                <strong>ระบบตรวจจับหมวดหมู่อัจฉริยะ:</strong> หากเว้นว่าง <code>classificationID</code> ระบบจะวิเคราะห์ชื่อรายการและรหัสครุภัณฑ์เพื่อผูกหมวดหมู่ให้อัตโนมัติ หรือพิมพ์เป็นชื่อ เช่น "คอมพิวเตอร์" ก็ได้
                            </div>
                            <div class="col-md-6">
                                <i class="fa-solid fa-calendar-days text-primary me-1"></i>
                                <strong>ปีงบประมาณและสังกัด:</strong> หากเว้นว่าง <code>DA_Year</code> ระบบจะดึงปีจากรหัสครุภัณฑ์ (เช่น /43, 63...) และผูก <code>owner_department</code> ตามแผนกของแอดมินที่ล็อกอิน
                            </div>
                        </div>
                    </div>

                    <!-- ข้อมูลอ้างอิง Master Data จากฐานข้อมูลจริง (Cheatsheet Reference) -->
                    <div class="card bg-light border-0 mb-4">
                        <div class="card-header bg-transparent border-0 pb-0">
                            <ul class="nav nav-tabs card-header-tabs" id="refTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="class-tab" data-bs-toggle="tab" data-bs-target="#tab-class" type="button" role="tab">
                                        <i class="fa-solid fa-tags me-1"></i> รหัสหมวดหมู่ในฐานข้อมูล (<?php echo count($dbClasses); ?> รายการ)
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="build-tab" data-bs-toggle="tab" data-bs-target="#tab-build" type="button" role="tab">
                                        <i class="fa-solid fa-building me-1"></i> รหัสอาคาร/สถานที่ (<?php echo count($dbBuildings); ?> อาคาร)
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="rooms-tab" data-bs-toggle="tab" data-bs-target="#tab-rooms" type="button" role="tab">
                                        <i class="fa-solid fa-door-open me-1"></i> ตัวอย่างรหัสห้อง
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body bg-white border rounded-bottom p-3">
                            <div class="tab-content" id="refTabsContent">
                                <!-- แถบที่ 1: หมวดหมู่ครุภัณฑ์ -->
                                <div class="tab-pane fade show active" id="tab-class" role="tabpanel">
                                    <div class="row g-2">
                                        <?php foreach ($dbClasses as $c): ?>
                                            <div class="col-sm-6 col-md-4">
                                                <div class="p-2 border rounded d-flex align-items-center justify-content-between bg-light">
                                                    <span class="small text-dark fw-semibold"><?php echo htmlspecialchars($c['classificationName']); ?></span>
                                                    <span class="badge bg-primary font-monospace font-xs"><?php echo htmlspecialchars($c['classificationID']); ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="small text-muted mt-2">
                                        <i class="fa-solid fa-circle-check text-success me-1"></i> ในไฟล์ CSV สามารถระบุเป็นรหัส เช่น <code>c0000000004</code> หรือพิมพ์ชื่อ เช่น <code>ครุภัณฑ์คอมพิวเตอร์</code> ก็ได้
                                    </div>
                                </div>

                                <!-- แถบที่ 2: อาคาร/สถานที่ -->
                                <div class="tab-pane fade" id="tab-build" role="tabpanel">
                                    <div class="row g-2">
                                        <?php foreach ($dbBuildings as $b): ?>
                                            <div class="col-sm-6 col-md-4">
                                                <div class="p-2 border rounded d-flex align-items-center justify-content-between bg-light">
                                                    <div>
                                                        <div class="small text-dark fw-semibold"><?php echo htmlspecialchars($b['buildingName']); ?></div>
                                                        <div class="font-xs text-muted">
                                                            <?php if (str_starts_with($b['buildingID'], 'CLB')): ?>
                                                                <span class="badge bg-secondary-subtle text-secondary">ซ่อนอัตโนมัติ (CLB)</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-success-subtle text-success">แสดงผลปกติ</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <span class="badge bg-info text-dark font-monospace"><?php echo htmlspecialchars($b['buildingID']); ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="small text-muted mt-2">
                                        <i class="fa-solid fa-circle-check text-success me-1"></i> สามารถระบุเป็นรหัสอาคาร เช่น <code>PB</code>, <code>ACB</code>, <code>CLB3</code> หรือพิมพ์ชื่ออาคาร ระบบจะจับคู่ให้อัตโนมัติ
                                    </div>
                                </div>

                                <!-- แถบที่ 3: ตัวอย่างห้อง -->
                                <div class="tab-pane fade" id="tab-rooms" role="tabpanel">
                                    <div class="row g-2">
                                        <?php foreach ($dbRooms as $r): ?>
                                            <div class="col-sm-6 col-md-4">
                                                <div class="p-2 border rounded d-flex align-items-center justify-content-between bg-light">
                                                    <div>
                                                        <span class="small text-dark"><?php echo htmlspecialchars($r['classroomName']); ?></span>
                                                        <div class="font-xs text-muted">อาคาร: <?php echo htmlspecialchars($r['buildingID']); ?></div>
                                                    </div>
                                                    <span class="badge bg-secondary font-monospace font-xs"><?php echo htmlspecialchars($r['classroomID']); ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="small text-muted mt-2">
                                        <i class="fa-solid fa-circle-info text-info me-1"></i> หากไม่แน่ใจรหัสห้อง สามารถเว้นว่างช่อง <code>classroomID</code> ไว้ได้
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ฟอร์มอัปโหลดไฟล์ CSV -->
                    <form action="process_import.php" method="POST" enctype="multipart/form-data" id="importForm" class="border p-4 rounded-3 bg-white shadow-sm">
                        <div class="mb-4">
                            <label for="csvFile" class="form-label fw-bold text-dark fs-5 mb-3">
                                <i class="fa-solid fa-file-csv text-success me-2"></i>เลือกไฟล์ CSV ที่ต้องการนำเข้าสู่ระบบ
                            </label>
                            
                            <div class="dropzone-box p-4 text-center">
                                <i class="fa-solid fa-cloud-arrow-up text-primary fa-3x mb-3"></i>
                                <input class="form-control form-control-lg border-primary mb-2" type="file" id="csvFile" name="csvFile" accept=".csv" required>
                                <div class="form-text text-muted">
                                    <span class="badge bg-light text-secondary border me-1"><i class="fa-solid fa-check text-success me-1"></i>รองรับไฟล์ .csv (UTF-8)</span>
                                    <span class="badge bg-light text-secondary border me-1"><i class="fa-solid fa-check text-success me-1"></i>ขนาดสูงสุด 5 MB</span>
                                    <span class="badge bg-light text-secondary border"><i class="fa-solid fa-check text-success me-1"></i>อัปเดตข้อมูลเดิมอัตโนมัติหากมี DA_Number ซ้ำ</span>
                                </div>
                            </div>
                        </div>

                        <div class="text-center mt-4">
                            <button type="submit" class="btn btn-primary btn-lg px-5 rounded-pill shadow-sm" id="btnSubmit">
                                <i class="fa-solid fa-upload me-2"></i> ยืนยันการนำเข้าข้อมูล
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('importForm').addEventListener('submit', function(e) {
    const fileInput = document.getElementById('csvFile');
    if (fileInput.files.length === 0) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'กรุณาเลือกไฟล์',
            text: 'โปรดเลือกไฟล์ CSV ก่อนกดยืนยันการนำเข้า',
            confirmButtonColor: '#0d6efd'
        });
        return;
    }

    const file = fileInput.files[0];
    if (!file.name.toLowerCase().endsWith('.csv')) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'รูปแบบไฟล์ไม่ถูกต้อง',
            text: 'ระบบรองรับเฉพาะไฟล์นามสกุล .csv เท่านั้น',
            confirmButtonColor: '#dc3545'
        });
        return;
    }

    const btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>กำลังประมวลผลการนำเข้า...';
});
</script>

<?php require_once __DIR__ . '/../component/admin_footer.php'; ?>
