<?php
include("../control/connectDB.php");

$sql = "SELECT * FROM `usertype`";
$result = $conn->query($sql);
?>
<select class="form-select" aria-label="Default select example" id="useraccountType">
    <option selected value="">เลือกประเภทผู้ใช้งาน</option>
    <?php
    foreach ($result as $dataUserType) {
    ?>
        <option value="<?php echo $dataUserType["useraccountType"]; ?>"><?php echo $dataUserType["usertypeName"]; ?></option>
    <?php
    }
    ?>
</select>