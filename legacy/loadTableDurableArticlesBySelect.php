<?php
include("../control/connectDB.php");

$txt_class="";
$txt_building="";
$txt_year="";
$txt_status="";

$_POST['txtselectClassSearch'];
$_POST['txtselectBuildingSearch'];
$_POST['txtselectYearSearch'];
$_POST['txtselectStatusSearch'];

if($_POST['txtselectClassSearch'] != ""){
    $selectClassSearch = $_POST['txtselectClassSearch'];
    $txt_class= "AND durable_articles.classificationID = '$selectClassSearch'";
}

if($_POST['txtselectBuildingSearch'] != ""){
    $selectBuildingSearch = $_POST['txtselectBuildingSearch'];
    $txt_building= "AND durable_articles.buildingID = '$selectBuildingSearch'";
}

if($_POST['txtselectYearSearch'] != ""){
    $selectYearSearch = $_POST['txtselectYearSearch'];
    $txt_year= "AND durable_articles.DA_Year = '$selectYearSearch'";
}

if($_POST['txtselectStatusSearch'] != ""){
    $selectStatusSearch = $_POST['txtselectStatusSearch'];
    $txt_status= "AND durable_articles.statusID = '$selectStatusSearch'";
}



$sql_dataDA = "SELECT * FROM `durable_articles` 
LEFT JOIN classification ON classification.classificationID = durable_articles.classificationID 
LEFT JOIN status_active ON status_active.statusID = durable_articles.statusID
LEFT JOIN building ON building.buildingID = durable_articles.buildingID
LEFT JOIN building_classroom ON building_classroom.classroomID = durable_articles.classroomID
WHERE DA_ID <> '' ".$txt_class." ".$txt_building." ".$txt_year." ".$txt_status." 
";
$result_dataDA = $conn->query($sql_dataDA);

$YearNow = date("Y") + 543;
?>

<table class="cell-border" id="tableDataDA">
    <thead>
        <tr>
            <th class="text-nowrap">ลำดับ</th>
            <th class="text-nowrap">หมวดหมู่</th>
            <th class="text-nowrap">สถานที่</th>
            <th class="text-nowrap">รายละเอียดสถานที่</th>
            <th class="text-nowrap">รายการ</th>
            <th class="text-nowrap">ยี่ห้อ / รุ่น</th>
            <th class="text-nowrap">ขนาด</th>
            <th class="text-nowrap">หมายเลขครุภัณฑ์</th>
            <th class="text-nowrap">เริ่มใช้งาน</th>
            <th class="text-nowrap">อายุการใช้งาน</th>
            <th class="text-nowrap">ราคา</th>
            <th class="text-nowrap">ประวัติการซ่อม</th>
            <th class="text-nowrap">ความพร้อมใช้งาน</th>
            <th class="text-nowrap">หมายเหตุ</th>
            <th class="text-nowrap">ดำเนินการ</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $orderNumber = 1;
        foreach ($result_dataDA as $dataDA) {
        ?>
            <tr>
                <td class="text-nowrap"><?php echo $orderNumber; ?></td>
                <td class="text-nowrap"><?php echo $dataDA["classificationName"]; ?></td>
                <td class="text-nowrap"><?php echo $dataDA["buildingName"]." ".$dataDA["classroomName"]; ?></td>
                <td class="text-nowrap"><?php echo $dataDA["DA_DetailLocation"]; ?></td>
                <td class="text-nowrap"><?php echo $dataDA["DA_ListName"]; ?></td>
                <td class="text-nowrap"><?php echo $dataDA["DA_Brand"]; ?></td>
                <td class="text-nowrap"><?php echo $dataDA["DA_Size"]; ?></td>
                <td class="text-nowrap"><?php echo $dataDA["DA_Number"]; ?></td>
                <td class="text-nowrap"><?php if($dataDA["DA_Year"] == "0000"){ echo "ไม่ทราบปีใช้งาน";}else{ echo $dataDA["DA_Year"]; } ?></td>
                <td class="text-nowrap"><?php if($dataDA["DA_Year"] == "0000"){ echo "ไม่ทราบอายุใช้งาน";}else{ echo $YearNow - $dataDA["DA_Year"]; echo"ปี"; } ?></td>
                <td class="text-nowrap"><?php echo $dataDA["DA_Price"]; ?></td>
                <td class="text-nowrap">
                    <a href="page_Repair_AddEdit.php?DAID=<?php echo $dataDA["DA_ID"]; ?>" class="btn btn-outline-secondary">เพิ่มประวัติการซ่อม</a>
                </td>
                <td class="text-nowrap">
                    <?php 
                        if($dataDA["statusID"] == 's0001'){ ?>
                        <span class="badge text-bg-success" style="font-size: 11px;"><?php echo $dataDA["statusName"]; ?></span>
                    <?php
                    }elseif($dataDA["statusID"] == 's0002'){ ?>
                        <span class="badge text-bg-danger" style="font-size: 11px;"><?php echo $dataDA["statusName"]; ?></span>
                    <?php
                    }else{ ?>
                        <span class="badge text-bg-warning" style="font-size: 11px;"><?php echo $dataDA["statusName"]; ?></span>
                    <?php
                    }
                    ?>
                    
                </td>
                <td class="text-nowrap"><?php echo $dataDA["DA_Detail"]; ?></td>
                <td class="text-nowrap">
                    <a href="page_EditDurableArticles.php?DAID=<?php echo $dataDA["DA_ID"]; ?>" class="btn btn-outline-warning">แก้ไข</a>
                    <button class="btn btn-outline-success" onclick="ShowimgURLDA('<?php echo $dataDA["DA_URLImg"]; ?>')">รูปภาพ</button>
                </td>
            </tr>
        <?php
            $orderNumber++;
        }
        ?>
    </tbody>
</table>