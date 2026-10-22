<?php
/**
 * component/admin_header.php - ส่วนหัวของระบบ Admin Layout (Head & Initialization)
 * ทำหน้าที่โหลด CSS Framework, Responsive Meta, DataTables Responsive และเปิด Container หลัก
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. ตรวจสอบสิทธิ์การเข้าถึง (เฉพาะ Admin เท่านั้น)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || ($_SESSION['useraccountType'] ?? '') !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

// 2. ดึงไฟล์เชื่อมต่อฐานข้อมูล
require_once __DIR__ . '/../control/connectPDO_dev.php';

// ข้อมูลผู้ใช้และระดับสิทธิ์
$currentUserID   = $_SESSION['SeUser'] ?? '';
$useraccountName = $_SESSION['Sename'] ?? 'ผู้ดูแลระบบ';
$currentUserDept = $_SESSION['userDepartment'] ?? '';
$useraccountType = $_SESSION['useraccountType'] ?? '';
$isSuperAdmin    = ($useraccountType === 'Super Admin' || $currentUserID === 'admin');

// หากยังไม่มีแผนกใน Session ให้ดึงสดจาก DB
if (empty($currentUserDept) && !empty($currentUserID)) {
    try {
        $stmtD = $pdo->prepare("SELECT userDepartment FROM useraccount WHERE useraccountID = :uid");
        $stmtD->execute([':uid' => $currentUserID]);
        $currentUserDept = $stmtD->fetchColumn() ?: '';
        $_SESSION['userDepartment'] = $currentUserDept;
    } catch (PDOException $e) {
        $currentUserDept = '';
    }
}

// ป้ายกำกับระดับสิทธิ์ (Role Badge)
if ($isSuperAdmin) {
    $roleBadge = '<span class="badge bg-danger shadow-sm px-2 py-1"><i class="fa-solid fa-crown text-warning me-1"></i> Super Admin</span>';
} else {
    $deptDisplay = !empty($currentUserDept) ? htmlspecialchars($currentUserDept) : 'ไม่ระบุแผนก';
    $roleBadge = '<span class="badge bg-info text-dark shadow-sm px-2 py-1"><i class="fa-solid fa-building text-primary me-1"></i> ' . $deptDisplay . '</span>';
}

$pageTitle = $pageTitle ?? 'ระบบบริหารจัดการครุภัณฑ์ (Asset Management)';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Admin System</title>

    <!-- Google Fonts: Sarabun & Prompt -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- DataTables 1.13.4 Bootstrap 5 CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css">
    <!-- DataTables Responsive 2.4.1 Extension CSS (รองรับการยุบคอลัมน์บนมือถือ) -->
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.4.1/css/responsive.bootstrap5.min.css">

    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <!-- Custom Enterprise Responsive Dashboard CSS & Design System -->
    <link rel="stylesheet" href="../css/admin_responsive.css">
    <link rel="stylesheet" href="../css/admin_dashboard.css">
</head>
<body class="overflow-hidden m-0 p-0">

<!-- โครงสร้าง Layout หลักแบบ Flexbox (d-flex flex-row vh-100 overflow-hidden) ครอบคลุม 100vh ห้าม Body Scroll -->
<div id="app-layout" class="d-flex flex-row vh-100 vw-100 overflow-hidden">
