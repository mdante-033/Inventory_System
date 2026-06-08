<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

$year = date('Y');
$contact_name = "James";
$contact_phone = "0713984839";
$contact_email = "support@luxestore.com";

$isLoggedIn = isset($_SESSION['user_id'], $_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$userName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Valued Customer';
$userRole = $_SESSION['role'] ?? 'customer';
$dashboardLink = in_array($userRole, ['admin', 'manager'], true) ? 'admin.php' : 'customer_dashboard.php';
$shopLink = $isLoggedIn ? 'customer_dashboard.php' : 'register.php';
$flashSuccess = $_SESSION['flash_success'] ?? '';
if ($flashSuccess !== '') unset($_SESSION['flash_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LuxeStore — The Pinnacle of Premium</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold: #c9a84c;
            --gold-light: #e8c97a;
            --gold-pale: rgba(201,168,76,0.12);
            --gold-pale-2: rgba(201,168,76,0.07);
            --cream: #f5f0e8;
            --ink: #0e0c09;
            --ink-mid: #1c1810;
            --ink-soft: #2e2b24;
            --mist: rgba(245,240,232,0.06);
            --mist-border: rgba(245,240,232,0.12);
            --text-dim: rgba(245,240,232,0.5);
            --text-mid: rgba(245,240,232,0.75);
            --serif: 'Cormorant Garamond', Georgia, serif;
            --sans: 'Outfit', sans-serif;
            --ease-out: cubic-bezier(0.16, 1, 0.3, 1);
            --header-h: 72px;
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        html {
            scroll-behavior: smooth;
            /* This is the key fix — all section anchors clear the sticky header */
            scroll-padding-top: var(--header-h);
        }

        /* Scroll margin fix for every anchored section */
        section[id], div[id] {
            scroll-margin-top: var(--header-h);
        }

        body {
            font-family: var(--sans);
            background: var(--ink);
            color: var(--cream);
            line-height: 1.65;
            overflow-x: hidden;
        }

        /* Grain overlay */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 9999;
            opacity: 0.4;
        }

        img { display: block; max-width: 100%; }
        a { color: inherit; text-decoration: none; }
        button { font-family: var(--sans); cursor: pointer; }
        address { font-style: normal; }

        .container { max-width: 1220px; margin: 0 auto; padding: 0 2rem; }

        .sr-only {
            position: absolute; width: 1px; height: 1px;
            padding: 0; margin: -1px; overflow: hidden;
            clip: rect(0,0,0,0); white-space: nowrap; border: 0;
        }

        .skip-link {
            position: absolute; top: -60px; left: 1.5rem;
            background: var(--cream); color: var(--ink);
            padding: 0.6rem 1.2rem; border-radius: 4px;
            font-weight: 600; font-size: 0.85rem; z-index: 9000;
            transition: top 0.2s;
        }
        .skip-link:focus { top: 1rem; }

        /* ── HEADER ── */
        .site-header {
            position: fixed; top: 0; left: 0; right: 0;
            z-index: 800;
            height: var(--header-h);
            display: flex; align-items: center;
            padding: 0;
            transition: background 0.4s, box-shadow 0.4s;
        }
        .site-header.scrolled {
            background: rgba(14,12,9,0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--mist-border);
            box-shadow: 0 4px 32px rgba(0,0,0,0.4);
        }
        .header-inner {
            width: 100%; display: flex;
            align-items: center; justify-content: space-between;
        }
        .logo {
            display: flex; align-items: center;
            gap: 0.7rem; transition: opacity 0.2s;
        }
        .logo:hover { opacity: 0.8; }
        .logo-mark {
            width: 36px; height: 36px; border: 1.5px solid var(--gold);
            border-radius: 50%; display: flex; align-items: center;
            justify-content: center; color: var(--gold);
            font-family: var(--serif); font-size: 1rem; font-weight: 600;
            flex-shrink: 0;
        }
        .logo-name {
            font-family: var(--serif);
            font-size: 1.45rem; font-weight: 400; letter-spacing: 0.06em;
        }
        .logo-name em { font-style: italic; color: var(--gold); }

        .primary-nav { display: flex; align-items: center; gap: 2.5rem; }
        .nav-link {
            font-size: 0.8rem; font-weight: 500; letter-spacing: 0.12em;
            text-transform: uppercase; color: var(--text-mid);
            position: relative; padding-bottom: 3px; transition: color 0.25s;
        }
        .nav-link::after {
            content: ''; position: absolute; bottom: 0; left: 0;
            width: 0; height: 1px; background: var(--gold);
            transition: width 0.3s var(--ease-out);
        }
        .nav-link:hover { color: var(--cream); }
        .nav-link:hover::after { width: 100%; }

        .contact-wrapper { position: relative; }
        .contact-btn {
            background: none; border: none; color: var(--text-mid);
            font-size: 0.8rem; font-weight: 500; letter-spacing: 0.12em;
            text-transform: uppercase; padding-bottom: 3px;
            position: relative; transition: color 0.25s;
        }
        .contact-btn::after {
            content: ''; position: absolute; bottom: 0; left: 0;
            width: 0; height: 1px; background: var(--gold);
            transition: width 0.3s var(--ease-out);
        }
        .contact-btn:hover { color: var(--cream); }
        .contact-btn:hover::after { width: 100%; }

        .contact-popover {
            position: absolute; top: calc(100% + 16px); right: 0;
            width: 230px; background: var(--ink-soft);
            border: 1px solid var(--mist-border); border-radius: 8px;
            padding: 1.2rem 1.4rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.6);
            opacity: 0; pointer-events: none;
            transform: translateY(-8px);
            transition: opacity 0.25s, transform 0.25s var(--ease-out);
            z-index: 900;
        }
        .contact-popover.open { opacity: 1; pointer-events: all; transform: translateY(0); }
        .cp-label { font-size: 0.7rem; letter-spacing: 0.14em; text-transform: uppercase; color: var(--gold); margin-bottom: 0.6rem; }
        .cp-name { font-family: var(--serif); font-size: 1.3rem; margin-bottom: 0.15rem; }
        .cp-role { font-size: 0.78rem; color: var(--text-dim); margin-bottom: 0.9rem; }
        .cp-phone { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.9rem; color: var(--gold-light); transition: color 0.2s; }
        .cp-phone:hover { color: var(--cream); }

        .auth-links { display: flex; align-items: center; gap: 0.9rem; }
        .btn-nav-link { font-size: 0.78rem; font-weight: 500; letter-spacing: 0.14em; text-transform: uppercase; color: var(--text-mid); transition: color 0.3s; }
        .btn-nav-link:hover { color: var(--cream); }
        .btn-nav-primary {
            font-size: 0.78rem; font-weight: 600; letter-spacing: 0.14em;
            text-transform: uppercase; border: 1px solid var(--gold);
            color: var(--gold); padding: 0.55rem 1.4rem; border-radius: 2px;
            transition: background 0.3s, color 0.3s;
        }
        .btn-nav-primary:hover { background: var(--gold); color: var(--ink); }

        .hamburger {
            display: none; flex-direction: column; gap: 5px;
            background: none; border: none; padding: 4px;
        }
        .hamburger span {
            display: block; width: 24px; height: 1.5px;
            background: var(--cream);
            transition: transform 0.3s, opacity 0.3s;
        }
        .hamburger.open span:nth-child(1) { transform: translateY(6.5px) rotate(45deg); }
        .hamburger.open span:nth-child(2) { opacity: 0; }
        .hamburger.open span:nth-child(3) { transform: translateY(-6.5px) rotate(-45deg); }

        .mobile-nav {
            display: none; position: fixed; top: 0; right: -100%; bottom: 0;
            width: min(340px, 80vw); background: var(--ink-mid);
            border-left: 1px solid var(--mist-border); z-index: 700;
            padding: 6rem 2.5rem 3rem; flex-direction: column;
            gap: 1.5rem; transition: right 0.4s var(--ease-out);
        }
        .mobile-nav.open { right: 0; }
        .mobile-nav a {
            font-size: 1.15rem; font-weight: 300; letter-spacing: 0.06em;
            color: var(--text-mid); border-bottom: 1px solid var(--mist-border);
            padding-bottom: 1rem; transition: color 0.2s;
        }
        .mobile-nav a:hover { color: var(--gold); }
        .nav-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 699; }
        .nav-overlay.open { display: block; }

        /* ── HERO ── */
        .hero {
            min-height: 100vh; position: relative;
            display: flex; align-items: center; overflow: hidden;
        }
        .hero-bg {
            position: absolute; inset: 0;
            background: url('https://images.unsplash.com/photo-1490481651871-ab68de25d43d?auto=format&fit=crop&w=2000&q=80') center/cover no-repeat;
            /* Fallback gradient if image fails */
            background-color: #1a1510;
            transform: scale(1.05);
            animation: heroZoom 12s ease-out forwards;
        }
        @keyframes heroZoom { from { transform: scale(1.05); } to { transform: scale(1); } }
        .hero-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(120deg, rgba(14,12,9,0.92) 0%, rgba(14,12,9,0.72) 55%, rgba(14,12,9,0.4) 100%);
        }
        .hero-lines { position: absolute; inset: 0; pointer-events: none; opacity: 0.12; }
        .hero-lines::before, .hero-lines::after {
            content: ''; position: absolute; top: 0; bottom: 0; width: 1px;
            background: linear-gradient(to bottom, transparent, var(--gold), transparent);
        }
        .hero-lines::before { left: 16%; }
        .hero-lines::after { left: 84%; }

        .hero-content {
            position: relative; z-index: 2;
            max-width: 680px; padding: 8rem 0 5rem;
        }
        .hero-eyebrow {
            display: flex; align-items: center; gap: 1rem;
            font-size: 0.72rem; letter-spacing: 0.25em; text-transform: uppercase;
            color: var(--gold); margin-bottom: 2rem;
            opacity: 0; animation: fadeUp 0.7s 0.3s var(--ease-out) forwards;
        }
        .hero-eyebrow::before { content: ''; display: block; width: 40px; height: 1px; background: var(--gold); }
        .hero-title {
            font-family: var(--serif); font-size: clamp(3.5rem, 7vw, 6.5rem);
            font-weight: 300; line-height: 1.05; margin-bottom: 1.8rem;
            opacity: 0; animation: fadeUp 0.8s 0.5s var(--ease-out) forwards;
        }
        .hero-title em { font-style: italic; color: var(--gold-light); display: block; }
        .hero-desc {
            font-size: 1.05rem; font-weight: 300; color: var(--text-mid);
            line-height: 1.75; max-width: 460px; margin-bottom: 2.8rem;
            opacity: 0; animation: fadeUp 0.8s 0.7s var(--ease-out) forwards;
        }
        .hero-actions {
            display: flex; gap: 1.2rem; flex-wrap: wrap;
            opacity: 0; animation: fadeUp 0.8s 0.9s var(--ease-out) forwards;
        }
        .btn-cta {
            display: inline-flex; align-items: center; gap: 0.65rem;
            background: var(--gold); color: var(--ink);
            font-size: 0.78rem; font-weight: 600; letter-spacing: 0.16em;
            text-transform: uppercase; padding: 1rem 2rem; border-radius: 2px;
            border: 1px solid var(--gold); transition: background 0.3s, transform 0.2s;
        }
        .btn-cta:hover { background: var(--gold-light); transform: translateY(-2px); }
        .btn-cta svg { width: 16px; height: 16px; flex-shrink: 0; }
        .btn-ghost {
            display: inline-flex; align-items: center; gap: 0.65rem;
            background: transparent; color: var(--cream);
            font-size: 0.78rem; font-weight: 500; letter-spacing: 0.16em;
            text-transform: uppercase; padding: 1rem 2rem;
            border: 1px solid var(--mist-border); border-radius: 2px;
            transition: border-color 0.3s, color 0.3s;
        }
        .btn-ghost:hover { border-color: var(--gold); color: var(--gold); }
        .hero-auth-note {
            margin-top: 1.2rem; color: var(--text-mid); font-size: 0.92rem;
            opacity: 0; animation: fadeUp 0.8s 1.05s var(--ease-out) forwards;
        }
        .hero-auth-note a { color: var(--gold-light); font-weight: 500; }
        .hero-scroll {
            position: absolute; bottom: 2.5rem; left: 50%; transform: translateX(-50%);
            display: flex; flex-direction: column; align-items: center; gap: 0.5rem;
            font-size: 0.65rem; letter-spacing: 0.2em; text-transform: uppercase;
            color: var(--text-dim); z-index: 2; animation: fadeIn 1s 1.5s both;
        }
        .hero-scroll-line {
            width: 1px; height: 40px;
            background: linear-gradient(to bottom, var(--gold), transparent);
            animation: scrollPulse 2s ease-in-out infinite;
        }
        @keyframes scrollPulse { 0%, 100% { opacity: 0.4; } 50% { opacity: 1; } }

        .flash-banner {
            position: fixed; top: calc(var(--header-h) + 12px); left: 50%;
            transform: translateX(-50%); z-index: 780;
            width: min(720px, calc(100% - 2rem));
            padding: 1rem 1.2rem; border-radius: 6px;
            border: 1px solid rgba(201,168,76,0.28);
            background: rgba(24,22,17,0.94); backdrop-filter: blur(16px);
            color: var(--cream); box-shadow: 0 18px 45px rgba(0,0,0,0.36);
        }
        .flash-banner strong { color: var(--gold-light); }

        /* ── MARQUEE ── */
        .marquee-strip { background: var(--gold); padding: 0.7rem 0; overflow: hidden; }
        .marquee-track { display: flex; white-space: nowrap; animation: marquee 28s linear infinite; }
        .marquee-item {
            display: inline-flex; align-items: center; gap: 1.5rem;
            padding: 0 2.5rem; font-size: 0.7rem; font-weight: 600;
            letter-spacing: 0.18em; text-transform: uppercase; color: var(--ink);
        }
        .marquee-dot { width: 4px; height: 4px; border-radius: 50%; background: var(--ink); opacity: 0.4; }
        @keyframes marquee { from { transform: translateX(0); } to { transform: translateX(-50%); } }
        @media (prefers-reduced-motion: reduce) {
            .marquee-track { animation: none; }
            .hero-bg { animation: none; transform: scale(1); }
        }

        /* ── SHARED ── */
        .section-label {
            display: inline-flex; align-items: center; gap: 0.8rem;
            font-size: 0.68rem; letter-spacing: 0.22em; text-transform: uppercase;
            color: var(--gold); margin-bottom: 1.2rem;
        }
        .section-label::before { content: ''; width: 28px; height: 1px; background: var(--gold); }
        .section-title { font-family: var(--serif); font-size: clamp(2.2rem, 4vw, 3.5rem); font-weight: 300; line-height: 1.15; }
        .section-subtitle { color: var(--text-dim); font-size: 1rem; font-weight: 300; max-width: 500px; line-height: 1.7; margin-top: 1rem; }

        .reveal { opacity: 0; transform: translateY(32px); transition: opacity 0.8s var(--ease-out), transform 0.8s var(--ease-out); }
        .reveal.visible { opacity: 1; transform: translateY(0); }
        .reveal-delay-1 { transition-delay: 0.1s; }
        .reveal-delay-2 { transition-delay: 0.2s; }
        .reveal-delay-3 { transition-delay: 0.3s; }

        /* ── FEATURES ── */
        .features { padding: 7rem 0; background: var(--ink); }
        .features-top { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 5rem; gap: 2rem; flex-wrap: wrap; }
        .features-grid {
            display: grid; grid-template-columns: repeat(4, 1fr);
            border: 1px solid var(--mist-border); border-radius: 4px; overflow: hidden;
        }
        .feature-card {
            padding: 2.8rem 2.2rem; border-right: 1px solid var(--mist-border);
            transition: background 0.35s; position: relative;
        }
        .feature-card:last-child { border-right: none; }
        .feature-card:hover { background: var(--mist); }
        .feature-icon {
            width: 48px; height: 48px; border: 1px solid var(--gold-pale);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            margin-bottom: 1.8rem; color: var(--gold);
        }
        .feature-icon svg { width: 22px; height: 22px; }
        .feature-num { position: absolute; top: 1.8rem; right: 2rem; font-family: var(--serif); font-size: 0.75rem; color: var(--text-dim); }
        .feature-title { font-family: var(--serif); font-size: 1.3rem; margin-bottom: 0.8rem; }
        .feature-desc { font-size: 0.88rem; color: var(--text-dim); line-height: 1.75; font-weight: 300; }

        /* ── ABOUT ── */
        .about { padding: 7rem 0; background: var(--ink-mid); }
        .about-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6rem; align-items: center; }
        .about-image-wrap { position: relative; }
        .about-img { width: 100%; aspect-ratio: 4/5; object-fit: cover; border-radius: 2px; filter: grayscale(20%) contrast(1.05); }
        .about-img-accent { position: absolute; bottom: -2rem; right: -2rem; width: 55%; aspect-ratio: 1; border: 1px solid var(--gold); border-radius: 2px; z-index: -1; }
        .about-badge {
            position: absolute; top: 2rem; left: -2rem;
            background: var(--gold); color: var(--ink); padding: 1.2rem;
            border-radius: 2px; text-align: center; box-shadow: 0 8px 32px rgba(0,0,0,0.4);
        }
        .about-badge-num { font-family: var(--serif); font-size: 2rem; font-weight: 600; line-height: 1; display: block; }
        .about-badge-txt { font-size: 0.65rem; letter-spacing: 0.15em; text-transform: uppercase; margin-top: 0.3rem; display: block; }
        .about-text .section-subtitle { max-width: 100%; }
        .stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-top: 2.5rem; }
        .stat { border-top: 1px solid var(--mist-border); padding-top: 1.2rem; }
        .stat-num { font-family: var(--serif); font-size: 2.2rem; font-weight: 300; color: var(--gold); display: block; }
        .stat-lbl { font-size: 0.75rem; color: var(--text-dim); letter-spacing: 0.1em; text-transform: uppercase; margin-top: 0.2rem; }

        /* ── TESTIMONIALS ── */
        .testimonials { padding: 7rem 0; background: var(--ink); }
        .testi-header { text-align: center; margin-bottom: 4rem; }
        .testi-header .section-label { justify-content: center; }
        .testi-header .section-label::before { display: none; }
        .testi-header .section-label::after { content: ''; width: 28px; height: 1px; background: var(--gold); }
        .testi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5px; background: var(--mist-border); border-radius: 4px; overflow: hidden; }
        .testi-card { background: var(--ink); padding: 3rem 2.5rem; transition: background 0.35s; }
        .testi-card:hover { background: var(--ink-soft); }
        .testi-stars { display: flex; gap: 2px; margin-bottom: 1.5rem; color: var(--gold); font-size: 0.9rem; }
        .testi-quote { font-family: var(--serif); font-size: 4rem; line-height: 1; color: var(--gold); opacity: 0.25; margin-bottom: 1.2rem; }
        .testi-text { font-family: var(--serif); font-size: 1.15rem; font-weight: 300; font-style: italic; line-height: 1.75; color: var(--text-mid); margin-bottom: 2rem; }
        .testi-author-row { display: flex; align-items: center; gap: 1rem; }
        .testi-avatar {
            width: 42px; height: 42px; border-radius: 50%; background: var(--gold-pale);
            border: 1px solid var(--mist-border); display: flex; align-items: center;
            justify-content: center; font-family: var(--serif); font-size: 1.1rem;
            color: var(--gold); flex-shrink: 0;
        }
        .testi-name { font-size: 0.9rem; font-weight: 500; margin-bottom: 0.15rem; }
        .testi-role { font-size: 0.75rem; color: var(--text-dim); }

        /* ── FAQ ── */
        .faq { padding: 7rem 0; background: var(--ink-mid); }
        .faq-layout { display: grid; grid-template-columns: 1fr 1.5fr; gap: 5rem; align-items: start; }
        .faq-sticky { position: sticky; top: calc(var(--header-h) + 2rem); }
        .faq-sticky .section-subtitle { margin-bottom: 2rem; }
        .faq-contact-card {
            background: var(--gold-pale); border: 1px solid rgba(201,168,76,0.25);
            border-radius: 6px; padding: 1.8rem; margin-top: 2.5rem;
        }
        .fcc-label { font-size: 0.68rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--gold); margin-bottom: 0.8rem; }
        .fcc-name { font-family: var(--serif); font-size: 1.4rem; margin-bottom: 0.2rem; }
        .fcc-role { font-size: 0.8rem; color: var(--text-dim); margin-bottom: 1.2rem; }
        .fcc-phone { display: flex; align-items: center; gap: 0.5rem; color: var(--gold-light); font-weight: 500; font-size: 0.95rem; transition: color 0.2s; }
        .fcc-phone:hover { color: var(--cream); }
        .faq-list { display: flex; flex-direction: column; }
        .faq-item { border-bottom: 1px solid var(--mist-border); }
        .faq-item:first-child { border-top: 1px solid var(--mist-border); }
        .faq-q {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1.6rem 0; gap: 1rem; background: none; border: none;
            color: var(--cream); width: 100%; text-align: left; font-size: 1rem;
            font-family: var(--sans); transition: color 0.2s;
        }
        .faq-q:hover, .faq-q.active { color: var(--gold); }
        .faq-icon {
            width: 28px; height: 28px; flex-shrink: 0;
            border: 1px solid var(--mist-border); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem; color: var(--text-dim);
            transition: transform 0.35s, border-color 0.25s, color 0.25s;
        }
        .faq-q.active .faq-icon { transform: rotate(45deg); border-color: var(--gold); color: var(--gold); }
        .faq-a { overflow: hidden; max-height: 0; transition: max-height 0.45s var(--ease-out), padding 0.3s; }
        .faq-a.open { max-height: 400px; padding-bottom: 1.5rem; }
        .faq-a p { font-size: 0.92rem; color: var(--text-dim); line-height: 1.8; font-weight: 300; }

        /* ── FOOTER ── */
        .site-footer { background: var(--ink-mid); border-top: 1px solid var(--mist-border); padding: 5rem 0 2rem; }
        .footer-top { display: grid; grid-template-columns: 1.5fr 1fr 1fr 1fr; gap: 3rem; padding-bottom: 4rem; border-bottom: 1px solid var(--mist-border); margin-bottom: 2.5rem; }
        .footer-brand p { font-size: 0.88rem; color: var(--text-dim); line-height: 1.75; font-weight: 300; max-width: 260px; margin-top: 1.2rem; }
        .footer-col h4 { font-size: 0.68rem; letter-spacing: 0.2em; text-transform: uppercase; color: var(--gold); margin-bottom: 1.4rem; }
        .footer-col ul { list-style: none; display: flex; flex-direction: column; gap: 0.75rem; }
        .footer-col a { font-size: 0.88rem; color: var(--text-dim); transition: color 0.2s; }
        .footer-col a:hover { color: var(--cream); }
        .footer-policies { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px; background: var(--mist-border); border-radius: 4px; overflow: hidden; margin-bottom: 2.5rem; }
        .policy-card { background: var(--ink-mid); padding: 1.5rem; }
        .policy-card h5 { font-size: 0.72rem; letter-spacing: 0.16em; text-transform: uppercase; color: var(--gold); margin-bottom: 0.6rem; }
        .policy-card p { font-size: 0.83rem; color: var(--text-dim); line-height: 1.7; font-weight: 300; }
        .footer-bottom { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; }
        .footer-bottom p { font-size: 0.78rem; color: var(--text-dim); }
        .footer-bottom-links { display: flex; gap: 1.5rem; }
        .footer-bottom-links a { font-size: 0.78rem; color: var(--text-dim); transition: color 0.2s; }
        .footer-bottom-links a:hover { color: var(--gold); }

        @keyframes fadeUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        /* ── RESPONSIVE ── */
        @media (max-width: 1024px) {
            .features-grid { grid-template-columns: repeat(2, 1fr); }
            .feature-card:nth-child(2) { border-right: none; }
            .feature-card:nth-child(1), .feature-card:nth-child(2) { border-bottom: 1px solid var(--mist-border); }
            .footer-top { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 900px) {
            .about-grid { grid-template-columns: 1fr; gap: 3rem; }
            .about-image-wrap { max-width: 480px; }
            .faq-layout { grid-template-columns: 1fr; gap: 3rem; }
            .faq-sticky { position: static; }
            .testi-grid { grid-template-columns: 1fr; }
            .footer-policies { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            :root { --header-h: 64px; }
            .primary-nav { display: none; }
            .hamburger { display: flex; }
            .mobile-nav { display: flex; }
            .features-grid { grid-template-columns: 1fr; border: none; gap: 1px; background: var(--mist-border); border-radius: 4px; overflow: hidden; }
            .feature-card { border-right: none; }
            .footer-top { grid-template-columns: 1fr; gap: 2rem; }
            .hero-content { padding-top: 6rem; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .footer-bottom { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 480px) {
            .hero-actions { flex-direction: column; }
            .btn-cta, .btn-ghost { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <header class="site-header" id="site-header" role="banner">
        <div class="container">
            <div class="header-inner">
                <a href="#home" class="logo" aria-label="LuxeStore Home">
                    <div class="logo-mark" aria-hidden="true">L</div>
                    <span class="logo-name">Luxe<em>Store</em></span>
                </a>
                <nav class="primary-nav" aria-label="Primary navigation">
                    <a href="#home" class="nav-link">Home</a>
                    <a href="<?= htmlspecialchars($shopLink) ?>" class="nav-link">Shop</a>
                    <a href="#about" class="nav-link">About</a>
                    <a href="#faq" class="nav-link">FAQ</a>
                    <div class="contact-wrapper">
                        <button class="contact-btn" id="contactBtn" aria-expanded="false">Contact</button>
                        <div class="contact-popover" id="contactPopover" role="dialog" aria-label="Contact information">
                            <p class="cp-label">Concierge Support</p>
                            <p class="cp-name"><?= htmlspecialchars($contact_name) ?></p>
                            <p class="cp-role">Customer Support Specialist</p>
                            <a class="cp-phone" href="tel:<?= htmlspecialchars($contact_phone) ?>"><?= htmlspecialchars($contact_phone) ?></a>
                        </div>
                    </div>
                    <div class="auth-links">
                        <?php if ($isLoggedIn): ?>
                            <a href="<?= htmlspecialchars($dashboardLink) ?>" class="btn-nav-link">Dashboard</a>
                            <a href="logout.php" class="btn-nav-primary">Logout</a>
                        <?php else: ?>
                            <a href="login.php" class="btn-nav-link">Sign In</a>
                            <a href="register.php" class="btn-nav-primary">Create Account</a>
                        <?php endif; ?>
                    </div>
                </nav>
                <button class="hamburger" id="hamburger" aria-label="Open navigation" aria-expanded="false">
                    <span></span><span></span><span></span>
                </button>
            </div>
        </div>
    </header>

    <div class="nav-overlay" id="navOverlay" aria-hidden="true"></div>
    <nav class="mobile-nav" id="mobileNav" aria-label="Mobile navigation">
        <a href="#home">Home</a>
        <a href="<?= htmlspecialchars($shopLink) ?>">Shop</a>
        <a href="#about">About</a>
        <a href="#faq">FAQ</a>
        <a href="tel:<?= htmlspecialchars($contact_phone) ?>">Call <?= htmlspecialchars($contact_name) ?>: <?= htmlspecialchars($contact_phone) ?></a>
        <?php if ($isLoggedIn): ?>
            <a href="<?= htmlspecialchars($dashboardLink) ?>" style="color:var(--gold)">Go to Dashboard &rarr;</a>
            <a href="logout.php">Logout</a>
        <?php else: ?>
            <a href="register.php" style="color:var(--gold)">Create Account &rarr;</a>
            <a href="login.php">Already have an account? Sign In</a>
        <?php endif; ?>
    </nav>

    <main id="main-content">
        <?php if ($flashSuccess !== ''): ?>
            <div class="flash-banner" role="status" aria-live="polite">
                <strong>Success:</strong> <?= htmlspecialchars($flashSuccess) ?>
            </div>
        <?php endif; ?>

        <!-- HERO -->
        <section id="home" class="hero" aria-labelledby="hero-heading">
            <div class="hero-bg" aria-hidden="true"></div>
            <div class="hero-overlay" aria-hidden="true"></div>
            <div class="hero-lines" aria-hidden="true"></div>
            <div class="container">
                <div class="hero-content">
                    <?php if ($isLoggedIn): ?>
                        <p class="hero-eyebrow">Welcome Back &nbsp;&middot;&nbsp; <?= htmlspecialchars($userName) ?></p>
                        <h1 class="hero-title" id="hero-heading">Your Next Exceptional Find<em>Is Ready</em></h1>
                        <p class="hero-desc">Pick up where you left off, review your account, and step back into a premium shopping experience tailored to you.</p>
                    <?php else: ?>
                        <p class="hero-eyebrow">Create Your Account &nbsp;&middot;&nbsp; Premium Access</p>
                        <h1 class="hero-title" id="hero-heading">Luxury Shopping Starts With<em>Your Account</em></h1>
                        <p class="hero-desc">Register in minutes to unlock a smoother checkout, track your orders, and keep your details ready for every premium purchase.</p>
                    <?php endif; ?>
                    <div class="hero-actions">
                        <?php if ($isLoggedIn): ?>
                            <a href="<?= htmlspecialchars($dashboardLink) ?>" class="btn-cta">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                Go to Dashboard
                            </a>
                            <a href="logout.php" class="btn-ghost">Logout</a>
                        <?php else: ?>
                            <a href="register.php" class="btn-cta">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                Create Account
                            </a>
                            <a href="login.php" class="btn-ghost">Sign In</a>
                        <?php endif; ?>
                    </div>
                    <?php if (!$isLoggedIn): ?>
                        <p class="hero-auth-note">Already have an account? <a href="login.php">Sign In</a></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-scroll" aria-hidden="true">
                <div class="hero-scroll-line"></div>
                <span>Scroll</span>
            </div>
        </section>

        <!-- MARQUEE -->
        <div class="marquee-strip" aria-hidden="true">
            <div class="marquee-track">
                <?php
                $items = ['Free Shipping Over $500','Authenticity Guaranteed','White-Glove Delivery','30-Day Returns','Global Concierge Support','500+ Premium Brands','10,000+ Happy Customers'];
                $all = array_merge($items, $items, $items, $items);
                foreach ($all as $item) {
                    echo '<span class="marquee-item">' . htmlspecialchars($item) . '<span class="marquee-dot"></span></span>';
                }
                ?>
            </div>
        </div>

        <!-- FEATURES -->
        <section class="features" aria-labelledby="features-heading">
            <div class="container">
                <div class="features-top">
                    <div>
                        <p class="section-label reveal">Why LuxeStore</p>
                        <h2 class="section-title reveal reveal-delay-1" id="features-heading">The Standard We<br>Hold Ourselves To</h2>
                    </div>
                    <p class="section-subtitle reveal reveal-delay-2">Every decision &mdash; from sourcing to delivery &mdash; guided by one principle: you deserve the extraordinary.</p>
                </div>
                <div class="features-grid reveal reveal-delay-2">
                    <div class="feature-card">
                        <span class="feature-num">01</span>
                        <div class="feature-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        </div>
                        <h3 class="feature-title">Expert Curation</h3>
                        <p class="feature-desc">Every item personally selected by our panel of luxury specialists with decades of combined experience.</p>
                    </div>
                    <div class="feature-card">
                        <span class="feature-num">02</span>
                        <div class="feature-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </div>
                        <h3 class="feature-title">100% Authentic</h3>
                        <p class="feature-desc">Direct brand partnerships and rigorous authentication. Every high-value item ships with a certificate of authenticity.</p>
                    </div>
                    <div class="feature-card">
                        <span class="feature-num">03</span>
                        <div class="feature-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v4h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                        </div>
                        <h3 class="feature-title">White-Glove Delivery</h3>
                        <p class="feature-desc">Premium packaging, full tracking, and signature confirmation on every order &mdash; because presentation matters.</p>
                    </div>
                    <div class="feature-card">
                        <span class="feature-num">04</span>
                        <div class="feature-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
                        </div>
                        <h3 class="feature-title">Concierge Service</h3>
                        <p class="feature-desc">Our dedicated team assists with every step &mdash; product selection, returns, exchanges, and care guidance.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ABOUT -->
        <section id="about" class="about" aria-labelledby="about-heading">
            <div class="container">
                <div class="about-grid">
                    <div class="about-image-wrap reveal">
                        <img class="about-img"
                             src="https://images.unsplash.com/photo-1441986300917-64674bd600d8?auto=format&fit=crop&w=800&q=80"
                             alt="Elegant luxury boutique interior" loading="lazy">
                        <div class="about-img-accent" aria-hidden="true"></div>
                        <div class="about-badge">
                            <span class="about-badge-num">2018</span>
                            <span class="about-badge-txt">Est.</span>
                        </div>
                    </div>
                    <div class="about-text">
                        <p class="section-label reveal">Our Story</p>
                        <h2 class="section-title reveal reveal-delay-1" id="about-heading">Redefining What<br>Luxury Means</h2>
                        <p class="section-subtitle reveal reveal-delay-2">LuxeStore was born from a simple conviction: that exceptional quality, honest craftsmanship, and genuine service should always come together.</p>
                        <p class="section-subtitle reveal reveal-delay-3" style="margin-top:0.8rem;">Every product passes through our rigorous selection process &mdash; because we believe what you own should reflect your standards.</p>
                        <div class="stats-row reveal reveal-delay-3">
                            <div class="stat"><span class="stat-num">10K+</span><span class="stat-lbl">Happy Clients</span></div>
                            <div class="stat"><span class="stat-num">500+</span><span class="stat-lbl">Premium Brands</span></div>
                            <div class="stat"><span class="stat-num">50+</span><span class="stat-lbl">Countries</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- TESTIMONIALS -->
        <section id="testimonials" class="testimonials" aria-labelledby="testi-heading">
            <div class="container">
                <div class="testi-header">
                    <p class="section-label reveal">Testimonials</p>
                    <h2 class="section-title reveal reveal-delay-1" id="testi-heading">What Our Clients Say</h2>
                    <p class="section-subtitle reveal reveal-delay-2" style="margin:1rem auto 0;text-align:center;">Thousands of discerning customers trust LuxeStore for the moments that matter.</p>
                </div>
                <div class="testi-grid reveal reveal-delay-2">
                    <?php
                    $testimonials = [
                        ['name'=>'Sarah Johnson','role'=>'Fashion Designer, NY','initial'=>'S','text'=>"Exceptional quality that speaks before you do. Every purchase has been a lesson in what true craftsmanship looks like. I won't shop anywhere else."],
                        ['name'=>'Michael Chen','role'=>'Business Executive, HK','initial'=>'M','text'=>"The attention to detail — from product to packaging — is extraordinary. LuxeStore doesn't just sell products; it delivers experiences."],
                        ['name'=>'Emma Rodriguez','role'=>'Interior Designer, Paris','initial'=>'E','text'=>'My go-to for anything premium. The curation is impeccable, the service is personal, and the quality never disappoints.'],
                    ];
                    foreach ($testimonials as $t): ?>
                    <article class="testi-card">
                        <div class="testi-stars" aria-label="5 stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                        <p class="testi-quote" aria-hidden="true">&ldquo;</p>
                        <p class="testi-text"><?= htmlspecialchars($t['text']) ?></p>
                        <div class="testi-author-row">
                            <div class="testi-avatar" aria-hidden="true"><?= htmlspecialchars($t['initial']) ?></div>
                            <div>
                                <p class="testi-name"><?= htmlspecialchars($t['name']) ?></p>
                                <p class="testi-role"><?= htmlspecialchars($t['role']) ?></p>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section id="faq" class="faq" aria-labelledby="faq-heading">
            <div class="container">
                <div class="faq-layout">
                    <div class="faq-sticky reveal">
                        <p class="section-label">FAQ</p>
                        <h2 class="section-title" id="faq-heading">Questions,<br>Answered</h2>
                        <p class="section-subtitle">Still have something on your mind? Our concierge team is a call away.</p>
                        <div class="faq-contact-card">
                            <p class="fcc-label">Talk to a Specialist</p>
                            <p class="fcc-name"><?= htmlspecialchars($contact_name) ?></p>
                            <p class="fcc-role">Customer Support Specialist</p>
                            <a class="fcc-phone" href="tel:<?= htmlspecialchars($contact_phone) ?>"><?= htmlspecialchars($contact_phone) ?></a>
                        </div>
                    </div>
                    <div class="faq-list reveal reveal-delay-2">
                        <?php
                        $faqs = [
                            ['q'=>'What makes LuxeStore different from other luxury retailers?','a'=>'Our meticulous curation process sets us apart. Every product is personally selected by our team of luxury experts who focus on timeless pieces combining exceptional quality, craftsmanship, and enduring style.'],
                            ['q'=>'How do you ensure product authenticity?','a'=>'We work directly with brands and verified suppliers, bypassing all intermediaries. Each product undergoes rigorous authentication, and high-value items ship with a certificate of authenticity.'],
                            ['q'=>'What is your return and exchange policy?','a'=>'We offer a 30-day return window for items in original condition and packaging. Exchanges are available within 60 days. Our concierge team handles every return personally.'],
                            ['q'=>'Do you offer international shipping?','a'=>'Yes — we ship to over 50 countries using premium carriers. All shipments include full tracking and signature confirmation. Customs duties are the recipient\'s responsibility.'],
                            ['q'=>'How can I care for my luxury purchases?','a'=>'Every product arrives with tailored care instructions. Our concierge team can provide personalised guidance and connect you with specialist cleaning services.'],
                        ];
                        foreach ($faqs as $i => $faq): ?>
                        <div class="faq-item">
                            <button class="faq-q" aria-expanded="false" data-faq="<?= $i ?>" aria-controls="faq-answer-<?= $i ?>">
                                <span><?= htmlspecialchars($faq['q']) ?></span>
                                <span class="faq-icon" aria-hidden="true">+</span>
                            </button>
                            <div class="faq-a" id="faq-answer-<?= $i ?>" role="region">
                                <p><?= htmlspecialchars($faq['a']) ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="site-footer" id="site-footer" role="contentinfo">
        <div class="container">
            <div class="footer-top">
                <div class="footer-brand">
                    <a href="#home" class="logo">
                        <div class="logo-mark" aria-hidden="true">L</div>
                        <span class="logo-name">Luxe<em>Store</em></span>
                    </a>
                    <p>Premium shopping experience with curated luxury products for the discerning customer. Quality is our promise.</p>
                </div>
                <nav class="footer-col" aria-label="Navigate">
                    <h4>Navigate</h4>
                    <ul>
                        <li><a href="#home">Home</a></li>
                        <li><a href="<?= htmlspecialchars($shopLink) ?>">Shop</a></li>
                        <li><a href="#about">About</a></li>
                        <li><a href="#faq">FAQ</a></li>
                    </ul>
                </nav>
                <nav class="footer-col" aria-label="Support">
                    <h4>Support</h4>
                    <ul>
                        <li><a href="#faq">FAQ</a></li>
                        <li><a href="<?= htmlspecialchars($isLoggedIn ? $dashboardLink : 'login.php') ?>">My Account</a></li>
                        <li><a href="mailto:<?= htmlspecialchars($contact_email) ?>"><?= htmlspecialchars($contact_email) ?></a></li>
                        <li><a href="tel:<?= htmlspecialchars($contact_phone) ?>"><?= htmlspecialchars($contact_phone) ?></a></li>
                    </ul>
                </nav>
                <div class="footer-col">
                    <h4>Visit Us</h4>
                    <address style="font-size:0.88rem;color:var(--text-dim);line-height:1.7;">
                        123 Luxury Avenue<br>Suite 100<br>New York, NY 10001
                    </address>
                </div>
            </div>
            <div class="footer-policies">
                <div class="policy-card"><h5>Shipping</h5><p>Orders processed within 1&ndash;2 business days with tracked delivery and signature confirmation.</p></div>
                <div class="policy-card"><h5>Returns</h5><p>30-day returns in original condition. Concierge support for exchanges and collection scheduling.</p></div>
                <div class="policy-card"><h5>Privacy</h5><p>We collect only what's needed to fulfill your order. Your data is never sold to third parties.</p></div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?= $year ?> LuxeStore. All rights reserved.</p>
                <div class="footer-bottom-links">
                    <a href="#">Privacy Policy</a>
                    <a href="#">Terms of Service</a>
                    <a href="#">Cookie Settings</a>
                </div>
            </div>
        </div>
    </footer>

    <script>
    // ── Header scroll effect
    const header = document.getElementById('site-header');
    window.addEventListener('scroll', () => {
        header.classList.toggle('scrolled', window.scrollY > 40);
    }, { passive: true });

    // ── Mobile nav
    const hamburger = document.getElementById('hamburger');
    const mobileNav = document.getElementById('mobileNav');
    const navOverlay = document.getElementById('navOverlay');
    function toggleNav(open) {
        hamburger.classList.toggle('open', open);
        mobileNav.classList.toggle('open', open);
        navOverlay.classList.toggle('open', open);
        hamburger.setAttribute('aria-expanded', open);
        navOverlay.setAttribute('aria-hidden', !open);
        document.body.style.overflow = open ? 'hidden' : '';
    }
    hamburger.addEventListener('click', () => toggleNav(!mobileNav.classList.contains('open')));
    navOverlay.addEventListener('click', () => toggleNav(false));
    mobileNav.querySelectorAll('a').forEach(a => a.addEventListener('click', () => toggleNav(false)));

    // ── Contact popover
    const contactBtn = document.getElementById('contactBtn');
    const contactPopover = document.getElementById('contactPopover');
    contactBtn.addEventListener('click', e => {
        e.stopPropagation();
        const open = contactPopover.classList.toggle('open');
        contactBtn.setAttribute('aria-expanded', open);
    });
    document.addEventListener('click', e => {
        if (!e.target.closest('.contact-wrapper')) {
            contactPopover.classList.remove('open');
            contactBtn.setAttribute('aria-expanded', false);
        }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            contactPopover.classList.remove('open');
            contactBtn.setAttribute('aria-expanded', false);
            toggleNav(false);
        }
    });

    // ── FAQ accordion
    document.querySelectorAll('.faq-q').forEach(btn => {
        btn.addEventListener('click', () => {
            const idx = btn.dataset.faq;
            const answer = document.getElementById('faq-answer-' + idx);
            const isOpen = btn.classList.contains('active');
            document.querySelectorAll('.faq-q').forEach(b => { b.classList.remove('active'); b.setAttribute('aria-expanded', false); });
            document.querySelectorAll('.faq-a').forEach(a => a.classList.remove('open'));
            if (!isOpen) {
                btn.classList.add('active');
                btn.setAttribute('aria-expanded', true);
                answer.classList.add('open');
            }
        });
    });

    // ── Scroll reveal
    const observer = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) { e.target.classList.add('visible'); observer.unobserve(e.target); }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -60px 0px' });
    document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

    // ── Smooth anchor scrolling (reinforces CSS scroll-padding-top)
    document.querySelectorAll('a[href^="#"]').forEach(a => {
        a.addEventListener('click', e => {
            const id = a.getAttribute('href');
            if (id === '#') return;
            const target = document.querySelector(id);
            if (!target) return;
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            // Close mobile nav if open
            toggleNav(false);
            // Update URL without jumping
            history.pushState(null, '', id);
        });
    });

    // ── Auto-dismiss flash banner
    const flash = document.querySelector('.flash-banner');
    if (flash) setTimeout(() => { flash.style.opacity = '0'; flash.style.transition = 'opacity 0.5s'; setTimeout(() => flash.remove(), 500); }, 5000);
    </script>
</body>
</html>
