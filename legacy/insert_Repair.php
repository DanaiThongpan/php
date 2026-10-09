<?php 
    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];
    
    include("../control/connectDB.php");

    $repair_DAID = $_POST['txt_daID'];
    $repair_Hisory = $_POST['txt_RepairHisory'];
    $repair_Price = $_POST['txt_RepairPrice'];
    $DateRepairHisory = $_POST['txt_DateRepairHisory'];

    $sql_Insert_Repair = "INSERT INTO `repair` (`repairID`, `repair_Hisory`, `repair_Price`, `DA_ID`, `repair_Date`) 
    VALUES (NULL, '$repair_Hisory', '$repair_Price', '$repair_DAID', '$DateRepairHisory')";

    if ($conn->query($sql_Insert_Repair) === TRUE) {
        echo "1"; //คืนค่า 1 คือ เพิ่มสำเร็จ
    } else {
        echo "2"; //คืนค่า 2 คือ Error
    }

    $conn->close();
?>