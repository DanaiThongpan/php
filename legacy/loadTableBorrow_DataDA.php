<?php
include("../control/connectDB.php");
$sql_DataDA = "SELECT * FROM `durable_articles` WHERE statusID = 's0001';";
$result_DA = $conn->query($sql_DataDA);
?>

            <table class="cell-border" id="tableDA">
                <thead>
                    <tr>
                        <th class="text-nowrap"></th>
                        <th class="text-nowrap">ลำดับ</th>
                        <th class="text-nowrap">หมายเลขครุภัณฑ์</th>
                        <th class="text-nowrap">รายการ</th>
                        <th class="text-nowrap">ยี่ห้อ / รุ่น</th>
                        <th class="text-nowrap">ขนาน</th>
                        
                    </tr>
                </thead>
                <tbody>

<?php
    $orderNumber = 1;
    foreach($result_DA as $dataDA)
    { 
?> 
                    <tr>
                        <td class="text-nowrap">
                            <button type="button" onclick="setinputDAID('<?php echo $dataDA["DA_ID"]; ?>')" data-bs-toggle="modal" data-bs-target="#ModalBorrowDA" class="btn btn-outline-primary"><i class="fa-solid fa-pen"></i> ยืมวัสดุ/ครุภัณฑ์</button>
                        </td>
                        <td class="text-nowrap"><?php echo $orderNumber; ?></td>
                        <td class="text-nowrap"><?php echo $dataDA["DA_Number"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataDA["DA_ListName"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataDA["DA_Brand"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataDA["DA_Size"]; ?></td>
                    </tr>            
<?php
    $orderNumber++;
    }
?>
                </tbody>
            </table>