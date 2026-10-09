<?php
include("../control/connectDB.php");
$sql_BorrowDataDA = 'SELECT  borrow_back.IDborrow_back,borrow_back.Deadline_borrow,durable_articles.DA_Number,
durable_articles.DA_ListName,durable_articles.DA_Brand,durable_articles.DA_Size,durable_articles.DA_ID,
borrow_back.Name_borrow,borrow_back.Rank_borrow,borrow_back.Belongto_borrow,borrow_back.Detail_borrow,useraccount.useraccountName
FROM `borrow_back`
LEFT JOIN durable_articles ON durable_articles.DA_ID = borrow_back.DA_ID
LEFT JOIN useraccount ON useraccount.useraccountID = borrow_back.useraccountID_borrow
WHERE borrow_back.timestamp_back = ""';
$result_BorrowDataDA = $conn->query($sql_BorrowDataDA);
?>

            <table class="cell-border" id="tableDA">
                <thead>
                    <tr>
                        <th class="text-nowrap"></th>
                        <th class="text-nowrap">ลำดับ</th>
                        <th class="text-nowrap">วันที่กำหนดคืน</th>
                        <th class="text-nowrap">หมายเลขครุภัณฑ์</th>
                        <th class="text-nowrap">รายการ</th>
                        <th class="text-nowrap">ยี่ห้อ / รุ่น</th>
                        <th class="text-nowrap">ขนาน</th>
                        <th class="text-nowrap">ผู้ยืม</th>
                        <th class="text-nowrap">ต่ำแหน่ง</th>
                        <th class="text-nowrap">สังกัด</th>
                        <th class="text-nowrap">รายละเอียดเพิ่มเติม</th>
                        <th class="text-nowrap">ผู้อนุมัติการยืม</th>
                    </tr>
                </thead>
                <tbody>

<?php
    $orderNumber = 1;
    foreach($result_BorrowDataDA as $dataBorrowDataDA)
    { 
?> 
                    <tr>
                        <td class="text-nowrap">
                            <button type="button" onclick="setinputDAID('<?php echo $dataBorrowDataDA["IDborrow_back"]; ?>','<?php echo $dataBorrowDataDA["DA_ID"]; ?>')" data-bs-toggle="modal" data-bs-target="#ModalBorrowDA" class="btn btn-outline-danger"><i class="fa-solid fa-pen"></i> คืนวัสดุ/ครุภัณฑ์</button>
                        </td>
                        <td class="text-nowrap"><?php echo $orderNumber; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowDataDA["Deadline_borrow"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowDataDA["DA_Number"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowDataDA["DA_ListName"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowDataDA["DA_Brand"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowDataDA["DA_Size"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowDataDA["Name_borrow"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowDataDA["Rank_borrow"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowDataDA["Belongto_borrow"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowDataDA["Detail_borrow"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowDataDA["useraccountName"]; ?></td>
                    </tr>            
<?php
    $orderNumber++;
    }
?>
                </tbody>
            </table>