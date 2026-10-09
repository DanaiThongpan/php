<?php 
    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];
    
    include("../control/connectDB.php");

    $StatusID = $_POST['txt_StatusID'];
    $StatusName = $_POST['txt_StatusName'];

    $sql_Update_Status = "UPDATE `status_active` SET `statusName` = '$StatusName' WHERE `status_active`.`statusID` = '$StatusID'";

    if ($conn->query($sql_Update_Status) === TRUE) {
        echo "1"; //คืนค่า 1 คือ update สำเร็จ
    } else {
        echo "2"; //คืนค่า 2 คือ Error
    }
      
    $conn->close();

?>