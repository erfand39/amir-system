# امیر سیستم | Amir System

فروشگاه تجهیزات صوتی خودرو و پنل مدیریت سفارش و انبار. نسخهٔ **۲٫۵٫۰** با PHP و MariaDB یا MySQL روی هاست لینوکس اجرا می‌شود. راهنمای استقرار اصلی برای cPanel و دیتابیس روی همان هاست است.

| بخش | مسیر |
| --- | --- |
| نصب تازه و ارتقای فروشگاه فعلی | [راهنمای نصب cPanel](INSTALL-CPANEL.fa.md) |
| تغییرات و نتیجهٔ آزمون‌ها | [گزارش نسخهٔ ۲٫۵٫۰](docs/REPORT-2.5.0.fa.md) |
| قالب تنظیمات سرور | [`.env.example`](.env.example) |
| سورس سرور و رابط | [`php/`](php/) و [`public/`](public/) |
| دیتابیس و آزمون‌ها | [`database/`](database/) و [`tests/`](tests/) |

## نصب و ارتقا

- **نصب تازه:** در دیتابیس خالی، [ساختار](database/01_schema.sql) و سپس [کاتالوگ اولیه](database/02_catalog.sql) را وارد کنید.
- **ارتقا از نسخهٔ ۲٫۴٫۰:** پس از پشتیبان‌گیری، اگر `schema_version` برابر `2` است، [اسکریپت ارتقا](database/05_upgrade_2_to_3.sql) را اجرا کنید و فایل `.env` فعلی را نگه دارید. سپس [ارتقا از ساختار ۳ به ۴](database/06_upgrade_3_to_4.sql) را اجرا کنید.
- **ارتقا از ساختار ۳:** پس از پشتیبان‌گیری، فقط [ارتقا از ساختار ۳ به ۴](database/06_upgrade_3_to_4.sql) را اجرا کنید.
- **ارتقا به ساختار ۵:** پس از رسیدن به ساختار ۴، [اسکریپت مرجوعی اقلام](database/07_upgrade_4_to_5.sql) را اجرا کنید.

## اجرای آزمون محلی

PHP 8.2 یا بالاتر با `pdo_sqlite` و `mbstring` لازم است.

```bash
php tests/php-workflow.php
php tests/php-acceptance.php
php tests/php-contract.php
php tests/php-health.php
php tests/php-security.php
python3 tests/http-workflow.py
python3 tests/http-security.py
```

اطلاعات اتصال و کلیدهای واقعی در فایل محلی `.env` نگهداری می‌شوند. پرداخت آنلاین در آزمون‌ها با پاسخ شبیه‌سازی‌شده بررسی شده است.
