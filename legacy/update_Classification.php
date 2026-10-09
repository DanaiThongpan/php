<?php 
    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];
    
    include("../control/connectDB.php");

    $id_Classification = $_POST['txt_ID_Classification'];
    $ClassificationName = $_POST['txt_ClassificationName'];

    $sql_Update_Class = "UPDATE `classification` SET `classificationName` = '$ClassificationName' WHERE `classification`.`classificationID` = '$id_Classification';";

    if ($conn->query($sql_Update_Class) === TRUE) {
        echo "1"; //คืนค่า 1 คือ update สำเร็จ
    } else {
        echo "2"; //คืนค่า 2 คือ Error
    }
      
    $conn->close();

?>