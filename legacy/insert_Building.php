<?php 
    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];
    
    include("../control/connectDB.php");

    $BuildingID = $_POST['txtBuildingID'];
    $BuildingName = $_POST['txtBuildingName'];

    $dir = "../upload_imgBuilding/";
    $fileImage = $dir. basename($_FILES["imgBuilding"]["name"]);
    $path = "/daam/upload_imgBuilding/";
    $nameimg = $_FILES["imgBuilding"]["name"];
    $linkpath = $path."".$nameimg;

    if(move_uploaded_file($_FILES["imgBuilding"]["tmp_name"], $fileImage)){

        $sql_InsertBuilding = "INSERT INTO `building` (`buildingID`, `buildingName`, `building_timestamp`, `building_URLImg`) 
        VALUES ('$BuildingID', '$BuildingName', current_timestamp(), '$linkpath');";
        if ($conn->query($sql_InsertBuilding) === TRUE) {
            echo "1";
        }else{
            echo "2";
        }

    }else{
        $sql_InsertBuilding = "INSERT INTO `building` (`buildingID`, `buildingName`, `building_timestamp`) 
        VALUES ('$BuildingID', '$BuildingName', current_timestamp());";
        if ($conn->query($sql_InsertBuilding) === TRUE) {
            echo "1";
        }else{
            echo "2";
        }
    }

    $conn->close();
?>