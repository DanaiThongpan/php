from django.conf import settings
from django.core.exceptions import ValidationError
from django.db import models
from django.utils import timezone
from django.utils.translation import gettext_lazy as _
from assets.models import Asset, TimeStampedModel


class BorrowTransaction(TimeStampedModel):
    """
    Model บันทึกรายการยืม-คืนครุภัณฑ์ (Borrow & Return Transaction)
    เชื่อมโยงระหว่างผู้ยืม (User) และครุภัณฑ์ (Asset)
    """
    class Status(models.TextChoices):
        PENDING  = 'pending', _('รออนุมัติ (Pending)')
        ACTIVE   = 'active', _('กำลังยืม (Active)')
        RETURNED = 'returned', _('คืนเรียบร้อยแล้ว (Returned)')
        OVERDUE  = 'overdue', _('เลยกำหนดส่งคืน (Overdue)')

    asset = models.ForeignKey(
        Asset,
        on_delete=models.PROTECT,
        related_name='borrow_transactions',
        verbose_name=_('ครุภัณฑ์ที่ยืม')
    )
    borrower = models.ForeignKey(
        settings.AUTH_USER_MODEL,
        on_delete=models.PROTECT,
        related_name='borrow_transactions',
        verbose_name=_('ผู้ทำรายการยืม')
    )
    borrow_date = models.DateField(
        default=timezone.now,
        verbose_name=_('วันที่เริ่มยืม')
    )
    due_date = models.DateField(
        verbose_name=_('กำหนดส่งคืน (Deadline)')
    )
    returned_date = models.DateField(
        null=True,
        blank=True,
        verbose_name=_('วันที่ส่งคืนจริง')
    )
    status = models.CharField(
        max_length=20,
        choices=Status.choices,
        default=Status.ACTIVE,
        db_index=True,
        verbose_name=_('สถานะรายการยืม')
    )
    note = models.TextField(
        blank=True,
        default='',
        verbose_name=_('หมายเหตุ / วัตถุประสงค์การใช้งาน')
    )

    class Meta:
        db_table = 'borrow_transactions'
        verbose_name = _('รายการยืม-คืนครุภัณฑ์')
        verbose_name_plural = _('ประวัติการยืม-คืนทั้งหมด')
        ordering = ['-borrow_date', '-created_at']

    def __str__(self):
        return f"รายการยืม #{self.id} | {self.asset.name} โดย {self.borrower.username} [{self.get_status_display()}]"

    def clean(self):
        if self.due_date and self.borrow_date and self.due_date < self.borrow_date:
            raise ValidationError({'due_date': _('กำหนดส่งคืนต้องไม่เกิดขึ้นก่อนวันที่เริ่มยืม')})

    @property
    def calculate_is_overdue(self) -> bool:
        if self.status == self.Status.RETURNED or self.returned_date:
            return False
        return self.due_date < timezone.now().date()
