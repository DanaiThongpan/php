<?php
include("../control/connectDB.php");
session_start();

if ($_SESSION['Selogin'] <> 1) {
    echo "<meta http-equiv='refresh' content='0;url=index.php'>";
}

$useraccountID =  $_SESSION['SeUser'];
$useraccountName =  $_SESSION['Sename'];


$UserID = $_GET['useraccountID'];
$sql_UserID_Edit = "SELECT * FROM `useraccount`
LEFT JOIN usertype ON usertype.useraccountType = useraccount.useraccountType
WHERE useraccountID = '$UserID'";
$result_sql_UserID_Edit = $conn->query($sql_UserID_Edit);
foreach ($result_sql_UserID_Edit as $dataUserIDEdit) {
    $DBuserName = $dataUserIDEdit['useraccountName'];
    $DBuserType = $dataUserIDEdit['useraccountType'];
    $DBuserTypeName = $dataUserIDEdit['usertypeName'];
    
    // ใช้ Null Coalescing Operator (?? '') ป้องกัน Error กรณีฐานข้อมูลยังไม่มีฟิลด์นี้ หรือเป็นค่าว่าง
    $DBuserRank = $dataUserIDEdit['userRank'] ?? '';
    $DBuserDepartment = $dataUserIDEdit['userDepartment'] ?? '';
}
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

<body class="bg-light">
    <?php require_once("../component/nav.php"); ?>

    <div class="container">
        <div class="mt-3">
            <div class="row">
                <div class="col-md">
                    <!--button add useraccount-->
                    <a href="page_useraccount.php" class="btn btn-danger"><i class="fa-solid fa-arrow-left"></i> ย้อนกลับ</a>
                    <hr>
                </div>

            </div>
        </div>
    </div>

    <!-- from Edit UserAccount-->
    <div class="container">
        <div class="mt-3 shadow-sm p-3 mb-5 bg-white rounded">
            <h5 class="mb-3"><i class="fa-solid fa-address-book"></i> แก้ไขข้อมูลผู้ใช้งาน</h5>
            <div class="mb-3">
                <div class="mb-3">
                    <label for="exampleFormControlInput1" class="form-label">ชื่อผู้ใช้งาน</label>
                    <input type="text" class="form-control" id="useraccountName" value="<?php if (isset($_GET['useraccountID'])) {
                                                                                            echo $DBuserName;
                                                                                        } ?>" placeholder="กรุณากรอก ชื่อผู้ใช้งาน">
                </div>
                
                <!-- ส่วนที่เพิ่มใหม่: ตำแหน่ง และ สังกัด -->
                <div class="mb-3">
                    <label for="userRank" class="form-label">ตำแหน่ง</label>
                    <input type="text" class="form-control" id="userRank" value="<?php echo htmlspecialchars($DBuserRank); ?>" placeholder="กรุณากรอก ตำแหน่ง">
                </div>
                <div class="mb-3">
                    <label for="userDepartment" class="form-label">สังกัด</label>
                    <input type="text" class="form-control" id="userDepartment" value="<?php echo htmlspecialchars($DBuserDepartment); ?>" placeholder="กรุณากรอก สังกัด">
                </div>

                <div class="md-3">
                    <label for="exampleFormControlInput1" class="form-label">ประเภทผู้ใช้งาน</label>
                    <select class="form-select" aria-label="Default select example" id="useraccountType">
                        <option selected value="<?php if (isset($_GET['useraccountID'])) {
                                                    echo $DBuserType;
                                                } else {
                                                    echo "";
                                                } ?>"><?php if (isset($_GET['useraccountID'])) {
                                                            echo $DBuserTypeName;
                                                        } else {
                                                            echo "เลือกประเภทผู้ใช้งาน";
                                                        } ?></option>
                        <?php
                        $sql_UserType = "SELECT * FROM `usertype`";
                        $result_sql_UserType = $conn->query($sql_UserType);
                        foreach ($result_sql_UserType as $dataUserType) {
                        ?>
                            <option value="<?php echo $dataUserType['useraccountType']; ?>"><?php echo $dataUserType['usertypeName']; ?></option>
                        <?php
                        }
                        ?>

                    </select>
                </div>
            </div>
            <button type="button" class="btn btn-success" onclick="updateUserAccount()">บันทึกข้อมูล</button>
        </div>
    </div>
    
    <!-- หมายเหตุ: ตัดส่วนแก้ไข Password ทิ้งไปตามความต้องการ -->

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
        function updateUserAccount() {
            var user_ID = '<?= $UserID ?>';
            var useraccountName = document.getElementById('useraccountName').value;
            var useraccountType = document.getElementById('useraccountType').value;
            
            // ดึงค่าตำแหน่งและสังกัดจากฟอร์ม
            var userRank = document.getElementById('userRank').value;
            var userDepartment = document.getElementById('userDepartment').value;
            var status_Update_User;

            // ตรวจสอบข้อมูลว่าง
            if (useraccountName == '' || useraccountType == '' || userRank == '' || userDepartment == '') {
                swal("กรุณากรอกข้อมูลให้ครบถ้วน", "warning", "warning");
            } else {
                status_Update_User = 'updateuser';
                $.ajax({
                    url: "update_UserAccount.php",
                    method: "POST",
                    data: {
                        user_ID: user_ID,
                        useraccountName: useraccountName,
                        useraccountType: useraccountType,
                        userRank: userRank,
                        userDepartment: userDepartment,
                        status_Update_User: status_Update_User
                    },
                    success: function(dataEditUserAccount) {
                        if (dataEditUserAccount == '1') {
                            setTimeout(function() {
                                swal({
                                    title: "อัพเดตข้อมูลสำเร็จ",
                                    text: "Success",
                                    type: "success",
                                    timer: 1300,
                                    showConfirmButton: false
                                }, function() {
                                    location.href = "page_useraccount.php";
                                });
                            });
                        } else {
                            setTimeout(function() {
                                swal({
                                    title: "พบข้อผิดพลาด",
                                    text: dataEditUserAccount, // แสดง Error Message จาก Backend ออกมาตรงๆ
                                    type: "error",
                                    showConfirmButton: true // เปิดปุ่มให้กดตกลง เพื่อให้มีเวลาอ่าน Error
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