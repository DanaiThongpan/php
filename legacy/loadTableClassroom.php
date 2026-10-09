<?php
include("../control/connectDB.php");
$BuildingID = $_POST['txtbuilding_ID'];
$sql_DataClassroom = "SELECT * FROM `building_classroom` WHERE buildingID = '$BuildingID'";
$result_DataClassroom = $conn->query($sql_DataClassroom);
?>

            <table class="cell-border" id="tableClassroom">
                <thead>
                    <tr>
                        <th class="text-nowrap">ลำดับ</th>
                        <th class="text-nowrap">รหัสห้อง</th>
                        <th class="text-nowrap">ชื่อห้อง</th>
                        <th class="text-nowrap">ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>

<?php
    $orderNumber = 1;
    foreach($result_DataClassroom as $dataClassroom)
    { 
?> 
                    <tr>
                        <td class="text-nowrap"><?php echo $orderNumber; ?></td>
                        <td class="text-nowrap"><?php echo $dataClassroom["classroomID"]; ?></td>
                        <td class="text-nowrap"><?php echo $dataClassroom["classroomName"]; ?></td>
                        <td class="text-nowrap">
                            <button type="button" onclick="ShowModalImgClassroom('<?php echo $dataClassroom["classroom_URLImg"]; ?>')" class="btn btn-outline-success">รูปภาพ</button>
                            <button type="button" onclick="EditClassroom('<?php echo $dataClassroom["classroomID"]; ?>','<?php echo $dataClassroom["classroomName"]; ?>','<?php echo $dataClassroom["classroom_URLImg"]; ?>')" class="btn btn-outline-warning">แก้ไข</button>
                            <button type="button" onclick="DelClassroom('<?php echo $dataClassroom["classroomID"]; ?>')"class="btn btn-outline-danger">ลบ</button>
                        </td>
                    </tr>            
<?php
    $orderNumber++;
    }
?>
                </tbody>
            </table>