<?php 
    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];
    
    include("../control/connectDB.php");

    $BuildingID = $_GET['building'];
    $classroomID = $_POST['classroomID'];
    $classroomName = $_POST['classroomName'];

    $dir = "../upload_imgBuildingClassroom/";
    $fileImage = $dir. basename($_FILES["imgClassroom"]["name"]);
    $path = "/daam/upload_imgBuildingClassroom/";
    $nameimg = $_FILES["imgClassroom"]["name"];
    $linkpath = $path."".$nameimg;

    $sql_ClassroomBYID = "SELECT * FROM `building_classroom` WHERE classroomID = '$classroomID'";
    $resultClassroomBYID = $conn->query($sql_ClassroomBYID);
    if ($resultClassroomBYID->num_rows > 0) {
        echo "3"; // รหัสห้องมีอยู่ในอยู่แล้ว
    }else{
        if(move_uploaded_file($_FILES["imgClassroom"]["tmp_name"], $fileImage)){

            $sql_InsertClassroom = "INSERT INTO `building_classroom` (`classroomID`, `classroomName`, `buildingID`, `classroom_timestamp`, `classroom_URLImg`) 
            VALUES ('$classroomID', '$classroomName', '$BuildingID', current_timestamp(), '$linkpath')";
            if ($conn->query($sql_InsertClassroom) === TRUE) {
                echo "1";
            }else{
                echo "2";
            }
    
        }else{
            $sql_InsertClassroom = "INSERT INTO `building_classroom` (`classroomID`, `classroomName`, `buildingID`, `classroom_timestamp`) 
            VALUES ('$classroomID', '$classroomName', '$BuildingID', current_timestamp() )";
            if ($conn->query($sql_InsertClassroom) === TRUE) {
                echo "1";
            }else{
                echo "2";
            }
        }
    }
    $conn->close(); 

    ?>
