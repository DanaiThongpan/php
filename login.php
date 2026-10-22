<?php
/**
 * login.php - จุดเข้าสู่ระบบและรองรับพารามิเตอร์ Redirect (QR Code Authentication Flow)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// จดจำ URL สำหรับ Redirect หากมีส่งมาทางพารามิเตอร์
if (!empty($_GET['redirect'])) {
    $_SESSION['redirect_url'] = $_GET['redirect'];
}

// ดึงหน้าเข้าสู่ระบบหลักมาแสดงผล
require_once __DIR__ . '/index.php';
