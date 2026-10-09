<?php 

    include("../control/connectDB.php");
    if(isset($_GET['useraccountID'])){
        $UserID = $_GET['useraccountID'];
        $statusActive = $_GET['statusActive'];
        $sql_DisActiveUser = "UPDATE `useraccount` SET `userActive` = '$statusActive' WHERE `useraccount`.`useraccountID` = '$UserID';";
        
        if ($conn->query($sql_DisActiveUser) === TRUE) {
            echo "<meta http-equiv='refresh' content='1;url=page_useraccount.php'>";
        } else {
            echo "<meta http-equiv='refresh' content='1;url=page_useraccount.php'>";
        }

    }
?>