<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — PORTAL LOGIN KHUSUS ADMIN (IDENTIK ILMUNA LEARN + MARGO ONE SSO)
 * ==============================================================================
 * Tampilan 100% identik dengan learn/login.php (Split-Panel Overlap, Canvas
 * Particle Network, Glow Orbs, Radial Wave Transition, Glass Card, Parallax Wave,
 * Logo Shine Sweep, Anti-Download) namun dikunci 100% khusus Admin via Margo One SSO.
 * ==============================================================================
 */

if (isset($_GET['sso_login']) && !defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Security Headers
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

if (wa_is_admin_logged_in()) {
    header('Location: ' . BASE_URL . '/dashboard/index.php');
    exit;
}

$error     = !empty($_GET['sso_error']) ? trim(strip_tags((string)$_GET['sso_error'])) : '';
$loggedOut = isset($_GET['msg']) && $_GET['msg'] === 'logged_out';

// Inisiasi OAuth 2.0 + PKCE S256 Redirect ke Margo One SSO
if (isset($_GET['sso_login']) && $_GET['sso_login'] === '1') {
    try {
        unset($_SESSION['wa_margo_sso_url'], $_SESSION['wa_margo_sso_redirect']);
        $client = wa_sso_build_client();
        header('Location: ' . $client->buildAuthorizeUrl(['scope' => 'openid profile email roles']));
        exit;
    } catch (Throwable $e) {
        $error = 'Gagal memulai autentikasi Margo One SSO: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Masuk — Panel WA · <?= htmlspecialchars(APP_NAME) ?></title>
    <?= renderFavicon() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?= renderIconifyScript() ?>
    <script>window.ILMUNA_BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --navy: #1D3B5E;
            --navy-dark: #152C47;
            --teal: #0D7A87;
            --teal-dark: #096470;
            --sky: #52B2DC;
            --mint: #14B8A6;
            --emerald: #10B981;
            --gold: #F59E0B;
            --font-h: 'Outfit', sans-serif;
            --font-b: 'Plus Jakarta Sans', sans-serif;
        }

        html {
            height: 100%;
            overflow: hidden;
        }

        body {
            font-family: var(--font-b);
            background: #F8FAFC;
            color: #334155;
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
            overscroll-behavior: none;
            -webkit-font-smoothing: antialiased;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px 0;
            position: relative;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            -webkit-touch-callout: none;
        }

        @media (min-width: 768px) {
            body { padding: 24px 0; }
        }

        img {
            -webkit-user-drag: none;
            -moz-user-drag: none;
            user-drag: none;
            -webkit-touch-callout: none;
            pointer-events: none;
        }

        /* ==========================================================
           PAGE TRANSITION (RADIAL WAVE — IDENTIK LEARN)
           ========================================================== */
        .page-transition {
            position: fixed;
            top: 50%; left: 50%;
            width: 300vmax;
            height: 300vmax;
            margin-top: -150vmax;
            margin-left: -150vmax;
            background: #E8EFF7;
            z-index: 9999;
            transform-origin: center;
            transform: rotate(-225deg) translateY(85%);
            transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
            box-shadow: 0 -20px 60px rgba(0,0,0,0.1);
            will-change: transform;
        }
        .page-transition::before {
            content: '';
            position: absolute;
            top: 10vh; left: 0;
            width: 100%; height: 100%;
            background: #52B2DC;
            box-shadow: 0 -15px 35px rgba(0,0,0,0.08);
        }
        .page-transition::after {
            content: '';
            position: absolute;
            top: 20vh; left: 0;
            width: 100%; height: 100%;
            background: #0D7A87;
            box-shadow: 0 -15px 35px rgba(0,0,0,0.1);
        }
        .page-transition.active {
            transform: rotate(135deg) translateY(-10%);
        }

        /* ==========================================================
           CANVAS + GLOW ORBS
           ========================================================== */
        #particleCanvas {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 2;
        }

        .glow-orb {
            position: absolute;
            border-radius: 50%;
            background: rgba(13, 122, 135, 0.10);
            filter: blur(100px);
            pointer-events: none;
            z-index: 1;
            width: 300px; height: 300px;
        }
        @media (min-width: 768px) { .glow-orb { width: 600px; height: 600px; } }
        .glow-orb-1 { top: -10%; left: 10%; }
        .glow-orb-2 { bottom: -10%; right: 10%; }

        /* ==========================================================
           WRAPPER
           ========================================================== */
        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 90%;
            max-width: 1200px;
            max-height: 100%;
            min-height: 0;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            overflow: visible;
        }
        @media (min-width: 768px) {
            .login-wrapper {
                flex-direction: row;
                align-items: stretch;
                justify-content: center;
                gap: 0;
                width: 85%;
            }
        }
        @media (min-width: 1024px) { .login-wrapper { width: 75%; } }
        @media (min-width: 1280px) { .login-wrapper { width: 70%; } }

        /* ==========================================================
           LEFT SLIDE PANEL (Z-INDEX 1 -> BELAKANG)
           ========================================================== */
        .slide-panel {
            width: 100%;
            height: auto;
            background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 50%, #152C47 100%);
            border-radius: 28px;
            border-bottom-right-radius: 60px;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            padding: 8px;
            position: relative;
            z-index: 1;
            overflow: hidden;
            transform: translate(-100%, 100%);
            animation: slideInFromBehind 1.2s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            will-change: transform;
            flex-shrink: 0;
        }
        @media (min-width: 768px) {
            .slide-panel {
                width: 45%;
                padding: 32px;
                border-radius: 0;
                border-top-left-radius: 32px;
                border-bottom-left-radius: 32px;
                border-bottom-right-radius: 100px;
            }
        }
        @media (min-width: 1024px) { .slide-panel { width: 48%; } }

        @keyframes slideInFromBehind {
            0% { transform: translate(-100%, 100%); opacity: 0; }
            100% { transform: translate(0, 0); opacity: 1; }
        }

        .pixel-pattern {
            position: absolute;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background-image: radial-gradient(circle, rgba(82, 178, 220, 0.30) 1.5px, transparent 1.5px);
            background-size: 20px 20px;
            animation: pixelPulse 8s ease-in-out infinite;
            opacity: 0.55;
        }
        @keyframes pixelPulse {
            0%, 100% { opacity: 0.40; transform: scale(1); }
            50%      { opacity: 0.70; transform: scale(1.02); }
        }

        .wave-wrap {
            position: absolute;
            bottom: 0; left: 0;
            width: 100%;
            overflow: hidden;
            line-height: 0;
            z-index: 10;
            pointer-events: none;
            opacity: 0.40;
        }
        .wave-wrap svg {
            position: relative;
            display: block;
            width: calc(100% + 800px);
            height: 30px;
        }
        @media (min-width: 768px) { .wave-wrap svg { height: 60px; } }

        .parallax > use { animation: wave-move 25s cubic-bezier(.55,.5,.45,.5) infinite; will-change: transform; }
        .parallax > use:nth-child(1) { animation-delay: -2s; animation-duration: 7s; }
        .parallax > use:nth-child(2) { animation-delay: -3s; animation-duration: 10s; }
        .parallax > use:nth-child(3) { animation-delay: -4s; animation-duration: 13s; }
        .parallax > use:nth-child(4) { animation-delay: -5s; animation-duration: 20s; }
        @keyframes wave-move {
            0%   { transform: translate3d(-90px, 0, 0); }
            100% { transform: translate3d(85px, 0, 0); }
        }

        .slide-inner {
            position: relative;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            text-align: left;
            width: 100%;
            max-width: 360px;
            margin: 0 auto;
            padding: 4px 0 8px;
        }
        @media (min-width: 768px) { .slide-inner { margin: 0; padding: 16px 0 0; } }

        .slide-glass-card {
            background: rgba(255, 255, 255, 0.10);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.20);
            border-radius: 14px;
            padding: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            width: 100%;
        }
        @media (min-width: 768px) { .slide-glass-card { padding: 28px; } }

        .logo-line {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 10px;
            margin-bottom: 8px;
        }
        @media (min-width: 768px) { .logo-line { margin-bottom: 20px; } }

        .logo-wrap {
            position: relative;
            width: fit-content;
            padding: 8px 14px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 252, 0.95) 100%);
            border-radius: 12px;
            box-shadow:
                0 8px 24px rgba(0, 0, 0, 0.20),
                0 0 28px rgba(255, 255, 255, 0.30),
                inset 0 1px 0 rgba(255, 255, 255, 1),
                inset 0 -1px 0 rgba(29, 59, 94, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.85);
            overflow: hidden;
        }
        @media (min-width: 768px) { .logo-wrap { padding: 12px 20px; border-radius: 16px; } }

        .logo-wrap::after {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 50%;
            height: 100%;
            background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.75) 50%, rgba(255,255,255,0) 100%);
            transform: translateX(-150%) skewX(-25deg);
            animation: logoShine 6s infinite ease-in-out;
            pointer-events: none;
            z-index: 3;
        }
        @keyframes logoShine {
            0%   { transform: translateX(-150%) skewX(-25deg); }
            100% { transform: translateX(250%) skewX(-25deg); }
        }

        .logo-wrap img {
            width: 60px;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 2px 6px rgba(29, 59, 94, 0.15));
            display: block;
        }
        @media (min-width: 768px) { .logo-wrap img { width: 150px; } }

        .mobile-side-text { flex: 1; text-align: left; }
        .mobile-side-text h2 { color: #FFFFFF; font-size: 12px; font-weight: 700; line-height: 1.2; text-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .mobile-side-text p { color: rgba(255,255,255,0.82); font-size: 9.5px; font-weight: 500; margin-top: 2px; }
        @media (min-width: 768px) { .mobile-side-text { display: none; } }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 9px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.20);
            border: 1px solid rgba(255, 255, 255, 0.30);
            backdrop-filter: blur(8px);
            margin-bottom: 6px;
            width: fit-content;
        }
        @media (min-width: 768px) { .badge { padding: 6px 14px; gap: 10px; margin-bottom: 14px; } }
        .badge .dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: var(--sky);
            animation: pulseDot 2s ease-in-out infinite;
            box-shadow: 0 0 8px var(--sky);
        }
        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%      { opacity: 0.5; transform: scale(0.85); }
        }
        .badge span {
            color: #FFFFFF;
            font-size: 8px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        @media (min-width: 768px) { .badge span { font-size: 11px; } }

        .slide-h1 {
            display: none;
            color: #FFFFFF;
            font-family: var(--font-h);
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.02em;
            line-height: 1.15;
            margin-bottom: 8px;
            text-shadow: 0 4px 12px rgba(0,0,0,0.30);
        }
        @media (min-width: 768px) { .slide-h1 { display: block; font-size: 2rem; margin-bottom: 10px; } }
        @media (min-width: 1024px) { .slide-h1 { font-size: 2.25rem; } }
        .slide-h1 .gradient-text {
            background: linear-gradient(to right, #FFFFFF 0%, #52B2DC 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            color: transparent;
            filter: drop-shadow(0 2px 8px rgba(0,0,0,0.2));
        }

        .slide-desc {
            display: none;
            color: rgba(255, 255, 255, 0.90);
            font-size: 13px;
            line-height: 1.55;
            font-weight: 500;
            max-width: 320px;
            text-shadow: 0 1px 4px rgba(0,0,0,0.2);
        }
        @media (min-width: 768px) { .slide-desc { display: block; } }

        /* ==========================================================
           RIGHT FORM PANEL (Z-INDEX 20 -> DEPAN, OVERLAP IDENTIK LEARN)
           ========================================================== */
        .form-panel {
            width: 100%;
            height: auto;
            border-radius: 28px;
            border-top-left-radius: 60px;
            padding: 18px;
            padding-top: 38px;
            padding-bottom: 18px;
            margin-top: -28px;
            position: relative;
            z-index: 20;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.45);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.60);
            box-shadow:
                0 8px 32px 0 rgba(29, 59, 94, 0.15),
                -20px 12px 40px -12px rgba(29, 59, 94, 0.20);
            overflow-y: auto;
            overflow-x: hidden;
            scrollbar-width: none;
            max-height: 100%;
            opacity: 0;
            animation: fadeIn 0.8s cubic-bezier(0.22, 1, 0.36, 1) forwards;
            animation-delay: 0.4s;
            will-change: opacity;
        }
        .form-panel::-webkit-scrollbar { display: none; }

        @media (min-width: 768px) {
            .form-panel {
                width: 55%;
                border-radius: 0;
                border-top-right-radius: 40px;
                border-bottom-right-radius: 40px;
                border-top-left-radius: 100px;
                padding: 36px;
                margin-top: 0;
                margin-left: -28px;
            }
        }
        @media (min-width: 1024px) { .form-panel { width: 52%; } }

        @keyframes fadeIn {
            0%   { opacity: 0; }
            100% { opacity: 1; }
        }

        .form-inner { width: 100%; max-width: 380px; }

        .form-head-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 4px;
        }
        .form-heading {
            color: var(--navy);
            font-family: var(--font-h);
            font-size: 1.4rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        @media (min-width: 768px) { .form-heading { font-size: 1.65rem; } }

        .admin-only-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 999px;
            background: rgba(245, 158, 11, 0.16);
            color: #B45309;
            border: 1px solid rgba(245, 158, 11, 0.36);
            font-family: var(--font-h);
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.03em;
        }

        .form-subheading {
            color: #64748B;
            font-size: 0.8rem;
            margin-bottom: 18px;
            font-weight: 500;
        }
        @media (min-width: 768px) { .form-subheading { font-size: 0.86rem; margin-bottom: 24px; } }

        .alert-error {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 10px 12px;
            background: #FEF2F2;
            border: 1px solid #FECACA;
            border-left: 4px solid #EF4444;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 0.8rem;
            color: #991B1B;
            line-height: 1.45;
        }
        .alert-error .alert-close {
            background: none; border: none; color: #DC2626;
            cursor: pointer; margin-left: auto; padding: 0;
        }

        .btn-submit {
            width: 100%;
            padding: 13px 16px;
            border-radius: 13px;
            border: none;
            background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 50%, #52B2DC 100%);
            color: #FFFFFF;
            font-family: var(--font-h);
            font-size: 0.96rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 10px 24px rgba(13, 122, 135, 0.32);
            transition: all 0.32s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        @media (min-width: 768px) { .btn-submit { padding: 15px 18px; font-size: 1rem; border-radius: 14px; } }
        .btn-submit::after {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 50%;
            height: 100%;
            background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.58) 50%, rgba(255,255,255,0) 100%);
            transform: skewX(-20deg);
            animation: sweepShine 3s cubic-bezier(0.4, 0, 0.2, 1) infinite;
        }
        @keyframes sweepShine {
            0%   { left: -100%; }
            20%  { left: 200%; }
            100% { left: 200%; }
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 30px rgba(13, 122, 135, 0.44);
        }
        .btn-submit.is-loading { opacity: 0.78; pointer-events: none; }
        .btn-submit .sso-brand-pill {
            font-size: 0.7rem;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.20);
            color: #FEF3C7;
            border: 1px solid rgba(254, 243, 199, 0.38);
        }

        .sso-info-card {
            margin-top: 18px;
            padding: 13px 15px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.78);
            border: 1px solid rgba(203, 213, 225, 0.85);
            font-size: 0.78rem;
            color: #475569;
            line-height: 1.55;
            display: grid;
            gap: 8px;
        }
        .sso-info-row {
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        .sso-info-row iconify-icon {
            color: var(--teal);
            font-size: 16px;
            margin-top: 1px;
            flex-shrink: 0;
        }

        .form-footer {
            margin-top: 18px;
            text-align: center;
        }
        @media (min-width: 768px) { .form-footer { margin-top: 26px; } }
        .form-footer p {
            color: #64748B;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        @media (min-width: 768px) { .form-footer p { font-size: 11px; } }
        .form-footer-links {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding-top: 6px;
            font-size: 11px;
            font-weight: 500;
            color: #64748B;
        }
        .form-footer-links a { color: #64748B; text-decoration: none; transition: color 0.2s; }
        .form-footer-links a:hover { color: var(--navy); }
        .form-footer-links .sep { width: 3px; height: 3px; border-radius: 50%; background: #94A3B8; }

        @media (max-width: 380px) {
            .form-subheading { display: none; }
            .slide-glass-card { padding: 8px; }
            .form-panel { padding-top: 30px; }
        }
    </style>
</head>
<body>
    <div id="pageTransition" class="page-transition active"></div>
    <canvas id="particleCanvas"></canvas>
    <div class="glow-orb glow-orb-1"></div>
    <div class="glow-orb glow-orb-2"></div>

    <div class="login-wrapper">
        <!-- =========== LEFT SLIDE PANEL (IDENTIK ILMUNA LEARN) =========== -->
        <div class="slide-panel" id="slidePanel">
            <div class="pixel-pattern"></div>

            <div class="wave-wrap">
                <svg viewBox="0 24 150 28" preserveAspectRatio="none" shape-rendering="auto">
                    <defs>
                        <path id="gentle-wave" d="M-160 44c30 0 58-18 88-18s 58 18 88 18 58-18 88-18 58 18 88 18 v44h-352z"/>
                    </defs>
                    <g class="parallax">
                        <use href="#gentle-wave" x="48" y="0" fill="rgba(255,255,255,0.30)"/>
                        <use href="#gentle-wave" x="48" y="3" fill="rgba(255,255,255,0.20)"/>
                        <use href="#gentle-wave" x="48" y="5" fill="rgba(255,255,255,0.15)"/>
                        <use href="#gentle-wave" x="48" y="7" fill="rgba(255,255,255,0.40)"/>
                    </g>
                </svg>
            </div>

            <div class="slide-inner">
                <div class="slide-glass-card">
                    <div class="logo-line">
                        <div class="logo-wrap">
                            <img src="<?= htmlspecialchars(BASE_URL . '/assets/img/logo-wa.png', ENT_QUOTES, 'UTF-8') ?>"
                                 alt="Panel WA Ilmuna"
                                 loading="lazy"
                                 draggable="false"
                                 oncontextmenu="return false;">
                        </div>
                        <div class="mobile-side-text">
                            <h2>Panel WA</h2>
                            <p>Ilmuna WA Bot · Admin</p>
                        </div>
                    </div>

                    <div class="badge">
                        <span class="dot"></span>
                        <span>Ilmuna WA Bot · Guardian Dome</span>
                    </div>

                    <h1 class="slide-h1">
                        Panel WA<br>
                        <span class="gradient-text">Ilmuna Bot</span>
                    </h1>

                    <p class="slide-desc">
                        Pusat kendali WhatsApp Bot Interaktif, Moderasi Grup Guard, Proteksi Anti-Call, dan Auto-Broadcast Akademik terintegrasi Ilmuna Learn.
                    </p>
                </div>
            </div>
        </div>

        <!-- =========== RIGHT FORM PANEL (IDENTIK LEARN · KHUSUS ADMIN SSO) =========== -->
        <div class="form-panel" id="formPanel">
            <div class="form-inner">
                <div class="form-head-row">
                    <h2 class="form-heading">Masuk</h2>
                    <span class="admin-only-pill">
                        <iconify-icon icon="solar:crown-bold" width="14"></iconify-icon>
                        <span>Khusus Admin</span>
                    </span>
                </div>
                <p class="form-subheading">Autentikasi tunggal melalui Margo One SSO</p>

                <?php if ($error !== ''): ?>
                <div class="alert-error" role="alert">
                    <iconify-icon icon="solar:danger-triangle-bold" width="18" style="color:#DC2626;flex-shrink:0;margin-top:1px;"></iconify-icon>
                    <div><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <button type="button" class="alert-close" onclick="this.parentElement.remove()" aria-label="Tutup">
                        <iconify-icon icon="solar:close-circle-bold" width="16"></iconify-icon>
                    </button>
                </div>
                <?php endif; ?>

                <a href="<?= htmlspecialchars(BASE_URL . '/login.php?sso_login=1', ENT_QUOTES, 'UTF-8') ?>"
                   class="btn-submit" id="btnMargoSsoLogin">
                    <iconify-icon icon="solar:shield-keyhole-bold" width="20"></iconify-icon>
                    <span>Masuk dengan SSO</span>
                    <span class="sso-brand-pill">Margo One</span>
                </a>

                <div class="sso-info-card">
                    <div class="sso-info-row">
                        <iconify-icon icon="solar:shield-check-bold"></iconify-icon>
                        <span><strong>Otorisasi Ketat Admin:</strong> Hanya akun ber-role <strong>Admin</strong> di Margo One SSO yang diizinkan mengakses Panel WA.</span>
                    </div>
                    <div class="sso-info-row">
                        <iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon>
                        <span><strong>Zero-Trust &amp; Guardian Dome:</strong> Proteksi OAuth 2.0 PKCE S256, JWT RS256, dan WAF Guardian Dome aktif.</span>
                    </div>
                </div>
            </div>

            <div class="form-footer">
                <p>Hak Cipta © <?= date('Y') ?> Ilmuna WA Bot</p>
                <div class="form-footer-links">
                    <a href="<?= htmlspecialchars(LEARN_PUBLIC_URL) ?>" target="_blank" rel="noopener">Ilmuna Learn</a>
                    <span class="sep"></span>
                    <a href="<?= htmlspecialchars(ILMUNA_SSO_URL) ?>" target="_blank" rel="noopener">Margo One SSO</a>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= htmlspecialchars(BASE_URL) ?>/assets/js/alert.js" defer></script>
    <script>
    (function () {
        'use strict';

        // 1. Anti-download & anti-inspect (identik learn/login.php)
        document.addEventListener('contextmenu', function (e) { e.preventDefault(); return false; });
        document.addEventListener('dragstart', function (e) {
            if (e.target && (e.target.tagName === 'IMG' || e.target.tagName === 'A')) { e.preventDefault(); return false; }
        });
        document.addEventListener('keydown', function (e) {
            var k = (e.key || '').toLowerCase();
            if (e.ctrlKey && !e.shiftKey && !e.altKey && (k === 's' || k === 'p' || k === 'u')) { e.preventDefault(); return false; }
            if (e.ctrlKey && e.shiftKey && (k === 'i' || k === 'j' || k === 'c' || k === 'k')) { e.preventDefault(); return false; }
            if (k === 'f12' || e.keyCode === 123) { e.preventDefault(); return false; }
        });

        // 2. Page transition sweep-out
        var pageTransition = document.getElementById('pageTransition');
        setTimeout(function () { if (pageTransition) pageTransition.classList.remove('active'); }, 100);

        // 3. Canvas particle network (identik learn/login.php)
        var canvas = document.getElementById('particleCanvas');
        if (canvas) {
            var ctx = canvas.getContext('2d');
            function resizeCanvas() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
            resizeCanvas();
            window.addEventListener('resize', resizeCanvas);

            var colors = ['#1D3B5E', '#0D7A87', '#52B2DC', '#10B981'];
            var particles = [];
            for (var i = 0; i < 48; i++) {
                particles.push({
                    x: Math.random() * canvas.width,
                    y: Math.random() * canvas.height,
                    vx: (Math.random() - 0.5) * 0.5,
                    vy: (Math.random() - 0.5) * 0.5,
                    r: Math.random() * 3 + 1,
                    c: colors[Math.floor(Math.random() * colors.length)],
                    o: Math.random() * 0.5 + 0.2
                });
            }
            (function animate() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                for (var i = 0; i < particles.length; i++) {
                    var p1 = particles[i];
                    p1.x += p1.vx; p1.y += p1.vy;
                    if (p1.x < 0 || p1.x > canvas.width) p1.vx *= -1;
                    if (p1.y < 0 || p1.y > canvas.height) p1.vy *= -1;
                    ctx.beginPath(); ctx.arc(p1.x, p1.y, p1.r, 0, Math.PI * 2);
                    ctx.fillStyle = p1.c; ctx.globalAlpha = p1.o; ctx.fill();
                    for (var j = i + 1; j < particles.length; j++) {
                        var p2 = particles[j];
                        var dist = Math.hypot(p1.x - p2.x, p1.y - p2.y);
                        if (dist < 100) {
                            ctx.beginPath(); ctx.moveTo(p1.x, p1.y); ctx.lineTo(p2.x, p2.y);
                            ctx.strokeStyle = p1.c; ctx.globalAlpha = (1 - dist / 100) * 0.2; ctx.stroke();
                        }
                    }
                }
                ctx.globalAlpha = 1;
                requestAnimationFrame(animate);
            })();
        }

        // 4. SSO loading state
        var btnSso = document.getElementById('btnMargoSsoLogin');
        if (btnSso) {
            btnSso.addEventListener('click', function () {
                btnSso.classList.add('is-loading');
                btnSso.innerHTML = '<iconify-icon icon="solar:refresh-circle-bold" width="19"></iconify-icon><span>Menghubungkan Margo One SSO...</span>';
            });
        }

        // 5. Window.Ilmuna toast notification
        window.addEventListener('DOMContentLoaded', function () {
            <?php if ($error !== ''): ?>
            if (window.Ilmuna) Ilmuna.error('Autentikasi Ditolak', <?= json_encode($error) ?>);
            <?php elseif ($loggedOut): ?>
            if (window.Ilmuna) Ilmuna.success('Berhasil Keluar', 'Sesi Admin Panel WA telah diakhiri.');
            <?php endif; ?>
        });
    })();
    </script>
</body>
</html>
