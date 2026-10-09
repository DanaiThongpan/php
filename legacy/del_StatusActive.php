<?php 
    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];
    
    include("../control/connectDB.php");

    $StatusID = $_POST['id_Status'];

    $sql_SelectStatusIDFormDA = "SELECT * FROM `durable_articles` WHERE statusID = '$StatusID'";
    $result_StatusIDFormDA = $conn->query($sql_SelectStatusIDFormDA);
        if ($result_StatusIDFormDA->num_rows > 0) {
            echo "3"; //คืนค่า 3 คือ มีข้อมูลหมวดหมู่ใน ตารางครุภัณ ไม่สามารถลบได้
        }else{
            $sql_DelStatusActive = "DELETE FROM status_active WHERE `status_active`.`statusID` = '$StatusID'";

            if ($conn->query($sql_DelStatusActive) === TRUE) {
                echo "1"; //คืนค่า 1 คือ เพิ่มสำเร็จ
            } else {
                echo "2"; //คืนค่า 2 คือ Error
            }
        }

    

    $conn->close();
?>