from django.contrib import admin
from django.utils.html import format_html
from django.utils.translation import gettext_lazy as _
from .models import BorrowTransaction


@admin.register(BorrowTransaction)
class BorrowTransactionAdmin(admin.ModelAdmin):
    """
    Admin Configuration สำหรับประวัติการยืม-คืนครุภัณฑ์
    """
    list_display = (
        'id',
        'get_asset_info',
        'get_borrower_info',
        'borrow_date',
        'due_date',
        'returned_date',
        'get_status_badge',
        'created_at'
    )
    list_filter = (
        'status',
        'borrow_date',
        'due_date',
        'returned_date',
        'asset__category'
    )
    search_fields = (
        'asset__da_number',
        'asset__name',
        'borrower__username',
        'borrower__first_name',
        'borrower__last_name',
        'borrower__department',
        'note'
    )
    ordering = ('-borrow_date', '-created_at')
    readonly_fields = ('created_at', 'updated_at')
    autocomplete_fields = ('asset', 'borrower')

    fieldsets = (
        (_('ข้อมูลการยืม-คืน'), {
            'fields': ('asset', 'borrower', 'status', 'note')
        }),
        (_('กำหนดเวลา (Timeline)'), {
            'fields': ('borrow_date', 'due_date', 'returned_date')
        }),
        (_('ประวัติการบันทึก (Audit Trail)'), {
            'fields': ('created_at', 'updated_at'),
            'classes': ('collapse',)
        }),
    )

    @admin.display(description=_('ครุภัณฑ์'), ordering='asset__name')
    def get_asset_info(self, obj):
        return f"{obj.asset.da_number} - {obj.asset.name}"

    @admin.display(description=_('ผู้ยืม'), ordering='borrower__username')
    def get_borrower_info(self, obj):
        name = obj.borrower.get_full_name() or obj.borrower.username
        dept = f" ({obj.borrower.department})" if obj.borrower.department else ""
        return f"{name}{dept}"

    @admin.display(description=_('สถานะการยืม'), ordering='status')
    def get_status_badge(self, obj):
        color_map = {
            BorrowTransaction.Status.PENDING:  '#f59e0b',  # ส้มเหลือง
            BorrowTransaction.Status.ACTIVE:   '#3b82f6',  # น้ำเงิน
            BorrowTransaction.Status.RETURNED: '#10b981',  # เขียว
            BorrowTransaction.Status.OVERDUE:  '#ef4444',  # แดง
        }
        bg_color = color_map.get(obj.status, '#64748b')
        return format_html(
            '<span style="background-color: {}; color: #ffffff; padding: 4px 10px; border-radius: 12px; font-weight: bold; font-size: 11px;">{}</span>',
            bg_color,
            obj.get_status_display()
        )
