<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — SHARED DASHBOARD FOOTER & MOBILE BOTTOM NAVIGATION DOCK
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    exit('Forbidden');
}

$currentScript = basename($_SERVER['PHP_SELF'] ?? 'index.php');
?>
        </main><!-- /.ilmuna-app-container -->
    </div><!-- /.wa-main-wrap -->
</div><!-- /.wa-shell -->

<!-- MOBILE BOTTOM NAVIGATION DOCK (<= 768px) -->
<nav class="wa-bottom-nav" aria-label="Navigasi Mobile">
    <a href="<?= htmlspecialchars(BASE_URL) ?>/dashboard/index.php" class="wa-bnb-item <?= $currentScript === 'index.php' ? 'is-active' : '' ?>">
        <iconify-icon icon="solar:home-2-bold"></iconify-icon>
        <span>Live</span>
    </a>
    <a href="<?= htmlspecialchars(BASE_URL) ?>/dashboard/devices.php" class="wa-bnb-item <?= $currentScript === 'devices.php' ? 'is-active' : '' ?>">
        <iconify-icon icon="solar:smartphone-2-bold"></iconify-icon>
        <span>Device</span>
    </a>
    <a href="<?= htmlspecialchars(BASE_URL) ?>/dashboard/broadcast.php" class="wa-bnb-item <?= $currentScript === 'broadcast.php' ? 'is-active' : '' ?>">
        <iconify-icon icon="solar:plain-3-bold"></iconify-icon>
        <span>Broadcast</span>
    </a>
    <a href="<?= htmlspecialchars(BASE_URL) ?>/dashboard/group-guard.php" class="wa-bnb-item <?= $currentScript === 'group-guard.php' ? 'is-active' : '' ?>">
        <iconify-icon icon="solar:shield-warning-bold"></iconify-icon>
        <span>Guard</span>
    </a>
    <button type="button" class="wa-bnb-item" id="btnOpenMobileSheet">
        <iconify-icon icon="solar:menu-dots-bold"></iconify-icon>
        <span>Lainnya</span>
    </button>
</nav>

<!-- MOBILE MORE SHEET -->
<div class="wa-mobile-sheet-backdrop" id="mobileNavSheetBackdrop">
    <div class="wa-mobile-sheet">
        <a href="<?= htmlspecialchars(BASE_URL) ?>/dashboard/menus.php" class="wa-sheet-link">
            <iconify-icon icon="solar:widget-5-bold" width="20" style="color:var(--lumina-teal);"></iconify-icon>
            <span>Menu Tombol</span>
        </a>
        <a href="<?= htmlspecialchars(BASE_URL) ?>/dashboard/anti-call.php" class="wa-sheet-link">
            <iconify-icon icon="solar:phone-calling-rounded-bold" width="20" style="color:var(--lumina-amber);"></iconify-icon>
            <span>Anti-Call</span>
        </a>
        <a href="<?= htmlspecialchars(BASE_URL) ?>/dashboard/logs.php" class="wa-sheet-link">
            <iconify-icon icon="solar:document-text-bold" width="20" style="color:var(--lumina-navy);"></iconify-icon>
            <span>Log &amp; Sync</span>
        </a>
        <a href="<?= htmlspecialchars(LEARN_PUBLIC_URL) ?>" target="_blank" rel="noopener" class="wa-sheet-link">
            <iconify-icon icon="solar:square-top-down-bold" width="20" style="color:var(--lumina-sky);"></iconify-icon>
            <span>Ilmuna Learn</span>
        </a>
        <button type="button" class="wa-sheet-link danger" data-ilm-logout="<?= htmlspecialchars(BASE_URL) ?>/logout.php">
            <iconify-icon icon="solar:logout-2-bold" width="20"></iconify-icon>
            <span>Keluar Margo One SSO</span>
        </button>
    </div>
</div>
</body>
</html>
