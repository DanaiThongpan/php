<?php 

    include("../control/connectDB.php");

    $userID = $_POST['useraccountID'];
    $userName = $_POST['useraccountName'];
    $userPassword = $_POST['useraccountPassword'];
    $userType = $_POST['useraccountType'];

    //fomat password to hash PASSWORD_DEFAULT
    $passwordHash = password_hash($userPassword, PASSWORD_DEFAULT);



    $sql_userID = "SELECT * FROM `useraccount` WHERE useraccountID = '$userID';";
    $result_userID = $conn->query($sql_userID);

    if ($result_userID->num_rows > 0) {
        echo "3"; //คืนค่า 3 คือมี userID นี้อยู่แล้ว
    }else{
        $sql_Insert_UserAccount = "INSERT INTO `useraccount` (`useraccountID`, `useraccountName`, `userPassword`, `userActive`, `useraccountType`)
        VALUES ('$userID', '$userName', '$passwordHash', '1', '$userType');";

        if ($conn->query($sql_Insert_UserAccount) === TRUE) {
            echo "1"; //คืนค่า 1 คือ เพิ่มสำเร็จ
        } else {
            echo "2"; //คืนค่า 2 คือ Error
        }
        
        $conn->close();
    }

?>