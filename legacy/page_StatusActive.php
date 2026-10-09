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
    <title>จัดการข้อมูลสถานะ</title>

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
        <h5 class="mb-3"><i class="fa-solid fa-address-book"></i> ข้อมูลสถานะ</h5>
        <hr>
        <div class="row">
            <div class="col-md-3 p-3">
                <input type="hidden" value="" id="txt_StatusID">
                <div class="col-md-12">
                    <label for="inputEmail4" class="form-label">ชื่อสถานะ</label>
                    <input type="text" class="form-control" id="txt_StatusName">
                </div>
                <div class="col-md-12 mt-3" id="btnShowActive">
                    <button type="button" onclick="addStatusActive()" style="width : 100%;" class="btn btn-outline-primary"><i class="fa-solid fa-plus"></i> เพิ่มสถานะ</button>
                </div>
                <div class="col-md-12 mt-3">
                    <button type="button" onclick="resetFormData()" style="width : 100%;" class="btn btn-outline-danger"><i class="fa-solid fa-rotate-right"></i> รีเซ็ทข้อมูล</button>
                </div>
            </div>
            <div class="col-md-9 p-3">
                <ol class="list-group list-group-numbered" id="ListStatusActive">
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
                url: "loadListStatusActive.php",
                method: "POST",
                data: {},
                success: function(dataloadListStatusActive) {
                    $("#ListStatusActive").html(dataloadListStatusActive);
                }
            });
        }

        function addStatusActive() {
            var StatusName = document.getElementById("txt_StatusName").value;

            if (StatusName == '') {
                swal("กรุณากรอก ชื่อสถานะ", "warning", "warning");
            } else {
                $.ajax({
                    url: "insert_StatusActive.php",
                    method: "POST",
                    data: {
                        StatusName: StatusName
                    },
                    success: function(dataInsertStatusActive) {
                        console.log(dataInsertStatusActive);
                        if (dataInsertStatusActive == '1') {
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
                        if (dataInsertStatusActive == '2') {
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

        function resetFormData() {
            document.getElementById("txt_StatusID").value = "";
            document.getElementById("txt_StatusName").value = "";
            $("#btnShowActive").html('<button type="button" onclick="addStatusActive()" style="width : 100%;" class="btn btn-outline-primary"><i class="fa-solid fa-plus"></i> เพิ่มสถานะ</button>');
        }

        function editStatusActive(idStatus, nameStatus) {
            document.getElementById("txt_StatusID").value = idStatus;
            document.getElementById("txt_StatusName").value = nameStatus;
            $("#btnShowActive").html('<button type="button" onclick="updateStatusActive()" style="width : 100%;" class="btn btn-outline-warning">แก้ไขสถานะ</button>');
        }

        function updateStatusActive() {
            var txt_StatusID = document.getElementById("txt_StatusID").value;
            var txt_StatusName = document.getElementById("txt_StatusName").value;

            if (txt_StatusID == '') {
                swal("ยังไม่เลือกข้อมูล", "warning", "warning");
            } else if (txt_StatusName == '') {
                swal("กรุณากรอก ชื่อสถานะ", "warning", "warning");
            } else {
                $.ajax({
                    url: "update_StatusActive.php",
                    method: "POST",
                    data: {
                        txt_StatusID: txt_StatusID,
                        txt_StatusName: txt_StatusName
                    },
                    success: function(dataUpdateStatusActive) {
                        console.log(dataUpdateStatusActive);
                        if (dataUpdateStatusActive == '1') {
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
                        if (dataUpdateStatusActive == '2') {
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

        function delStatusActive(id_Status) {
            let text = "ต้องการลบข้อมูลสถานะ ใช่ หรือ ไม่ ?";
            if (confirm(text) == true) {
                $.ajax({
                    url: "del_StatusActive.php",
                    method: "POST",
                    data: {
                        id_Status: id_Status
                    },
                    success: function(dataDelStatusActive) {
                        console.log(dataDelStatusActive);
                        if (dataDelStatusActive == '1') {
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
                        if (dataDelStatusActive == '2') {
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
                        if (dataDelStatusActive == '3') {
                            setTimeout(function() {
                                swal({
                                    title: "ไม่สามารถสถานะลบได้ !", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "เนื่องจากมีข้อมูลครุภัณฑ์ใช้สถานะนี้", //ข้อความเปลี่ยนได้ตามการใช้งาน
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