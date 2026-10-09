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
    <title>ยืม ครุภัณฑ์</title>

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
        <h5 class="mb-3"><i class="fa-solid fa-pen"></i> ข้อมูลครุภัณฑ์</h5>
        <hr>
        <div class="table-responsive">
            <table class="cell-border" id="tableDA">

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
                    <input type="hidden" id="txt_setDAID" class="form-control">
                    <div class="mb-3">
                        <label class="form-label">วัน/เดือน/ปี</label>
                        <input type="date" id="txt_date_Borrow" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ชื่อผู้ยืม</label>
                        <input type="text" id="txt_name_Borrow" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ต่ำแหน่ง</label>
                        <input type="text" id="txt_rank_Borrow" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">สังกัด</label>
                        <input type="text" id="txt_belongto_Borrow" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">รายละเอียดเพิ่มเติม</label>
                        <input type="text" id="txt_detail_Borrow" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">กำหนดส่งคืน</label>
                        <input type="date" id="txt_deadline_Borrow" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="insertBorrow()" class="btn btn-primary">บันทึกข้อมูล</button>
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
                url: "loadTableBorrow_DataDA.php",
                method: "POST",
                data: {},
                success: function(dataTableBorrow_DataDA) {
                    $("#tableDA").html(dataTableBorrow_DataDA);
                    $('#tableDA').DataTable({
                        dom: 'Bfrtip',
                        buttons: [
                            'copy', 'excel', 'print'
                        ]
                    });
                }
            });
        }

        function setinputDAID(DaIDs) {
            document.getElementById("txt_setDAID").value = DaIDs;
        }

        function insertBorrow() {
            var setDAID = document.getElementById("txt_setDAID").value;
            var date_Borrow = document.getElementById("txt_date_Borrow").value;
            var name_Borrow = document.getElementById("txt_name_Borrow").value;
            var rank_Borrow = document.getElementById("txt_rank_Borrow").value;
            var belongto_Borrow = document.getElementById("txt_belongto_Borrow").value;
            var detail_Borrow = document.getElementById("txt_detail_Borrow").value;
            var deadline_Borrow = document.getElementById("txt_deadline_Borrow").value;

            if (setDAID == '' || date_Borrow == '' || name_Borrow == '' || rank_Borrow == '' ||
                belongto_Borrow == '' || detail_Borrow == '' || deadline_Borrow == '') {
                swal("กรุณากรอกข้อมูลให้ครบถ้วน", "warning", "warning");
            } else {
                $.ajax({
                    url: "insert_BorrowDA.php",
                    method: "POST",
                    data: {
                        setDAID: setDAID,
                        date_Borrow: date_Borrow,
                        name_Borrow: name_Borrow,
                        rank_Borrow: rank_Borrow,
                        belongto_Borrow: belongto_Borrow,
                        detail_Borrow: detail_Borrow,
                        deadline_Borrow: deadline_Borrow
                    },
                    success: function(dataInsertBorrowDA) {
                        console.log(dataInsertBorrowDA);
                        if (dataInsertBorrowDA == '1') {
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