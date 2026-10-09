<?php 

    include("../control/connectDB.php");

    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];

    $txt_da_ID = $_POST['txt_ID_DA'];
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

    $dir = "../upload_imgDA/";
    $fileImage = $dir. basename($_FILES["imgDA"]["name"]);
    $path = "/daam/upload_imgDA/";
    $nameimg = $_FILES["imgDA"]["name"];
    $linkpath = $path."".$nameimg;

    if(move_uploaded_file($_FILES["imgDA"]["tmp_name"], $fileImage)){

        $sql_UpdateDurableArticles = "UPDATE `durable_articles` SET `DA_ListName` = '$txt_daListName', `DA_Brand` = '$txt_daBrand', `DA_Size` = '$txt_daSize', `DA_Number` = '$txt_daNumber', `DA_Year` = '$txt_daYear', 
        `statusID` = '$txt_selectStatusActive', `DA_Detail` = '$txt_daDetail', `useraccountID` = '$useraccountID', `classificationID` = '$txt_selectClassification', `DA_URLImg` = '$linkpath', `buildingID` = '$txt_selectBuilding', 
        `classroomID` = '$txt_selectClassroom', `DA_Price` = '$txt_daPrice', `DA_DetailLocation` = '$txt_daDetailLocatin' WHERE `durable_articles`.`DA_ID` = '$txt_da_ID';";
        if ($conn->query($sql_UpdateDurableArticles) === TRUE) {
            echo "1";
        }else{
            echo "2";
        }

    }else{
        $sql_UpdateDurableArticles = "UPDATE `durable_articles` SET `DA_ListName` = '$txt_daListName', `DA_Brand` = '$txt_daBrand', `DA_Size` = '$txt_daSize', `DA_Number` = '$txt_daNumber', `DA_Year` = '$txt_daYear', 
        `statusID` = '$txt_selectStatusActive', `DA_Detail` = '$txt_daDetail', `useraccountID` = '$useraccountID', `classificationID` = '$txt_selectClassification', `buildingID` = '$txt_selectBuilding', 
        `classroomID` = '$txt_selectClassroom', `DA_Price` = '$txt_daPrice', `DA_DetailLocation` = '$txt_daDetailLocatin' WHERE `durable_articles`.`DA_ID` = '$txt_da_ID';";
        if ($conn->query($sql_UpdateDurableArticles) === TRUE) {
            echo "1";
        }else{
            echo "2";
        }
    }



?>