<nav class="navbar navbar-expand-lg shadow" style="background-color:#0f9ce2;">
    <div class="container-fluid p-2">
        <a class="navbar-brand text-white"><i class="fa-solid fa-pen"></i> ระบบครุภัณฑ์</a>
        <button class="navbar-toggler bg-white" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link text-white" aria-current="page" href="main.php"><i class="fa-solid fa-chart-simple"></i> Dashboard</a>
                </li>
                <?php if($_SESSION['SeType'] == 'UT00000001'){?>
                <li class="nav-item">
                    <a class="nav-link text-white" aria-current="page" href="page_useraccount.php"><i class="fa-solid fa-user-pen"></i> จัดการข้อมูลผู้ใช้</a>
                </li>
                <?php } ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fa-solid fa-server"></i> จัดการข้อมูลทั่วไป
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="page_Classification.php"><i class="fa-solid fa-book"></i> ข้อมูลหมวดหมู่</a></li>
                        <li><a class="dropdown-item" href="page_StatusActive.php"><i class="fa-solid fa-star"></i> ข้อมูลสถานะ</a></li>
                        <li><a class="dropdown-item" href="page_building.php"><i class="fa-solid fa-building"></i> ข้อมูลอาคาร / ห้องเรียน</a></li>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white" aria-current="page" href="page_durable_articles.php"><i class="fa-solid fa-pen-to-square"></i> จัดการข้อมูลครุภัณฑ์</a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fa-solid fa-repeat"></i> ยืม-คืนครุภัณฑ์
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="page_DataborrowbackDA.php"><i class="fa-solid fa-book"></i> ข้อมูล ยืม-คืน ครุภัณฑ์</a></li>
                        <li><a class="dropdown-item" href="page_BorrowDA.php"><i class="fa-solid fa-star"></i> ยืม ครุภัณฑ์</a></li>
                        <li><a class="dropdown-item" href="page_BackDA.php"><i class="fa-solid fa-star"></i> คืน ครุภัณฑ์</a></li>
                    </ul>
                </li>
            </ul>

            <a class="nav-link text-white"><i class="fa-solid fa-user"></i> <?php echo $useraccountName; ?></a>
            &nbsp;
            <a class="btn btn-danger text-white" href="logout.php">ออกจากระบบ <i class="fa-solid fa-right-to-bracket"></i></a>

        </div>
    </div>
</nav>