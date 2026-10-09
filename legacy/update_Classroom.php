<?php 
    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];
    
    include("../control/connectDB.php");

    $classroomID = $_GET['ClassID'];
    $classroomName = $_POST['classroomName'];

    $dir = "../upload_imgBuildingClassroom/";
    $fileImage = $dir. basename($_FILES["imgClassroom"]["name"]);
    $path = "/daam/upload_imgBuildingClassroom/";
    $nameimg = $_FILES["imgClassroom"]["name"];
    $linkpath = $path."".$nameimg;

    if(move_uploaded_file($_FILES["imgClassroom"]["tmp_name"], $fileImage)){

        $sql_UpdateClassroom = "UPDATE `building_classroom` SET `classroomName` = '$classroomName', `classroom_URLImg` = '$linkpath' WHERE `building_classroom`.`classroomID` = '$classroomID';";
        if ($conn->query($sql_UpdateClassroom) === TRUE) {
            echo "1";
        }else{
            echo "2";
        }

    }else{
        $sql_UpdateClassroom = "UPDATE `building_classroom` SET `classroomName` = '$classroomName' WHERE `building_classroom`.`classroomID` = '$classroomID';";
        if ($conn->query($sql_UpdateClassroom) === TRUE) {
            echo "1";
        }else{
            echo "2";
        }
    }

    $conn->close();

?>