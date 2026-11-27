<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — SINGLETON PDO & AUTO-MIGRATION ENGINE
 * ==============================================================================
 * Database : uofruaju_wa (MySQL 8.0+ / MariaDB 10.4+, InnoDB, utf8mb4)
 * Skema    : 14 tabel resmi dari DATABASE_SCHEMA.sql + seed menu & kamus awal
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/config.php';
}

final class WaDatabase
{
    private static ?PDO $instance = null;
    private static bool $autoMigrated = false;

    private function __construct() {}

    /**
     * Ambil koneksi PDO singleton (otomatis buat DB & jalankan migrasi jika kosong)
     */
    public static function getConnection(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+07:00'";
        }

        try {
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Jika database belum dibuat, coba buat otomatis lalu sambungkan ulang
            $dsnNoDb = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
            try {
                $bootstrap = new PDO($dsnNoDb, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 3,
                ]);
                $dbNameSafe = str_replace('`', '', DB_NAME);
                $bootstrap->exec(
                    "CREATE DATABASE IF NOT EXISTS `{$dbNameSafe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                );
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e2) {
                // Fallback lokal Laragon/XAMPP default root (hanya di localhost/CLI)
                $httpHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
                $isLocalEnv = (PHP_SAPI === 'cli')
                    || in_array($httpHost, ['localhost', '127.0.0.1', 'wa-bot.test'], true)
                    || strpos($httpHost, 'localhost:') === 0
                    || strpos($httpHost, '127.0.0.1:') === 0;

                if (!$isLocalEnv) {
                    throw $e;
                }

                $localDsnNoDb = 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
                $bootstrap = new PDO($localDsnNoDb, 'root', '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 3,
                ]);
                $dbNameSafe = str_replace('`', '', DB_NAME);
                $bootstrap->exec(
                    "CREATE DATABASE IF NOT EXISTS `{$dbNameSafe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
                );
                self::$instance = new PDO(
                    "mysql:host=127.0.0.1;port=3306;dbname={$dbNameSafe};charset=utf8mb4",
                    'root',
                    '',
                    $options
                );
            }
        }

        if (!self::$autoMigrated) {
            self::$autoMigrated = true;
            self::autoInitializeIfEmpty(self::$instance);
        }

        return self::$instance;
    }

    /**
     * Reset singleton (berguna untuk unit/CLI test)
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$autoMigrated = false;
    }

    /**
     * Auto-migrasi ringan jika tabel inti belum tersedia
     */
    private static function autoInitializeIfEmpty(PDO $pdo): void
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'wa_button_menus'");
            $exists = (bool)$stmt->fetchColumn();
            if (!$exists) {
                runWaDatabaseMigration($pdo, true);
            }
        } catch (Throwable $e) {
            // Biarkan caller menangani bila perlu
        }
    }
}

/**
 * Helper global standar ekosistem Ilmuna (seragam dengan learn/config.php)
 */
function getDBConnection(): PDO
{
    return WaDatabase::getConnection();
}

/**
 * Daftar 14 tabel resmi Ilmuna WA Bot sesuai DATABASE_SCHEMA.sql
 */
function getOfficialWaTables(): array
{
    return [
        'wa_settings',
        'wa_devices',
        'wa_contacts_cache',
        'wa_sessions',
        'wa_button_menus',
        'wa_groups',
        'wa_group_rules',
        'wa_violation_logs',
        'wa_call_logs',
        'wa_broadcast_campaigns',
        'wa_broadcast_queue',
        'wa_message_logs',
        'wa_webhook_events',
        'wa_learn_sync_logs',
    ];
}

/**
 * Pecah isi file SQL menjadi statement individual secara aman terhadap string literal
 */
function wa_split_sql_statements(string $sql): array
{
    // Hapus komentar satu baris (-- ... dan # ...)
    $lines = preg_split('/\R/', $sql) ?: [];
    $cleaned = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $cleaned[] = $line;
    }
    $sql = implode("\n", $cleaned);

    $statements = [];
    $buffer = '';
    $inSingle = false;
    $inDouble = false;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if ($ch === "'" && !$inDouble && $prev !== '\\') {
            $inSingle = !$inSingle;
        } elseif ($ch === '"' && !$inSingle && $prev !== '\\') {
            $inDouble = !$inDouble;
        }

        if ($ch === ';' && !$inSingle && !$inDouble) {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
            continue;
        }
        $buffer .= $ch;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

/**
 * Jalankan migrasi skema DATABASE_SCHEMA.sql + seed menu tombol & kamus kata terlarang (100% idempoten)
 */
function runWaDatabaseMigration(PDO $pdo, bool $seedDefaults = true): array
{
    $schemaFile = BASE_PATH . '/DATABASE_SCHEMA.sql';
    if (!is_file($schemaFile)) {
        throw new RuntimeException('File DATABASE_SCHEMA.sql tidak ditemukan di ' . $schemaFile);
    }

    $sql = (string)file_get_contents($schemaFile);
    $statements = wa_split_sql_statements($sql);

    $executedDdl = 0;
    foreach ($statements as $stmt) {
        $upper = strtoupper(ltrim($stmt));
        // Skip INSERT ke wa_group_rules di file SQL karena tidak punya unique key; ditangani idempoten di seedDefaultWaData()
        if (str_starts_with($upper, 'INSERT') && stripos($stmt, 'wa_group_rules') !== false) {
            continue;
        }
        $pdo->exec($stmt);
        $executedDdl++;
    }

    $seedStats = [];
    if ($seedDefaults) {
        $seedStats = seedDefaultWaData($pdo);
    }

    $existingTables = [];
    $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    foreach ($rows as $row) {
        $existingTables[] = (string)$row[0];
    }

    $official = getOfficialWaTables();
    $missing = array_values(array_diff($official, $existingTables));

    return [
        'executed_statements' => $executedDdl,
        'tables_expected'     => count($official),
        'tables_present'      => count($official) - count($missing),
        'missing_tables'      => $missing,
        'seed'                => $seedStats,
    ];
}

/**
 * Seed data awal secara 100% idempoten:
 * - Pengaturan global tambahan di wa_settings
 * - Master menu tombol interaktif default per role di wa_button_menus
 * - Kamus kata terlarang & aturan grup default di wa_group_rules
 * - Profil device awal di wa_devices (jika tabel masih kosong)
 */
function seedDefaultWaData(PDO $pdo): array
{
    $stats = [
        'settings_seeded' => 0,
        'menus_seeded'    => 0,
        'rules_seeded'    => 0,
        'devices_seeded'  => 0,
    ];

    // 1. Pengaturan tambahan di wa_settings
    $extraSettings = [
        'wa_driver'                    => WA_DRIVER,
        'school_name'                  => SCHOOL_NAME,
        'school_admin_phone'           => SCHOOL_ADMIN_PHONE,
        'learn_api_base_url'           => LEARN_API_BASE_URL,
        'learn_public_url'             => LEARN_PUBLIC_URL,
        'group_guard_global_enabled'   => '1',
        'session_ttl_minutes'          => '60',
        'anti_call_enabled'            => '1',
        'anti_call_reply_text'         => 'Mohon maaf, nomor WhatsApp resmi sekolah ini hanya melayani pesan teks & tombol interaktif dan tidak dapat menerima panggilan suara/video. Silakan pilih menu di bawah ini.',
        'anti_call_spam_limit'         => '3',
        'anti_call_spam_window_minutes'=> '5',
        'broadcast_delay_min_sec'      => '3',
        'broadcast_delay_max_sec'      => '7',
        'broadcast_batch_size'         => '25',
        'group_max_strike_kick'        => '3',
    ];

    $stmtSetting = $pdo->prepare(
        'INSERT IGNORE INTO wa_settings (setting_key, setting_value) VALUES (:k, :v)'
    );
    foreach ($extraSettings as $k => $v) {
        $stmtSetting->execute([':k' => $k, ':v' => $v]);
        $stats['settings_seeded'] += $stmtSetting->rowCount();
    }

    // 2. Master Menu Tombol Interaktif Default per Role (Zero Manual Typing)
    $defaultMenus = [
        [
            'menu_code'   => 'MENU_ORANG_TUA_MAIN',
            'target_role' => 'orang_tua',
            'ui_type'     => 'buttons',
            'header_text' => 'Portal Orang Tua · Ilmuna Learn',
            'body_text'   => "Assalamu'alaikum Bapak/Ibu *{nama}*.\nMemantau ananda: *{nama_anak}* ({kelas}).\n\nSilakan pilih layanan melalui tombol di bawah ini:",
            'footer_text' => 'Ilmuna Learn · Asisten Resmi Sekolah',
            'buttons'     => [
                ['id' => 'MENU_ORTU_NILAI',   'title' => '📊 Nilai & Tugas'],
                ['id' => 'MENU_ORTU_ABSENSI', 'title' => '📅 Absensi & Izin'],
                ['id' => 'MENU_ORTU_BK',      'title' => '⚠️ Catatan BK'],
            ],
        ],
        [
            'menu_code'   => 'MENU_ORANG_TUA_LIST',
            'target_role' => 'orang_tua',
            'ui_type'     => 'list',
            'header_text' => 'Menu Lengkap Orang Tua',
            'body_text'   => "Pilih informasi akademik atau pembinaan ananda *{nama_anak}* ({kelas}):",
            'footer_text' => 'Ilmuna Learn · Tanpa Ketik Manual',
            'buttons'     => [
                ['id' => 'MENU_ORTU_NILAI',      'title' => '📊 Nilai & Tugas',    'description' => 'Lihat tugas aktif, proyek, dan ringkasan nilai'],
                ['id' => 'MENU_ORTU_ABSENSI',    'title' => '📅 Absensi & Izin',   'description' => 'Rekap kehadiran harian dan riwayat perizinan'],
                ['id' => 'MENU_ORTU_BK',         'title' => '⚠️ Catatan BK',       'description' => 'Pantau poin disiplin dan catatan pembinaan BK'],
                ['id' => 'MENU_ORTU_AGENDA',     'title' => '🗓️ Agenda & Tenggat', 'description' => 'Jadwal tugas, proyek, asesmen, dan kaldik'],
                ['id' => 'MENU_ORTU_GANTI_ANAK', 'title' => '🔄 Ganti Anak',       'description' => 'Beralih ke anak lain yang terdaftar'],
                ['id' => 'MENU_ORTU_PORTAL',     'title' => '🌐 Buka Portal Ortu', 'description' => 'Akses dasbor web Ilmuna Learn Orang Tua'],
            ],
        ],
        [
            'menu_code'   => 'MENU_SISWA_MAIN',
            'target_role' => 'siswa',
            'ui_type'     => 'buttons',
            'header_text' => 'Portal Siswa · Ilmuna Learn',
            'body_text'   => "Assalamu'alaikum *{nama}* ({kelas}) 👋\nPilih menu belajar melalui tombol di bawah ini:",
            'footer_text' => 'Ilmuna Learn · Asisten Belajar Resmi',
            'buttons'     => [
                ['id' => 'MENU_SISWA_TUGAS',    'title' => '📚 Tugas & Proyek'],
                ['id' => 'MENU_SISWA_PRESENSI', 'title' => '✅ Rekap Presensi'],
                ['id' => 'MENU_SISWA_IZIN',     'title' => '📩 Status Perizinan'],
            ],
        ],
        [
            'menu_code'   => 'MENU_SISWA_CTA',
            'target_role' => 'siswa',
            'ui_type'     => 'cta_url',
            'header_text' => 'Akses Portal Ilmuna Learn',
            'body_text'   => 'Tekan tombol di bawah ini untuk langsung membuka kelas digital, tugas, CBT, dan Mutaba\'ah Yaumiyah di Ilmuna Learn.',
            'footer_text' => 'Ilmuna Learn · SIAKAD & LMS',
            'buttons'     => [
                ['id' => 'CTA_OPEN_LEARN', 'title' => '🌐 Buka Ilmuna Learn', 'url' => LEARN_PUBLIC_URL],
            ],
        ],
        [
            'menu_code'   => 'MENU_GURU_MAIN',
            'target_role' => 'guru',
            'ui_type'     => 'buttons',
            'header_text' => 'Panel Guru · Ilmuna Learn',
            'body_text'   => "Assalamu'alaikum Ustadz/Ustadzah *{nama}*.\nPilih aksi cepat kelas melalui tombol di bawah ini:",
            'footer_text' => 'Ilmuna Learn · Asisten Pendidik',
            'buttons'     => [
                ['id' => 'MENU_GURU_ABSENSI', 'title' => '📋 Rekap Absensi'],
                ['id' => 'MENU_GURU_IZIN',    'title' => '📩 Approval Izin'],
                ['id' => 'MENU_GURU_AGENDA',  'title' => '📅 Agenda & Tenggat'],
            ],
        ],
        [
            'menu_code'   => 'MENU_GURU_BK_MAIN',
            'target_role' => 'guru_bk',
            'ui_type'     => 'buttons',
            'header_text' => 'Panel Guru BK · Ilmuna Learn',
            'body_text'   => "Assalamu'alaikum *{nama}* (Tim BK).\nPilih layanan bimbingan & kedisiplinan:",
            'footer_text' => 'Ilmuna Learn · Bimbingan Konseling',
            'buttons'     => [
                ['id' => 'MENU_GURU_ABSENSI',  'title' => '📋 Rekap Absensi'],
                ['id' => 'MENU_GURU_IZIN',     'title' => '📩 Approval Izin'],
                ['id' => 'MENU_BK_PELANGGARAN','title' => '⚠️ Catat Pelanggaran'],
            ],
        ],
        [
            'menu_code'   => 'MENU_ADMIN_MAIN',
            'target_role' => 'admin',
            'ui_type'     => 'buttons',
            'header_text' => 'Panel Admin · Ilmuna WA Bot',
            'body_text'   => "Assalamu'alaikum Admin *{nama}*.\nPantau operasional WA Bot & integrasi Ilmuna Learn:",
            'footer_text' => 'Ilmuna WA Bot · Administrator',
            'buttons'     => [
                ['id' => 'MENU_ADMIN_STATUS',    'title' => '📊 Status Sistem'],
                ['id' => 'MENU_ADMIN_BROADCAST', 'title' => '📢 Status Broadcast'],
                ['id' => 'MENU_ADMIN_GUARD',     'title' => '🛡️ Log Moderasi'],
            ],
        ],
        [
            'menu_code'   => 'MENU_GUEST_MAIN',
            'target_role' => 'guest',
            'ui_type'     => 'buttons',
            'header_text' => 'Selamat Datang di Ilmuna Learn',
            'body_text'   => "Assalamu'alaikum Warahmatullahi Wabarakatuh 👋\nNomor WhatsApp Anda belum terhubung dengan akun aktif *Ilmuna Learn*.\n\nSilakan pilih layanan pendaftaran atau bantuan di bawah ini:",
            'footer_text' => 'Ilmuna Learn · Layanan Terpadu Sekolah',
            'buttons'     => [
                ['id' => 'MENU_GUEST_FORM_SISWA', 'title' => '📝 Form Siswa & Ortu'],
                ['id' => 'MENU_GUEST_FORM_GURU',  'title' => '👨‍🏫 Form Guru'],
                ['id' => 'MENU_CONTACT_ADMIN',    'title' => '📞 Hubungi Admin'],
            ],
        ],
        [
            'menu_code'   => 'MENU_ANTI_CALL_REPLY',
            'target_role' => 'all',
            'ui_type'     => 'buttons',
            'header_text' => 'Panggilan Tidak Didukung',
            'body_text'   => 'Mohon maaf, nomor WhatsApp resmi sekolah ini hanya melayani pesan teks & tombol interaktif dan tidak dapat menerima panggilan suara/video. Silakan pilih menu di bawah ini.',
            'footer_text' => 'Ilmuna WA Bot · Proteksi Anti-Call',
            'buttons'     => [
                ['id' => 'MENU_MAIN',          'title' => '📋 Buka Menu Utama'],
                ['id' => 'MENU_CONTACT_ADMIN', 'title' => '📞 Hubungi Admin'],
            ],
        ],
    ];

    $stmtMenu = $pdo->prepare(
        'INSERT IGNORE INTO wa_button_menus
            (menu_code, target_role, ui_type, header_text, body_text, footer_text, buttons_json, is_active)
         VALUES
            (:menu_code, :target_role, :ui_type, :header_text, :body_text, :footer_text, :buttons_json, 1)'
    );

    foreach ($defaultMenus as $menu) {
        $stmtMenu->execute([
            ':menu_code'    => $menu['menu_code'],
            ':target_role'  => $menu['target_role'],
            ':ui_type'      => $menu['ui_type'],
            ':header_text'  => $menu['header_text'],
            ':body_text'    => $menu['body_text'],
            ':footer_text'  => $menu['footer_text'],
            ':buttons_json' => json_encode($menu['buttons'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $stats['menus_seeded'] += $stmtMenu->rowCount();
    }

    // 3. Aturan Group Guard & Kamus Kata Terlarang Awal (Idempoten berdasarkan rule_type + pattern)
    $defaultRules = [
        // Whitelist domain resmi
        ['link_whitelist', 'ilmuna.my.id',                                                'ringan', 'warn',        0],
        ['link_whitelist', 'docs.google.com',                                             'ringan', 'warn',        0],
        ['link_whitelist', 'drive.google.com',                                            'ringan', 'warn',        0],
        ['link_whitelist', 'forms.gle',                                                   'ringan', 'warn',        0],
        // Blacklist judol, phishing, invite grup luar
        ['link_blacklist', 'slot|gacor|maxwin|judi|togel|zeus88|pgsoft',                  'berat',  'delete_kick', 35],
        ['link_blacklist', 'slot88|pragmatic|sbobet|deposit\\s*pulsa|scatter\\s*hitam',   'berat',  'delete_kick', 35],
        ['link_blacklist', 'chat\\.whatsapp\\.com',                                       'sedang', 'delete_warn', 15],
        ['link_blacklist', 't\\.me/|linktr\\.ee/.*slot|bit\\.ly/.*gacor',                 'berat',  'delete_warn', 20],
        // Anti-flood & Anti-virtex
        ['flood',          '5_msg_in_15_sec',                                             'sedang', 'delete_warn', 10],
        ['virtex',         'len_gt_4000',                                                 'berat',  'delete_kick', 25],
        // Kamus kata kasar / toxic awal (normalisasi leetspeak akan diterapkan di GroupGuardEngine)
        ['badword',        'anjing',                                                      'sedang', 'delete_warn', 10],
        ['badword',        'bangsat',                                                     'sedang', 'delete_warn', 15],
        ['badword',        'bajingan',                                                    'sedang', 'delete_warn', 15],
        ['badword',        'kontol',                                                      'berat',  'delete_warn', 25],
        ['badword',        'memek',                                                       'berat',  'delete_warn', 25],
        ['badword',        'ngentot',                                                     'berat',  'delete_warn', 25],
        ['badword',        'jancok',                                                      'sedang', 'delete_warn', 15],
        ['badword',        'goblok',                                                      'ringan', 'delete_warn', 10],
        ['badword',        'tolol',                                                       'ringan', 'delete_warn', 10],
        ['badword',        'pantek',                                                      'sedang', 'delete_warn', 15],
        ['badword',        'lonte',                                                       'berat',  'delete_warn', 25],
        ['badword',        'perek',                                                       'berat',  'delete_warn', 20],
        ['badword',        'bangke',                                                      'ringan', 'warn',        5],
    ];

    $stmtCheckRule = $pdo->prepare(
        'SELECT id FROM wa_group_rules WHERE rule_type = :rt AND pattern = :pt LIMIT 1'
    );
    $stmtInsertRule = $pdo->prepare(
        'INSERT INTO wa_group_rules (rule_type, pattern, severity, action_mode, bk_poin, is_active)
         VALUES (:rt, :pt, :sv, :am, :bp, 1)'
    );

    foreach ($defaultRules as [$rt, $pt, $sv, $am, $bp]) {
        $stmtCheckRule->execute([':rt' => $rt, ':pt' => $pt]);
        if (!$stmtCheckRule->fetchColumn()) {
            $stmtInsertRule->execute([
                ':rt' => $rt,
                ':pt' => $pt,
                ':sv' => $sv,
                ':am' => $am,
                ':bp' => $bp,
            ]);
            $stats['rules_seeded']++;
        }
    }

    // 4. Device bawaan awal bila tabel wa_devices masih kosong
    $deviceCount = (int)$pdo->query('SELECT COUNT(*) FROM wa_devices')->fetchColumn();
    if ($deviceCount === 0) {
        $stmtDev = $pdo->prepare(
            'INSERT INTO wa_devices
                (name, driver, phone_number, endpoint_url, api_token, webhook_secret, status, is_default)
             VALUES
                (:name, :driver, :phone, :url, :token, :secret, :status, :is_default)'
        );
        $stmtDev->execute([
            ':name'       => 'Ilmuna MD Bridge Utama (Grup & Anti-Call)',
            ':driver'     => 'md_bridge',
            ':phone'      => SCHOOL_ADMIN_PHONE,
            ':url'        => WA_BRIDGE_BASE_URL,
            ':token'      => WA_BRIDGE_API_KEY,
            ':secret'     => WA_BRIDGE_WEBHOOK_SECRET,
            ':status'     => 'disconnected',
            ':is_default' => 1,
        ]);
        $stmtDev->execute([
            ':name'       => 'Meta WhatsApp Cloud API (Interactive & Broadcast)',
            ':driver'     => 'cloud_api',
            ':phone'      => WA_CLOUD_PHONE_NUMBER_ID !== '' ? WA_CLOUD_PHONE_NUMBER_ID : null,
            ':url'        => WA_CLOUD_GRAPH_URL,
            ':token'      => WA_CLOUD_ACCESS_TOKEN,
            ':secret'     => WA_CLOUD_APP_SECRET !== '' ? WA_CLOUD_APP_SECRET : WA_CLOUD_VERIFY_TOKEN,
            ':status'     => 'disconnected',
            ':is_default' => 0,
        ]);
        $stats['devices_seeded'] = 2;
    }

    return $stats;
}

// Eksekusi CLI langsung: `php database.php --migrate` atau `php database.php --status`
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $args = array_slice($argv, 1);
    $pdo = getDBConnection();

    if (in_array('--status', $args, true)) {
        $tables = [];
        foreach (getOfficialWaTables() as $tbl) {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
            $tables[$tbl] = $count;
        }
        echo json_encode(['status' => 'ok', 'database' => DB_NAME, 'tables' => $tables], JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    $result = runWaDatabaseMigration($pdo, true);
    echo json_encode(['status' => 'migrated', 'database' => DB_NAME, 'result' => $result], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}
