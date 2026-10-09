<?php
// ไฟล์: register.php
session_start();
require_once 'control/connectPDO.php';

// สร้าง CSRF Token ไว้ใน Session เพื่อใช้ป้องกันการโจมตีข้ามไซต์
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครสมาชิก - Asset Management</title>
    <!-- ใช้ Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height: 100vh;">

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card shadow border-0 rounded-4">
                <div class="card-header bg-primary text-white text-center py-3" style="border-radius: 1rem 1rem 0 0;">
                    <h4 class="mb-0">ลงทะเบียนสมาชิกใหม่</h4>
                </div>
                <div class="card-body p-4 p-md-5">
                    
                    <!-- ส่วนแสดงข้อความแจ้งเตือน Error หรือ Success -->
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($_SESSION['error']) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['error']); ?>
                    <?php endif; ?>

                    <form action="register_process.php" method="POST" class="needs-validation" novalidate>
                        <!-- ซ่อน CSRF Token ไปกับฟอร์ม -->
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        
                        <!-- ซ่อนประเภทผู้ใช้งาน บังคับให้เป็น User ทั่วไปเท่านั้น (รหัส UT00000002 คือ User จากตาราง usertype) -->
                        <input type="hidden" name="useraccountType" value="UT00000002">

                        <div class="row">
                            <div class="col-12 mb-3">
                                <label for="useraccountID" class="form-label fw-bold">ชื่อผู้ใช้งาน (Username)</label>
                                <input type="text" class="form-control" id="useraccountID" name="useraccountID" required placeholder="สำหรับใช้เข้าสู่ระบบ">
                                <div class="invalid-feedback">กรุณากรอกชื่อผู้ใช้งาน</div>
                            </div>

                            <div class="col-12 mb-3">
                                <label for="userPassword" class="form-label fw-bold">รหัสผ่าน (Password)</label>
                                <input type="password" class="form-control" id="userPassword" name="userPassword" minlength="8" required placeholder="อย่างน้อย 8 ตัวอักษร">
                                <div class="invalid-feedback">กรุณากรอกรหัสผ่านอย่างน้อย 8 ตัวอักษร</div>
                            </div>

                            <div class="col-12 mb-3">
                                <label for="useraccountName" class="form-label fw-bold">ชื่อ - นามสกุล</label>
                                <input type="text" class="form-control" id="useraccountName" name="useraccountName" required>
                                <div class="invalid-feedback">กรุณากรอกชื่อ-นามสกุล</div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="userRank" class="form-label fw-bold">ตำแหน่ง</label>
                                <input type="text" class="form-control" id="userRank" name="userRank" required placeholder="เช่น ครู, เจ้าหน้าที่">
                                <div class="invalid-feedback">กรุณากรอกตำแหน่ง</div>
                            </div>

                            <div class="col-md-6 mb-4">
                                <label for="userDepartment" class="form-label fw-bold">สังกัด</label>
                                <input type="text" class="form-control" id="userDepartment" name="userDepartment" required placeholder="เช่น กลุ่มสาระฯ, ฝ่ายบริหาร">
                                <div class="invalid-feedback">กรุณากรอกสังกัด</div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-bold fs-5">สร้างบัญชี</button>
                        
                        <div class="text-center mt-4 border-top pt-3">
                            <a href="index.php" class="text-decoration-none">มีบัญชีอยู่แล้ว? เข้าสู่ระบบที่นี่</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- สคริปต์สำหรับตรวจสอบฟอร์มฝั่ง Client (Bootstrap Form Validation) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  'use strict'
  // ดึงฟอร์มทั้งหมดที่ต้องการให้ทำงาน Validation
  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms).forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!form.checkValidity()) {
        event.preventDefault()
        event.stopPropagation()
      }
      form.classList.add('was-validated')
    }, false)
  })
})()
</script>
</body>
</html>
