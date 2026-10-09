<?php
include("../control/connectDB.php");

$sql = "SELECT * FROM `classification`";
$result = $conn->query($sql);
foreach ($result as $dataClassification) {
?>
    <li class="list-group-item d-flex justify-content-between align-items-start">
        <div class="ms-2 me-auto">
            <div class="fw-bold">รหัสหมวดหมู่ : <?php echo $dataClassification["classificationID"]; ?></div>
            หมวดหมู่ : <?php echo $dataClassification["classificationName"]; ?>
        </div>
        <button onclick="editClassification('<?php echo $dataClassification["classificationID"]; ?>','<?php echo $dataClassification["classificationName"]; ?>')" class="btn btn-outline-warning"><i class="fa-solid fa-pen-to-square"></i> แก้ไข</button> &nbsp;
        <button onclick="delClassification('<?php echo $dataClassification["classificationID"]; ?>')" class="btn btn-outline-danger"><i class="fa-solid fa-trash"></i> ลบ</button>
    </li>
<?php 
}
?>