<?php 
    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];
    
    include("../control/connectDB.php");

    $repairID = $_POST['txt_repairID'];
    $repair_Hisory = $_POST['txt_RepairHisory'];
    $repair_Price = $_POST['txt_RepairPrice'];
    $DateRepairHisory = $_POST['txt_DateRepairHisory'];

    $sql_updateRepair = "UPDATE `repair` SET `repair_Hisory` = '$repair_Hisory', `repair_Price` = '$repair_Price', `repair_Date` = '$DateRepairHisory' WHERE `repair`.`repairID` = $repairID;";
    
    if ($conn->query($sql_updateRepair) === TRUE) {
        echo "1"; //คืนค่า 1 คือ update สำเร็จ
    } else {
        echo "2"; //คืนค่า 2 คือ Error
    }
      
    $conn->close();
?>