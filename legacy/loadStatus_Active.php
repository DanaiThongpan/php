<?php
include("../control/connectDB.php");

$sql = "SELECT * FROM `status_active`";
$result = $conn->query($sql);
?>
<select class="form-select" aria-label="Default select example" id="selectStatusActive">
    <option selected value="s0001">พร้อมใช้</option>
    <?php
    foreach ($result as $dataStatusActive) {
    ?>
        <option value="<?php echo $dataStatusActive["statusID"]; ?>"><?php echo $dataStatusActive["statusName"]; ?></option>
    <?php
    }
    ?>
</select>