<?php 
    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];
    
    include("../control/connectDB.php");

    $classification_Name = $_POST['classificationName'];

    $sql_Select_classificationID = "SELECT Max(substr(classificationID,-10))+1 AS MaxID FROM classification";
    $resultSelect_classificationID = $conn->query($sql_Select_classificationID);

    foreach($resultSelect_classificationID as $dataSelect_classificationID)
    { 
        $classificationID = $dataSelect_classificationID["MaxID"];
    }
    if($classificationID > "1"){
        $num_classificationID = sprintf("%010d",$classificationID);
        $class_ID = "c".$num_classificationID;
    }else{
        $class_ID = "c0000000001";
    }

    $sql_Insert_Classification = "INSERT INTO `classification` (`classificationID`, `classificationName`) VALUES ('$class_ID', '$classification_Name');";
    if ($conn->query($sql_Insert_Classification) === TRUE) {
        echo "1"; //คืนค่า 1 คือ เพิ่มสำเร็จ
    } else {
        echo "2"; //คืนค่า 2 คือ Error
    }

    $conn->close();
?>