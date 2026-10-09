<?php
include("../control/connectDB.php");
session_start();

if ($_SESSION['Selogin'] <> 1) {
    echo "<meta http-equiv='refresh' content='0;url=index.php'>";
}

$useraccountID =  $_SESSION['SeUser'];
$useraccountName =  $_SESSION['Sename'];

$YearNow = date("Y") + 543;

$txt_DAID = $_GET['DAID'];
$sql_SlectDA_ID = "SELECT * FROM `durable_articles` 
LEFT JOIN classification ON classification.classificationID = durable_articles.classificationID 
LEFT JOIN status_active ON status_active.statusID = durable_articles.statusID
LEFT JOIN building ON building.buildingID = durable_articles.buildingID
LEFT JOIN building_classroom ON building_classroom.classroomID = durable_articles.classroomID
WHERE durable_articles.DA_ID = '$txt_DAID'";
$result_SlectDA_ID = $conn->query($sql_SlectDA_ID);
foreach ($result_SlectDA_ID as $dataSlectDA_ID) {


    $db_DA_ListName = $dataSlectDA_ID['DA_ListName'];
    $db_DA_Brand = $dataSlectDA_ID['DA_Brand'];
    $db_DA_Size = $dataSlectDA_ID['DA_Size'];
    $db_DA_Number = $dataSlectDA_ID['DA_Number'];
    $db_DA_Year = $dataSlectDA_ID['DA_Year'];
    $db_DA_Detail = $dataSlectDA_ID['DA_Detail'];

    $db_statusID = $dataSlectDA_ID['statusID'];
    $db_statusName = $dataSlectDA_ID['statusName'];

    $db_buildingID = $dataSlectDA_ID['buildingID'];
    $db_buildingName = $dataSlectDA_ID['buildingName'];
    $db_classroomID = $dataSlectDA_ID['classroomID'];
    $db_classroomName = $dataSlectDA_ID['classroomName'];

    $db_classificationID = $dataSlectDA_ID['classificationID'];
    $db_classificationName = $dataSlectDA_ID['classificationName'];
}

$sql_RepairByDAID = "SELECT * FROM `repair` WHERE DA_ID = '$txt_DAID'";
$result_RepairByDAID = $conn->query($sql_RepairByDAID);
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ประวัติการซ่อมบำรุง</title>

    <!--css style bootstrap-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <!--css style-->
    <link rel="stylesheet" href="../css/style.css" />
    <!--style font awesome-->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>

<body onload="myFunction()">
    <?php require_once("../component/nav.php"); ?>

    <div class="container">
        <div class="mt-3">
            <div class="row">
                <div class="col-md">
                    <!--button add useraccount-->
                    <a href="page_durable_articles.php" class="btn btn-danger"><i class="fa-solid fa-arrow-left"></i> ย้อนกลับ</a>
                    <div class="mt-3">
                        <h5><i class="fa-solid fa-screwdriver-wrench"></i> ประวัติการซ่อมบำรุง</h5>
                    </div>
                    <hr>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row g-3">
            <div class="col-md-2">
                <label for="inputEmail4" class="form-label">หมวดหมู่</label>
                <input type="text" class="form-control" value="<?php echo $db_classificationName; ?>" disabled readonly>
            </div>
            <div class="col-md-4">
                <label for="inputEmail4" class="form-label">สถานที่</label>
                <input type="text" class="form-control" value="<?php echo $db_buildingName." ".$db_classroomName; ?>" disabled readonly>
            </div>
            <div class="col-md-4">
                <label for="inputEmail4" class="form-label">รายการ</label>
                <input type="text" class="form-control" value="<?php echo $db_DA_ListName; ?>" disabled readonly>
            </div>
            <div class="col-md-2">
                <label for="inputEmail4" class="form-label">ยี่ห้อ / รุ่น</label>
                <input type="text" class="form-control" value="<?php echo $db_DA_Brand; ?>" disabled readonly>
            </div>
            <div class="col-md-2">
                <label for="inputEmail4" class="form-label">ขนาด</label>
                <input type="text" class="form-control" value="<?php echo $db_DA_Size; ?>" disabled readonly>
            </div>
            <div class="col-md-4">
                <label for="inputEmail4" class="form-label">หมายเลขครุภัณฑ์</label>
                <input type="text" class="form-control" value="<?php echo $db_DA_Number; ?>" disabled readonly>
            </div>
            <div class="col-md-3">
                <label for="inputEmail4" class="form-label">เริ่มใช้งาน</label>
                <input type="text" class="form-control" value="<?php echo $db_DA_Year; ?>" disabled readonly>
            </div>
            <div class="col-md-1">
                <label for="inputEmail4" class="form-label">อายุการใช้งาน</label>
                <input type="text" class="form-control" value="<?php echo $YearNow - $db_DA_Year; ?> ปี" disabled readonly>
            </div>
            <div class="col-md-2">
                <label for="inputEmail4" class="form-label">สถานะ</label>
                <input type="text" class="form-control" value="<?php echo $db_statusName; ?>" disabled readonly>
            </div>
            <div class="col-md-12">
                <textarea class="form-control" style="height: 70px" disabled readonly>หมายเหตุ : <?php echo $db_DA_Detail; ?></textarea>
            </div>
        </div>
        <hr>
    </div>
    <div class="container">
        <div class="mt-3">
            <h5><i class="fa-solid fa-toolbox"></i> เพิ่มประวัติการซ่อมบำรุง</h5>
        </div>
        <div class="row g-3">
            <input type="hidden" value="" id="txt_repairID">
            <input type="hidden" value="<?php echo $txt_DAID; ?>" id="txt_daID">
            <div class="col-md-2">
                <input type="date" class="form-control" id="txt_DateRepairHisory">
            </div>
            <div class="col-md-4">
                <input type="text" class="form-control" id="txt_RepairHisory" placeholder="กรุณากรอกประวัติการซ่อมบำรุง">
            </div>
            <div class="col-md-2">
                <input type="number" class="form-control" id="txt_RepairPrice" placeholder="กรุณากรอกจำนวนเงิน">
            </div>
            <div class="col-md-2" id="btnShowActive">
                <button type="button" onclick="addRepair()" class="btn btn-outline-success" style="width: 100%;"><i class="fa-solid fa-file-circle-plus"></i> บันทึกข้อมูล</button>
            </div>
            <div class="col-md-2">
                <button type="button" onclick="resetDataForm()" class="btn btn-outline-danger" style="width: 100%;"><i class="fa-solid fa-rotate-right"></i> รีเซ็ทข้อมูล</button>
            </div>
        </div>
        <hr>
    </div>

    <!-- Table repair-->
    <div class="container">
        <div class="mt-3 shadow-sm p-3 mb-5 bg-white rounded">
            <h5 class="mb-3"><i class="fa-regular fa-file-lines"></i> ข้อมูลประวัติการซ่อม</h5>
            <div class="table-responsive">
                <table class="cell-border" id="tableDataRapair">
                    <thead>
                        <tr>
                            <th scope="col">ลำดับ</th>
                            <th scope="col">วันที่</th>
                            <th scope="col">รายการซ่อม</th>
                            <th scope="col">จำนวน</th>
                            <th scope="col">ดำเนินการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $orderNumber = 1;
                        $repair_NetTotal = 0.000;
                        foreach ($result_RepairByDAID as $dataRepairByDAID) {
                        ?>
                            <tr>
                                <th><?php echo $orderNumber; ?></th>
                                <td><?php echo $dataRepairByDAID['repair_Date']; ?></td>
                                <td><?php echo $dataRepairByDAID['repair_Hisory']; ?></td>
                                <td><?php echo number_format($repair_Price = $dataRepairByDAID['repair_Price'], 2); ?></td>
                                <td>
                                    <a class="btn btn-outline-warning" onclick="editRepair('<?php echo $dataRepairByDAID['repairID']; ?>','<?php echo $dataRepairByDAID['repair_Hisory']; ?>','<?php echo $dataRepairByDAID['repair_Price']; ?>','<?php echo $dataRepairByDAID['repair_Date']; ?>')"><i class="fa-solid fa-file-pen"></i> แก้ไข</a>
                                </td>
                            </tr>
                        <?php $orderNumber++;
                            $repair_NetTotal = $repair_NetTotal + $repair_Price;
                        } ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3" align="center">รวมค่าใช้จ่ายในการซ่อมบำรุ่ง</th>
                            <th scope="col"><?php echo number_format($repair_NetTotal, 2); ?> บาท</th>
                            <th scope="col"></th>
                        </tr>
                    </tfoot>
                </table>
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
            $('#tableDataRapair').DataTable({
                dom: 'Bfrtip',
                buttons: [
                    'copy', 'excel', 'print'
                ]
            });
        }

        function resetDataForm() {
            document.getElementById("txt_repairID").value = "";
            document.getElementById("txt_RepairHisory").value = "";
            document.getElementById("txt_RepairPrice").value = "";
            document.getElementById("txt_DateRepairHisory").value = "";

            $("#btnShowActive").html('<button type="button" onclick="addRepair()" class="btn btn-outline-success" style="width: 100%;"><i class="fa-solid fa-file-circle-plus"></i> บันทึกข้อมูล</button>');
        }

        function editRepair(repair_ID, repair_History, repair_Price, repair_date) {
            document.getElementById("txt_repairID").value = repair_ID;
            document.getElementById("txt_RepairHisory").value = repair_History;
            document.getElementById("txt_RepairPrice").value = repair_Price;
            document.getElementById("txt_DateRepairHisory").value = repair_date;

            $("#btnShowActive").html('<button type="button" onclick="updateRepair()" class="btn btn-outline-warning" style="width: 100%;"><i class="fa-solid fa-file-pen"></i> แก้ไขข้อมูล</button>');
        }

        function updateRepair() {
            var txt_repairID = document.getElementById("txt_repairID").value;
            var txt_RepairHisory = document.getElementById("txt_RepairHisory").value;
            var txt_RepairPrice = document.getElementById("txt_RepairPrice").value;
            var txt_DateRepairHisory = document.getElementById("txt_DateRepairHisory").value;

            if (txt_repairID == '') {
                swal("กรุณาเลือกข้อมูล", "warning", "warning");
            } else if (txt_RepairHisory == '' || txt_RepairPrice == '') {
                swal("กรุณากรอกข้อมูลให้ครบถ้วน", "warning", "warning");
            } else {
                $.ajax({
                    url: "update_Repair.php",
                    method: "POST",
                    data: {
                        txt_repairID: txt_repairID,
                        txt_RepairHisory: txt_RepairHisory,
                        txt_RepairPrice: txt_RepairPrice,
                        txt_DateRepairHisory: txt_DateRepairHisory
                    },
                    success: function(dataUpdate_Repair) {
                        console.log(dataUpdate_Repair);
                        if (dataUpdate_Repair == '1') {
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
                        if (dataUpdate_Repair == '2') {
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

        function addRepair() {
            var txt_daID = document.getElementById("txt_daID").value;
            var txt_RepairHisory = document.getElementById("txt_RepairHisory").value;
            var txt_RepairPrice = document.getElementById("txt_RepairPrice").value;
            var txt_DateRepairHisory = document.getElementById("txt_DateRepairHisory").value;

            if (txt_daID == '' || txt_RepairHisory == '' || txt_RepairPrice == '' || txt_DateRepairHisory == '') {
                swal("กรุณากรอกข้อมูลให้ครบถ้วน", "warning", "warning");
            } else {
                $.ajax({
                    url: "insert_Repair.php",
                    method: "POST",
                    data: {
                        txt_daID: txt_daID,
                        txt_RepairHisory: txt_RepairHisory,
                        txt_RepairPrice: txt_RepairPrice,
                        txt_DateRepairHisory: txt_DateRepairHisory
                    },
                    success: function(dataInsertRepair) {
                        console.log(dataInsertRepair);
                        if (dataInsertRepair == '1') {
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
                        if (dataInsertRepair == '2') {
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
    </script>
</body>

</html>