<?php
include("../control/connectDB.php");

$sql = "SELECT * FROM `classification`";
$result = $conn->query($sql);
?>
<select class="form-select" aria-label="Default select example" id="selectClassification">
    <option selected value="">เลือกหมวดหมู่</option>
    <?php
    foreach ($result as $dataClassification) {
    ?>
        <option value="<?php echo $dataClassification["classificationID"]; ?>"><?php echo $dataClassification["classificationName"]; ?></option>
    <?php
    }
    ?>
</select>