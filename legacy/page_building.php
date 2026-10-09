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
    <br>
    <form id="frombuilding">
        <div class="container">
            <h5 class="mb-3"><i class="fa-solid fa-building"></i> เพิ่มข้อมูลอาคาร</h5>
            <div class="row">
                <div class="col-md-3">
                    <input type="text" class="form-control" placeholder="กรุณากรอก รหัสอาคาร" id="txtBuildingID" name="txtBuildingID">
                </div>
                <div class="col-md-5">
                    <input type="text" class="form-control" placeholder="กรุณากรอก ชื่ออาคาร" id="txtBuildingName" name="txtBuildingName">
                </div>
                <div class="col-md-2" id="btnShowActive">
                    <button type="button" onclick="addBuilding()" class="form-control btn btn-primary">บันทึกข้อมูล</button>
                </div>
                <div class="col-md-2">
                    <button type="button" onclick="resetFormData()" class="form-control btn btn-danger">รีเซ็ท</button>
                </div>
                <div class="row">
                    <div class="col-md-4 mt-2">
                        <input class="form-control" type="file" id="imgBuilding" name="imgBuilding" onchange="showImg()">
                    </div>
                    <div class="col-md-3 mt-2">
                        <img id="previewImg" style="width: 30%;" class="img-fluid rounded">
                    </div>
                </div>
            </div>
        </div>
    </form>
    <div class="container mt-3 shadow-sm p-3 mb-5 bg-white rounded">
        <h5 class="mb-3"><i class="fa-solid fa-building"></i> ข้อมูลอาคาร</h5>
        <hr>
        <div class="table-responsive">
            <table class="cell-border" id="tableBuilding">
            </table>
        </div>
    </div>

    <!-- Modal Show IMG Building -->
    <div class="modal fade" id="ShowBuildingImgModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5">รูปภาพอาคาร</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <img id="ModalimgBuilding" class="img-fluid rounded">
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
        function showImg() {
            let imgBuilding = document.querySelector("#imgBuilding");
            let previewImg = document.querySelector("#previewImg");

            const [file] = imgBuilding.files;
            previewImg.src = URL.createObjectURL(file);
        }

        function myFunction() {
            $.ajax({
                url: "loadTableBuilding.php",
                method: "POST",
                data: {},
                success: function(dataTableBuilding) {
                    $("#tableBuilding").html(dataTableBuilding);
                    $('#tableBuilding').DataTable({
                        dom: 'Bfrtip',
                        buttons: [
                            'copy', 'excel', 'print'
                        ]
                    });
                }
            });
        }

        function EditBuilding(EditBuildingID, EditBuildingName,URLImgBuilding) {
            document.getElementById("txtBuildingID").value = EditBuildingID;
            document.getElementById("txtBuildingName").value = EditBuildingName;
            document.getElementById("txtBuildingID").disabled = true;
            previewImg.src = URLImgBuilding;
            $("#btnShowActive").html('<button type="button" onclick="updateBuilding()" class="form-control btn btn-warning">แก้ไขข้อมูล</button>');
        }

        function resetFormData() {
            document.getElementById("txtBuildingID").value = "";
            document.getElementById("txtBuildingName").value = "";
            document.getElementById("txtBuildingID").disabled = false;
            previewImg.src = "";
            $("#btnShowActive").html('<button type="button" onclick="addBuilding()" class="form-control btn btn-primary">บันทึกข้อมูล</button>');
        }

        function updateBuilding() {
            var txtBuildingID = document.getElementById("txtBuildingID").value;
            var txtBuildingName = document.getElementById("txtBuildingName").value;

            var imgBuilding = $('#imgBuilding')[0].files;
            var formBuilding = new FormData($('#frombuilding')[0]);

            if (txtBuildingID == '') {
                swal("ยังไม่เลือกข้อมูล", "warning", "warning");
            } else if (txtBuildingName == '') {
                swal("กรุณากรอกข้อมูลให้ครบ", "warning", "warning");
            } else {
                $.ajax({
                    url: "update_Building.php?BuildingID="+txtBuildingID,
                    method: "POST",
                    data: formBuilding,
                    contentType: false,
                    processData: false,
                    success: function(dataUpdateBuilding) {
                        console.log(dataUpdateBuilding);
                        if (dataUpdateBuilding == '1') {
                            setTimeout(function() {
                                swal({
                                    title: "แก้ไขข้อมูลสำเร็จ!", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "Success", //ข้อความเปลี่ยนได้ตามการใช้งาน
                                    type: "success", //success, warning, danger
                                    timer: 1300, //ระยะเวลา redirect 3000 = 3 วิ เพิ่มลดได้
                                    showConfirmButton: false //ปิดการแสดงปุ่มคอนเฟิร์ม ถ้าแก้เป็น true จะแสดงปุ่ม ok ให้คลิกเหมือนเดิม
                                }, function() {
                                    window.location.reload();
                                });
                            });
                        } else {
                            setTimeout(function() {
                                swal({
                                    title: "แก้ไขข้อมูลล้มเหล้ว", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
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

        function addBuilding() {
            var txtBuildingID = document.getElementById("txtBuildingID").value;
            var txtBuildingName = document.getElementById("txtBuildingName").value;

            var imgBuilding = $('#imgBuilding')[0].files;
            var formBuilding = new FormData($('#frombuilding')[0]);

            if (txtBuildingID == '' || txtBuildingName == '') {
                swal("กรุณากรอกข้อมูลให้ครบถ้วน", "warning", "warning");
            } else {
                $.ajax({
                    url: "insert_Building.php",
                    method: "POST",
                    data: formBuilding,
                    contentType: false,
                    processData: false,
                    success: function(dataInsertBuilding) {
                        console.log(dataInsertBuilding);
                        if (dataInsertBuilding == '1') {
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
                        if (dataInsertBuilding == '2') {
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
                        if (dataInsertBuilding == '3') {
                            swal("มีรหัสอาคารนี้อยู่แล้ว !!", "warning", "warning");
                        }
                    }
                });
            }

        }

        function DelBuilding(DelBuildingID) {
            let text = "ต้องการลบข้อมูลอาคาร ใช่ หรือ ไม่ ?";
            if (confirm(text) == true) {
                $.ajax({
                    url: "del_Building.php",
                    method: "POST",
                    data: {
                        DelBuildingID: DelBuildingID
                    },
                    success: function(dataDelBuildingID) {
                        console.log(dataDelBuildingID);
                        if (dataDelBuildingID == '1') {
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
                        if (dataDelBuildingID == '2') {
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
                        if (dataDelBuildingID == '3') {
                            setTimeout(function() {
                                swal({
                                    title: "ไม่สามารถลบอาคารได้ !", //ข้อความ เปลี่ยนได้ เช่น บันทึกข้อมูลสำเร็จ!!
                                    text: "เนื่องจากมีข้อมูลห้องเรียนใช้อาคารนี้", //ข้อความเปลี่ยนได้ตามการใช้งาน
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

        function ShowModalImgBuilding(urlModalImgBuilding){
            if(urlModalImgBuilding == ''){
                swal("ไม่มีข้อมูลรูปภาพ", "warning", "warning");
            }else{
                $('#ShowBuildingImgModal').modal('show');
                document.getElementById("ModalimgBuilding").src = urlModalImgBuilding;
            }
        }
    </script>
</body>

</html>