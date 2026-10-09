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
$setIDBorrowBack = $_POST['setIDBorrowBack'];

$date_Back = $_POST['date_Back'];
$name_Back = $_POST['name_Back'];
$rank_Back = $_POST['rank_Back'];
$detail_Back = $_POST['detail_Back'];

$sql_UpBorrowDA = "UPDATE `borrow_back` SET `Date_back` = '$date_Back', `Name_back` = '$name_Back', `Rank_back` = '$rank_Back', `Detail_back` = '$detail_Back', `useraccountID_back` = '$useraccountID', `timestamp_back` = '$timeStampDateNow' 
WHERE `borrow_back`.`IDborrow_back` = $setIDBorrowBack;";

if ($conn->query($sql_UpBorrowDA) === TRUE) {
    $sql_UpStatusDA = "UPDATE `durable_articles` SET `statusID` = 's0001' WHERE `durable_articles`.`DA_ID` = '$setDAID';";
    if ($conn->query($sql_UpStatusDA) === TRUE) {
        echo "1";
    }
} else {
  echo "Error";
}

$conn->close();

?>