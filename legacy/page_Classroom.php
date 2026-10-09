<?php
include("../control/connectDB.php");
session_start();

if ($_SESSION['Selogin'] <> 1) {
    echo "<meta http-equiv='refresh' content='0;url=index.php'>";
}

$useraccountID =  $_SESSION['SeUser'];
$useraccountName =  $_SESSION['Sename'];

$buildingID = $_GET['buildingID'];

$sql_SelectbuildingBYID = "SELECT * FROM `building` WHERE buildingID = '$buildingID'";
$result_tbuildingBYID = $conn->query($sql_SelectbuildingBYID);
foreach ($result_tbuildingBYID as $databuildingBYID) {
    $db_buildingID = $databuildingBYID['buildingID'];
    $db_buildingName = $databuildingBYID['buildingName'];
}
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

    <div class="container mt-3">
        <h5 class="mb-3"><i class="fa-solid fa-building"></i> ข้อมูลอาคาร</h5>
        <hr>
        <div class="row">
            <div class="col-md-6">
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="building_ID" value="<?php echo $db_buildingID; ?>" placeholder="อาคาร" readonly>
                    <label for="floatingInput">รหัสอาคาร</label>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="building_Name" value="<?php echo $db_buildingName; ?>" placeholder="อาคาร" readonly>
                    <label for="floatingInput">อาคาร</label>
                </div>
            </div>
        </div>
        <hr>
    </div>

    <form id="fromclassroom">
        <div class="container">
            <h5 class="mb-3"><i class="fa-solid fa-building"></i> เพิ่มข้อมูลห้องภายในอาคาร</h5>
            <div class="row">
                <input type="hidden" class="form-control" value="<?php echo $buildingID; ?>" id="txtbuilding_ID" name="txtbuilding_ID">
                <div class="mb-3">
                    <label for="formGroupExampleInput" class="form-label">รหัสห้อง</label>
                    <input type="text" class="form-control" id="classroomID" name="classroomID" placeholder="กรุณากรอก รหัสห้องภายในอาคาร">
                </div>
                <div class="mb-3">
                    <label for="formGroupExampleInput2" class="form-label">ชื่อห้อง</label>
                    <input type="text" class="form-control" id="classroomName" name="classroomName" placeholder="กรุณากรอก ชื่อห้องภายในอาคาร">
                </div>
                <div class="row">
                    <div class="col-md-2">
                        <img id="previewImg" src="../img/noimg.jpg" style="width: 100%;" class="img-fluid rounded">
                    </div>
                    <div class="col-md-4">
                        <input class="form-control" type="file" id="imgClassroom" name="imgClassroom" onchange="showImg()">
                    </div>
                    <div class="col-md-3" id="btnShowActive">
                        <button type="button" onclick="addclassroom()" class="form-control btn btn-primary mb-2">บันทึกข้อมูล</button>
                    </div>
                    <div class="col-md-3">
                        <button type="button" onclick="resetFormData()" class="form-control btn btn-danger mb-2">รีเซ็ท</button>
                    </div>
                </div>
            </div>
        </div>
    </form>



    <div class="container mt-3 shadow-sm p-3 mb-5 bg-white rounded">
        <h5 class="mb-3"><i class="fa-solid fa-building"></i> ข้อมูลห้อง</h5>
        <hr>
        <div class="table-responsive">
            <table class="table" cell-border" id="tableClassroom">
            </table>
        </div>
    </div>

    <!-- Modal Show IMG Building -->
    <div class="modal fade" id="ShowClassroomImgModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5">รูปภาพอาคาร</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <img id="ModalimgClassroom" class="img-fluid rounded">
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
            let imgClassroom = document.querySelector("#imgClassroom");
            let previewImg = document.querySelector("#previewImg");

            const [file] = imgClassroom.files;
            previewImg.src = URL.createObjectURL(file);
        }

        function EditClassroom(IDClassroom,NameClassroom,URLImgClassroom){
            document.getElementById("classroomID").value = IDClassroom;
            document.getElementById("classroomName").value = NameClassroom;
            document.getElementById("classroomID").disabled = true;
            if(URLImgClassroom != ''){
                previewImg.src = URLImgClassroom;
            }else{
                previewImg.src = "../img/noimg.jpg";
            }
            $("#btnShowActive").html('<button type="button" onclick="updateClassroom()" class="form-control btn btn-warning">แก้ไขข้อมูล</button>');
        }

        function myFunction() {
            var txtbuilding_ID = document.getElementById("txtbuilding_ID").value;
            $.ajax({
                url: "loadTableClassroom.php",
                method: "POST",
                data: {txtbuilding_ID:txtbuilding_ID},
                success: function(dataTableClassroom) {
                    $("#tableClassroom").html(dataTableClassroom);
                    $('#tableClassroom').DataTable({
                        dom: 'Bfrtip',
                        buttons: [
                            'copy', 'excel', 'print'
                        ]
                    });
                }
            });
        }

        function addclassroom() {

            var txtbuilding_ID = document.getElementById("txtbuilding_ID").value;
            var txtClassroomID = document.getElementById("classroomID").value;
            var txtClassroomName = document.getElementById("classroomName").value;

            var imgClassroom = $('#imgClassroom')[0].files;
            var fromClassroom = new FormData($('#fromclassroom')[0]);

            if (txtClassroomID == '' || txtClassroomName == '') {
                swal("กรุณากรอกข้อมูลให้ครบถ้วน", "warning", "warning");
            } else {
                $.ajax({
                    url: "insert_Classroom.php?building=" + txtbuilding_ID,
                    method: "POST",
                    data: fromClassroom,
                    contentType: false,
                    processData: false,
                    success: function(dataInsertClassroom) {
                        console.log(dataInsertClassroom);
                        if (dataInsertClassroom == '1') {
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
                        if (dataInsertClassroom == '2') {
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
                        if (dataInsertClassroom == '3') {
                            swal("มีรหัสห้องอยู่ในระบบ", "เปลี่ยนรหัสห้องใหม่", "warning");
                        }
                    }
                });
            }
        }

        function ShowModalImgClassroom(urlModalImgClassroom){
            if(urlModalImgClassroom == ''){
                swal("ไม่มีข้อมูลรูปภาพ", "warning", "warning");
            }else{
                $('#ShowClassroomImgModal').modal('show');
                document.getElementById("ModalimgClassroom").src = urlModalImgClassroom;
            }
        }

        function resetFormData(){
            document.getElementById("classroomID").value = "";
            document.getElementById("classroomName").value = "";
            document.getElementById("classroomID").disabled = false;
            previewImg.src = "../img/noimg.jpg";
            $("#btnShowActive").html('<button type="button" onclick="addclassroom()" class="form-control btn btn-primary mb-2">บันทึกข้อมูล</button>');
        }

        function updateClassroom(){
            var txtClassroomID = document.getElementById("classroomID").value;
            var txtClassroomName = document.getElementById("classroomName").value;

            var imgClassroom = $('#imgClassroom')[0].files;
            var fromClassroom = new FormData($('#fromclassroom')[0]);

            if(txtClassroomID == ''){
                swal("ยังไม่เลือกข้อมูล", "warning", "warning");
            }else if(txtClassroomName == ''){
                swal("กรุณากรอกข้อมูล ในช่องชื่อห้อง", "warning", "warning");
            }else{
                $.ajax({
                    url: "update_Classroom.php?ClassID="+txtClassroomID,
                    method: "POST",
                    data: fromClassroom,
                    contentType: false,
                    processData: false,
                    success: function(dataUpdateClassroom) {
                        console.log(dataUpdateClassroom);
                        if (dataUpdateClassroom == '1') {
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
                        }
                        if (dataUpdateClassroom == '2') {
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

        function DelClassroom(DelClassroomID){
            let text = "ต้องการลบข้อมูลอาคาร ใช่ หรือ ไม่ ?";
            if (confirm(text) == true) {
                $.ajax({
                    url: "del_Classroom.php",
                    method: "POST",
                    data: {
                        DelClassroomID: DelClassroomID
                    },
                    success: function(dataDelClassroomID) {
                        console.log(dataDelClassroomID);
                        if (dataDelClassroomID == '1') {
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
                        if (dataDelClassroomID == '2') {
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
                    }
                });
            }
        }
    </script>
</body>

</html>