<?php
include("../control/connectDB.php");

$sql = "SELECT * FROM `status_active`";
$result = $conn->query($sql);
foreach ($result as $dataStatusActive) {
?>
    <li class="list-group-item d-flex justify-content-between align-items-start">
        <div class="ms-2 me-auto">
            <div class="fw-bold">รหัสสถานะ : <?php echo $dataStatusActive["statusID"]; ?></div>
            สถานะ : <?php echo $dataStatusActive["statusName"]; ?>
        </div>
        <button onclick="editStatusActive('<?php echo $dataStatusActive["statusID"]; ?>','<?php echo $dataStatusActive["statusName"]; ?>')" class="btn btn-outline-warning"><i class="fa-solid fa-pen-to-square"></i> แก้ไข</button> &nbsp;
        <button onclick="delStatusActive('<?php echo $dataStatusActive["statusID"]; ?>')" class="btn btn-outline-danger"><i class="fa-solid fa-trash"></i> ลบ</button>
    </li>
<?php 
}
?>