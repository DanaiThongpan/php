<?php 
    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];
    
    include("../control/connectDB.php");

    $classID = $_POST['classID'];

    $sql_SlectClassIDFormDA = "SELECT * FROM `durable_articles` WHERE classificationID = '$classID';";
    $result_ClassIDFormDA = $conn->query($sql_SlectClassIDFormDA);
        if ($result_ClassIDFormDA->num_rows > 0) {
            echo "3"; //คืนค่า 3 คือ มีข้อมูลหมวดหมู่ใน ตารางครุภัณ ไม่สามารถลบได้
        }else{
            $sql_DelClassification = "DELETE FROM classification WHERE `classification`.`classificationID` = '$classID'";

            if ($conn->query($sql_DelClassification) === TRUE) {
                echo "1"; //คืนค่า 1 คือ เพิ่มสำเร็จ
            } else {
                echo "2"; //คืนค่า 2 คือ Error
            }
        }

    

    $conn->close();
?>