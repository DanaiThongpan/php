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
    <title>จัดการข้อมูลครุภัณฑ์</title>

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
                    <button type="button" data-bs-toggle="modal" data-bs-target="#modalFormDA" class="btn btn-primary"><i class="fa-solid fa-plus"></i> เพิ่มข้อมูลครุภัณฑ์</button>
                    <hr>
                </div>

            </div>
        </div>
    </div>

    <div class="container">
        <div class="row">
            <div class="col-md-3">
                <div class="form-floating">
                    <select class="form-select" id="selectClassSearch">
                        <option selected value="">เลือกหมวดหมู่</option>
                        <?php $sql_selectClass = "SELECT * FROM `classification`";
                        $result_selectClass = $conn->query($sql_selectClass);
                        foreach ($result_selectClass as $dataselectClass) { ?>
                            <option value="<?php echo $dataselectClass['classificationID']; ?>"><?php echo $dataselectClass['classificationName']; ?></option>
                        <?php   }
                        ?>
                    </select>
                    <label for="floatingSelectGrid">หมวดหมู่</label>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-floating">
                    <select class="form-select" id="selectBuildingSearch">
                        <option selected value="" disabled>เลือกอาคาร</option>
                        <?php $sql_selectBuilding = "SELECT * FROM `building`";
                        $result_selectBuilding = $conn->query($sql_selectBuilding);
                        foreach ($result_selectBuilding as $dataselectBuilding) { ?>
                            <option value="<?php echo $dataselectBuilding['buildingID']; ?>"><?php echo $dataselectBuilding['buildingName']; ?></option>
                        <?php   }
                        ?>
                    </select>
                    <label for="floatingSelectGrid">อาคาร</label>
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-floating">
                    <select class="form-select" id="selectYearSearch">
                        <option selected value="">เลือกปี</option>
                        <option value="0000">ไม่ทราบปีใช้งาน</option>
                        <?php for ($i = 0; $i <= 50; $i++) { ?>
                            <option value="<?php echo date("Y") - $i + 543 ?>"><?php echo date("Y") - $i + 543 ?></option>
                        <?php } ?>
                    </select>
                    <label for="floatingSelectGrid">ปีการใช้งาน</label>
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-floating">
                    <select class="form-select" id="selectStatusSearch">
                        <option selected value="">เลือกสถานะ</option>
                        <?php $sql_selectStatus_active = "SELECT * FROM `status_active`";
                        $result_selectStatus_active = $conn->query($sql_selectStatus_active);
                        foreach ($result_selectStatus_active as $dataselectStatus_active) { ?>
                            <option value="<?php echo $dataselectStatus_active['statusID']; ?>"><?php echo $dataselectStatus_active['statusName']; ?></option>
                        <?php   }
                        ?>
                    </select>
                    <label for="floatingSelectGrid">สถานะ</label>
                </div>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-primary" onclick="searchDurableArticles()" style="height: 100%; width: 100%;">ค้นหาข้อมูล</button>
            </div>
        </div>
    </div>

    <div class="container p-3" align="center" id="londing">
        <div class="spinner-border text-primary" id="spinnerlond" role="status"><span class="visually-hidden">Loading...</span></div>
    </div>

    <!-- Table UserAccount-->
    <div class="container">
        <div class="mt-3 shadow-sm p-3 mb-5 bg-white rounded">
            <h5 class="mb-3"><i class="fa-solid fa-address-book"></i> ข้อมูลครุภัณฑ์</h5>
            <div class="table-responsive" id="divtableDA">
                <table class="cell-border" id="tableDataDA">
                </table>
            </div>
        </div>
    </div>

    <!--Modal form useraccount-->
    <div class="modal fade" id="modalFormDA" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="staticBackdropLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5" id="modalFormUseraccountLabel">เพิ่มข้อมูลครุภัณฑ์</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="fromdurableArticles">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="exampleFormControlInput1" class="form-label">หมวดหมู่</label>
                            <select class="form-select" aria-label="Default select example" id="selectClassification" name="selectClassification">
                                <option selected value="">เลือกหมวดหมู่</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="exampleFormControlInput1" class="form-label">อาคาร</label>
                            <select class="form-select" aria-label="Default select example" onchange="selectDataClassroom()" id="selectBuilding" name="selectBuilding">
                                <option selected value="">เลือกอาคาร</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="exampleFormControlInput1" class="form-label">ห้อง</label>
                            <select class="form-select" aria-label="Default select example" id="selectClassroom" name="selectClassroom">
                                <option selected value="">เลือกห้อง</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="exampleFormControlInput1" class="form-label">รายละเอียดสถานที่</label>
                            <input type="text" class="form-control" id="daDetailLocatin" name="daDetailLocatin" placeholder="กรุณากรอก รายละเอียดสถานที่">
                        </div>
                        <div class="mb-3">
                            <label for="exampleFormControlInput1" class="form-label">รายการ</label>
                            <input type="text" class="form-control" id="daListName" name="daListName" placeholder="กรุณากรอกรายการ">
                        </div>
                        <div class="mb-3">
                            <label for="exampleFormControlInput1" class="form-label">ยี่ห้อ / รุ่น</label>
                            <input type="text" class="form-control" id="daBrand" name="daBrand" placeholder="กรุณากรอก ยี่ห้อ / รุ่น">
                        </div>
                        <div class="mb-3">
                            <label for="exampleFormControlInput1" class="form-label">ขนาด</label>
                            <input type="text" class="form-control" id="daSize" name="daSize" placeholder="กรุณากรอก ขนาน Size">
                        </div>
                        <div class="mb-3">
                            <label for="exampleFormControlInput1" class="form-label">หมายเลขครุภัณฑ์</label>
                            <input type="text" class="form-control" id="daNumber" name="daNumber" placeholder="กรุณากรอก หมายเลขครุภัณฑ์">
                        </div>
                        <div class="mb-3">
                            <label for="exampleFormControlInput1" class="form-label">ปีเริ่มใช้งาน</label>
                            <select class="form-select" aria-label="Default select example" id="daYear" name="daYear">
                                <option selected value="0000">ไม่ทราบปีใช้งาน</option>
                                <?php for ($i = 0; $i <= 50; $i++) { ?>
                                    <option value="<?php echo date("Y") - $i + 543 ?>"><?php echo date("Y") - $i + 543 ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="exampleFormControlInput1" class="form-label">ราคา</label>
                            <input type="number" class="form-control" id="daPrice" name="daPrice" placeholder="กรุณากรอก ราคาครุภัณฑ์">
                        </div>
                        <div class="mb-3">
                            <label for="exampleFormControlInput1" class="form-label">สถานะ</label>
                            <select class="form-select" aria-label="Default select example" id="selectStatusActive" name="selectStatusActive">
                                <option selected value="">พร้อมใช้งาน</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="exampleFormControlInput1" class="form-label">หมายเหตุ</label>
                            <input type="text" class="form-control" id="daDetail" name="daDetail" placeholder="หมายเหตุหรือรายละเอียดเพิ่มเติม">
                        </div>
                        <div class="mb-3">
                            <label for="formFile" class="form-label">อัพโหลดรูปภาพครุภัณฑ์</label>
                            <input class="form-control" type="file" id="imgDA" name="imgDA" onchange="showImg()">
                            <img id="previewImg" class="img-fluid rounded">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-success" onclick="addDurableArticles()">บันทึกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Show IMG DA -->
    <div class="modal fade" id="ShowDAImgModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h1 class="modal-title fs-5">รูปภาพครุภัณฑ์</h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <img id="ModalimgDA" class="img-fluid rounded">
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
                url: "loadStatus_Active.php",
                method: "POST",
                data: {},
                success: function(dataStatus_Active) {
                    $("#selectStatusActive").html(dataStatus_Active);
                }
            });

            $.ajax({
                url: "loadClassification.php",
                method: "POST",
                data: {},
                success: function(dataClassification) {
                    $("#selectClassification").html(dataClassification);
                }
            });

            $.ajax({
                url: "loadBuilding.php",
                method: "POST",
                data: {},
                success: function(dataSelectBuilding) {
                    $("#selectBuilding").html(dataSelectBuilding);
                }
            });



            $.ajax({
                url: "loadTableDurableArticles.php",
                method: "POST",
                data: {},
                success: function(datatableDataDA) {
                    $("#divtableDA").html(datatableDataDA);
                    //$('#tableDataDA').DataTable();
                    $('#tableDataDA').DataTable({
                        dom: 'Bfrtip',
                        buttons: [
                            'copy', 'excel', 'print'
                        ]
                    });

                    document.getElementById("spinnerlond").remove();
                }
            });
        }

        function showImg() {
            let imgDA = document.querySelector("#imgDA");
            let previewImg = document.querySelector("#previewImg");

            const [file] = imgDA.files;
            previewImg.src = URL.createObjectURL(file);
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

        function ShowimgURLDA(URLImgDA) {
            if (URLImgDA == '') {
                swal("ครุภัณฑ์ ยังไม่มีข้อมูลรูปภาพ", "warning", "warning");
            } else {
                $('#ShowDAImgModal').modal('show');
                document.getElementById("ModalimgDA").src = URLImgDA;
            }

        }

        function addDurableArticles() {
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

            if (selectClassification == '' || selectBuilding == '' || daListName == '' ||
                daBrand == '' || daSize == '' || daNumber == '' ||
                daYear == '' || selectStatusActive == '') {
                swal("กรุณากรอกข้อมูลให้ครบถ้วน", "warning", "warning");
            } else {
                $.ajax({
                    url: "insert_DurableArticles.php",
                    method: "POST",
                    data: formDataDA,
                    contentType: false,
                    processData: false,
                    success: function(datainsert_DurableArticles) {
                        console.log(datainsert_DurableArticles);
                        if (datainsert_DurableArticles == '1') {
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
                        if (datainsert_DurableArticles == '2') {
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

        function searchDurableArticles() {
            $("#londing").html('<div id="spinnerlond" class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>');
            var txtselectClassSearch = document.getElementById("selectClassSearch").value;
            var txtselectBuildingSearch = document.getElementById("selectBuildingSearch").value;
            var txtselectYearSearch = document.getElementById("selectYearSearch").value;
            var txtselectStatusSearch = document.getElementById("selectStatusSearch").value;

            if (txtselectClassSearch == '' && txtselectBuildingSearch == '' &&
                txtselectYearSearch == '' && txtselectStatusSearch == '') {
                swal("เลือกข้อมูลอย่างน้อย 1 ช่อง", "warning", "warning");
            } else {
                $.ajax({
                    url: "loadTableDurableArticlesBySelect.php",
                    method: "POST",
                    data: {
                        txtselectClassSearch: txtselectClassSearch,
                        txtselectBuildingSearch: txtselectBuildingSearch,
                        txtselectYearSearch: txtselectYearSearch,
                        txtselectStatusSearch: txtselectStatusSearch
                    },
                    success: function(datatableDataDABySelect) {
                        $("#divtableDA").html(datatableDataDABySelect);
                        $('#tableDataDA').DataTable({
                            dom: 'Bfrtip',
                            buttons: [
                                'copy', 'excel', 'print'
                            ]
                        });

                        document.getElementById("spinnerlond").remove();
                    }
                });
            }
        }
    </script>
</body>

</html>