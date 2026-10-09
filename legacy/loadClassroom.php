<?php
include("../control/connectDB.php");

$BuildingID = $_POST['txtselectBuilding'];
$sql = "SELECT * FROM `building_classroom` WHERE buildingID = '$BuildingID'";
$result = $conn->query($sql);
?>
<select class="form-select" aria-label="Default select example" id="selectClassroom">
    <option selected value="">เลือกห้อง</option>
    <?php
    foreach ($result as $dataClassroom) {
    ?>
        <option value="<?php echo $dataClassroom["classroomID"]; ?>"><?php echo $dataClassroom["classroomName"]; ?></option>
    <?php
    }
    ?>
</select>