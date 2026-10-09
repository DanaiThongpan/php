<?php
include("../control/connectDB.php");
$sql_BorrowBackDataDA = 'SELECT t2.DA_Number,t2.DA_ListName,t2.DA_Brand,t2.DA_Size,
t1.Date_borrow,t1.Name_borrow,t1.Rank_borrow,t1.Belongto_borrow,t1.Detail_borrow,t1.Deadline_borrow,u1.useraccountName,t1.timestamp_borrow,
t1.Date_back,t1.Name_back,t1.Rank_back,t1.Detail_back,u2.useraccountName,t1.timestamp_back
FROM `borrow_back`AS t1
LEFT JOIN durable_articles AS t2 ON t2.DA_ID = t1.DA_ID
LEFT JOIN useraccount AS u1 ON u1.useraccountID = t1.useraccountID_borrow
LEFT JOIN useraccount AS u2 ON u2.useraccountID = t1.useraccountID_back';
$result_BorrowBackDataDA = $conn->query($sql_BorrowBackDataDA);
?>

            <table class="cell-border" id="tableDA">
                <thead>
                    <tr>
                        <th class="text-nowrap">ลำดับ</th>
                        <th class="text-nowrap">หมายเลขครุภัณฑ์</th>
                        <th class="text-nowrap">รายการ</th>
                        <th class="text-nowrap">ยี่ห้อ / รุ่น</th>
                        <th class="text-nowrap">ขนาน</th>
                        <th class="text-nowrap">วันที่ยืม</th>
                        <th class="text-nowrap">ผู้ยืม</th>
                        <th class="text-nowrap">ต่ำแหน่ง</th>
                        <th class="text-nowrap">สังกัด</th>
                        <th class="text-nowrap">รายละเอียดเพิ่มเติม</th>
                        <th class="text-nowrap">วันที่กำหนดคืน</th>
                        <th class="text-nowrap">ผู้อนุมัติการยืม</th>
                        <th class="text-nowrap">วันที่คืน</th>
                        <th class="text-nowrap">ผู้นำมาคืน</th>
                        <th class="text-nowrap">ต่ำแหน่ง</th>
                        <th class="text-nowrap">รายละเอียดเพิ่มเติม</th>
                        <th class="text-nowrap">ผู้รับครุภัณฑ์</th>
                    </tr>
                </thead>
                <tbody>

<?php
    $orderNumber = 1;
    foreach($result_BorrowBackDataDA as $dataBorrowBackDataDA)
    { 
?> 
                    <tr>
                        <td class="text-nowrap"><?php echo $orderNumber; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["DA_Number"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["DA_ListName"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["DA_Brand"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["DA_Size"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["Date_borrow"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["Name_borrow"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["Rank_borrow"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["Belongto_borrow"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["Detail_borrow"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["Deadline_borrow"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["useraccountName"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["Date_back"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["Name_back"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["Rank_back"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["Detail_back"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBorrowBackDataDA["useraccountName"]; ?></td>
                    </tr>            
<?php
    $orderNumber++;
    }
?>
                </tbody>
            </table>