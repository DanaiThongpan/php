<?php
include("../control/connectDB.php");
?>
<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ ครุภัณฑ์</title>
</head>

<body>
    <?php
    if (isset($_POST["txt_user"])) {
        $username = $_POST["txt_user"];
        $password = $_POST["txt_Password"];

        $sql = "SELECT * FROM `useraccount` WHERE useraccountID = '$username' AND userActive = '1';";
        $result = $conn->query($sql);
        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();
            if (password_verify($password, $data["userPassword"])) {
                $_SESSION['Selogin'] = 1;
                $_SESSION['SeUser'] = $data["useraccountID"];
                $_SESSION['Sename'] = $data["useraccountName"];
                $_SESSION['SeType'] = $data["useraccountType"];

                echo '
                <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/1.1.3/sweetalert-dev.min.js"></script>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/1.1.3/sweetalert.min.css"/>
                ';


                echo '
                            <script>
                                swal("เข้าสู่ระบบสำเร็จ", "Login Success", "success"); 
                            </script>
                        ';

                echo "<meta http-equiv='refresh' content='1;url=main.php'>";
            } else {
                //ถ้าไม่มีข้อมูล
                echo '
                <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/1.1.3/sweetalert-dev.min.js"></script>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/1.1.3/sweetalert.min.css"/>
                ';
                echo '
                            <script>
                                swal("เข้าสู่ระบบไม่สำเร็จ", "warning", "warning"); 
                            </script>
                        ';
                echo "<meta http-equiv='refresh' content='1;url=index.php'>";
            }
        } else {
            echo '
                    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/1.1.3/sweetalert-dev.min.js"></script>
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/1.1.3/sweetalert.min.css"/>
                    ';
            echo '
                                <script>
                                    swal("เข้าสู่ระบบไม่สำเร็จ", "warning", "warning"); 
                                </script>
                            ';
            echo "<meta http-equiv='refresh' content='1;url=index.php'>";
        }
    }
    ?>
</body>

</html>