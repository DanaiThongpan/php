<?php
/**
 * manual_remind.php - Root wrapper forwarding to admin/manual_remind.php
 * ป้องกันปัญหา 404 กรณีมีการเรียกผ่าน Root URL (/manual_remind.php) หรือ Admin Subfolder (/admin/manual_remind.php)
 */
require_once __DIR__ . '/admin/manual_remind.php';
