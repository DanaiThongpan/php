<?php
/**
 * BorrowService.php
 * คลาสศูนย์กลางจัดการกระบวนการยืม-คืน ครุภัณฑ์ (Single Source of Truth)
 * ตามหลักการ DRY (Don't Repeat Yourself) และสถาปัตยกรรมระดับ Enterprise
 * 
 * คุณสมบัติ:
 * 1. Concurrency & Race Condition Safe: ใช้ Atomic Status Update เพื่อป้องกันการยืมชนกัน
 * 2. Strict Database Transactions: รับประกัน Data Integrity ด้วย BEGIN, COMMIT, ROLLBACK
 * 3. Centralized Business Logic: ตรวจสอบความถูกต้องของวันที่ กฎการยืม การคืน และสิทธิ์อย่างรัดกุม
 * 4. High-Performance Dashboard Telemetry: บริการคำนวณและดึงข้อมูลสถิติสำหรับ Real-time SSE / REST API
 */

class BorrowService
{
    /**
     * ตรวจสอบความถูกต้องและสมเหตุสมผลของวันที่สำหรับการยืม
     * 
     * @param string $dateBorrow วันที่เริ่มยืม (YYYY-MM-DD)
     * @param string $deadlineBorrow กำหนดส่งคืน (YYYY-MM-DD)
     * @return array ผลการตรวจสอบ ['valid' => bool, 'error' => ?string]
     */
    public static function validateDates(string $dateBorrow, string $deadlineBorrow): array
    {
        $todayStr      = date('Y-m-d');
        $maxAdvanceStr = date('Y-m-d', strtotime('+30 days'));

        if (empty($dateBorrow) || empty($deadlineBorrow)) {
            return ['valid' => false, 'error' => 'กรุณาระบุวันที่เริ่มยืมและกำหนดส่งคืนให้ครบถ้วน'];
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateBorrow) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadlineBorrow)) {
            return ['valid' => false, 'error' => 'รูปแบบวันที่ไม่ถูกต้อง กรุณาเลือกวันที่ตามรูปแบบ ปี-เดือน-วัน (YYYY-MM-DD)'];
        }

        [$y1, $m1, $d1] = explode('-', $dateBorrow);
        [$y2, $m2, $d2] = explode('-', $deadlineBorrow);

        if (!checkdate((int)$m1, (int)$d1, (int)$y1) || !checkdate((int)$m2, (int)$d2, (int)$y2)) {
            return ['valid' => false, 'error' => 'วันที่ที่ระบุไม่มีอยู่จริงในปฏิทิน กรุณาตรวจสอบอีกครั้ง'];
        }

        if ($dateBorrow < $todayStr) {
            return ['valid' => false, 'error' => "วันที่เริ่มยืม ({$dateBorrow}) ต้องไม่เป็นวันที่ในอดีต (ต้องเป็นวันนี้ [{$todayStr}] หรือหลังจากนี้)"];
        }

        if ($dateBorrow > $maxAdvanceStr) {
            return ['valid' => false, 'error' => "ไม่อนุญาตให้จองยืมครุภัณฑ์ล่วงหน้าเกิน 30 วัน (เลือกได้ไม่เกิน {$maxAdvanceStr})"];
        }

        if ($deadlineBorrow < $dateBorrow) {
            return ['valid' => false, 'error' => "กำหนดส่งคืน ({$deadlineBorrow}) ต้องไม่เกิดขึ้นก่อนวันที่เริ่มยืม ({$dateBorrow})"];
        }

        $dtStart  = new DateTime($dateBorrow);
        $dtEnd    = new DateTime($deadlineBorrow);
        $diffDays = (int)$dtStart->diff($dtEnd)->days;

        if ($diffDays > 365) {
            return ['valid' => false, 'error' => "ระยะเวลาการยืมครุภัณฑ์ต้องไม่เกิน 1 ปี (365 วัน) นับจากวันที่เริ่มยืม (ท่านระบุ {$diffDays} วัน)"];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * ดึงข้อมูลครุภัณฑ์ตาม ID หรือ รหัสครุภัณฑ์ (DA_Number)
     */
    public static function getAsset(PDO $pdo, string $identifier): ?array
    {
        $sql = "SELECT da.*, s.statusName, c.classificationName, b.buildingName, cr.classroomName 
                FROM durable_articles da
                LEFT JOIN status_active s ON da.statusID = s.statusID
                LEFT JOIN classification c ON da.classificationID = c.classificationID
                LEFT JOIN building b ON da.buildingID = b.buildingID
                LEFT JOIN building_classroom cr ON da.classroomID = cr.classroomID
                WHERE da.DA_ID = :id OR da.DA_Number = :num
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $identifier, ':num' => $identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * ตรวจสอบว่าครุภัณฑ์พร้อมให้ยืมหรือไม่
     */
    public static function isAssetAvailable(?array $asset): bool
    {
        if (!$asset) return false;
        $isApproved = ($asset['is_approved'] === null || (int)$asset['is_approved'] === 1);
        $isStatusOk = ($asset['statusID'] === 's0001' || empty($asset['statusID']));
        return $isApproved && $isStatusOk;
    }

    /**
     * ค้นหารายการที่กำลังยืมอยู่ปัจจุบันของครุภัณฑ์ชิ้นนี้ (รวมถึงที่กำลังรอเจ้าของอนุมัติรับคืน)
     */
    public static function getActiveBorrow(PDO $pdo, string $daId, ?string $userId = null): ?array
    {
        $sql = "SELECT * FROM borrow_back 
                WHERE DA_ID = :daid 
                  AND (status != 'returned' OR status IS NULL)
                  AND (Date_back IS NULL OR Date_back = '' OR status = 'pending_return')";
        $params = [':daid' => $daId];

        if (!empty($userId)) {
            $sql .= " AND useraccountID_borrow = :uid";
            $params[':uid'] = $userId;
        }

        $sql .= " ORDER BY IDborrow_back DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * ดำเนินการยืมครุภัณฑ์แบบ Atomic Transaction & Race-Condition Safe
     * 
     * @param PDO $pdo
     * @param array $params ข้อมูลการขอยืม
     * @return array ผลลัพธ์ ['success' => bool, 'borrow_id' => ?string, 'message' => string]
     */
    public static function executeBorrow(PDO $pdo, array $params): array
    {
        $daId           = trim($params['da_id'] ?? '');
        $dateBorrow     = trim($params['date_borrow'] ?? '');
        $deadlineBorrow = trim($params['deadline_borrow'] ?? '');
        $nameBorrow     = trim($params['name_borrow'] ?? '');
        $rankBorrow     = trim($params['rank_borrow'] ?? '');
        $belongtoBorrow = trim($params['belongto_borrow'] ?? '');
        $detailBorrow   = trim($params['detail_borrow'] ?? 'ใช้งานทั่วไป');
        $uid            = trim($params['uid'] ?? '');
        $email          = trim($params['email'] ?? '');

        if (empty($daId)) {
            return ['success' => false, 'message' => 'ไม่พบรหัสครุภัณฑ์ที่ต้องการยืม'];
        }

        // ตรวจสอบเงื่อนไขความถูกต้องของวันที่
        $val = self::validateDates($dateBorrow, $deadlineBorrow);
        if (!$val['valid']) {
            return ['success' => false, 'message' => $val['error']];
        }

        $nowTimestamp = date('Y-m-d H:i:s');
        $newBorrowID  = "B" . time() . rand(10, 99);

        try {
            $pdo->beginTransaction();

            $driverName = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) ?: '');
            $forUpdate = ($driverName === 'mysql') ? ' FOR UPDATE' : '';

            // [SECURITY PATCH: Pessimistic Locking]
            // 1. ตรวจสอบและล็อกแถวครุภัณฑ์ชิ้นนี้ทันทีด้วย SELECT ... FOR UPDATE (เมื่อใช้ MySQL)
            $sqlSelectAsset = "SELECT DA_ID, statusID, is_approved 
                               FROM durable_articles 
                               WHERE DA_ID = :da_id" . $forUpdate;
            $stmtSelectAsset = $pdo->prepare($sqlSelectAsset);
            $stmtSelectAsset->execute([':da_id' => $daId]);
            $asset = $stmtSelectAsset->fetch(PDO::FETCH_ASSOC);

            if (!$asset) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'ไม่พบข้อมูลครุภัณฑ์ที่ต้องการยืม'
                ];
            }

            // ตรวจสอบสถานะว่าพร้อมใช้งานและได้รับการอนุมัติหรือไม่
            $isApproved = ($asset['is_approved'] === null || (int)$asset['is_approved'] === 1);
            $isStatusOk = ($asset['statusID'] === 's0001' || empty($asset['statusID']));

            if (!$isApproved || !$isStatusOk) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'ขออภัย ครุภัณฑ์นี้ไม่พร้อมให้ยืมในขณะนี้ หรือกำลังถูกทำรายการโดยผู้ใช้งานท่านอื่น'
                ];
            }

            // 2. Double-check Concurrency: ตรวจสอบตาราง borrow_back ให้แน่ใจว่าไม่มีรายการยืมที่ยังไม่คืนค้างอยู่
            $sqlActiveCheck = "SELECT IDborrow_back, Name_borrow, status 
                               FROM borrow_back 
                               WHERE DA_ID = :da_id 
                                 AND (status != 'returned' OR status IS NULL)
                                 AND (Date_back IS NULL OR Date_back = '' OR status = 'pending_return')
                               LIMIT 1" . $forUpdate;
            $stmtActiveCheck = $pdo->prepare($sqlActiveCheck);
            $stmtActiveCheck->execute([':da_id' => $daId]);
            $existingActive = $stmtActiveCheck->fetch(PDO::FETCH_ASSOC);

            if ($existingActive) {
                $pdo->rollBack();
                // อัปเดตสถานะครุภัณฑ์ให้เป็น s0003 นอก Transaction เพื่อให้การซิงค์สถานะมีผลจริง
                try {
                    $pdo->prepare("UPDATE durable_articles SET statusID = 's0003' WHERE DA_ID = :da_id")->execute([':da_id' => $daId]);
                } catch (Exception $syncEx) {}

                return [
                    'success' => false,
                    'message' => 'ขออภัย ครุภัณฑ์นี้กำลังถูกยืมอยู่ หรืออยู่ระหว่างรอส่งคืน ไม่สามารถทำรายการยืมซ้ำได้'
                ];
            }

            // 3. ปรับสถานะเป็น 'ไม่ว่าง (ยืม)' (s0003)
            $sqlUpdateAsset = "UPDATE durable_articles 
                               SET statusID = 's0003' 
                               WHERE DA_ID = :da_id";
            $stmtUpdateAsset = $pdo->prepare($sqlUpdateAsset);
            $stmtUpdateAsset->execute([':da_id' => $daId]);

            // 4. บันทึกประวัติลงตาราง borrow_back
            $sqlInsert = "INSERT INTO borrow_back 
                          (IDborrow_back, DA_ID, Date_borrow, Name_borrow, Rank_borrow, Belongto_borrow, 
                           Detail_borrow, Deadline_borrow, useraccountID_borrow, timestamp_borrow, userEmail, due_date, status) 
                          VALUES 
                          (:id, :da_id, :d_borrow, :name_borrow, :rank_borrow, :belongto, 
                           :detail, :deadline, :uid, :ts, :email, :due_date, 'borrowed')";
            $stmtInsert = $pdo->prepare($sqlInsert);
            $stmtInsert->execute([
                ':id'          => $newBorrowID,
                ':da_id'       => $daId,
                ':d_borrow'    => $dateBorrow,
                ':name_borrow' => $nameBorrow,
                ':rank_borrow' => $rankBorrow,
                ':belongto'    => $belongtoBorrow,
                ':detail'      => $detailBorrow,
                ':deadline'    => $deadlineBorrow,
                ':uid'         => $uid,
                ':ts'          => $nowTimestamp,
                ':email'       => $email,
                ':due_date'    => $deadlineBorrow
            ]);

            $pdo->commit();

            return [
                'success'   => true,
                'borrow_id' => $newBorrowID,
                'message'   => "ทำรายการขอยืมครุภัณฑ์สำเร็จเรียบร้อย! รหัสอ้างอิง: {$newBorrowID}"
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage()
            ];
        }
    }

    /**
     * ดำเนินการแจ้งส่งคืนครุภัณฑ์ (Request Return / ผู้ยืมส่งคืน รอเจ้าของอนุมัติรับคืน)
     * 
     * @param PDO $pdo
     * @param array $params ข้อมูลการแจ้งส่งคืน
     * @return array ผลลัพธ์ ['success' => bool, 'message' => string, 'da_id' => ?string]
     */
    public static function requestReturn(PDO $pdo, array $params): array
    {
        $borrowId       = trim($params['borrow_id'] ?? '');
        $returnedByName = trim($params['returned_by_name'] ?? 'ผู้ใช้งาน');
        $returnedByRank = trim($params['returned_by_rank'] ?? '');
        $returnedByUid  = trim($params['returned_by_uid'] ?? '');
        $detailBack     = trim($params['detail_back'] ?? 'ส่งคืนตามกำหนด');

        if (empty($borrowId)) {
            return ['success' => false, 'message' => 'ไม่พบรหัสรายการยืม-คืน'];
        }

        $nowTimestamp = date('Y-m-d H:i:s');
        $returnDate   = date('Y-m-d');

        try {
            $pdo->beginTransaction();

            // 1. ตรวจสอบข้อมูลรายการยืม
            $stmtCheck = $pdo->prepare("
                SELECT b.IDborrow_back, b.DA_ID, b.Date_back, b.status, da.DA_ListName, da.DA_Number
                FROM borrow_back b
                LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
                WHERE b.IDborrow_back = :id
                LIMIT 1
            ");
            $stmtCheck->execute([':id' => $borrowId]);
            $record = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'ไม่พบข้อมูลรายการยืมนี้ในระบบ'];
            }

            if (($record['status'] ?? '') === 'returned') {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'รายการนี้ได้รับการอนุมัติส่งคืนเรียบร้อยแล้ว'];
            }

            if (($record['status'] ?? '') === 'pending_return') {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'รายการนี้อยู่ในระหว่างรอเจ้าของหรือผู้ดูแลอนุมัติการรับคืน'];
            }

            // 2. อัปเดตสถานะเป็น 'pending_return' (รอเจ้าของอนุมัติรับคืน)
            $stmtUpdateB = $pdo->prepare("
                UPDATE borrow_back 
                SET Date_back = :return_date,
                    timestamp_back = :return_time,
                    Name_back = :returned_by,
                    Rank_back = :rank_back,
                    Detail_back = :detail_back,
                    useraccountID_back = :uid_back,
                    status = 'pending_return'
                WHERE IDborrow_back = :id 
                  AND (status = 'borrowed' OR status IS NULL OR status = '')
            ");
            $stmtUpdateB->execute([
                ':return_date' => $returnDate,
                ':return_time' => $nowTimestamp,
                ':returned_by' => $returnedByName,
                ':rank_back'   => $returnedByRank,
                ':detail_back' => $detailBack,
                ':uid_back'    => $returnedByUid,
                ':id'          => $borrowId
            ]);

            if ($stmtUpdateB->rowCount() === 0) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'รายการนี้ไม่อยู่ในสถานะที่สามารถแจ้งคืนได้ หรือถูกทำรายการไปแล้ว'];
            }

            $pdo->commit();

            return [
                'success' => true,
                'message' => 'แจ้งส่งคืนครุภัณฑ์เรียบร้อยแล้ว! กรุณารอเจ้าของครุภัณฑ์หรือผู้ดูแลระบบตรวจสอบและกดยืนยันการรับคืน',
                'da_id'   => $record['DA_ID'],
                'da_name' => $record['DA_ListName'] ?? '',
                'da_num'  => $record['DA_Number'] ?? ''
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'message' => 'เกิดข้อผิดพลาดในการแจ้งส่งคืน: ' . $e->getMessage()
            ];
        }
    }

    /**
     * อนุมัติการรับคืนครุภัณฑ์โดยเจ้าของหรือผู้ดูแลระบบ (Approve Return)
     * 
     * @param PDO $pdo
     * @param array $params ข้อมูลการอนุมัติ
     * @return array ผลลัพธ์ ['success' => bool, 'message' => string, 'da_id' => ?string]
     */
    public static function approveReturn(PDO $pdo, array $params): array
    {
        $borrowId       = trim($params['borrow_id'] ?? '');
        $approvedByUid  = trim($params['approved_by_uid'] ?? '');
        $approvedByName = trim($params['approved_by_name'] ?? 'ผู้ดูแลระบบ');
        $approvedByDept = trim($params['approved_by_dept'] ?? '');
        $isSuperAdmin   = (bool)($params['is_super_admin'] ?? false);

        if (empty($borrowId)) {
            return ['success' => false, 'message' => 'ไม่พบรหัสรายการยืม-คืน'];
        }

        $nowTimestamp = date('Y-m-d H:i:s');
        $returnDate   = date('Y-m-d');

        try {
            $pdo->beginTransaction();

            // 1. ตรวจสอบข้อมูลรายการยืมและความเป็นเจ้าของ
            $stmtCheck = $pdo->prepare("
                SELECT b.IDborrow_back, b.DA_ID, b.Date_back, b.status, 
                       da.owner_department, da.owner_useraccountID, da.created_by, da.DA_ListName, da.DA_Number
                FROM borrow_back b
                LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
                WHERE b.IDborrow_back = :id
                LIMIT 1
            ");
            $stmtCheck->execute([':id' => $borrowId]);
            $record = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'ไม่พบข้อมูลรายการยืมนี้ในระบบ'];
            }

            if (($record['status'] ?? '') === 'returned' && empty($params['force'])) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'รายการนี้ได้รับการอนุมัติส่งคืนเรียบร้อยแล้วก่อนหน้านี้'];
            }

            // ตรวจสอบสิทธิ์ความเป็นเจ้าของ:
            // ต้องเป็น Super Admin, หรือเป็นเจ้าของครุภัณฑ์ (owner_useraccountID/created_by),
            // หรือเป็น Admin แผนกเดียวกับครุภัณฑ์, หรือครุภัณฑ์เป็นของส่วนกลาง
            if (!$isSuperAdmin) {
                $isOwner = (!empty($record['owner_useraccountID']) && $record['owner_useraccountID'] === $approvedByUid)
                        || (!empty($record['created_by']) && $record['created_by'] === $approvedByUid)
                        || ($record['owner_department'] === $approvedByDept)
                        || ($record['owner_department'] === 'ส่วนกลาง')
                        || empty($record['owner_department']);
                if (!$isOwner) {
                    $pdo->rollBack();
                    return ['success' => false, 'message' => 'คุณไม่มีสิทธิ์อนุมัติการส่งคืนครุภัณฑ์ของเจ้าของหรือแผนกอื่น'];
                }
            }

            // 2. อัปเดตสถานะในตาราง borrow_back เป็น 'returned' (คืนสำเร็จ)
            $stmtUpdateB = $pdo->prepare("
                UPDATE borrow_back 
                SET Date_back = COALESCE(NULLIF(Date_back, ''), :return_date),
                    timestamp_back = :return_time,
                    status = 'returned'
                WHERE IDborrow_back = :id
            ");
            $stmtUpdateB->execute([
                ':return_date' => $returnDate,
                ':return_time' => $nowTimestamp,
                ':id'          => $borrowId
            ]);

            // 3. ปรับสถานะครุภัณฑ์ในตาราง durable_articles ให้กลับมา 'พร้อมใช้' (s0001) เฉพาะเมื่อไม่มีรายการยืมอื่นค้างอยู่
            $daId = $record['DA_ID'];
            if (!empty($daId)) {
                $stmtCheckOther = $pdo->prepare("
                    SELECT IDborrow_back FROM borrow_back 
                    WHERE DA_ID = :da_id 
                      AND IDborrow_back != :current_id
                      AND (status != 'returned' OR status IS NULL)
                      AND (Date_back IS NULL OR Date_back = '' OR status = 'pending_return')
                    LIMIT 1
                ");
                $stmtCheckOther->execute([':da_id' => $daId, ':current_id' => $borrowId]);
                if (!$stmtCheckOther->fetch()) {
                    $stmtUpdateDA = $pdo->prepare("UPDATE durable_articles SET statusID = 's0001' WHERE DA_ID = :da_id");
                    $stmtUpdateDA->execute([':da_id' => $daId]);
                }
            }

            $pdo->commit();

            return [
                'success' => true,
                'message' => "อนุมัติรับคืนครุภัณฑ์ [{$record['DA_Number']} - {$record['DA_ListName']}] เรียบร้อยแล้ว! รายการพร้อมใช้งานในระบบ",
                'da_id'   => $daId,
                'da_name' => $record['DA_ListName'] ?? '',
                'da_num'  => $record['DA_Number'] ?? ''
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'message' => 'เกิดข้อผิดพลาดในการอนุมัติการรับคืน: ' . $e->getMessage()
            ];
        }
    }

    /**
     * ดำเนินการส่งคืนครุภัณฑ์แบบ Atomic Transaction (Direct Return / Admin Override)
     * 
     * @param PDO $pdo
     * @param array $params ข้อมูลการส่งคืน
     * @return array ผลลัพธ์ ['success' => bool, 'message' => string, 'da_id' => ?string]
     */
    public static function executeReturn(PDO $pdo, array $params): array
    {
        // หากต้องการบังคับให้ผ่านขั้นตอนการอนุมัติก่อน
        $requireApproval = (bool)($params['require_approval'] ?? false);
        if ($requireApproval) {
            return self::requestReturn($pdo, $params);
        }

        // กรณีแอดมินหรือเจ้าของเป็นผู้กดยืนยันคืนโดยตรง
        return self::approveReturn($pdo, [
            'borrow_id'        => $params['borrow_id'] ?? '',
            'approved_by_uid'  => $params['returned_by_uid'] ?? '',
            'approved_by_name' => $params['returned_by_name'] ?? 'ผู้ดูแลระบบ',
            'approved_by_dept' => $params['user_dept'] ?? '',
            'is_super_admin'   => $params['is_super_admin'] ?? false,
            'force'            => true
        ]);
    }

    /**
     * ลบประวัติการยืม-คืน (Delete Borrow History)
     * สิทธิ์เฉพาะผู้ดูแลระบบระดับสูงสุด (Super Admin) เท่านั้น
     * 
     * @param PDO $pdo
     * @param string $borrowId รหัสรายการยืม (IDborrow_back)
     * @param bool $isSuperAdmin สิทธิ์ Super Admin
     * @return array ผลลัพธ์ ['success' => bool, 'message' => string, 'da_id' => ?string]
     */
    public static function deleteBorrowRecord(PDO $pdo, string $borrowId, bool $isSuperAdmin = false): array
    {
        if (!$isSuperAdmin) {
            return [
                'success' => false,
                'message' => 'ไม่อนุญาต: สิทธิ์การลบประวัติการยืม-คืนจำกัดเฉพาะผู้ดูแลระบบระดับสูงสุด (Super Admin) เท่านั้น'
            ];
        }

        if (empty($borrowId)) {
            return [
                'success' => false,
                'message' => 'รหัสรายการไม่ถูกต้อง'
            ];
        }

        try {
            $pdo->beginTransaction();

            // 1. ดึงข้อมูลรายการยืมก่อนลบ เพื่อตรวจสอบ DA_ID และชื่อครุภัณฑ์
            $stmt = $pdo->prepare("SELECT b.IDborrow_back, b.DA_ID, b.status, b.Date_back, da.DA_Number, da.DA_ListName 
                                   FROM borrow_back b
                                   LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
                                   WHERE b.IDborrow_back = :id 
                                   LIMIT 1");
            $stmt->execute([':id' => $borrowId]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'message' => 'ไม่พบข้อมูลรายการยืมที่ต้องการลบในระบบ'
                ];
            }

            $daId     = $record['DA_ID'] ?? '';
            $daNumber = $record['DA_Number'] ?? '-';
            $daName   = $record['DA_ListName'] ?? 'ครุภัณฑ์';

            // 2. ดำเนินการลบออกจาก borrow_back
            $stmtDel = $pdo->prepare("DELETE FROM borrow_back WHERE IDborrow_back = :id");
            $stmtDel->execute([':id' => $borrowId]);

            // ลบจากตาราง borrowing หากมี
            try {
                $pdo->prepare("DELETE FROM borrowing WHERE IDborrow_back = :id")->execute([':id' => $borrowId]);
            } catch (Exception $e) {}

            // 3. ตรวจสอบสถานะครุภัณฑ์: หากไม่มีรายการยืมค้างอยู่อื่นอีก และเดิมถูกยืม (s0003) ให้คืนสถานะเป็น พร้อมใช้งาน (s0001)
            if (!empty($daId)) {
                $stmtCheckOther = $pdo->prepare("
                    SELECT IDborrow_back FROM borrow_back 
                    WHERE DA_ID = :da_id 
                      AND (status != 'returned' OR status IS NULL)
                      AND (Date_back IS NULL OR Date_back = '' OR status = 'pending_return')
                    LIMIT 1
                ");
                $stmtCheckOther->execute([':da_id' => $daId]);
                if (!$stmtCheckOther->fetch()) {
                    $stmtStatus = $pdo->prepare("SELECT statusID FROM durable_articles WHERE DA_ID = :da_id");
                    $stmtStatus->execute([':da_id' => $daId]);
                    $curStatus = $stmtStatus->fetchColumn();
                    if ($curStatus === 's0003') {
                        $stmtUpdateDA = $pdo->prepare("UPDATE durable_articles SET statusID = 's0001' WHERE DA_ID = :da_id");
                        $stmtUpdateDA->execute([':da_id' => $daId]);
                    }
                }
            }

            $pdo->commit();

            return [
                'success' => true,
                'message' => "ลบประวัติการยืม [{$daNumber} - {$daName}] เรียบร้อยแล้ว",
                'da_id'   => $daId
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'message' => 'เกิดข้อผิดพลาดในการลบประวัติ: ' . $e->getMessage()
            ];
        }
    }

    /**
     * อนุมัติคำขอขึ้นทะเบียนครุภัณฑ์ใหม่
     */
    public static function approveAsset(PDO $pdo, string $daId, string $currentUserId, string $currentUserDept, bool $isSuperAdmin): array
    {
        try {
            $pdo->beginTransaction();

            if ($isSuperAdmin) {
                $stmt = $pdo->prepare("UPDATE durable_articles SET is_approved = 1, statusID = 's0001' WHERE DA_ID = :id AND is_approved = 0");
                $stmt->execute([':id' => $daId]);
            } else {
                $stmt = $pdo->prepare("UPDATE durable_articles SET is_approved = 1, statusID = 's0001' 
                                       WHERE DA_ID = :id AND is_approved = 0 
                                         AND (owner_department = :dept OR (owner_department IS NULL AND created_by = :uid))");
                $stmt->execute([':id' => $daId, ':dept' => $currentUserDept, ':uid' => $currentUserId]);
            }

            if ($stmt->rowCount() > 0) {
                $pdo->commit();
                return ['success' => true, 'message' => 'อนุมัติครุภัณฑ์เรียบร้อยแล้ว รายการพร้อมใช้งาน'];
            } else {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'ไม่พบคำขอ หรือคำขอนี้ได้รับการอนุมัติไปแล้ว หรือไม่มีสิทธิ์'];
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * ปฏิเสธคำขอขึ้นทะเบียนครุภัณฑ์
     */
    public static function rejectAsset(PDO $pdo, string $daId, string $currentUserId, string $currentUserDept, bool $isSuperAdmin): array
    {
        try {
            $pdo->beginTransaction();

            if ($isSuperAdmin) {
                $stmt = $pdo->prepare("DELETE FROM durable_articles WHERE DA_ID = :id AND is_approved = 0");
                $stmt->execute([':id' => $daId]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM durable_articles 
                                       WHERE DA_ID = :id AND is_approved = 0 
                                         AND (owner_department = :dept OR (owner_department IS NULL AND created_by = :uid))");
                $stmt->execute([':id' => $daId, ':dept' => $currentUserDept, ':uid' => $currentUserId]);
            }

            if ($stmt->rowCount() > 0) {
                $pdo->commit();
                return ['success' => true, 'message' => 'ปฏิเสธและลบคำขอครุภัณฑ์เรียบร้อยแล้ว'];
            } else {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'ไม่พบคำขอ หรือไม่มีสิทธิ์จัดการคำขอนี้'];
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * ดึงข้อมูลสถิติ แดชบอร์ด กราฟ และกิจกรรมล่าสุด (Single Source of Truth สำหรับ API และ SSE)
     * 
     * @param PDO $pdo
     * @param string $viewMode 'normal' | 'all' | 'hidden'
     * @param string $adminDept แผนกของ Admin
     * @param string $currentUserID รหัส Admin
     * @param bool $isSuperAdmin สถานะ Super Admin
     * @return array ข้อมูลแดชบอร์ดตามสเปก
     */
    public static function getDashboardData(PDO $pdo, string $viewMode = 'normal', string $adminDept = '', string $currentUserID = '', bool $isSuperAdmin = false): array
    {
        $visCondition   = " AND (is_visible = 1 OR is_visible IS NULL)";
        $visConditionDa = " AND (da.is_visible = 1 OR da.is_visible IS NULL)";

        if ($viewMode === 'all') {
            $visCondition   = "";
            $visConditionDa = "";
        } elseif ($viewMode === 'hidden') {
            $visCondition   = " AND is_visible = 0";
            $visConditionDa = " AND da.is_visible = 0";
        }

        $today = date('Y-m-d');

        // 1. ครุภัณฑ์ทั้งหมด (Total Assets)
        if ($isSuperAdmin) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM durable_articles WHERE (is_approved = 1 OR is_approved IS NULL) {$visCondition}");
            $totalDA = (int)$stmt->fetchColumn();
        } else {
            $sqlTotal = "SELECT COUNT(*) FROM durable_articles 
                         WHERE (is_approved = 1 OR is_approved IS NULL) 
                           {$visCondition}
                           AND (owner_department = :dept OR owner_department = 'ส่วนกลาง' OR (owner_department IS NULL AND created_by = :uid))";
            $stmt = $pdo->prepare($sqlTotal);
            $stmt->execute([':dept' => $adminDept, ':uid' => $currentUserID]);
            $totalDA = (int)$stmt->fetchColumn();
        }

        // 2. กำลังถูกยืม (Currently Borrowed)
        if ($isSuperAdmin) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM borrow_back b
                                 INNER JOIN durable_articles da ON b.DA_ID = da.DA_ID
                                 WHERE (b.status != 'returned' AND (b.Date_back IS NULL OR b.Date_back = '' OR b.status = 'pending_return')) {$visConditionDa}");
            $borrowedDA = (int)$stmt->fetchColumn();
        } else {
            $sqlBorrowed = "SELECT COUNT(*) 
                            FROM borrow_back b
                            INNER JOIN durable_articles da ON b.DA_ID = da.DA_ID
                            WHERE (b.status != 'returned' AND (b.Date_back IS NULL OR b.Date_back = '' OR b.status = 'pending_return'))
                              {$visConditionDa}
                              AND (da.owner_department = :dept OR da.owner_department = 'ส่วนกลาง' OR (da.owner_department IS NULL AND da.created_by = :uid))";
            $stmt = $pdo->prepare($sqlBorrowed);
            $stmt->execute([':dept' => $adminDept, ':uid' => $currentUserID]);
            $borrowedDA = (int)$stmt->fetchColumn();
        }

        // 3. เลยกำหนดส่งคืน (Overdue)
        if ($isSuperAdmin) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrow_back b
                                   INNER JOIN durable_articles da ON b.DA_ID = da.DA_ID
                                   WHERE b.status != 'returned' 
                                     AND (b.Date_back IS NULL OR b.Date_back = '' OR b.status = 'pending_return') 
                                     AND b.Deadline_borrow < :today
                                     {$visConditionDa}");
            $stmt->execute([':today' => $today]);
            $overdueDA = (int)$stmt->fetchColumn();
        } else {
            $sqlOverdue = "SELECT COUNT(*) 
                           FROM borrow_back b
                           INNER JOIN durable_articles da ON b.DA_ID = da.DA_ID
                           WHERE b.status != 'returned'
                             AND (b.Date_back IS NULL OR b.Date_back = '' OR b.status = 'pending_return')
                             AND b.Deadline_borrow < :today
                             {$visConditionDa}
                             AND (da.owner_department = :dept OR da.owner_department = 'ส่วนกลาง' OR (da.owner_department IS NULL AND da.created_by = :uid))";
            $stmt = $pdo->prepare($sqlOverdue);
            $stmt->execute([':today' => $today, ':dept' => $adminDept, ':uid' => $currentUserID]);
            $overdueDA = (int)$stmt->fetchColumn();
        }

        // 4. คำขอรออนุมัติขึ้นทะเบียนใหม่ (Pending Requests)
        if ($isSuperAdmin) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM durable_articles WHERE is_approved = 0 {$visCondition}");
            $pendingDA = (int)$stmt->fetchColumn();
        } else {
            $sqlPending = "SELECT COUNT(*) FROM durable_articles 
                           WHERE is_approved = 0 
                             {$visCondition}
                             AND (owner_department = :dept OR owner_department = 'ส่วนกลาง' OR (owner_department IS NULL AND created_by = :uid))";
            $stmt = $pdo->prepare($sqlPending);
            $stmt->execute([':dept' => $adminDept, ':uid' => $currentUserID]);
            $pendingDA = (int)$stmt->fetchColumn();
        }

        // 5. ชำรุด/ไม่พร้อมใช้งาน (Maintenance Status: สถานะ s0002 หรือไม่ใช่พร้อมใช้และไม่ใช่ยืม)
        if ($isSuperAdmin) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM durable_articles 
                                 WHERE (statusID = 's0002' OR (statusID != 's0001' AND statusID != 's0003' AND statusID IS NOT NULL AND statusID != ''))
                                   AND (is_approved = 1 OR is_approved IS NULL) {$visCondition}");
            $maintenanceDA = (int)$stmt->fetchColumn();
        } else {
            $sqlMaint = "SELECT COUNT(*) FROM durable_articles 
                         WHERE (statusID = 's0002' OR (statusID != 's0001' AND statusID != 's0003' AND statusID IS NOT NULL AND statusID != ''))
                           AND (is_approved = 1 OR is_approved IS NULL)
                           {$visCondition}
                           AND (owner_department = :dept OR owner_department = 'ส่วนกลาง' OR (owner_department IS NULL AND created_by = :uid))";
            $stmt = $pdo->prepare($sqlMaint);
            $stmt->execute([':dept' => $adminDept, ':uid' => $currentUserID]);
            $maintenanceDA = (int)$stmt->fetchColumn();
        }

        // คำนวณพร้อมใช้งาน
        $availableDA = max(0, $totalDA - $borrowedDA - $maintenanceDA);

        // 6. สถิติแยกตามหมวดหมู่ (Category Distribution)
        $barLabels = [];
        $barValues = [];

        if ($isSuperAdmin) {
            $sqlBar = "SELECT c.classificationName, COUNT(da.DA_ID) AS total 
                       FROM classification c 
                       INNER JOIN durable_articles da ON c.classificationID = da.classificationID 
                            AND (da.is_approved = 1 OR da.is_approved IS NULL)
                            {$visConditionDa}
                       GROUP BY c.classificationID, c.classificationName 
                       ORDER BY total DESC 
                       LIMIT 6";
            $stmtBar = $pdo->query($sqlBar);
        } else {
            $sqlBar = "SELECT c.classificationName, COUNT(da.DA_ID) AS total 
                       FROM classification c 
                       INNER JOIN durable_articles da ON c.classificationID = da.classificationID 
                            AND (da.is_approved = 1 OR da.is_approved IS NULL)
                            {$visConditionDa}
                            AND (da.owner_department = :dept OR da.owner_department = 'ส่วนกลาง' OR (da.owner_department IS NULL AND da.created_by = :uid))
                       GROUP BY c.classificationID, c.classificationName 
                       ORDER BY total DESC 
                       LIMIT 6";
            $stmtBar = $pdo->prepare($sqlBar);
            $stmtBar->execute([':dept' => $adminDept, ':uid' => $currentUserID]);
        }
        $barRows = $stmtBar->fetchAll(PDO::FETCH_ASSOC);

        foreach ($barRows as $row) {
            $barLabels[] = $row['classificationName'];
            $barValues[] = (int)$row['total'];
        }

        // 7. ความเคลื่อนไหวล่าสุด 5 รายการ (Recent Activities)
        if ($isSuperAdmin) {
            $sqlRecent = "SELECT b.IDborrow_back, b.DA_ID, b.Date_borrow, b.Deadline_borrow, b.Date_back, b.status, 
                                 b.Name_borrow, da.DA_Number, da.DA_ListName, b.timestamp_borrow
                          FROM borrow_back b
                          LEFT JOIN durable_articles da ON b.DA_ID = da.DA_ID
                          WHERE 1=1 {$visConditionDa}
                          ORDER BY b.IDborrow_back DESC
                          LIMIT 5";
            $stmtRecent = $pdo->query($sqlRecent);
        } else {
            $sqlRecent = "SELECT b.IDborrow_back, b.DA_ID, b.Date_borrow, b.Deadline_borrow, b.Date_back, b.status, 
                                 b.Name_borrow, da.DA_Number, da.DA_ListName, b.timestamp_borrow
                          FROM borrow_back b
                          INNER JOIN durable_articles da ON b.DA_ID = da.DA_ID
                          WHERE (da.owner_department = :dept OR da.owner_department = 'ส่วนกลาง' OR (da.owner_department IS NULL AND da.created_by = :uid))
                            {$visConditionDa}
                          ORDER BY b.IDborrow_back DESC
                          LIMIT 5";
            $stmtRecent = $pdo->prepare($sqlRecent);
            $stmtRecent->execute([':dept' => $adminDept, ':uid' => $currentUserID]);
        }
        $rawActivities = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

        $recentActivities = [];
        foreach ($rawActivities as $act) {
            $isReturned = (($act['status'] ?? '') === 'returned' || (!empty($act['Date_back']) && ($act['status'] ?? '') !== 'pending_return'));
            $isPendingReturn = (($act['status'] ?? '') === 'pending_return');
            $isOverdue  = (!$isReturned && !empty($act['Deadline_borrow']) && $act['Deadline_borrow'] < $today);
            
            $recentActivities[] = [
                'IDborrow_back'   => (string)$act['IDborrow_back'],
                'DA_ID'           => (string)$act['DA_ID'],
                'DA_Number'       => $act['DA_Number'] ?? '-',
                'DA_ListName'     => $act['DA_ListName'] ?? 'ไม่ระบุชื่อ',
                'Name_borrow'     => $act['Name_borrow'] ?? 'ไม่ระบุ',
                'Date_borrow'     => $act['Date_borrow'] ?? '-',
                'Deadline_borrow' => $act['Deadline_borrow'] ?? '-',
                'Date_back'       => $act['Date_back'] ?? null,
                'status'          => $act['status'] ?? ($isReturned ? 'returned' : 'borrowed'),
                'isReturned'      => $isReturned,
                'isPendingReturn' => $isPendingReturn,
                'isOverdue'       => $isOverdue
            ];
        }

        return [
            'stats' => [
                'totalDA'       => $totalDA,
                'borrowedDA'    => $borrowedDA,
                'overdueDA'     => $overdueDA,
                'pendingDA'     => $pendingDA,
                'maintenanceDA' => $maintenanceDA,
                'availableDA'   => $availableDA
            ],
            'charts' => [
                'status' => [
                    'labels' => ['พร้อมใช้งาน', 'กำลังถูกยืม', 'ชำรุด / ไม่พร้อมใช้'],
                    'values' => [$availableDA, $borrowedDA, $maintenanceDA]
                ],
                'category' => [
                    'labels' => $barLabels,
                    'values' => $barValues
                ]
            ],
            'recentActivities' => $recentActivities
        ];
    }
}
