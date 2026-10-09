<?php
include("../control/connectDB.php");
session_start();

if ($_SESSION['Selogin'] <> 1) {
    echo "<meta http-equiv='refresh' content='0;url=index.php'>";
}

$useraccountID =  $_SESSION['SeUser'];
$useraccountName =  $_SESSION['Sename'];


?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>จัดการหมวดหมู่</title>

    <!--css style bootstrap-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <!--css style-->
    <link rel="stylesheet" href="../css/style.css" />
    <!--style font awesome-->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>

<body onload="myFunction()">
    <?php require_once("../component/nav.php"); ?>

    <div class="container mt-3 shadow-sm p-3 mb-5 bg-white rounded">
        <h5 class="mb-3"><i class="fa-solid fa-address-book"></i> ข้อมูลหมวดหมู่</h5>
        <hr>
        <div class="row">
            <div class="col-md-3 p-3">
                <input type="hidden" value="" id="txt_ID_Classification">
                <div class="col-md-12">
                    <label for="inputEmail4" class="form-label">ชื่อหมวดหมู่</label>
                    <input type="text" class="form-control" id="txt_ClassificationName">
                </div>
                <div class="col-md-12 mt-3" id="btnShowActive">
                    <button type="button" onclick="addClassification()" style="width : 100%;" class="btn btn-outline-primary"><i class="fa-solid fa-plus"></i> เพิ่มหมวดหมู่</button>
                </div>
                <div class="col-md-12 mt-3">
                    <button type="button" onclick="resetFormClass()" style="width : 100%;" class="btn btn-outline-danger"><i class="fa-solid fa-rotate-right"></i> รีเซ็ทข้อมูล</button>
                </div>
            </div>
            <div class="col-md-9 p-3">
                <ol class="list-group list-group-numbered" id="listClassification">
                </ol>
            </div>
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
        function myFunction() {
            $.ajax({
                url: "loadListClassification.php",
                method: "POST",
                data: {},
                success: function(dataListClassification) {
                    $("#listClassification").html(dataListClassification);
                }
            });
        }

        function resetFormClass() {
            document.getElementById("txt_ID_Classification").value = "";
            document.getElementById("txt_ClassificationName").value = "";
            $("#btnShowActive").html('<button type="button" onclick="addClassification()" style="width : 100%;" class="btn btn-outline-primary"><i class="fa-solid fa-plus"></i> เพิ่มหมวดหมู่</button>');
        }

        function editClassification(classID, className) {
            document.getElementById("txt_ID_Classification").value = classID;
            document.getElementById("txt_ClassificationName").value = className;
            $("#btnShowActive").html('<button type="button" onclick="updateClassification()" style="width : 100%;" class="btn btn-outline-warning">แก้ไขหมวดหมู่</button>');
        }

        function updateClassification() {
            var txt_ID_Classification = document.getElementById("txt_ID_Classification").value;
            var txt_ClassificationName = document.getElementById("txt_ClassificationName").value;
            if (txt_ID_Classification == '') {
                swal("ยังไม่เลือกข้อมูล", "warning", "warning");
            } else if (txt_ClassificationName == '') {
                swal("กรุณากรอก ชื่อหมวดหมู่", "warning", "warning");
            } else {
                $.ajax({
                    url: "update_Classification.php",
                    method: "POST",
                    data: {
                        txt_ID_Classification: txt_ID_Classification,
                        txt_ClassificationName: txt_ClassificationName
                    },
                    success: function(dataUpdateClassification) {
                        //console.log(dataUpdateClassification);
                        if (dataUpdateClassification == '1') {
                            setTimeout(function() {
                                swal({
                                    title: "บันทึกรายการสำเร็จ!", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "Success", //ข้อความเปลี่ยนได้ตามการใช้งาน
                                    type: "success", //success, warning, danger
                                    timer: 1300, //ระยะเวลา redirect 3000 = 3 วิ เพิ่มลดได้
                                    showConfirmButton: false //ปิดการแสดงปุ่มคอนเฟิร์ม ถ้าแก้เป็น true จะแสดงปุ่ม ok ให้คลิกเหมือนเดิม
                                }, function() {
                                    window.location.reload();
                                });
                            });
                        }
                        if (dataUpdateClassification == '2') {
                            setTimeout(function() {
                                swal({
                                    title: "บันทึกข้อมูลล้มเหล้ว", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "danger", //ข้อความเปลี่ยนได้ตามการใช้งาน
                                    type: "danger", //success, warning, danger
                                    timer: 1300, //ระยะเวลา redirect 3000 = 3 วิ เพิ่มลดได้
                                    showConfirmButton: false //ปิดการแสดงปุ่มคอนเฟิร์ม ถ้าแก้เป็น true จะแสดงปุ่ม ok ให้คลิกเหมือนเดิม
                                }, function() {
                                    window.location.reload();
                                });
                            });
                        }
                    }
                });
            }
        }

        function addClassification() {
            var classificationName = document.getElementById("txt_ClassificationName").value;

            if (classificationName == '') {
                swal("กรุณากรอก ชื่อหมวดหมู่", "warning", "warning");
            } else {
                $.ajax({
                    url: "insert_Classification.php",
                    method: "POST",
                    data: {
                        classificationName: classificationName
                    },
                    success: function(dataInsertClassification) {
                        //console.log(dataInsertClassification);
                        if (dataInsertClassification == '1') {
                            setTimeout(function() {
                                swal({
                                    title: "บันทึกรายการสำเร็จ!", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "Success", //ข้อความเปลี่ยนได้ตามการใช้งาน
                                    type: "success", //success, warning, danger
                                    timer: 1300, //ระยะเวลา redirect 3000 = 3 วิ เพิ่มลดได้
                                    showConfirmButton: false //ปิดการแสดงปุ่มคอนเฟิร์ม ถ้าแก้เป็น true จะแสดงปุ่ม ok ให้คลิกเหมือนเดิม
                                }, function() {
                                    window.location.reload();
                                });
                            });
                        }
                        if (dataInsertClassification == '2') {
                            setTimeout(function() {
                                swal({
                                    title: "บันทึกข้อมูลล้มเหล้ว", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "danger", //ข้อความเปลี่ยนได้ตามการใช้งาน
                                    type: "danger", //success, warning, danger
                                    timer: 1300, //ระยะเวลา redirect 3000 = 3 วิ เพิ่มลดได้
                                    showConfirmButton: false //ปิดการแสดงปุ่มคอนเฟิร์ม ถ้าแก้เป็น true จะแสดงปุ่ม ok ให้คลิกเหมือนเดิม
                                }, function() {
                                    window.location.reload();
                                });
                            });
                        }
                    }
                });
            }
        }

        function delClassification(classID) {
            let text = "ต้องการลบข้อมูลหมวดหมู่ ใช่ หรือ ไม่ ?";
            if (confirm(text) == true) {
                $.ajax({
                    url: "del_Classification.php",
                    method: "POST",
                    data: {
                        classID: classID
                    },
                    success: function(dataDelClassification) {
                        //console.log(dataDelClassification);
                        if (dataDelClassification == '1') {
                            setTimeout(function() {
                                swal({
                                    title: "ลบข้อมูลสำเร็จ!", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "Success", //ข้อความเปลี่ยนได้ตามการใช้งาน
                                    type: "success", //success, warning, danger
                                    timer: 1300, //ระยะเวลา redirect 3000 = 3 วิ เพิ่มลดได้
                                    showConfirmButton: false //ปิดการแสดงปุ่มคอนเฟิร์ม ถ้าแก้เป็น true จะแสดงปุ่ม ok ให้คลิกเหมือนเดิม
                                }, function() {
                                    window.location.reload();
                                });
                            });
                        }
                        if (dataDelClassification == '2') {
                            setTimeout(function() {
                                swal({
                                    title: "ลบข้อมูลล้มเหล้ว", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "danger", //ข้อความเปลี่ยนได้ตามการใช้งาน
                                    type: "danger", //success, warning, danger
                                    timer: 1300, //ระยะเวลา redirect 3000 = 3 วิ เพิ่มลดได้
                                    showConfirmButton: false //ปิดการแสดงปุ่มคอนเฟิร์ม ถ้าแก้เป็น true จะแสดงปุ่ม ok ให้คลิกเหมือนเดิม
                                }, function() {
                                    window.location.reload();
                                });
                            });
                        }
                        if (dataDelClassification == '3') {
                            setTimeout(function() {
                                swal({
                                    title: "ไม่สามารถลบได้ !", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "เนื่องจากมีข้อมูลครุภัณฑ์ในหมวดหมู่นี้", //ข้อความเปลี่ยนได้ตามการใช้งาน
                                    type: "warning", //success, warning, danger
                                    timer: 1300, //ระยะเวลา redirect 3000 = 3 วิ เพิ่มลดได้
                                    showConfirmButton: false //ปิดการแสดงปุ่มคอนเฟิร์ม ถ้าแก้เป็น true จะแสดงปุ่ม ok ให้คลิกเหมือนเดิม
                                }, function() {
                                    window.location.reload();
                                });
                            });
                        }
                    }
                });
            }
        }
    </script>
</body>

</html>