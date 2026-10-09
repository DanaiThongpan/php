<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/4.4.0/mdb.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    
    <!--css style-->
    <link rel="stylesheet" href="../css/style.css" />
    <title>เข้าสู่ระบบ ครุภัณฑ์</title>
</head>

<body>
    <section class="vh-100" style="background-color: #F5F5F5;">
        <div class="container py-5 h-100">
            <div class="row d-flex justify-content-center align-items-center h-100">
                <div class="col col-xl-10">
                    <div class="card" style="border-radius: 1rem;">
                        <div class="row g-0">
                            <div class="col-md-6 col-lg-5 d-none d-md-block">
                                <img src="img/Suppliesarticles.png" alt="login form" class="img-fluid" style="border-radius: 1rem 0 0 1rem; width: 100%; height: 100%;" />
                            </div>
                            <div class="col-md-6 col-lg-7 d-flex align-items-center">
                                <div class="card-body p-4 p-lg-5 text-black">

                                    <?php if (isset($_SESSION['success'])): ?>
                                        <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                                            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success']) ?>
                                            <button type="button" class="btn-close" data-mdb-dismiss="alert" aria-label="Close"></button>
                                        </div>
                                        <?php unset($_SESSION['success']); ?>
                                    <?php endif; ?>

                                    <form action="login.php" method="POST">
                                        <div class="d-flex align-items-center mb-3 pb-1">
                                            <i class="fa-solid fa-door-open fa-2x me-3" style="color: #ff6219;"></i>
                                            <span class="h1 fw-bold mb-0">เข้าสู่ระบบ</span>
                                        </div>

                                        <h5 class="fw-normal mb-3 pb-3" style="letter-spacing: 1px;">ระบบครุภัณฑ์</h5>

                                        <div class="form-outline mb-4">
                                            <input type="text" class="form-control form-control-lg" name="txt_user" />
                                            <label class="form-label" for="form2Example17">Username</label>
                                        </div>

                                        <div class="form-outline mb-4">
                                            <input type="password" class="form-control form-control-lg" name="txt_Password" />
                                            <label class="form-label" for="form2Example27">Password</label>
                                        </div>

                                        <div class="pt-1 mb-4">
                                            <button class="btn btn-dark btn-lg btn-block" type="submit">Login</button>
                                        </div>
                                        
                                        <!-- เพิ่มปุ่ม/ลิงก์สำหรับไปหน้าสมัครสมาชิก -->
                                        <div class="text-center mt-4 pt-2 border-top">
                                            <p class="small mb-0 mt-3">ยังไม่มีบัญชีผู้ใช้งานใช่หรือไม่?</p>
                                            <a href="register.php" class="btn btn-outline-primary btn-lg btn-block mt-2">ลงทะเบียน / สร้างบัญชี</a>
                                        </div>
                                    </form>

                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mdb-ui-kit/4.4.0/mdb.min.js"></script>
</body>

</html>