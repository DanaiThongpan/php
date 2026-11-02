from django.contrib import admin
from django.contrib.auth.admin import UserAdmin
from django.utils.translation import gettext_lazy as _
from .models import User


@admin.register(User)
class CustomUserAdmin(UserAdmin):
    model = User
    list_display = (
        'username', 'get_full_name_display', 'department', 
        'role', 'email', 'is_staff', 'is_active', 'created_at'
    )
    list_filter = ('role', 'department', 'is_staff', 'is_superuser', 'is_active', 'created_at')
    search_fields = ('username', 'first_name', 'last_name', 'email', 'department')
    ordering = ('-created_at',)
    readonly_fields = ('created_at', 'updated_at')

    fieldsets = UserAdmin.fieldsets + (
        (_('ข้อมูลสังกัดและสิทธิ์ในระบบ (DAAM Profile)'), {'fields': ('department', 'role')}),
        (_('ประวัติการบันทึก (Audit Trail)'), {'fields': ('created_at', 'updated_at'), 'classes': ('collapse',)}),
    )

    add_fieldsets = UserAdmin.add_fieldsets + (
        (_('ข้อมูลสังกัดและสิทธิ์เพิ่มเติม'), {'fields': ('first_name', 'last_name', 'email', 'department', 'role')}),
    )

    @admin.display(description=_('ชื่อ - นามสกุล'))
    def get_full_name_display(self, obj):
        return obj.get_full_name() or '-'
