from django.contrib import admin
from django.urls import path
from django.conf import settings
from django.conf.urls.static import static

# Custom Admin Site Branding
admin.site.site_header = "ระบบบริหารจัดการครุภัณฑ์ (DAAM Asset Management)"
admin.site.site_title = "DAAM Admin Portal"
admin.site.index_title = "ระบบจัดการฐานข้อมูลครุภัณฑ์และประวัติการยืม-คืน"

urlpatterns = [
    path('admin/', admin.site.urls),
]

if settings.DEBUG:
    urlpatterns += static(settings.MEDIA_URL, document_root=settings.MEDIA_ROOT)
