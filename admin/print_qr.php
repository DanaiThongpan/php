<?php
session_start();

// ตรวจสอบสิทธิ์ (ต้องเป็น Admin)
if (!isset($_SESSION['Selogin']) || $_SESSION['Selogin'] != 1 || $_SESSION['useraccountType'] !== 'UT00000001') {
    header("Location: ../index.php");
    exit();
}

require_once '../control/connectPDO_dev.php';

$da_id = $_GET['da_id'] ?? '';

if (empty($da_id)) {
    echo "ไม่พบรหัสครุภัณฑ์";
    exit();
}

// ดึงข้อมูลครุภัณฑ์
$stmt = $pdo->prepare("SELECT da.*, c.classificationName 
                       FROM durable_articles da 
                       LEFT JOIN classification c ON da.classificationID = c.classificationID 
                       WHERE da.DA_ID = :da_id OR da.DA_Number = :da_id");
$stmt->execute([':da_id' => $da_id]);
$item = $stmt->fetch();

if (!$item) {
    echo "ไม่พบข้อมูลครุภัณฑ์";
    exit();
}

// สร้าง URL สำหรับให้ User สแกน (สมมติว่ารันบน localhost:8000)
// ในการใช้งานจริง ควรเปลี่ยนเป็น Domain Name ของเซิร์ฟเวอร์
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$domain = $_SERVER['HTTP_HOST'];
$base_dir = dirname(dirname($_SERVER['SCRIPT_NAME'])); // พาธหลักของโปรเจกต์
$targetUrl = $protocol . "://" . $domain . $base_dir . "/user/borrow_action.php?da_id=" . urlencode($item['DA_Number']);

// ใช้ Google Chart API (หรือ qrserver) ในการ Generate QR Code Image
$qrApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($targetUrl);

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>พิมพ์ QR Code - <?php echo htmlspecialchars($item['DA_Number']); ?></title>
    <!-- Bootstrap 5 สำหรับจัดหน้า -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .qr-print-card {
            width: 350px;
            margin: 20px auto;
            border: 2px solid #000;
            background-color: #fff;
            padding: 20px;
            text-align: center;
        }
        .qr-image {
            width: 250px;
            height: 250px;
            margin-bottom: 15px;
        }
        .da-number {
            font-size: 1.2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .da-name {
            font-size: 0.9rem;
            color: #333;
        }
        /* ซ่อนปุ่มต่างๆ เวลากดสั่งพิมพ์จริง */
        @media print {
            body {
                background-color: #fff;
            }
            .no-print {
                display: none !important;
            }
            .qr-print-card {
                margin: 0;
                border: 1px dashed #ccc;
            }
        }
    </style>
</head>
<body>

<div class="container text-center mt-4 no-print">
    <h3 class="fw-bold">ระบบสร้าง QR Code สำหรับแปะประจำเครื่อง</h3>
    <p class="text-muted">ขนาดและรูปแบบถูกออกแบบมาให้พอดีกับการพิมพ์ (Printable)</p>
    <button onclick="window.print()" class="btn btn-primary px-4"><i class="fa-solid fa-print"></i> พิมพ์ QR Code นี้</button>
    <button onclick="window.close()" class="btn btn-secondary px-4">ปิดหน้าต่าง</button>
</div>

<!-- ป้าย QR Code ที่จะนำไปแปะ -->
<div class="qr-print-card">
    <img src="<?php echo $qrApiUrl; ?>" alt="QR Code" class="qr-image">
    <div class="da-number"><?php echo htmlspecialchars($item['DA_Number']); ?></div>
    <div class="da-name"><?php echo htmlspecialchars($item['DA_ListName']); ?></div>
    <?php if(!empty($item['DA_Brand'])): ?>
        <div class="da-name text-muted">(<?php echo htmlspecialchars($item['DA_Brand']); ?>)</div>
    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
</body>
</html>
