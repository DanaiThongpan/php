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
    <title>จัดการข้อมูลผู้ใช้</title>

    <!--css style bootstrap-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <!--css style-->
    <link rel="stylesheet" href="../css/style.css" />
    <!--style font awesome-->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>

<body class="bg-light" onload="myFunction()">
    <?php require_once("../component/nav.php"); ?>

    <div class="container">
        <div class="mt-3">
            <div class="row">
                <div class="col-md">
                    <!--button add useraccount-->
                    <button type="button" data-bs-toggle="modal" data-bs-target="#modalFormUseraccount" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> เพิ่มข้อมูลผู้ใช้</button>
                    <hr>
                </div>

            </div>
        </div>
    </div>

    <!-- Table UserAccount-->
    <div class="container">
        <div class="mt-3 shadow-sm p-3 mb-5 bg-white rounded">
            <h5 class="mb-3" ><i class="fa-solid fa-address-book"></i> ข้อมูลผู้ใช้งาน</h5>
            <table class="cell-border" id="tableUserAccount">
            </table>
        </div>
    </div>


    <!--Modal form useraccount-->
    <div class="modal fade" id="modalFormUseraccount" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="staticBackdropLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="modalFormUseraccountLabel">เพิ่มข้อมูลผู้ใช้</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="exampleFormControlInput1" class="form-label">Username</label>
                        <input type="text" class="form-control" id="useraccountID" placeholder="กรุณากรอก Username">
                    </div>
                    <div class="mb-3">
                        <label for="exampleFormControlInput1" class="form-label">ชื่อผู้ใช้งาน</label>
                        <input type="text" class="form-control" id="useraccountName" placeholder="กรุณากรอก ชื่อผู้ใช้งาน">
                    </div>
                    <div class="mb-3">
                        <label for="exampleFormControlInput1" class="form-label">Password</label>
                        <input type="password" class="form-control" id="useraccountPassword" placeholder="กรุณากรอก Password">
                    </div>
                    <div class="md-3">
                        <label for="exampleFormControlInput1" class="form-label">ประเภทผู้ใช้งาน</label>
                        <select class="form-select" aria-label="Default select example" id="useraccountType">
                            <option selected value="">เลือกประเภทผู้ใช้งาน</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" onclick="addUserAccount()">บันทึกข้อมูล</button>
                </div>
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
    <!-- script jquerydataTables-->
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>


    <!-- script Ajax -->
    <script>
        function myFunction() {
            $.ajax({
                url: "loadTypeUser.php",
                method: "POST",
                data: {},
                success: function(dataTypeUser) {
                    $("#useraccountType").html(dataTypeUser);
                }
            });

            $.ajax({
                url: "loadTableUserAccount.php",
                method: "POST",
                data: {},
                success: function(dataTableUserAccount) {
                    $("#tableUserAccount").html(dataTableUserAccount);
                    $('#tableUserAccount').DataTable();
                }
            });
        }


        function addUserAccount() {
            var useraccountID = document.getElementById("useraccountID").value;
            var useraccountName = document.getElementById("useraccountName").value;
            var useraccountPassword = document.getElementById("useraccountPassword").value;
            var useraccountType = document.getElementById("useraccountType").value;

            if (useraccountID == '' || useraccountName == '' || useraccountPassword == '' || useraccountType == '') {
                swal("กรุณากรอกข้อมูลให้ครบถ้วน", "warning", "warning");
            } else {
                $.ajax({
                    url: "insert_UserAccount.php",
                    method: "POST",
                    data: {
                        useraccountID: useraccountID,
                        useraccountName: useraccountName,
                        useraccountPassword: useraccountPassword,
                        useraccountType: useraccountType
                    },
                    success: function(dataInsertUserAccount) {
                        if (dataInsertUserAccount == '1') {
                            setTimeout(function() {
                                swal({
                                    title: "บันทึกข้อมูลสำเร็จ!", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "Success", //ข้อความเปลี่ยนได้ตามการใช้งาน
                                    type: "success", //success, warning, danger
                                    timer: 1300, //ระยะเวลา redirect 3000 = 3 วิ เพิ่มลดได้
                                    showConfirmButton: false //ปิดการแสดงปุ่มคอนเฟิร์ม ถ้าแก้เป็น true จะแสดงปุ่ม ok ให้คลิกเหมือนเดิม
                                }, function() {
                                    window.location.reload();
                                });
                            });
                        }
                        if (dataInsertUserAccount == '2') {
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
                        if (dataInsertUserAccount == '3') {
                            swal("มี Username นี้อยู่ในระบบอยู่แล้ว", "กรุณา Username ใหม่", "warning");
                        }
                    }
                });
            }
        }
    </script>

</body>

</html>