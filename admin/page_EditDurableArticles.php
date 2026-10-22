<?php
$da_id = $_GET['DAID'] ?? $_GET['da_id'] ?? '';
header("Location: edit_durable_article.php?da_id=" . urlencode($da_id));
exit();
