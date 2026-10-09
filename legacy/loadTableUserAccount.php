<?php
include("../control/connectDB.php");
$sql_useraccount = "SELECT * FROM `useraccount` 
LEFT JOIN usertype ON usertype.useraccountType = useraccount.useraccountType";
$result_useraccount = $conn->query($sql_useraccount);
?>

            <table class="cell-border" id="tableUserAccount">
                <thead>
                    <tr>
                        <th scope="col">ลำดับ</th>
                        <th scope="col">Username</th>
                        <th scope="col">ชื่อผู้ใช้งาน</th>
                        <th scope="col">สถานะ</th>
                        <th scope="col">ประเภทผู้ใช้งาน</th>
                        <th scope="col">ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>

<?php
    $orderNumber = 1;
    foreach($result_useraccount as $dataUserAccount)
    { 
?> 

                    <tr>
                        <th><?php echo $orderNumber; ?></th>
                        <td><?php echo $dataUserAccount["useraccountID"]; ?></td>
                        <td><?php echo $dataUserAccount["useraccountName"]; ?></td>
                        <td>
                            <?php 
                            if($dataUserAccount["userActive"] == '1'){
                                echo '<span class="badge text-bg-success">สามารถใช้งานได้</span>';
                            }else{
                                echo '<span class="badge text-bg-danger">ปิดการใช้งาน</span>';
                            }
                            ?>
                        </td>
                        <td><?php echo $dataUserAccount["usertypeName"]; ?></td>
                        <td>
                        <?php if($dataUserAccount["useraccountType"] != 'UT00000001'){?>
                            <a class="btn btn-outline-warning" href="page_Edituseraccount.php?useraccountID=<?php echo $dataUserAccount["useraccountID"]; ?>"><i class="fa-solid fa-file-pen"></i> แก้ไข</a>
                        <?php 
                            if($dataUserAccount["userActive"] == '1'){
                        ?>
                            <a class="btn btn-outline-danger" href="page_DisActiveUseraccount.php?useraccountID=<?php echo $dataUserAccount["useraccountID"];?>&statusActive=0" onClick="return confirm('คุณต้องการที่จะปิดการใช้งานผู้ใช้นี้หรือไม่ ?');"><i class="fa-solid fa-lock"></i> ปิดการใช้งาน</a>
                        <?php
                            }else{
                        ?>
                            <a class="btn btn-outline-success" href="page_DisActiveUseraccount.php?useraccountID=<?php echo $dataUserAccount["useraccountID"]; ?>&statusActive=1" onClick="return confirm('คุณต้องการที่จะเปิดการใช้งานผู้ใช้นี้หรือไม่ ?');"><i class="fa-solid fa-lock-open"></i> เปิดการใช้งาน</a>
                        <?php
                            }
                            }
                        ?>
                        </td>
                    </tr>
<?php
    $orderNumber++;
    }
?>
                </tbody>
            </table>