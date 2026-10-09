<?php 
session_start();
$useraccountID =  $_SESSION['SeUser'];
$useraccountName =  $_SESSION['Sename'];

include("../control/connectDB.php");

    $txt_selectClassification = $_POST['selectClassification'];
    $txt_selectBuilding = $_POST['selectBuilding'];
    $txt_selectClassroom = $_POST['selectClassroom'];
    $txt_daListName = $_POST['daListName'];
    $txt_daBrand = $_POST['daBrand'];
    $txt_daSize = $_POST['daSize'];
    $txt_daNumber = $_POST['daNumber'];
    $txt_daYear = $_POST['daYear'];
    $txt_selectStatusActive = $_POST['selectStatusActive'];
    $txt_daDetail = $_POST['daDetail'];

    $txt_daDetailLocatin = $_POST['daDetailLocatin'];
    $txt_daPrice = $_POST['daPrice'];

    $sql_Select_DA_ID = "SELECT Max(substr(DA_ID,-10))+1 AS MaxID FROM durable_articles";
    $resultSelect_DA_ID = $conn->query($sql_Select_DA_ID);

    foreach($resultSelect_DA_ID as $dataSelect_DA_ID)
    { 
        $SelectDA_ID = $dataSelect_DA_ID["MaxID"];
    }
    if($SelectDA_ID > "1"){
        $num_DA_ID = sprintf("%010d",$SelectDA_ID);
        $da_ID = "DT".$num_DA_ID;
    }else{
        $da_ID = "DT0000000001";
    }

    $dir = "../upload_imgDA/";
    $fileImage = $dir. basename($_FILES["imgDA"]["name"]);
    $path = "/daam/upload_imgDA/";
    $nameimg = $_FILES["imgDA"]["name"];
    $linkpath = $path."".$nameimg;

    if(move_uploaded_file($_FILES["imgDA"]["tmp_name"], $fileImage)){

        $sql_InsertDurableArticles = "INSERT INTO `durable_articles` (`DA_ID`, `DA_ListName`, `DA_Brand`, `DA_Size`, `DA_Number`, `DA_Year`, `statusID`, `DA_Detail`, `useraccountID`, `classificationID`, `DA_URLImg`, `buildingID`, `classroomID`, `DA_Price`, `DA_DetailLocation`) 
        VALUES ('$da_ID', '$txt_daListName', '$txt_daBrand', '$txt_daSize', '$txt_daNumber', '$txt_daYear', '$txt_selectStatusActive', '$txt_daDetail', '$useraccountID', '$txt_selectClassification', '$linkpath', '$txt_selectBuilding', '$txt_selectClassroom', '$txt_daPrice', '$txt_daDetailLocatin');";
        if ($conn->query($sql_InsertDurableArticles) === TRUE) {
            echo "1";
        }else{
            echo "2";
        }

    }else{
        $sql_InsertDurableArticles = "INSERT INTO `durable_articles` (`DA_ID`, `DA_ListName`, `DA_Brand`, `DA_Size`, `DA_Number`, `DA_Year`, `statusID`, `DA_Detail`, `useraccountID`, `classificationID`, `buildingID`, `classroomID`, `DA_Price`, `DA_DetailLocation`) 
        VALUES ('$da_ID', '$txt_daListName', '$txt_daBrand', '$txt_daSize', '$txt_daNumber', '$txt_daYear', '$txt_selectStatusActive', '$txt_daDetail', '$useraccountID', '$txt_selectClassification', '$txt_selectBuilding', '$txt_selectClassroom', '$txt_daPrice', '$txt_daDetailLocatin');";
        if ($conn->query($sql_InsertDurableArticles) === TRUE) {
            echo "1";
        }else{
            echo "2";
        }
    }

?>