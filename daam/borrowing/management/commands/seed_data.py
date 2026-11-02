import random
from datetime import timedelta
from django.core.management.base import BaseCommand
from django.utils import timezone
from faker import Faker

from accounts.models import User
from assets.models import Category, Asset
from borrowing.models import BorrowTransaction


class Command(BaseCommand):
    help = "Seed mock data for DAAM Asset Management System (Users, Categories, Assets, Borrow Transactions)"

    def handle(self, *args, **options):
        self.stdout.write(self.style.NOTICE("🚀 เริ่มต้นสร้างข้อมูลจำลองสำหรับระบบ DAAM..."))
        fake = Faker('th_TH')
        today = timezone.now().date()

        # ---------------------------------------------------------------------
        # 1. สร้างผู้ใช้งานจำลอง (5 Users: 1 Superadmin, 1 Dept Admin, 3 General Users)
        # ---------------------------------------------------------------------
        self.stdout.write("👤 กำลังสร้างบัญชีผู้ใช้งานจำลอง...")
        users_data = [
            {
                'username': 'admin',
                'first_name': 'ผู้ดูแลระบบ',
                'last_name': 'ส่วนกลาง',
                'email': 'admin@ubu.ac.th',
                'department': 'กองบริหารงานทั่วไป (ส่วนกลาง)',
                'role': User.Role.ADMIN,
                'is_staff': True,
                'is_superuser': True,
                'password': 'admin1234password'
            },
            {
                'username': 'officer_somchai',
                'first_name': 'สมชาย',
                'last_name': 'พิทักษ์สินทรัพย์',
                'email': 'somchai.p@ubu.ac.th',
                'department': 'กลุ่มงานพัสดุและครุภัณฑ์',
                'role': User.Role.ADMIN,
                'is_staff': True,
                'is_superuser': False,
                'password': 'password123'
            },
            {
                'username': 'staff_kanya',
                'first_name': 'กัญญา',
                'last_name': 'วงศ์วิชาญ',
                'email': 'kanya.w@ubu.ac.th',
                'department': 'กองบริการการศึกษา',
                'role': User.Role.USER,
                'is_staff': False,
                'is_superuser': False,
                'password': 'password123'
            },
            {
                'username': 'staff_anon',
                'first_name': 'อนันต์',
                'last_name': 'เจริญสุข',
                'email': 'anon.c@ubu.ac.th',
                'department': 'คณะวิทยาศาสตร์',
                'role': User.Role.USER,
                'is_staff': False,
                'is_superuser': False,
                'password': 'password123'
            },
            {
                'username': 'staff_wichai',
                'first_name': 'วิชัย',
                'last_name': 'สิทธิเดช',
                'email': 'wichai.s@ubu.ac.th',
                'department': 'คณะวิศวกรรมศาสตร์',
                'role': User.Role.USER,
                'is_staff': False,
                'is_superuser': False,
                'password': 'password123'
            },
        ]

        created_users = []
        for u in users_data:
            user, created = User.objects.get_or_create(
                username=u['username'],
                defaults={
                    'first_name': u['first_name'],
                    'last_name': u['last_name'],
                    'email': u['email'],
                    'department': u['department'],
                    'role': u['role'],
                    'is_staff': u['is_staff'],
                    'is_superuser': u['is_superuser'],
                }
            )
            if created:
                user.set_password(u['password'])
                user.save()
            created_users.append(user)

        self.stdout.write(self.style.SUCCESS(f"  ✔ สร้างผู้ใช้งานสำเร็จ {len(created_users)} บัญชี (Superadmin: admin / รหัส: admin1234password)"))

        # ---------------------------------------------------------------------
        # 2. สร้างหมวดหมู่ครุภัณฑ์จำลอง (4 Categories)
        # ---------------------------------------------------------------------
        self.stdout.write("📦 กำลังสร้างหมวดหมู่ครุภัณฑ์...")
        categories_data = [
            ('ครุภัณฑ์สำนักงาน', 'โต๊ะทำงาน เก้าอี้ ตู้เอกสาร และเครื่องใช้สำนักงานทั่วไป'),
            ('ครุภัณฑ์คอมพิวเตอร์และระบบเครือข่าย', 'คอมพิวเตอร์ แล็ปท็อป สวิตช์ และอุปกรณ์ไอที'),
            ('ครุภัณฑ์วิทยาศาสตร์และการวิจัย', 'กล้องจุลทรรศน์ เครื่องวัด เครื่องแก้ว และอุปกรณ์ห้องปฏิบัติการ'),
            ('ครุภัณฑ์โสตทัศนูปกรณ์และมัลติมีเดีย', 'โปรเจกเตอร์ ไมโครโฟน ลำโพง และกล้องบันทึกภาพ'),
        ]

        created_categories = {}
        for cat_name, desc in categories_data:
            cat, _ = Category.objects.get_or_create(
                name=cat_name,
                defaults={'description': desc}
            )
            created_categories[cat_name] = cat

        self.stdout.write(self.style.SUCCESS(f"  ✔ สร้างหมวดหมู่ครุภัณฑ์สำเร็จ {len(created_categories)} หมวด"))

        # ---------------------------------------------------------------------
        # 3. สร้างครุภัณฑ์จำลอง 20 รายการ (Assets)
        # ---------------------------------------------------------------------
        self.stdout.write("🏷️ กำลังสร้างครุภัณฑ์จำลอง 20 รายการ...")
        sample_assets = [
            ('6310040000101', 'คอมพิวเตอร์ All-in-One Dell OptiPlex 7490', 'ครุภัณฑ์คอมพิวเตอร์และระบบเครือข่าย'),
            ('6310040000102', 'คอมพิวเตอร์ All-in-One Dell OptiPlex 7490', 'ครุภัณฑ์คอมพิวเตอร์และระบบเครือข่าย'),
            ('6410040000201', 'โน้ตบุ๊ก Lenovo ThinkPad L14 Gen 2', 'ครุภัณฑ์คอมพิวเตอร์และระบบเครือข่าย'),
            ('6410040000202', 'โน้ตบุ๊ก Lenovo ThinkPad L14 Gen 2', 'ครุภัณฑ์คอมพิวเตอร์และระบบเครือข่าย'),
            ('6510040000301', 'เครื่องพิมพ์เลเซอร์มัลติฟังก์ชัน HP LaserJet Pro 400', 'ครุภัณฑ์คอมพิวเตอร์และระบบเครือข่าย'),
            ('6510040000302', 'สวิตช์เครือข่าย Cisco Catalyst 24-Port Gigabit', 'ครุภัณฑ์คอมพิวเตอร์และระบบเครือข่าย'),
            ('6310040000401', 'เครื่องโปรเจกเตอร์ Epson EB-X51 ความสว่าง 3,800 Lumens', 'ครุภัณฑ์โสตทัศนูปกรณ์และมัลติมีเดีย'),
            ('6310040000402', 'เครื่องโปรเจกเตอร์ BenQ MH535 Full HD', 'ครุภัณฑ์โสตทัศนูปกรณ์และมัลติมีเดีย'),
            ('6410040000501', 'ไมโครโฟนไร้สายคู่ Shure BLX288/PG58', 'ครุภัณฑ์โสตทัศนูปกรณ์และมัลติมีเดีย'),
            ('6510040000601', 'กล้องบันทึกภาพวิดีโอ 4K Sony FDR-AX43A', 'ครุภัณฑ์โสตทัศนูปกรณ์และมัลติมีเดีย'),
            ('6310040000730', 'ตู้ล็อกเกอร์เหล็กเก็บของ ขนาด 9 ประตู', 'ครุภัณฑ์สำนักงาน'),
            ('6310040000731', 'ตู้ล็อกเกอร์เหล็กเก็บของ ขนาด 9 ประตู', 'ครุภัณฑ์สำนักงาน'),
            ('6410040000740', 'ตู้เก็บเอกสารเหล็ก 4 ลิ้นชัก รางลูกปืน', 'ครุภัณฑ์สำนักงาน'),
            ('6410040000741', 'เก้าอี้สำนักงานเพื่อสุขภาพ Ergonomic Mesh Chair', 'ครุภัณฑ์สำนักงาน'),
            ('6510040000750', 'โต๊ะประชุมโครงเหล็กพับเก็บได้ ขนาด 180x60 ซม.', 'ครุภัณฑ์สำนักงาน'),
            ('6510040000751', 'เครื่องปรับอากาศชนิดติดผนัง Inverter 18,000 BTU', 'ครุภัณฑ์สำนักงาน'),
            ('6310040000801', 'กล้องจุลทรรศน์สเตอริโอ 2 ตา Olympus CX23', 'ครุภัณฑ์วิทยาศาสตร์และการวิจัย'),
            ('6310040000802', 'เครื่องชั่งดิจิทัลทศนิยม 4 ตำแหน่ง Mettler Toledo', 'ครุภัณฑ์วิทยาศาสตร์และการวิจัย'),
            ('6410040000803', 'เครื่องกวนสารละลายพร้อมให้ความร้อน Magnetic Stirrer Hotplate', 'ครุภัณฑ์วิทยาศาสตร์และการวิจัย'),
            ('6510040000804', 'ตู้ดูดควันไอสารเคมี Fume Hood ขนาด 1.5 เมตร', 'ครุภัณฑ์วิทยาศาสตร์และการวิจัย'),
        ]

        created_assets = []
        for idx, (da_num, a_name, cat_title) in enumerate(sample_assets):
            reg_date = today - timedelta(days=random.randint(60, 900))
            category_obj = created_categories[cat_title]

            initial_status = Asset.Status.AVAILABLE
            if idx in [14, 19]:
                initial_status = Asset.Status.BROKEN

            asset_obj, _ = Asset.objects.get_or_create(
                da_number=da_num,
                defaults={
                    'name': a_name,
                    'category': category_obj,
                    'status': initial_status,
                    'registered_date': reg_date
                }
            )
            created_assets.append(asset_obj)

        self.stdout.write(self.style.SUCCESS(f"  ✔ สร้างครุภัณฑ์สำเร็จ {len(created_assets)} รายการ"))

        # ---------------------------------------------------------------------
        # 4. สร้างประวัติการยืม-คืน 10 รายการ (BorrowTransactions)
        # ---------------------------------------------------------------------
        self.stdout.write("📋 กำลังสร้างประวัติการยืม-คืน 10 รายการ...")
        BorrowTransaction.objects.all().delete()
        general_borrowers = [u for u in created_users if u.role == User.Role.USER]

        # 4.1 รายการที่คืนเรียบร้อยแล้ว (4 รายการ)
        for i in range(4):
            asset = created_assets[i]
            borrower = random.choice(general_borrowers)
            b_date = today - timedelta(days=random.randint(25, 45))
            d_date = b_date + timedelta(days=random.randint(5, 10))
            r_date = d_date - timedelta(days=random.randint(0, 2))

            BorrowTransaction.objects.create(
                asset=asset,
                borrower=borrower,
                borrow_date=b_date,
                due_date=d_date,
                returned_date=r_date,
                status=BorrowTransaction.Status.RETURNED,
                note=f"ขอยืมสำหรับการจัดอบรมเชิงปฏิบัติการ ({fake.sentence()})"
            )
            asset.status = Asset.Status.AVAILABLE
            asset.save()

        # 4.2 รายการที่กำลังยืมอยู่ปัจจุบัน (4 รายการ)
        for i in range(4, 8):
            asset = created_assets[i]
            borrower = random.choice(general_borrowers)
            b_date = today - timedelta(days=random.randint(1, 4))
            d_date = today + timedelta(days=random.randint(5, 14))

            BorrowTransaction.objects.create(
                asset=asset,
                borrower=borrower,
                borrow_date=b_date,
                due_date=d_date,
                returned_date=None,
                status=BorrowTransaction.Status.ACTIVE,
                note=f"ใช้ปฏิบัติงานโครงการพัฒนานวัตกรรมหน่วยงาน ณ ห้องประชุมใหญ่"
            )
            asset.status = Asset.Status.BORROWED
            asset.save()

        # 4.3 รายการที่เลยกำหนดส่งคืน Overdue (2 รายการ)
        for i in range(8, 10):
            asset = created_assets[i]
            borrower = random.choice(general_borrowers)
            b_date = today - timedelta(days=random.randint(30, 60))
            d_date = today - timedelta(days=random.randint(7, 20))

            BorrowTransaction.objects.create(
                asset=asset,
                borrower=borrower,
                borrow_date=b_date,
                due_date=d_date,
                returned_date=None,
                status=BorrowTransaction.Status.OVERDUE,
                note=f"ยืมสำหรับการทดสอบภาคสนาม ติดภารกิจด่วน กำลังประสานงานติดต่อส่งคืน"
            )
            asset.status = Asset.Status.BORROWED
            asset.save()

        self.stdout.write(self.style.SUCCESS("  ✔ สร้างประวัติการยืม-คืน 10 รายการสำเร็จ (Returned: 4, Active: 4, Overdue: 2)"))
        self.stdout.write(self.style.SUCCESS("\n🎉 [เสร็จสมบูรณ์] ข้อมูลจำลองทั้งหมดถูก Seed เข้าสู่ฐานข้อมูลเรียบร้อยแล้ว!"))
        self.stdout.write("==================================================================")
        self.stdout.write("🔑 บัญชี Superadmin สำหรับเข้า Django Admin:")
        self.stdout.write("   - URL:      http://127.0.0.1:8000/admin/")
        self.stdout.write("   - Username: admin")
        self.stdout.write("   - Password: admin1234password")
        self.stdout.write("==================================================================")
