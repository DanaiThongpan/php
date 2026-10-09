<?php
include("../control/connectDB.php");
session_start();

if ($_SESSION['Selogin'] <> 1) {
    echo "<meta http-equiv='refresh' content='0;url=index.php'>";
}

$useraccountID =  $_SESSION['SeUser'];
$useraccountName =  $_SESSION['Sename'];
$useraccountType = $_SESSION['SeType'];

date_default_timezone_set('asia/bangkok');
$timeStampDateNow = date('Y-m-d H:i:s');


$setDAID = $_POST['setDAID'];
$date_Borrow = $_POST['date_Borrow'];
$name_Borrow = $_POST['name_Borrow'];
$rank_Borrow = $_POST['rank_Borrow'];
$belongto_Borrow = $_POST['belongto_Borrow'];
$detail_Borrow = $_POST['detail_Borrow'];
$deadline_Borrow = $_POST['deadline_Borrow'];

$sql_InsertBorrowDA = "INSERT INTO `borrow_back` (`IDborrow_back`, `DA_ID`, `Date_borrow`, `Name_borrow`, `Rank_borrow`, `Belongto_borrow`, `Detail_borrow`, `Deadline_borrow`, `useraccountID_borrow`, `timestamp_borrow`, `Date_back`, `Name_back`, `Rank_back`, `Detail_back`, `useraccountID_back`, `timestamp_back`) 
VALUES (NULL, '$setDAID', '$date_Borrow', '$name_Borrow', '$rank_Borrow', '$belongto_Borrow', '$detail_Borrow', '$deadline_Borrow', '$useraccountID', '$timeStampDateNow', '', '', '', '', '', '');";

if ($conn->query($sql_InsertBorrowDA) === TRUE) {
    $sql_UpStatusDA = "UPDATE `durable_articles` SET `statusID` = 's0003' WHERE `durable_articles`.`DA_ID` = '$setDAID';";
    if ($conn->query($sql_UpStatusDA) === TRUE) {
        echo "1";
    }
} else {
  echo "Error";
}

$conn->close();

?>