ALTER TABLE useraccount
  ADD COLUMN userRank VARCHAR(255) NULL AFTER useraccountName,
  ADD COLUMN userDepartment VARCHAR(255) NULL AFTER userRank;

-- ลบคอลัมน์ที่ซ้ำซ้อนในตาราง borrow_back เนื่องจากสามารถดึงข้อมูล (Join) 
-- ตำแหน่งและสังกัด จากตาราง useraccount ได้โดยตรงผ่าน useraccountID_borrow
ALTER TABLE borrow_back
  DROP COLUMN Rank_borrow,
  DROP COLUMN Belongto_borrow;
