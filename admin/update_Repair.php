<?php
session_start();
require_once '../control/connectPDO_dev.php';

$repairID = trim($_POST['txt_repairID'] ?? '');
$repair_Hisory = trim($_POST['txt_RepairHisory'] ?? '');
$repair_Price = (float)($_POST['txt_RepairPrice'] ?? 0);
$DateRepairHisory = trim($_POST['txt_DateRepairHisory'] ?? date('Y-m-d'));

if (empty($repairID) || empty($repair_Hisory)) {
    echo "2";
    exit();
}

try {
    $stmt = $pdo->prepare("UPDATE repair SET repair_Hisory = :his, repair_Price = :price, repair_Date = :rdate WHERE repairID = :id");
    $stmt->execute([
        ':his' => $repair_Hisory,
        ':price' => $repair_Price,
        ':rdate' => $DateRepairHisory,
        ':id' => $repairID
    ]);
    echo "1";
} catch (Exception $e) {
    echo "2";
}
