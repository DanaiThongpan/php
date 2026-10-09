<?php 
    session_start();
    $useraccountID =  $_SESSION['SeUser'];
    $useraccountName =  $_SESSION['Sename'];
    
    include("../control/connectDB.php");

    $DelBuildingID = $_POST['DelBuildingID'];

    $sql_Slectclassroom = "SELECT * FROM `building_classroom` WHERE buildingID = '$DelBuildingID'";
    $result_classroom = $conn->query($sql_Slectclassroom);
        if ($result_classroom->num_rows > 0) {
            echo "3"; //คืนค่า 3 คือ มีข้อมูลหมวดหมู่ใน ตารางครุภัณ ไม่สามารถลบได้
        }else{
            $sql_DelBuilding = "DELETE FROM building WHERE `building`.`buildingID` = '$DelBuildingID'";

            if ($conn->query($sql_DelBuilding) === TRUE) {
                echo "1"; //คืนค่า 1 คือ เพิ่มสำเร็จ
            } else {
                echo "2"; //คืนค่า 2 คือ Error
            }
        }

    

    $conn->close();
?>