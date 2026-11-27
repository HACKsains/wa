<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — SHARED DASHBOARD HEADER & SIDEBAR SHELL (LUMINA GOLD)
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    exit('Forbidden');
}

$admin         = wa_current_admin();
$pageTitle     = $pageTitle ?? 'Overview Live';
$pageIcon      = $pageIcon ?? 'solar:home-2-bold';
$currentScript = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$csrfToken     = wa_csrf_token();
// Lepas session lock setelah token CSRF siap agar request AJAX lazy-load langsung paralel!
wa_release_session_lock();

$initials = '';
foreach (preg_split('/\s+/', trim($admin['name'])) ?: [] as $part) {
    $initials .= mb_substr($part, 0, 1, 'UTF-8');
    if (mb_strlen($initials, 'UTF-8') >= 2) {
        break;
    }
}
$initials = mb_strtoupper($initials ?: 'AD', 'UTF-8');

$navItems = [
    ['file' => 'index.php',       'label' => 'Overview Live',     'icon' => 'solar:home-2-bold',               'section' => 'Utama'],
    ['file' => 'devices.php',     'label' => 'Devices & Baileys','icon' => 'solar:smartphone-2-bold',         'section' => 'Utama'],
    ['file' => 'menus.php',       'label' => 'Menu Tombol',       'icon' => 'solar:widget-5-bold',             'section' => 'Otomasi'],
    ['file' => 'broadcast.php',   'label' => 'Broadcast Akademik','icon' => 'solar:plain-3-bold',              'section' => 'Otomasi'],
    ['file' => 'group-guard.php', 'label' => 'Group Guard',       'icon' => 'solar:shield-warning-bold',       'section' => 'Proteksi'],
    ['file' => 'anti-call.php',   'label' => 'Anti-Call',         'icon' => 'solar:phone-calling-rounded-bold','section' => 'Proteksi'],
    ['file' => 'logs.php',        'label' => 'Log & Sync Learn',  'icon' => 'solar:document-text-bold',        'section' => 'Audit'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
    <?= renderFavicon() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;600&family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;600&family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap"></noscript>
    <?= renderIconifyScript() ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/lumina-wa.css?v=<?= @filemtime(BASE_PATH . '/assets/css/lumina-wa.css') ?: 1 ?>">
    <script>
        window.ILMUNA_BASE_URL = <?= json_encode(BASE_URL) ?>;
        window.WA_CSRF_TOKEN   = <?= json_encode($csrfToken) ?>;
    </script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/alert.js?v=<?= @filemtime(BASE_PATH . '/assets/js/alert.js') ?: 1 ?>" defer></script>
    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/wa-panel.js?v=<?= @filemtime(BASE_PATH . '/assets/js/wa-panel.js') ?: 1 ?>" defer></script>
    <?php loadCentralTemplates($pageTemplates ?? ['modal', 'table']); ?>
</head>
<body>
<div class="wa-shell">
    <!-- SIDEBAR DESKTOP -->
    <aside class="wa-sidebar" aria-label="Navigasi Admin WA Bot">
        <div class="wa-sidebar-brand ilmuna-logo-shield" oncontextmenu="return false;">
            <div class="wa-sidebar-logo-pill">
                <img src="<?= htmlspecialchars(getLogoUrl(), ENT_QUOTES, 'UTF-8') ?>" alt="Panel WA" loading="lazy" draggable="false">
            </div>
            <div class="wa-sidebar-brand-meta">
                <div class="wa-sidebar-brand-sub">Admin · Margo One SSO</div>
            </div>
        </div>

        <nav class="wa-sidebar-nav">
            <?php
            $lastSection = '';
            foreach ($navItems as $item):
                if ($item['section'] !== $lastSection):
                    $lastSection = $item['section'];
            ?>
            <div class="wa-nav-section"><?= htmlspecialchars($lastSection) ?></div>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(BASE_URL . '/dashboard/' . $item['file']) ?>"
               class="wa-nav-item <?= $currentScript === $item['file'] ? 'is-active' : '' ?>">
                <iconify-icon icon="<?= htmlspecialchars($item['icon']) ?>"></iconify-icon>
                <span><?= htmlspecialchars($item['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="wa-sidebar-foot">
            <button type="button" class="wa-logout-btn" data-ilm-logout="<?= htmlspecialchars(BASE_URL) ?>/logout.php">
                <iconify-icon icon="solar:logout-2-bold" width="18"></iconify-icon>
                <span>Keluar SSO</span>
            </button>
        </div>
    </aside>

    <!-- MAIN CONTENT WRAPPER -->
    <div class="wa-main-wrap">
        <header class="wa-topbar">
            <div class="wa-topbar-left">
                <span class="wa-breadcrumb-icon">
                    <iconify-icon icon="<?= htmlspecialchars($pageIcon) ?>" width="18"></iconify-icon>
                </span>
                <span class="wa-breadcrumb-title"><?= htmlspecialchars($pageTitle) ?></span>
            </div>

            <div class="wa-topbar-right">
                <span class="wa-gd-pill" title="Guardian Dome WAF & Telegram Sentinel Aktif">
                    <iconify-icon icon="solar:shield-check-bold" width="15"></iconify-icon>
                    <span>Guardian Dome</span>
                </span>
                <div class="wa-admin-chip" title="<?= htmlspecialchars($admin['email'] ?: $admin['username']) ?>">
                    <span class="wa-admin-avatar">
                        <?php if ($admin['avatar'] !== ''): ?>
                            <img src="<?= htmlspecialchars($admin['avatar']) ?>" alt="Admin" loading="lazy" draggable="false">
                        <?php else: ?>
                            <?= htmlspecialchars($initials) ?>
                        <?php endif; ?>
                    </span>
                    <span><?= htmlspecialchars($admin['name']) ?></span>
                </div>
            </div>
        </header>

        <main class="ilmuna-app-container">
