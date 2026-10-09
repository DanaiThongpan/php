<?php
include("../control/connectDB.php");

$sql = "SELECT * FROM `building`";
$result = $conn->query($sql);
?>
<select class="form-select" aria-label="Default select example" id="selectBuilding">
    <option selected value="">เลือกอาคาร</option>
    <?php
    foreach ($result as $dataBuilding) {
    ?>
        <option value="<?php echo $dataBuilding["buildingID"]; ?>"><?php echo $dataBuilding["buildingName"]; ?></option>
    <?php
    }
    ?>
</select>