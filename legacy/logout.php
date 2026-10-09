<?php
session_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>ออกจากระบบ</title>
</head>

<body>
	<?php
		session_destroy();
		session_unset();
		

        echo '
            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/1.1.3/sweetalert-dev.min.js"></script>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert/1.1.3/sweetalert.min.css"/>
            ';

            
                    echo '
                        <script>
                            swal("ออกจากระบบสำเร็จ", "Logout Success", "success"); 
                        </script>
                    ';
		echo"<meta http-equiv='refresh' content='1;url=index.php'>";
	?>
</body>

</html>