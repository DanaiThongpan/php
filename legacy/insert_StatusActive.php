<?php 
    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];
    
    include("../control/connectDB.php");

    $StatusActiveName = $_POST['StatusName'];

    $sql_Select_StatusActiveID = "SELECT Max(substr(statusID,-4))+1 AS MaxID FROM status_active";
    $resultSelect_StatusActiveID = $conn->query($sql_Select_StatusActiveID);
    
    foreach($resultSelect_StatusActiveID as $dataSelect_StatusActiveID)
    { 
        $StatusActiveID = $dataSelect_StatusActiveID["MaxID"];
    }
    if($StatusActiveID > "1"){
        $num_StatusActiveID = sprintf("%04d",$StatusActiveID);
        $statusActive_ID = "s".$num_StatusActiveID;
    }else{
        $statusActive_ID = "s0001";
    }

    $sql_Insert_StatusActive = "INSERT INTO `status_active` (`statusID`, `statusName`) 
    VALUES ('$statusActive_ID', '$StatusActiveName');";
    if ($conn->query($sql_Insert_StatusActive) === TRUE) {
        echo "1"; //คืนค่า 1 คือ เพิ่มสำเร็จ
    } else {
        echo "2"; //คืนค่า 2 คือ Error
    }

    $conn->close();
?>