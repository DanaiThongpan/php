<?php
session_start();
require_once '../control/connectPDO_dev.php';

$repair_DAID = trim($_POST['txt_daID'] ?? '');
$repair_Hisory = trim($_POST['txt_RepairHisory'] ?? '');
$repair_Price = (float)($_POST['txt_RepairPrice'] ?? 0);
$DateRepairHisory = trim($_POST['txt_DateRepairHisory'] ?? date('Y-m-d'));

if (empty($repair_DAID) || empty($repair_Hisory)) {
    echo "2";
    exit();
}

try {
    $nextId = (int)$pdo->query("SELECT MAX(CAST(repairID AS INTEGER)) FROM repair")->fetchColumn() + 1;
    if ($nextId < 1) $nextId = 1;

    $stmt = $pdo->prepare("INSERT INTO repair (repairID, repair_Hisory, repair_Price, DA_ID, repair_Date) VALUES (:id, :his, :price, :da_id, :rdate)");
    $stmt->execute([
        ':id' => (string)$nextId,
        ':his' => $repair_Hisory,
        ':price' => $repair_Price,
        ':da_id' => $repair_DAID,
        ':rdate' => $DateRepairHisory
    ]);
    echo "1";
} catch (Exception $e) {
    echo "2";
}
