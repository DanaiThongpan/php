<?php
$pageTitle = "นำเข้าข้อมูลครุภัณฑ์ด้วย CSV (CSV Import)";
require_once __DIR__ . '/../component/admin_header.php';
require_once __DIR__ . '/../component/admin_sidebar.php';
require_once __DIR__ . '/../component/admin_navbar.php';
?>
<style>
    .card { border-radius: 14px; }
    .alert-custom { border-left: 5px solid #0dcaf0; }
    .table-preview th { font-size: 0.85rem; font-weight: 600; vertical-align: middle; }
    .table-preview td { font-size: 0.85rem; vertical-align: middle; color: #555; }
</style>

<!-- Main Content -->
<div class="container-fluid px-0">
    <div class="row justify-content-center">
        <div class="col-lg-11 col-xl-10">
            <div class="card shadow border-0">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="fa-solid fa-cloud-arrow-up me-2"></i> ระบบนำเข้าข้อมูลครุภัณฑ์ (CSV Import)</h5>
                </div>
                <div class="card-body p-4 p-md-5">
                    
                    <!-- กล่องคำแนะนำและตัวอย่างข้อมูล -->
                    <div class="alert alert-light shadow-sm alert-custom mb-4" role="alert">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <h6 class="alert-heading fw-bold text-dark mb-0">
                                <i class="fa-solid fa-circle-info text-info me-2"></i> คำแนะนำและรูปแบบข้อมูล
                            </h6>
                            <a href="template_durable_articles.csv" class="btn btn-info btn-sm text-white fw-bold rounded-pill shadow-sm" download>
                                <i class="fa-solid fa-download me-1"></i> โหลดไฟล์ต้นแบบ (Template)
                            </a>
                        </div>
                        <p class="text-muted small mb-3">กรุณาจัดเตรียมไฟล์ข้อมูลของท่านให้อยู่ในรูปแบบ <code>.csv</code> (UTF-8) โดยมีหัวคอลัมน์ภาษาอังกฤษเรียงตามตัวอย่างด้านล่างนี้ เพื่อให้ระบบสามารถจับคู่ข้อมูลได้อย่างถูกต้อง</p>
                        
                        <!-- ตารางตัวอย่างข้อมูล (Data Preview) -->
                        <div class="table-responsive rounded border">
                            <table class="table table-hover table-striped table-preview text-center bg-white mb-0">
                                <thead class="table-light text-primary">
                                    <tr>
                                        <th>DA_Number<br><span class="text-secondary small fw-normal">(รหัสครุภัณฑ์)</span><br><span class="badge bg-danger-subtle text-danger fw-normal">จำเป็นต้องระบุ</span></th>
                                        <th>DA_ListName<br><span class="text-secondary small fw-normal">(ชื่อรายการ)</span><br><span class="badge bg-danger-subtle text-danger fw-normal">จำเป็นต้องระบุ</span></th>
                                        <th>DA_Brand<br><span class="text-secondary small fw-normal">(ยี่ห้อ)</span><br><span class="badge bg-secondary-subtle text-secondary fw-normal">เว้นว่างได้</span></th>
                                        <th>classificationID<br><span class="text-secondary small fw-normal">(รหัสหมวดหมู่)</span><br><span class="badge bg-success-subtle text-success fw-normal">เว้นว่างได้ (ตรวจจับอัตโนมัติ)</span></th>
                                        <th>buildingID<br><span class="text-secondary small fw-normal">(รหัสอาคาร)</span><br><span class="badge bg-secondary-subtle text-secondary fw-normal">เว้นว่างได้</span></th>
                                        <th>classroomID<br><span class="text-secondary small fw-normal">(รหัสห้อง)</span><br><span class="badge bg-secondary-subtle text-secondary fw-normal">เว้นว่างได้</span></th>
                                        <th>DA_Price<br><span class="text-secondary small fw-normal">(ราคา)</span><br><span class="badge bg-secondary-subtle text-secondary fw-normal">เว้นว่างได้</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="font-monospace text-primary">1206-001</td>
                                        <td>เครื่องปรับอากาศ</td>
                                        <td>Daikin</td>
                                        <td><span class="badge bg-light text-dark border">1</span></td>
                                        <td><span class="badge bg-light text-dark border">อาคาร 1</span></td>
                                        <td><span class="badge bg-light text-dark border">ห้อง 101</span></td>
                                        <td>25000.00</td>
                                    </tr>
                                    <tr>
                                        <td class="font-monospace text-primary">1206-002</td>
                                        <td>เครื่องคอมพิวเตอร์</td>
                                        <td>Dell</td>
                                        <td><span class="badge bg-light text-dark border">1</span></td>
                                        <td><span class="badge bg-light text-dark border">อาคาร 2</span></td>
                                        <td><span class="badge bg-light text-dark border">ห้อง 204</span></td>
                                        <td>32000.00</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ฟอร์มอัปโหลดไฟล์ -->
                    <form action="process_import.php" method="POST" enctype="multipart/form-data" class="border p-4 rounded-3 bg-white shadow-sm">
                        <div class="mb-4">
                            <label for="csvFile" class="form-label fw-bold text-dark fs-5 mb-3">
                                <i class="fa-solid fa-file-excel text-success me-2"></i>เลือกไฟล์ CSV ที่ต้องการนำเข้า
                            </label>
                            <input class="form-control form-control-lg border-primary" type="file" id="csvFile" name="csvFile" accept=".csv" required>
                            <div class="form-text mt-2 text-muted">
                                <i class="fa-solid fa-check text-success"></i> รองรับเฉพาะนามสกุล .csv <br>
                                <i class="fa-solid fa-check text-success"></i> ขนาดไฟล์สูงสุดไม่เกิน 5MB
                            </div>
                        </div>
                        <div class="text-center mt-4">
                            <button type="submit" class="btn btn-primary btn-lg px-5 rounded-pill shadow">
                                <i class="fa-solid fa-upload me-2"></i> ยืนยันการนำเข้าข้อมูล
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../component/admin_footer.php'; ?>
