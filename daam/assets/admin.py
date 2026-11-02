from django.contrib import admin
from django.utils.html import format_html
from django.utils.translation import gettext_lazy as _
from .models import Category, Asset


@admin.register(Category)
class CategoryAdmin(admin.ModelAdmin):
    """
    Admin Configuration สำหรับหมวดหมู่ครุภัณฑ์
    """
    list_display = (
        'name',
        'get_asset_count',
        'description',
        'created_at',
        'updated_at'
    )
    search_fields = ('name', 'description')
    ordering = ('name',)
    readonly_fields = ('created_at', 'updated_at')

    @admin.display(description=_('จำนวนครุภัณฑ์ในหมวด'))
    def get_asset_count(self, obj):
        return obj.assets.count()


@admin.register(Asset)
class AssetAdmin(admin.ModelAdmin):
    """
    Admin Configuration สำหรับรายการครุภัณฑ์
    """
    list_display = (
        'da_number',
        'name',
        'category',
        'get_status_badge',
        'registered_date',
        'created_at'
    )
    list_filter = (
        'status',
        'category',
        'registered_date',
        'created_at'
    )
    search_fields = (
        'da_number',
        'name',
        'category__name'
    )
    ordering = ('-created_at',)
    readonly_fields = ('created_at', 'updated_at')
    autocomplete_fields = ('category',)

    fieldsets = (
        (_('ข้อมูลหลักของครุภัณฑ์'), {
            'fields': ('da_number', 'name', 'category', 'status', 'registered_date')
        }),
        (_('ไฟล์สื่อ (Media)'), {
            'fields': ('image',)
        }),
        (_('ประวัติการบันทึก (Audit Trail)'), {
            'fields': ('created_at', 'updated_at'),
            'classes': ('collapse',)
        }),
    )

    @admin.display(description=_('สถานะ'))
    def get_status_badge(self, obj):
        color_map = {
            Asset.Status.AVAILABLE: '#10b981',  # เขียว
            Asset.Status.BORROWED:  '#3b82f6',  # น้ำเงิน
            Asset.Status.BROKEN:    '#ef4444',  # แดง
        }
        bg_color = color_map.get(obj.status, '#64748b')
        return format_html(
            '<span style="background-color: {}; color: #ffffff; padding: 3px 8px; border-radius: 12px; font-weight: bold; font-size: 11px;">{}</span>',
            bg_color,
            obj.get_status_display()
        )
