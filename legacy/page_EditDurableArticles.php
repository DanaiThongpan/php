<?php
include("../control/connectDB.php");
session_start();

if ($_SESSION['Selogin'] <> 1) {
    echo "<meta http-equiv='refresh' content='0;url=index.php'>";
}

$useraccountID =  $_SESSION['SeUser'];
$useraccountName =  $_SESSION['Sename'];

$txt_DAID = $_GET['DAID'];
$sql_SlectDA_ID = "SELECT *,durable_articles.buildingID AS DABID
FROM `durable_articles` 
LEFT JOIN classification ON classification.classificationID = durable_articles.classificationID 
LEFT JOIN status_active ON status_active.statusID = durable_articles.statusID 
LEFT JOIN building ON building.buildingID = durable_articles.buildingID 
LEFT JOIN building_classroom ON building_classroom.classroomID = durable_articles.classroomID 
WHERE durable_articles.DA_ID = '$txt_DAID';";
$result_SlectDA_ID = $conn->query($sql_SlectDA_ID);
foreach ($result_SlectDA_ID as $dataSlectDA_ID) {


    $db_DA_ListName = $dataSlectDA_ID['DA_ListName'];
    $db_DA_Brand = $dataSlectDA_ID['DA_Brand'];
    $db_DA_Size = $dataSlectDA_ID['DA_Size'];
    $db_DA_Number = $dataSlectDA_ID['DA_Number'];
    $db_DA_Year = $dataSlectDA_ID['DA_Year'];
    $db_DA_Detail = $dataSlectDA_ID['DA_Detail'];
    $db_DA_URLImg = $dataSlectDA_ID['DA_URLImg'];

    $db_statusID = $dataSlectDA_ID['statusID'];
    $db_statusName = $dataSlectDA_ID['statusName'];

    $db_classificationID = $dataSlectDA_ID['classificationID'];
    $db_classificationName = $dataSlectDA_ID['classificationName'];

    $db_buildingID = $dataSlectDA_ID['DABID'];
    $db_buildingName = $dataSlectDA_ID['buildingName'];

    $db_classroomID = $dataSlectDA_ID['classroomID'];
    $db_classroomName = $dataSlectDA_ID['classroomName'];

    $db_DA_Price = $dataSlectDA_ID['DA_Price'];
    $db_DA_DetailLocation = $dataSlectDA_ID['DA_DetailLocation'];
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>จัดการข้อมูลครุภัณฑ์</title>

    <!--css style bootstrap-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <!--css style-->
    <link rel="stylesheet" href="../css/style.css" />
    <!--style font awesome-->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>

<body>
    <?php require_once("../component/nav.php"); ?>

    <div class="container">
        <div class="mt-3">
            <div class="row">
                <div class="col-md">
                    <!--button add useraccount-->
                    <a href="page_durable_articles.php" class="btn btn-danger"><i class="fa-solid fa-arrow-left"></i> ย้อนกลับ</a>
                    <hr>
                </div>

            </div>
        </div>
    </div>

    <!-- Form DA-->
    <div class="container">
        <div class="mt-3 shadow-sm p-3 mb-5 bg-white rounded">
            <h5 class="mb-3"><i class="fa-solid fa-address-book"></i> แก้ไขข้อมูลครุภัณฑ์</h5>
            <div class="mb-3">
                <form id="fromdurableArticles">
                    <input type="hidden" value="<?php echo $txt_DAID; ?>" id="txt_ID_DA" name="txt_ID_DA">
                    <label for="exampleFormControlInput1" class="form-label">หมวดหมู่</label>
                    <select class="form-select" aria-label="Default select example" id="selectClassification" name="selectClassification">
                        <option selected value="<?php if (isset($_GET['DAID'])) {
                                                    echo $db_classificationID;
                                                } ?>"><?php if (isset($_GET['DAID'])) {
                                                            echo $db_classificationName;
                                                        } else {
                                                            echo "เลือกหมวดหมู่";
                                                        } ?></option>
                        <?php
                        $sql_Classification = "SELECT * FROM `classification`";
                        $resultClassification = $conn->query($sql_Classification);
                        foreach ($resultClassification as $dataClassification) {
                        ?>
                            <option value="<?php echo $dataClassification["classificationID"]; ?>"><?php echo $dataClassification["classificationName"]; ?></option>
                        <?php
                        }
                        ?>
                    </select>
            </div>
            <div class="mb-3">
                <label for="exampleFormControlInput1" class="form-label">อาคาร</label>
                <select class="form-select" aria-label="Default select example" onchange="selectDataClassroom()" id="selectBuilding" name="selectBuilding">
                    <option selected value="<?php if (isset($_GET['DAID'])) {
                                                echo $db_buildingID;
                                            } ?>"><?php if (isset($_GET['DAID'])) {
                                                        echo $db_buildingName;
                                                    } else {
                                                        echo "เลือกอาคาร";
                                                    } ?></option>
                    <?php
                    $sql_building = "SELECT * FROM `building`";
                    $resultbuilding = $conn->query($sql_building);
                    foreach ($resultbuilding as $databuilding) {
                    ?>
                        <option value="<?php echo $databuilding["buildingID"]; ?>"><?php echo $databuilding["buildingName"]; ?></option>
                    <?php
                    }
                    ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="exampleFormControlInput1" class="form-label">ห้อง</label>
                <select class="form-select" aria-label="Default select example" id="selectClassroom" name="selectClassroom">
                    <option selected value="<?php if ($db_classroomName != "") {
                                                echo $db_classroomID;
                                            } ?>"><?php if ($db_classroomName != "") {
                                                        echo $db_classroomName;
                                                    } else {
                                                        echo "เลือกห้อง";
                                                    } ?></option>
                    <?php
                    $sql_classroom = "SELECT * FROM `building_classroom` WHERE buildingID = '$db_buildingID';";
                    $resultclassroom = $conn->query($sql_classroom);
                    foreach ($resultclassroom as $dataclassroom) {
                    ?>
                        <option value="<?php echo $dataclassroom["classroomID"]; ?>"><?php echo $dataclassroom["classroomName"]; ?></option>
                    <?php
                    }
                    ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="exampleFormControlInput1" class="form-label">รายละเอียดสถานที่</label>
                <input type="text" class="form-control" id="daDetailLocatin" name="daDetailLocatin" placeholder="กรุณากรอก รายละเอียดสถานที่" value="<?php if (isset($_GET['DAID'])) {
                                                                                                                                    echo $db_DA_DetailLocation;
                                                                                                                                } ?>">
            </div>
            <div class="mb-3">
                <label for="exampleFormControlInput1" class="form-label">รายการ</label>
                <input type="text" class="form-control" id="daListName" name="daListName" placeholder="กรุณากรอกรายการ" value="<?php if (isset($_GET['DAID'])) {
                                                                                                                                    echo $db_DA_ListName;
                                                                                                                                } ?>">
            </div>
            <div class="mb-3">
                <label for="exampleFormControlInput1" class="form-label">ยี่ห้อ / รุ่น</label>
                <input type="text" class="form-control" id="daBrand" name="daBrand" placeholder="กรุณากรอก ยี่ห้อ / รุ่น" value="<?php if (isset($_GET['DAID'])) {
                                                                                                                                        echo $db_DA_Brand;
                                                                                                                                    } ?>">
            </div>
            <div class="mb-3">
                <label for="exampleFormControlInput1" class="form-label">ขนาด</label>
                <input type="text" class="form-control" id="daSize" name="daSize" placeholder="กรุณากรอก ขนาน Size" value="<?php if (isset($_GET['DAID'])) {
                                                                                                                                echo $db_DA_Size;
                                                                                                                            } ?>">
            </div>
            <div class="mb-3">
                <label for="exampleFormControlInput1" class="form-label">หมายเลขครุภัณฑ์</label>
                <input type="text" class="form-control" id="daNumber" name="daNumber" placeholder="กรุณากรอก หมายเลขครุภัณฑ์" value="<?php if (isset($_GET['DAID'])) {
                                                                                                                                            echo $db_DA_Number;
                                                                                                                                        } ?>">
            </div>
            <div class="mb-3">
                <label for="exampleFormControlInput1" class="form-label">ปีเริ่มใช้งาน</label>
                <select class="form-select" aria-label="Default select example" id="daYear" name="daYear">
                    <option selected value="<?php if (isset($_GET['DAID'])) {
                                                echo $db_DA_Year;
                                            } ?>"><?php if (isset($_GET['DAID'])) {
                                                        echo $db_DA_Year;
                                                    } else {
                                                        echo "เลือกปี";
                                                    } ?></option>
                    <?php for ($i = 0; $i <= 50; $i++) { ?>
                        <option value="<?php echo date("Y") - $i + 543 ?>"><?php echo date("Y") - $i + 543 ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="mb-3">
                            <label for="exampleFormControlInput1" class="form-label">ราคา</label>
                            <input type="number" class="form-control" id="daPrice" name="daPrice" placeholder="กรุณากรอก ราคาครุภัณฑ์" value="<?php if (isset($_GET['DAID'])) {
                                                                                                                                    echo $db_DA_Price;
                                                                                                                                } ?>">
                        </div>
            <div class="mb-3">
                <label for="exampleFormControlInput1" class="form-label">สถานะ</label>
                <select class="form-select" aria-label="Default select example" id="selectStatusActive" name="selectStatusActive">
                    <option selected value="<?php if (isset($_GET['DAID'])) {
                                                echo $db_statusID;
                                            } ?>"><?php if (isset($_GET['DAID'])) {
                                                        echo $db_statusName;
                                                    } else {
                                                        echo "พร้อมใช้";
                                                    } ?></option>
                    <?php
                    $sql_StatusActive = "SELECT * FROM `status_active`";
                    $resultStatusActive = $conn->query($sql_StatusActive);
                    foreach ($resultStatusActive as $dataStatusActive) {
                    ?>
                        <option value="<?php echo $dataStatusActive["statusID"]; ?>"><?php echo $dataStatusActive["statusName"]; ?></option>
                    <?php
                    }
                    ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="exampleFormControlInput1" class="form-label">หมายเหตุ</label>
                <input type="text" class="form-control" id="daDetail" name="daDetail" placeholder="หมายเหตุหรือรายละเอียดเพิ่มเติม" value="<?php if (isset($_GET['DAID'])) {
                                                                                                                                                echo $db_DA_Detail;
                                                                                                                                            } ?>">
            </div>
            <div class="mb-3">
                <label for="formFile" class="form-label">อัพโหลดรูปภาพครุภัณฑ์</label>
                <input class="form-control" type="file" id="imgDA" name="imgDA" onchange="showImg()">
                <img id="previewImg" src="<?php echo $db_DA_URLImg; ?>" <?php if ($db_DA_URLImg) { ?>style="width: 30%;" <?php } ?> class="img-fluid rounded">
            </div>
            <button type="button" class="btn btn-success" onclick="editDurableArticles()">บันทึกการแก้ไขข้อมูล</button>
            </form>
        </div>
    </div>

    <!-- script bootstrap -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js" integrity="sha384-oBqDVmMz9ATKxIep9tiCxS/Z9fNfEXiDAYTujMAeBAsjFuCZSmKbSSUnQlmh/jp3" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.2/dist/js/bootstrap.min.js" integrity="sha384-IDwe1+LCz02ROU9k972gdyvl+AESN10+x7tBKgc9I5HFtuNz0wWnPclzo6p9vxnk" crossorigin="anonymous"></script>
    <!-- script jquery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- script sweetalert -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/1.1.3/sweetalert-dev.min.js"></script>
    <!-- css sweetalert -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/1.1.3/sweetalert.min.css" />
    <!-- css jquerydataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css" />
    <!-- script jquerydataTables-->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>

    <!-- script jquerydataTables button Exportfile-->
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>

    <script>
        function editDurableArticles() {

            var id_DA = document.getElementById("txt_ID_DA").value;
            var selectClassification = document.getElementById("selectClassification").value;
            var selectBuilding = document.getElementById("selectBuilding").value;
            var daListName = document.getElementById("daListName").value;
            var daBrand = document.getElementById("daBrand").value;
            var daSize = document.getElementById("daSize").value;
            var daNumber = document.getElementById("daNumber").value;
            var daYear = document.getElementById("daYear").value;
            var selectStatusActive = document.getElementById("selectStatusActive").value;

            var imgDA = $('#imgDA')[0].files;
            var formDataDA = new FormData($('#fromdurableArticles')[0]);

            if (id_DA == '') {
                setTimeout(function() {
                    swal({
                        title: "ยังไม่เลือกข้อมูล !", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                        text: "danger", //ข้อความเปลี่ยนได้ตามการใช้งาน
                        type: "danger", //success, warning, danger
                        timer: 1300, //ระยะเวลา redirect 3000 = 3 วิ เพิ่มลดได้
                        showConfirmButton: false //ปิดการแสดงปุ่มคอนเฟิร์ม ถ้าแก้เป็น true จะแสดงปุ่ม ok ให้คลิกเหมือนเดิม
                    }, function() {
                        location.href = "page_durable_articles.php";
                    });
                });
            } else {
                $.ajax({
                    url: "update_DurableArticles.php",
                    method: "POST",
                    data: formDataDA,
                    contentType: false,
                    processData: false,
                    success: function(dataEdit_DurableArticles) {
                        console.log(dataEdit_DurableArticles);
                        if (dataEdit_DurableArticles == '1') {
                            setTimeout(function() {
                                swal({
                                    title: "บันทึกรายการสำเร็จ!", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "Success", //ข้อความเปลี่ยนได้ตามการใช้งาน
                                    type: "success", //success, warning, danger
                                    timer: 1300, //ระยะเวลา redirect 3000 = 3 วิ เพิ่มลดได้
                                    showConfirmButton: false //ปิดการแสดงปุ่มคอนเฟิร์ม ถ้าแก้เป็น true จะแสดงปุ่ม ok ให้คลิกเหมือนเดิม
                                }, function() {
                                    location.href = "page_durable_articles.php";
                                });
                            });
                        }
                        if (dataEdit_DurableArticles == '2') {
                            setTimeout(function() {
                                swal({
                                    title: "บันทึกข้อมูลล้มเหล้ว", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "danger", //ข้อความเปลี่ยนได้ตามการใช้งาน
                                    type: "danger", //success, warning, danger
                                    timer: 1300, //ระยะเวลา redirect 3000 = 3 วิ เพิ่มลดได้
                                    showConfirmButton: false //ปิดการแสดงปุ่มคอนเฟิร์ม ถ้าแก้เป็น true จะแสดงปุ่ม ok ให้คลิกเหมือนเดิม
                                }, function() {
                                    location.href = "page_durable_articles.php";
                                });
                            });
                        }
                    }
                });
            }

        }

        function selectDataClassroom() {
            var txtselectBuilding = document.getElementById("selectBuilding").value;
            $.ajax({
                url: "loadClassroom.php",
                method: "POST",
                data: {
                    txtselectBuilding: txtselectBuilding
                },
                success: function(dataSelectClassroom) {
                    $("#selectClassroom").html(dataSelectClassroom);
                }
            });

        }

        function showImg() {
            let imgDA = document.querySelector("#imgDA");
            let previewImg = document.querySelector("#previewImg");

            const [file] = imgDA.files;
            previewImg.src = URL.createObjectURL(file);
        }
    </script>
</body>

</html>