<?php
/**
 * mail_config.php - การตั้งค่าบัญชี Gmail SMTP สำหรับ PHPMailer
 * SMTP Configuration for Gmail Email Notifications
 * 
 * 👉 กรุณานำอีเมล Gmail และ รหัสผ่านแอป (App Password 16 หลัก) มาใส่ที่นี่:
 */

return [
    // ที่อยู่ของ SMTP Server สำหรับ Gmail
    'host'        => 'smtp.gmail.com',

    // พอร์ตสำหรับการเชื่อมต่อ: 587 (สำหรับ TLS แนะนำ) หรือ 465 (สำหรับ SSL)
    'port'        => 587,

    // เปิดใช้งานการยืนยันตัวตน SMTP
    'smtp_auth'   => true,

    // ⭐️ ชื่อผู้ใช้ / อีเมล Gmail ของคุณ (เช่น asset.admin@gmail.com)
    'username'    => 'YOUR_GMAIL@gmail.com',

    // ⭐️ รหัสผ่านแอป 16 หลัก (App Password) ที่สร้างจาก Google Account (เช่น abcd efgh ijkl mnop)
    'password'    => 'xxxx xxxx xxxx xxxx',

    // รูปแบบการเข้ารหัส: 'tls' หรือ 'ssl'
    'smtp_secure' => 'tls',

    // อีเมลผู้ส่งเริ่มต้น (ใช้อีเมลเดียวกับ username)
    'from_email'  => 'YOUR_GMAIL@gmail.com',

    // ชื่อผู้ส่งที่จะแสดงในกล่องจดหมายผู้รับ
    'from_name'   => 'ระบบบริหารจัดการครุภัณฑ์ (Asset Management)',

    // ระดับการดีบั๊ก (0 = ปิด, 1 = ฝั่ง client, 2 = ทั้ง client และ server)
    'debug'       => 0,
];
