from django.db import models
from django.utils import timezone
from django.utils.translation import gettext_lazy as _


class TimeStampedModel(models.Model):
    """
    Abstract Base Model สำหรับบันทึก Timestamp เพื่อรองรับ System Migration และ Audit Trail
    """
    created_at = models.DateTimeField(auto_now_add=True, verbose_name=_('วันที่สร้างข้อมูล'))
    updated_at = models.DateTimeField(auto_now=True, verbose_name=_('วันที่แก้ไขข้อมูลล่าสุด'))

    class Meta:
        abstract = True


class Category(TimeStampedModel):
    """
    Model หมวดหมู่ครุภัณฑ์ เช่น ครุภัณฑ์สำนักงาน, ครุภัณฑ์คอมพิวเตอร์
    """
    name = models.CharField(
        max_length=150,
        unique=True,
        verbose_name=_('ชื่อหมวดหมู่ครุภัณฑ์'),
        help_text=_('ระบุชื่อหมวดหมู่ เช่น ครุภัณฑ์สำนักงาน, ครุภัณฑ์วิทยาศาสตร์')
    )
    description = models.TextField(
        blank=True,
        default='',
        verbose_name=_('คำอธิบาย/รายละเอียดหมวดหมู่')
    )

    class Meta:
        db_table = 'asset_categories'
        verbose_name = _('หมวดหมู่ครุภัณฑ์')
        verbose_name_plural = _('หมวดหมู่ครุภัณฑ์ทั้งหมด')
        ordering = ['name']

    def __str__(self):
        return self.name


class Asset(TimeStampedModel):
    """
    Model รายการครุภัณฑ์ (Asset Item)
    เก็บรหัสครุภัณฑ์, สถานะ, หมวดหมู่ และประวัติ
    """
    class Status(models.TextChoices):
        AVAILABLE = 'available', _('พร้อมใช้งาน (Available)')
        BORROWED  = 'borrowed', _('กำลังถูกยืม (Borrowed)')
        BROKEN    = 'broken', _('ชำรุด/ส่งซ่อม (Broken)')

    da_number = models.CharField(
        max_length=60,
        unique=True,
        db_index=True,
        verbose_name=_('หมายเลขครุภัณฑ์ (DA Number)'),
        help_text=_('รหัสครุภัณฑ์ตามระบบพัสดุ ห้ามซ้ำกัน เช่น 6310040000730')
    )
    name = models.CharField(
        max_length=255,
        verbose_name=_('ชื่อรายการครุภัณฑ์'),
        help_text=_('ระบุชื่อรายการครุภัณฑ์ ยี่ห้อ หรือรุ่น')
    )
    category = models.ForeignKey(
        Category,
        on_delete=models.PROTECT,
        related_name='assets',
        verbose_name=_('หมวดหมู่ครุภัณฑ์')
    )
    status = models.CharField(
        max_length=20,
        choices=Status.choices,
        default=Status.AVAILABLE,
        db_index=True,
        verbose_name=_('สถานะครุภัณฑ์')
    )
    image = models.ImageField(
        upload_to='assets/%Y/%m/',
        blank=True,
        null=True,
        verbose_name=_('รูปภาพครุภัณฑ์')
    )
    registered_date = models.DateField(
        default=timezone.now,
        verbose_name=_('วันที่ลงทะเบียนครุภัณฑ์')
    )

    class Meta:
        db_table = 'asset_items'
        verbose_name = _('ครุภัณฑ์')
        verbose_name_plural = _('ข้อมูลครุภัณฑ์ทั้งหมด')
        ordering = ['-created_at']

    def __str__(self):
        return f"{self.da_number} - {self.name} [{self.get_status_display()}]"

    @property
    def is_available_for_borrow(self) -> bool:
        """ตรวจสอบว่าครุภัณฑ์พร้อมให้ยืมหรือไม่"""
        return self.status == self.Status.AVAILABLE
