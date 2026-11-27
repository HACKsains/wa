-- ==============================================================================
-- ILMUNA WA BOT — DATABASE SCHEMA (14 TABEL RESMI)
-- Database: uofruaju_wa_ilmuna (MySQL 8.0+ / MariaDB 10.4+, InnoDB, utf8mb4)
-- ==============================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- 1. Pengaturan Global Bot & Integrasi Learn
CREATE TABLE IF NOT EXISTS `wa_settings` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(80) NOT NULL,
    `setting_value` TEXT NULL DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wa_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Konfigurasi Device / Transport (Cloud API & Self-Hosted MD Bridge)
CREATE TABLE IF NOT EXISTS `wa_devices` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `driver` ENUM('cloud_api','md_bridge') NOT NULL DEFAULT 'md_bridge',
    `phone_number` VARCHAR(30) NULL DEFAULT NULL,
    `endpoint_url` VARCHAR(255) NULL DEFAULT NULL,
    `api_token` TEXT NULL DEFAULT NULL,
    `webhook_secret` VARCHAR(128) NULL DEFAULT NULL,
    `status` ENUM('connected','disconnected','qr_pending') NOT NULL DEFAULT 'disconnected',
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `last_seen_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Cache Kontak & Mapping Role dari Ilmuna Learn
CREATE TABLE IF NOT EXISTS `wa_contacts_cache` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `phone_e164` VARCHAR(25) NOT NULL COMMENT 'Format 628xxxxxxxxxx',
    `role` ENUM('admin','guru','guru_bk','orang_tua','siswa','guest') NOT NULL DEFAULT 'guest',
    `learn_ref_id` INT(11) UNSIGNED NULL DEFAULT NULL COMMENT 'ID di tabel guru/orang_tua/siswa/users',
    `display_name` VARCHAR(150) NULL DEFAULT NULL,
    `extra_json` JSON NULL DEFAULT NULL COMMENT 'Daftar anak utk orang_tua, kelas utk siswa, dll',
    `is_blocked` TINYINT(1) NOT NULL DEFAULT 0,
    `synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wcc_phone` (`phone_e164`),
    KEY `idx_wcc_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. State Machine Sesi Interaktif Tombol (Zero Manual Typing)
CREATE TABLE IF NOT EXISTS `wa_sessions` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `phone_e164` VARCHAR(25) NOT NULL,
    `current_state` VARCHAR(80) NOT NULL DEFAULT 'MAIN_MENU',
    `selected_child_id` INT(11) UNSIGNED NULL DEFAULT NULL COMMENT 'Khusus role orang_tua multi-anak',
    `context_json` JSON NULL DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ws_phone` (`phone_e164`),
    KEY `idx_ws_exp` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Master Menu Tombol Interaktif per Role (Bisa Diubah dari Admin Panel)
CREATE TABLE IF NOT EXISTS `wa_button_menus` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `menu_code` VARCHAR(60) NOT NULL,
    `target_role` ENUM('all','admin','guru','guru_bk','orang_tua','siswa','guest') NOT NULL DEFAULT 'all',
    `ui_type` ENUM('buttons','list','cta_url') NOT NULL DEFAULT 'buttons',
    `header_text` VARCHAR(120) NULL DEFAULT NULL,
    `body_text` TEXT NOT NULL,
    `footer_text` VARCHAR(120) NULL DEFAULT 'Ilmuna Learn · Asisten Resmi Sekolah',
    `buttons_json` JSON NOT NULL COMMENT 'Array [{id, title, description, url}]',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wbm_code` (`menu_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Daftar Grup WhatsApp yang Dipantau Bot
CREATE TABLE IF NOT EXISTS `wa_groups` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `group_jid` VARCHAR(100) NOT NULL COMMENT 'Contoh: 1203630xxxx@g.us',
    `group_name` VARCHAR(160) NOT NULL,
    `group_type` ENUM('kelas','orang_tua','guru','umum') NOT NULL DEFAULT 'kelas',
    `learn_kelas_id` INT(11) UNSIGNED NULL DEFAULT NULL COMMENT 'Relasi ke learn.kelas.id',
    `guard_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `sync_bk_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Sinkron pelanggaran siswa ke Learn BK',
    `notify_parent_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `quiet_start` TIME NULL DEFAULT NULL COMMENT 'Contoh: 21:30:00',
    `quiet_end` TIME NULL DEFAULT NULL COMMENT 'Contoh: 05:00:00',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wg_jid` (`group_jid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Aturan Deteksi Pelanggaran Grup (Kata Kasar, Link Judol, Spam, Virtex)
CREATE TABLE IF NOT EXISTS `wa_group_rules` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `rule_type` ENUM('badword','link_blacklist','link_whitelist','flood','virtex') NOT NULL,
    `pattern` VARCHAR(255) NOT NULL,
    `severity` ENUM('ringan','sedang','berat') NOT NULL DEFAULT 'sedang',
    `action_mode` ENUM('warn','delete_warn','delete_kick') NOT NULL DEFAULT 'delete_warn',
    `bk_poin` INT(11) NOT NULL DEFAULT 10 COMMENT 'Poin pelanggaran jika disinkron ke Learn BK',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wgr_type` (`rule_type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Log Pelanggaran Grup & Eskalasi Strike
CREATE TABLE IF NOT EXISTS `wa_violation_logs` (
    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `group_jid` VARCHAR(100) NOT NULL,
    `sender_phone` VARCHAR(25) NOT NULL,
    `sender_name` VARCHAR(150) NULL DEFAULT NULL,
    `learn_siswa_id` INT(11) UNSIGNED NULL DEFAULT NULL,
    `violation_type` ENUM('badword','link','judol','flood','virtex','quiet_hours') NOT NULL,
    `matched_pattern` VARCHAR(160) NULL DEFAULT NULL,
    `message_excerpt` TEXT NULL DEFAULT NULL,
    `strike_count` INT(11) NOT NULL DEFAULT 1,
    `action_taken` VARCHAR(80) NOT NULL,
    `synced_to_bk` TINYINT(1) NOT NULL DEFAULT 0,
    `parent_notified` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wvl_group_sender` (`group_jid`, `sender_phone`),
    KEY `idx_wvl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Log Panggilan Masuk (Anti-Call Auto-Reject & Auto-Block)
CREATE TABLE IF NOT EXISTS `wa_call_logs` (
    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `caller_phone` VARCHAR(25) NOT NULL,
    `call_type` ENUM('voice','video') NOT NULL DEFAULT 'voice',
    `was_rejected` TINYINT(1) NOT NULL DEFAULT 1,
    `auto_replied` TINYINT(1) NOT NULL DEFAULT 1,
    `triggered_block` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wcl_caller` (`caller_phone`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Kampanye Broadcast Massal & Auto-Broadcast Akademik (Tugas, Proyek, Agenda, Asesmen, dll)
CREATE TABLE IF NOT EXISTS `wa_broadcast_campaigns` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(160) NOT NULL,
    `source_module` ENUM('manual','tugas','proyek','agenda','asesmen','lkpd','materi','mutabaah','public_form') NOT NULL DEFAULT 'manual',
    `source_ref_id` INT(11) UNSIGNED NULL DEFAULT NULL COMMENT 'ID entitas di Ilmuna Learn (misal tugas.id / proyek.id / kalender_pendidikan.id)',
    `target_scope` ENUM('all_ortu','ortu_kelas','all_siswa','siswa_kelas','all_guru','groups','custom') NOT NULL,
    `target_filter_json` JSON NULL DEFAULT NULL COMMENT 'Misal: {"kelas_ids":[1,2]}',
    `message_body` TEXT NOT NULL,
    `media_url` VARCHAR(255) NULL DEFAULT NULL,
    `buttons_json` JSON NULL DEFAULT NULL COMMENT 'Tombol interaktif / CTA URL',
    `status` ENUM('draft','scheduled','processing','completed','paused','cancelled') NOT NULL DEFAULT 'draft',
    `scheduled_at` DATETIME NULL DEFAULT NULL,
    `total_targets` INT(11) NOT NULL DEFAULT 0,
    `sent_count` INT(11) NOT NULL DEFAULT 0,
    `failed_count` INT(11) NOT NULL DEFAULT 0,
    `created_by` VARCHAR(100) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wbc_status` (`status`, `scheduled_at`),
    KEY `idx_wbc_source` (`source_module`, `source_ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Antrean Kirim Broadcast & Notifikasi Prioritas
CREATE TABLE IF NOT EXISTS `wa_broadcast_queue` (
    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id` INT(11) UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = notifikasi real-time dari Learn',
    `priority` TINYINT(1) NOT NULL DEFAULT 5 COMMENT '1=Tertinggi (Notifikasi Learn), 5=Broadcast Massal',
    `recipient_jid` VARCHAR(100) NOT NULL COMMENT '628xxx@s.whatsapp.net atau xxx@g.us',
    `recipient_name` VARCHAR(150) NULL DEFAULT NULL,
    `payload_type` ENUM('text','buttons','list','cta_url','media') NOT NULL DEFAULT 'buttons',
    `payload_json` JSON NOT NULL,
    `status` ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT(2) NOT NULL DEFAULT 0,
    `last_error` TEXT NULL DEFAULT NULL,
    `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_wbq_worker` (`status`, `priority`, `scheduled_at`),
    KEY `idx_wbq_campaign` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Log Pesan Masuk & Keluar (Audit Trail)
CREATE TABLE IF NOT EXISTS `wa_message_logs` (
    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `direction` ENUM('in','out') NOT NULL,
    `remote_jid` VARCHAR(100) NOT NULL,
    `message_type` VARCHAR(40) NOT NULL DEFAULT 'text',
    `button_id` VARCHAR(100) NULL DEFAULT NULL,
    `content_preview` TEXT NULL DEFAULT NULL,
    `provider_msg_id` VARCHAR(120) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wml_remote` (`remote_jid`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Idempotency Webhook (Anti-Proses Ganda)
CREATE TABLE IF NOT EXISTS `wa_webhook_events` (
    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider_event_id` VARCHAR(128) NOT NULL,
    `event_type` VARCHAR(50) NOT NULL,
    `processed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wwe_event` (`provider_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Log Sinkronisasi API Ilmuna Learn <-> WA Bot
CREATE TABLE IF NOT EXISTS `wa_learn_sync_logs` (
    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `direction` ENUM('learn_to_wa','wa_to_learn') NOT NULL,
    `event_name` VARCHAR(80) NOT NULL,
    `http_status` INT(5) NULL DEFAULT NULL,
    `request_summary` TEXT NULL DEFAULT NULL,
    `response_summary` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wlsl_event` (`event_name`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==============================================================================
-- SEED AWAL PENGATURAN & ATURAN DEFAULT
-- ==============================================================================
INSERT IGNORE INTO `wa_settings` (`setting_key`, `setting_value`) VALUES
('anti_call_enabled', '1'),
('anti_call_reply_text', 'Mohon maaf, nomor WhatsApp resmi sekolah ini hanya melayani pesan teks & tombol interaktif dan tidak dapat menerima panggilan suara/video. Silakan pilih menu di bawah ini.'),
('anti_call_spam_limit', '3'),
('anti_call_spam_window_minutes', '5'),
('broadcast_delay_min_sec', '3'),
('broadcast_delay_max_sec', '7'),
('broadcast_batch_size', '25'),
('group_max_strike_kick', '3');

INSERT IGNORE INTO `wa_group_rules` (`rule_type`, `pattern`, `severity`, `action_mode`, `bk_poin`) VALUES
('link_whitelist', 'ilmuna.my.id', 'ringan', 'warn', 0),
('link_whitelist', 'docs.google.com', 'ringan', 'warn', 0),
('link_blacklist', 'slot|gacor|maxwin|judi|togel|zeus88|pgsoft', 'berat', 'delete_kick', 35),
('link_blacklist', 'chat\\.whatsapp\\.com', 'sedang', 'delete_warn', 15),
('flood', '5_msg_in_15_sec', 'sedang', 'delete_warn', 10),
('virtex', 'len_gt_4000', 'berat', 'delete_kick', 25);
