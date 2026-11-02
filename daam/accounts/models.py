from django.contrib.auth.models import AbstractUser
from django.db import models
from django.utils.translation import gettext_lazy as _


class User(AbstractUser):
    """
    Custom User Model สำหรับระบบบริหารจัดการครุภัณฑ์
    รองรับการเชื่อมโยงกับข้อมูลผู้ใช้จริงในระบบ (useraccount)
    """
    class Role(models.TextChoices):
        ADMIN = 'admin', _('ผู้ดูแลระบบ (Admin)')
        USER  = 'user', _('ผู้ใช้งานทั่วไป (User)')

    department = models.CharField(
        max_length=150,
        blank=True,
        default='',
        verbose_name=_('แผนก/สังกัด/สาขาวิชา')
    )
    rank = models.CharField(
        max_length=100,
        blank=True,
        default='',
        verbose_name=_('ตำแหน่ง/ระดับ')
    )
    role = models.CharField(
        max_length=20,
        choices=Role.choices,
        default=Role.USER,
        verbose_name=_('บทบาท/สิทธิ์การใช้งาน')
    )

    created_at = models.DateTimeField(auto_now_add=True, verbose_name=_('วันที่สร้างบัญชี'))
    updated_at = models.DateTimeField(auto_now=True, verbose_name=_('วันที่แก้ไขล่าสุด'))

    class Meta:
        db_table = 'auth_custom_user'
        verbose_name = _('ผู้ใช้งาน')
        verbose_name_plural = _('ข้อมูลผู้ใช้งานทั้งหมด')
        ordering = ['username']

    def __str__(self):
        full_name = self.get_full_name()
        display_name = f"{self.username} - {full_name}" if full_name else self.username
        if self.department:
            display_name += f" ({self.department})"
        return display_name

    @property
    def is_admin_role(self) -> bool:
        return self.role == self.Role.ADMIN or self.is_superuser or self.is_staff
