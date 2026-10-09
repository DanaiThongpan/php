<?php 
// นำเข้าไฟล์เชื่อมต่อฐานข้อมูล PDO เพื่อความปลอดภัยและหลีกเลี่ยง SQL Injection
require_once 'control/connectPDO.php';

if(isset($_POST['status_Update_User'])){
    
    // อัปเดตข้อมูลผู้ใช้งาน (รวมถึงตำแหน่งและสังกัด)
    if($_POST['status_Update_User'] == 'updateuser'){

        $userID = $_POST['user_ID'];
        $userName = $_POST['useraccountName'];
        $userType = $_POST['useraccountType'];
        
        // รับค่าตำแหน่งและสังกัดที่ส่งมาจาก AJAX
        $userRank = $_POST['userRank'] ?? '';
        $userDepartment = $_POST['userDepartment'] ?? '';

        try {
            // ใช้ PDO Prepared Statement ในการอัปเดตข้อมูล ป้องกัน SQL Injection
            $sql = "UPDATE useraccount 
                    SET useraccountName = :name, 
                        useraccountType = :type, 
                        userRank = :rank, 
                        userDepartment = :dept 
                    WHERE useraccountID = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':name', $userName);
            $stmt->bindParam(':type', $userType);
            $stmt->bindParam(':rank', $userRank);
            $stmt->bindParam(':dept', $userDepartment);
            $stmt->bindParam(':id', $userID);
            
            // ประมวลผลคำสั่ง
            if($stmt->execute()) {
                echo "1"; // คืนค่า 1 คืออัปเดตสำเร็จ ส่งกลับไปให้ AJAX แสดงผล SweetAlert
            } else {
                echo "Error: ไม่สามารถบันทึกข้อมูลได้ (Execute failed)"; 
            }
        } catch(PDOException $e) {
            // คืนค่า Error Message ออกมาตรงๆ เพื่อให้ง่ายต่อการ Debug สาเหตุที่แท้จริง
            echo "Error: " . $e->getMessage(); 
        }
    }
}
?>