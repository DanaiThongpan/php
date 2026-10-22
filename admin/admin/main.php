<?php
/**
 * admin/admin/main.php - Fallback handler for double /admin/admin/ path
 * Redirects automatically to /admin/main.php
 */
header("Location: ../main.php");
exit();
