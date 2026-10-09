<?php
include("../control/connectDB.php");
session_start();

if ($_SESSION['Selogin'] <> 1) {
    echo "<meta http-equiv='refresh' content='0;url=index.php'>";
}

$useraccountID =  $_SESSION['SeUser'];
$useraccountName =  $_SESSION['Sename'];
$useraccountType = $_SESSION['SeType'];


?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>คืน ครุภัณฑ์</title>

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
        <h5 class="mb-3"><i class="fa-solid fa-pen"></i> ข้อมูลการยืม ครุภัณฑ์</h5>
        <hr>
        <div class="table-responsive">
            <table class="cell-border" id="table_BorrowDA">

            </table>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="ModalBorrowDA" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="exampleModalLabel">ยืมวัสดุ/ครุภัณฑ์</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="txt_setIDBorrowBack" class="form-control">
                    <input type="hidden" id="txt_setDAID" class="form-control">
                    <div class="mb-3">
                        <label class="form-label">วัน/เดือน/ปี</label>
                        <input type="date" id="txt_date_Back" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อผู้นำมาคืน</label>
                        <input type="text" id="txt_name_Back" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ต่ำแหน่ง</label>
                        <input type="text" id="txt_rank_Back" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รายละเอียดเพิ่มเติม</label>
                        <input type="text" id="txt_detail_Back" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="updateBorrow()" class="btn btn-primary">บันทึกข้อมูล</button>
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
                url: "loadTableBorrow_forBack.php",
                method: "POST",
                data: {},
                success: function(dataTableBorrow_forBack) {
                    $("#table_BorrowDA").html(dataTableBorrow_forBack);
                    $('#table_BorrowDA').DataTable({
                        dom: 'Bfrtip',
                        buttons: [
                            'copy', 'excel', 'print'
                        ]
                    });
                }
            });
        }

        function setinputDAID(BorrowBackID, DaIDs) {
            document.getElementById("txt_setIDBorrowBack").value = BorrowBackID;
            document.getElementById("txt_setDAID").value = DaIDs;


        }

        function updateBorrow() {
            var setDAID = document.getElementById("txt_setDAID").value;
            var setIDBorrowBack = document.getElementById("txt_setIDBorrowBack").value;
            var date_Back = document.getElementById("txt_date_Back").value;
            var name_Back = document.getElementById("txt_name_Back").value;
            var rank_Back = document.getElementById("txt_rank_Back").value;
            var detail_Back = document.getElementById("txt_detail_Back").value;

            if (setIDBorrowBack == '' || setDAID == '' || date_Back == '' || name_Back == '' || rank_Back == '' || detail_Back == '') {
                swal("กรุณากรอกข้อมูลให้ครบถ้วน", "warning", "warning");
            } else {
                $.ajax({
                    url: "Update_BorrowDA.php",
                    method: "POST",
                    data: {
                        setDAID: setDAID,
                        setIDBorrowBack: setIDBorrowBack,
                        date_Back: date_Back,
                        name_Back: name_Back,
                        rank_Back: rank_Back,
                        detail_Back: detail_Back
                    },
                    success: function(dataUpdateBorrowDA) {
                        console.log(dataUpdateBorrowDA);
                        if (dataUpdateBorrowDA == '1') {
                            setTimeout(function() {
                                swal({
                                    title: "บันทึกการยืมสำเร็จ!", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "Success", //ข้อความเปลี่ยนได้ตามการใช้งาน
                                    type: "success", //success, warning, danger
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