<?php 
    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];
    
    include("../control/connectDB.php");

    $DelClassroomID = $_POST['DelClassroomID'];

    
    $sql_DelClassroom = "DELETE FROM building_classroom WHERE `building_classroom`.`classroomID` = '$DelClassroomID'";

    if ($conn->query($sql_DelClassroom) === TRUE) {
        echo "1"; //คืนค่า 1 คือ เพิ่มสำเร็จ
    } else {
        echo "2"; //คืนค่า 2 คือ Error
    }

    $conn->close();
?>