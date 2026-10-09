<?php
// refactor.php
$legacyDir = __DIR__ . '/legacy';
if (!is_dir($legacyDir)) {
    mkdir($legacyDir, 0777, true);
}

// ไฟล์ที่ต้องการเก็บไว้ที่เดิม (โฟลเดอร์หลัก)
$keepFiles = [
    'index_v2.php',
    'register.php',
    'register_process.php',
    'refactor.php'
];

$files = scandir(__DIR__);

foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    
    // สนใจเฉพาะไฟล์ .php ใน root
    if (is_file(__DIR__ . '/' . $file) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        if (!in_array($file, $keepFiles)) {
            // ย้ายไฟล์ไปที่ legacy/
            $source = __DIR__ . '/' . $file;
            $dest = $legacyDir . '/' . $file;
            rename($source, $dest);
            
            // แก้ไข Path ภายในไฟล์
            $content = file_get_contents($dest);
            
            // เปลี่ยน ./control/ เป็น ../control/
            $content = str_replace('"./control/', '"../control/', $content);
            $content = str_replace("'./control/", "'../control/", $content);
            
            // เปลี่ยน ./component/ เป็น ../component/
            $content = str_replace('"./component/', '"../component/', $content);
            $content = str_replace("'./component/", "'../component/", $content);
            
            // เปลี่ยน ./css/ เป็น ../css/
            $content = str_replace('"./css/', '"../css/', $content);
            $content = str_replace("'./css/", "'../css/", $content);
            
            // เปลี่ยน ./img/ เป็น ../img/
            $content = str_replace('"./img/', '"../img/', $content);
            $content = str_replace("'./img/", "'../img/", $content);
            
            // เปลี่ยน upload_imgDA/ เป็น ../upload_imgDA/
            $content = str_replace('"upload_imgDA/', '"../upload_imgDA/', $content);
            $content = str_replace("'upload_imgDA/", "'../upload_imgDA/", $content);

            // เปลี่ยน upload_imgBuilding/ เป็น ../upload_imgBuilding/
            $content = str_replace('"upload_imgBuilding/', '"../upload_imgBuilding/', $content);
            $content = str_replace("'upload_imgBuilding/", "'../upload_imgBuilding/", $content);

            // เปลี่ยน upload_imgBuildingClassroom/ เป็น ../upload_imgBuildingClassroom/
            $content = str_replace('"upload_imgBuildingClassroom/', '"../upload_imgBuildingClassroom/', $content);
            $content = str_replace("'upload_imgBuildingClassroom/", "'../upload_imgBuildingClassroom/", $content);

            file_put_contents($dest, $content);
        }
    }
}

echo "Refactoring completed successfully.";
?>
