<?php
include("../control/connectDB.php");
$sql_DataBuilding = "SELECT * FROM `building`";
$result_DataBuilding = $conn->query($sql_DataBuilding);
?>

            <table class="cell-border" id="tableBuilding">
                <thead>
                    <tr>
                        <th class="text-nowrap">ลำดับ</th>
                        <th class="text-nowrap">รหัสอาคาร</th>
                        <th class="text-nowrap">ชื่ออาคาร</th>
                        <th class="text-nowrap">เกี่ยวกับห้องเรียน</th>
                        <th class="text-nowrap">ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>

<?php
    $orderNumber = 1;
    foreach($result_DataBuilding as $dataBuilding)
    { 
?> 
                    <tr>
                        <td class="text-nowrap"><?php echo $orderNumber; ?></td>
                        <td class="text-nowrap"><?php echo $dataBuilding["buildingID"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataBuilding["buildingName"]; ?></td>
                        <td class="text-nowrap">
                            <a type="button" href="page_Classroom.php?buildingID=<?php echo $dataBuilding["buildingID"]; ?>"class="btn btn-outline-success">จัดการข้อมูลห้องภายในอาคาร</a>
                        </td>
                        <td class="text-nowrap">
                            <button type="button" onclick="ShowModalImgBuilding('<?php echo $dataBuilding["building_URLImg"]; ?>')" class="btn btn-outline-success">รูปภาพ</button>
                            <button type="button" onclick="EditBuilding('<?php echo $dataBuilding["buildingID"]; ?>','<?php echo $dataBuilding["buildingName"]; ?>','<?php echo $dataBuilding["building_URLImg"]; ?>')" class="btn btn-outline-warning">แก้ไข</button>
                            <button type="button" onclick="DelBuilding('<?php echo $dataBuilding["buildingID"]; ?>')"class="btn btn-outline-danger">ลบ</button>
                        </td>
                    </tr>            
<?php
    $orderNumber++;
    }
?>
                </tbody>
            </table>