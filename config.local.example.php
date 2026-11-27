<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — CONTOH KONFIGURASI PRODUKSI (SALIN KE config.local.php)
 * ==============================================================================
 * File `config.local.php` otomatis diabaikan Git (.gitignore) dan diblokir dari
 * akses web (.htaccess). Gunakan untuk menyimpan kredensial DB cPanel & token WA.
 * ==============================================================================
 */

// 1. Kredensial MySQL cPanel Hosting
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'uofruaju_wa_ilmuna');
define('DB_USER', 'uofruaju_wa_ilmuna');
define('DB_PASS', 'ISI_PASSWORD_DB_CPANEL');

// 2. Mode Transport WA ('cloud_api' | 'md_bridge' | 'hybrid')
define('WA_DRIVER', 'hybrid');

// 3. Meta WhatsApp Cloud API Resmi (100% Pure PHP Apache, tanpa Node.js)
define('WA_CLOUD_PHONE_NUMBER_ID', '');
define('WA_CLOUD_ACCESS_TOKEN', '');
define('WA_CLOUD_VERIFY_TOKEN', 'ilmuna_wa_verify_2026');
define('WA_CLOUD_APP_SECRET', '');

// 4. Multi-Device HTTP Gateway / Bridge ('whapi' | 'fonnte' | 'gowa' | 'waha' | 'generic')
define('WA_BRIDGE_FLAVOR', 'whapi');
define('WA_BRIDGE_BASE_URL', 'https://gate.whapi.cloud');
define('WA_BRIDGE_API_KEY', '');
define('WA_BRIDGE_WEBHOOK_SECRET', 'ganti_secret_webhook_kuat_produksi');

// 5. Integrasi Dua Arah dengan Ilmuna Learn (Produksi)
define('LEARN_API_BASE_URL', 'https://learn.ilmuna.my.id');
define('LEARN_PUBLIC_URL', 'https://learn.ilmuna.my.id');
define('WA_BRIDGE_PUBLIC_KEY', 'pk_wa_ilmuna_2026_bridge');
define('LEARN_HMAC_SECRET', 'sk_wa_ilmuna_2026_hmac_shared_secret');

// 6. Identitas Sekolah & Kontak Admin
define('SCHOOL_NAME', 'Ilmuna Digital School');
define('SCHOOL_ADMIN_PHONE', '6281200000000');
