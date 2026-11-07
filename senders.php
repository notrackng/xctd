<?php

declare(strict_types=1);

use App\Auth;
use App\Database;
use App\Security;
use App\TeamRepository;
use App\UserRepository;

require __DIR__ . '/src/Autoload.php';

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);
Auth::requireLogin();
$currentUser = Auth::user();
$senders = [];
$errorMessage = null;

try {
    /** @var array<string,mixed> $config */
    $config = require __DIR__ . '/config/app.php';
    $dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];
    $appConfig = is_array($config['app'] ?? null) ? $config['app'] : [];
    $timezone = isset($appConfig['timezone']) && is_string($appConfig['timezone']) ? $appConfig['timezone'] : 'Asia/Jakarta';
    $pdo = Database::connect($dbConfig);
    $users = new UserRepository($pdo);
    Auth::syncUser($users);
    Auth::requireAdmin();
    $currentUser = Auth::user();
    $senders = (new TeamRepository($pdo, $timezone))->findAll();
} catch (Throwable $e) {
    error_log('Sender management page failure: ' . $e->getMessage());
    $errorMessage = 'Unable to load sender data.';
}

$csrfToken = Security::csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f4efe6" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1b1916" media="(prefers-color-scheme: dark)">
    <meta name="color-scheme" content="light dark">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="index.php?asset=app-meta">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/icons/apple-touch-icon.png">
    <title>Setting</title>
    <style nonce="<?= Security::e($nonce) ?>">
/* Natural soft theme. Components use these tokens only. */
        :root {
            color-scheme: light;
            --font-sans: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif;
            --space-1: 4px;
            --space-2: 8px;
            --space-3: 12px;
            --space-4: 16px;
            --space-5: 24px;
            --space-6: 32px;
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-pill: 999px;
            --shadow-sm: 0 1px 2px rgba(62, 46, 28, 0.06);
            --shadow: 0 10px 24px rgba(62, 46, 28, 0.08);
            --shadow-lg: 0 22px 48px rgba(44, 32, 18, 0.14);
            --ease: 160ms ease;
            --control-height: 44px;
            --control-pad-x: 12px;
            --control-border: 1px;
            --control-radius: var(--radius);
            --control-font-size: 16px;
            --control-line: calc(var(--control-height) - (2 * var(--control-border)));
            --control-gap: var(--space-2);
            --bg: #f4efe6;
            --bg-top: #fbf8f3;
            --panel: #fbf8f3;
            --panel-strong: #fffdf9;
            --surface: #e8e0d4;
            --surface-strong: #fffdf9;
            --line: #e4d8c8;
            --line-strong: #8c7760;
            --border: #e4d8c8;
            --text: #2c2823;
            --muted: #5e564c;
            --soft: #6a6258;
            --accent: #3e5c46;
            --accent-hover: #314838;
            --accent-soft: #e5efe4;
            --accent2: #a14e3c;
            --accent2-soft: #f8ebe6;
            --on-accent: #fbf8f3;
            --danger: #923c34;
            --danger-soft: #f8ebe8;
            --danger-line: #e4c4be;
            --danger-bg: #f8ebe8;
            --danger-border: #e4c4be;
            --ok: #2f6a46;
            --ok-soft: #e6f3ea;
            --ok-line: #bdd8c6;
            --warn: #8a5a16;
            --warn-soft: #f8f1de;
            --warn-line: #e4d3a6;
            --focus: #3e5c46;
            --overlay: rgba(44, 40, 35, 0.52);
            --glow-accent: rgba(62, 92, 70, 0.14);
            --glow-stone: rgba(140, 119, 96, 0.16);
            --bar-track: #e4d8c8;
            --hover: #fffdf9;
            --inset: rgba(255, 253, 249, 0.9);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                color-scheme: dark;
                --bg: #1b1916;
                --bg-top: #221f1b;
                --panel: #26231f;
                --panel-strong: #2e2a26;
                --surface: #312c27;
                --surface-strong: #2e2a26;
                --line: #4a433c;
                --line-strong: #8a8076;
                --border: #4a433c;
                --text: #f4efe6;
                --muted: #d2c6b6;
                --soft: #c4b8a8;
                --accent: #a9c5a8;
                --accent-hover: #c5d9c2;
                --accent-soft: #2c3830;
                --accent2: #e7a898;
                --accent2-soft: #3a2c28;
                --on-accent: #1b1916;
                --danger: #f0b2a8;
                --danger-soft: #3a2a27;
                --danger-line: #6e4a44;
                --danger-bg: #3a2a27;
                --danger-border: #6e4a44;
                --ok: #a8d2b4;
                --ok-soft: #24352c;
                --ok-line: #3e5c48;
                --warn: #e6c98a;
                --warn-soft: #3a3224;
                --warn-line: #6a5a38;
                --focus: #c5d9c2;
                --overlay: rgba(12, 10, 8, 0.66);
                --glow-accent: rgba(169, 197, 168, 0.16);
                --glow-stone: rgba(212, 198, 182, 0.08);
                --bar-track: #3a342e;
                --hover: #322e29;
                --inset: rgba(255, 253, 249, 0.06);
                --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.28);
                --shadow: 0 10px 24px rgba(0, 0, 0, 0.32);
                --shadow-lg: 0 22px 48px rgba(0, 0, 0, 0.42);
            }

            img.brand-icon,
            img.app-icon {
                filter: brightness(0) invert(0.94) sepia(0.16) saturate(0.35) hue-rotate(10deg);
            }
        }
        *, *::before, *::after { box-sizing: border-box; }
        [hidden] { display: none !important; }
        html {
            -webkit-text-size-adjust: 100%;
            min-height: 100%;
            background: var(--bg);
            scrollbar-width: thin;
            scrollbar-color: var(--line-strong) transparent;
        }
        img, svg, video { max-width: 100%; height: auto; }
        button, input, select, textarea, summary { font-family: inherit; }
        button, a, input, select, summary, label { -webkit-tap-highlight-color: transparent; touch-action: manipulation; }
        summary,
        .tab {
            min-height: var(--control-height);
        }
        :focus { outline: none; }
        :focus-visible {
            outline: 2px solid var(--focus);
            outline-offset: 2px;
        }
        ::selection { background: var(--accent-soft); color: var(--text); }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }
        body {
            margin: 0;
            min-height: 100dvh;
            max-width: 100%;
            overflow-x: hidden;
            padding:
                max(var(--space-3), env(safe-area-inset-top))
                max(var(--space-3), env(safe-area-inset-right))
                max(var(--space-4), env(safe-area-inset-bottom))
                max(var(--space-3), env(safe-area-inset-left));
            background:
                radial-gradient(circle at 12% 0%, var(--glow-accent), transparent 32%),
                radial-gradient(circle at 92% 100%, var(--glow-stone), transparent 34%),
                linear-gradient(180deg, var(--bg-top) 0%, var(--bg) 100%);
            color: var(--text);
            font-family: var(--font-sans);
            font-size: 15px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
        }
        .wrap { width: min(1180px, 100%); margin: 0 auto; min-width: 0; }
        .top {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: var(--space-2);
            margin: 0 0 var(--space-2);
            padding: var(--space-2) var(--space-3);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            background: var(--panel);
            box-shadow: var(--shadow);
        }
        .brand { display: flex; align-items: center; gap: var(--space-2); min-width: 0; flex: 1 1 180px; }
        .brand > div, .brand-copy { min-width: 0; }
        .brand-icon {
            width: 36px;
            height: 36px;
            flex: 0 0 36px;
            display: block;
            object-fit: contain;
        }
        .title {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -0.02em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sub, .top .muted {
            display: block;
            margin: 2px 0 0;
            color: var(--muted);
            font-size: 0.8125rem;
            font-weight: 500;
            line-height: 1.35;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .muted { margin: 0; color: var(--muted); font-size: 0.8125rem; line-height: 1.4; }
        .top-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: var(--space-2); margin-left: auto; }
        .logout-form { margin: 0; }
        .role {
            display: none;
            align-items: center;
            min-height: 44px;
            max-width: 220px;
            padding: 8px 10px;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface);
            color: var(--muted);
            font-size: 0.8125rem;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        .link, .logout, .install-btn, .tab, .alias-cancel, .alias-option, .btn, .primary, .pager-btn {
            border-radius: var(--radius);
            transition: background-color var(--ease), border-color var(--ease), color var(--ease), box-shadow var(--ease);
        }
        .link, .logout, .install-btn {
            min-height: 44px;
            padding: 8px 12px;
            border: 1px solid var(--line-strong);
            background: var(--panel-strong);
            color: var(--text);
            font-size: 0.875rem;
            font-weight: 600;
            white-space: nowrap;
            text-decoration: none;
            cursor: pointer;
        }
        .link:hover, .logout:hover, .install-btn:hover, .alias-cancel:hover, .btn.secondary:hover, .secondary:hover {
            border-color: var(--line-strong);
            background: var(--hover);
        }
        .install-btn[hidden] { display: none; }
        .live {
            display: inline-flex;
            align-items: center;
            min-height: 44px;
            padding: 8px 10px;
            color: var(--ok);
            font-size: 0.8125rem;
            font-weight: 700;
            white-space: nowrap;
        }
        .live::before {
            content: "";
            width: 8px;
            height: 8px;
            margin-right: 6px;
            border-radius: 50%;
            background: var(--ok);
            box-shadow: 0 0 0 3px var(--ok-soft);
        }
        .tabs {
            display: flex;
            width: 100%;
            gap: var(--space-1);
            margin: 0 0 var(--space-3);
            padding: var(--space-1);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            background: var(--panel);
            box-shadow: var(--shadow-sm);
        }
        .tab {
            flex: 1 1 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 0;
            padding: 6px 4px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text);
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 1.15;
            letter-spacing: 0;
            text-align: center;
            text-decoration: none;
            white-space: normal;
        }
        .tab:hover { background: var(--hover); color: var(--text); }
        .tab.active {
            border-color: var(--line);
            background: var(--accent-soft);
            color: var(--accent);
        }
        .card, .summary {
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            background: var(--panel);
            box-shadow: var(--shadow);
        }
        .card { padding: var(--space-3); margin-bottom: var(--space-3); min-width: 0; }
        .msg {
            margin-bottom: var(--space-3);
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--panel-strong);
            box-shadow: var(--shadow-sm);
            color: var(--text);
            font-size: 0.875rem;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }
        .msg[hidden] { display: none; }
        .msg.error { border-color: var(--danger-line); background: var(--danger-soft); color: var(--danger); }
        .msg.ok { border-color: var(--ok-line); background: var(--ok-soft); color: var(--ok); }
        .pwa-gate {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: max(var(--space-4), env(safe-area-inset-top)) max(var(--space-4), env(safe-area-inset-right)) max(var(--space-4), env(safe-area-inset-bottom)) max(var(--space-4), env(safe-area-inset-left));
            background: var(--overlay);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .pwa-gate-card {
            width: min(360px, 100%);
            padding: var(--space-4);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            background: var(--panel-strong);
            box-shadow: var(--shadow-lg);
            color: var(--text);
            text-align: center;
            font-family: var(--font-sans);
        }
        .pwa-gate-card h2 { margin: 0 0 6px; font-size: 1.05rem; font-weight: 700; letter-spacing: -0.02em; }
        .pwa-gate-card p { margin: 0 0 10px; color: var(--muted); font-size: 0.875rem; line-height: 1.5; }
        .pwa-gate-steps {
            display: grid;
            gap: 6px;
            margin: 0 0 12px;
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface);
            text-align: left;
            font-size: 0.8125rem;
            line-height: 1.45;
            color: var(--text);
        }
        .pwa-gate-steps b { display: block; margin-bottom: 2px; color: var(--text); font-size: 0.875rem; }
        .pwa-gate-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            margin-bottom: 6px;
            border: 0;
            border-radius: var(--radius);
            background: var(--accent);
            color: var(--on-accent);
            font: inherit;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
        }
        .pwa-gate-btn:hover { background: var(--accent-hover); }
        .pwa-gate-dismiss {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border: 0;
            background: transparent;
            color: var(--muted);
            font: inherit;
            font-size: 0.875rem;
            text-decoration: underline;
            cursor: pointer;
        }
        body.pwa-gate-locked { overflow: hidden; }
        @media (min-width: 768px) {
            body { padding: var(--space-4); }
            .title { font-size: 1.2rem; }
            .role { display: inline-flex; }
            .tab { font-size: 0.875rem; }
            .card { padding: var(--space-4); }
        }
        .head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2); margin-bottom: var(--space-2); }
        .head h2 { margin: 0; font-size: 0.95rem; line-height: 1.3; }
        .head .muted { margin: 0; text-align: right; }
        .line-wrap { max-width: 100%; overflow-x: auto; }
        .add-line {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 160px), 1fr));
            gap: var(--space-2);
            align-items: center;
        }
        .input, .select {
            width: 100%;
            min-width: 0;
            padding: 8px 10px;
            border: 1px solid var(--line-strong);
            border-radius: var(--radius);
            outline: 0;
            background: var(--panel-strong);
            color: var(--text);
            box-shadow: inset 0 1px 1px var(--inset);
            transition: border-color var(--ease), box-shadow var(--ease), background-color var(--ease);
        }
        .input:hover, .select:hover { border-color: var(--line-strong); }
        .input:focus-visible, .select:focus-visible { border-color: var(--accent); background: var(--hover); box-shadow: 0 0 0 3px var(--accent-soft); }
        .btn {
            padding: 8px 12px;
            border: 0;
            background: var(--accent);
            color: var(--on-accent);
            font-size: 0.875rem;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
        }
        .btn:hover { background: var(--accent-hover); }
        .btn.secondary, .secondary {
            border: 1px solid var(--line-strong);
            background: var(--panel-strong);
            color: var(--text);
            box-shadow: none;
        }
        .btn.danger {
            border: 1px solid var(--danger-line);
            background: var(--panel-strong);
            color: var(--danger);
            box-shadow: none;
        }
        .btn.danger:hover { background: var(--danger-soft); }
        .table-wrap, .table {
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--panel-strong);
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 10px; border-bottom: 1px solid var(--line); text-align: left; font-size: 0.875rem; line-height: 1.35; vertical-align: middle; }
        th { background: var(--surface); color: var(--muted); font-size: 0.75rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; }
        tr:last-child td { border-bottom: 0; }
        .row-form {
            display: grid;
            grid-template-columns: minmax(140px, 1.4fr) minmax(100px, 1fr) minmax(110px, 1fr) minmax(88px, 0.6fr) minmax(104px, 0.7fr) auto auto;
            gap: var(--space-2);
            align-items: center;
            min-width: 760px;
        }
        .accounts-row {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-2);
            align-items: center;
            margin-top: var(--space-2);
            padding-top: var(--space-2);
            border-top: 1px dashed var(--line);
            min-width: 760px;
        }
        .accounts-list { display: flex; flex-wrap: wrap; gap: var(--space-2); }
        .account-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            min-height: 44px;
            padding: 4px 8px 4px 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius-pill);
            background: var(--panel);
            color: var(--muted);
            font-size: 0.8125rem;
        }
        .account-remove {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            border: 0;
            background: transparent;
            color: var(--danger);
            cursor: pointer;
            font-size: 1.1rem;
            line-height: 1;
        }
        .account-add-form { display: flex; flex-wrap: wrap; gap: var(--space-2); align-items: center; }
        .account-add-form .select { width: auto; min-width: 120px; flex: 1 1 120px; }
        .account-add-form .input { width: auto; min-width: 0; flex: 2 1 160px; }
        .toast-stack {
            position: fixed;
            left: 50%;
            bottom: max(var(--space-3), env(safe-area-inset-bottom));
            z-index: 99;
            display: grid;
            gap: var(--space-2);
            width: min(320px, calc(100vw - 24px));
            transform: translateX(-50%);
            pointer-events: none;
        }
        .toast {
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--panel-strong);
            box-shadow: var(--shadow-lg);
            color: var(--text);
            font-size: 0.875rem;
            line-height: 1.35;
            text-align: center;
            opacity: 0;
            transform: translateY(6px);
            transition: opacity var(--ease), transform var(--ease);
        }
        .toast.show { opacity: 1; transform: none; }
        .toast.error { color: var(--danger); }
        .pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: var(--space-2); margin-top: var(--space-2); }
        .pager[hidden] { display: none; }
        .pager-info { color: var(--muted); font-size: 0.8125rem; font-weight: 700; }
        .pager-btn { min-width: 44px; padding: 8px 12px; border: 1px solid var(--line-strong); background: var(--panel-strong); color: var(--text); font-size: 0.875rem; font-weight: 700; cursor: pointer; }
        .pager-btn:disabled { opacity: 0.45; cursor: default; }
        .page-hidden { display: none !important; }
        @media (max-width: 759px) {
            .table-wrap { overflow: visible; }
            table, .row-form, .accounts-row { min-width: 0; }
            .table-wrap thead { display: none; }
            .row-form { grid-template-columns: 1fr; }
            .accounts-row { flex-direction: column; align-items: stretch; }
            .account-add-form { display: grid; grid-template-columns: 1fr; width: 100%; }
            .account-add-form .select, .account-add-form .input { width: 100%; min-width: 0; }
        }
        @media (min-width: 768px) {
            .toast-stack { left: auto; right: var(--space-4); width: 300px; transform: none; }
            .toast { text-align: left; }
        }
    
        /* Shared control box: one height, padding, radius, border, type size, and line box. */
        input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]),
        select,
        textarea,
        button,
        a.b,
        input.file,
        input.input,
        select.select,
        button.btn,
        button.primary,
        button.submit,
        button.install,
        button.logout,
        button.install-btn,
        button.pager-btn,
        button.tx-delete,
        button.alias-cancel,
        button.alias-option,
        button.secondary,
        button.pwa-gate-btn,
        button.pwa-gate-dismiss,
        button.account-remove {
            box-sizing: border-box;
            height: var(--control-height);
            min-height: var(--control-height);
            max-height: var(--control-height);
            padding: 0 var(--control-pad-x);
            border: var(--control-border) solid var(--line-strong);
            border-radius: var(--control-radius);
            background: var(--panel-strong);
            color: var(--text);
            font-family: inherit;
            font-size: var(--control-font-size);
            font-weight: 500;
            line-height: var(--control-line);
            vertical-align: middle;
            transition: background-color var(--ease), border-color var(--ease), color var(--ease), box-shadow var(--ease);
        }
        button,
        a.b,
        button.btn,
        button.primary,
        button.submit,
        button.install,
        button.logout,
        button.install-btn,
        button.pager-btn,
        button.tx-delete,
        button.alias-cancel,
        button.alias-option,
        button.pwa-gate-btn,
        button.pwa-gate-dismiss,
        button.account-remove {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--control-gap);
            font-weight: 700;
            line-height: 1.2;
            white-space: nowrap;
            text-decoration: none;
            cursor: pointer;
        }
        button.primary,
        button.submit,
        button.btn,
        button.pwa-gate-btn,
        a.b {
            border-color: var(--accent);
            background: var(--accent);
            color: var(--on-accent);
            box-shadow: var(--shadow-sm);
        }
        button.btn.secondary,
        button.secondary,
        button.install,
        button.logout,
        button.install-btn,
        button.pager-btn,
        button.alias-cancel {
            border-color: var(--line-strong);
            background: var(--panel-strong);
            color: var(--text);
            box-shadow: none;
        }
        button.alias-option {
            border-color: var(--line-strong);
            background: var(--panel-strong);
            color: var(--accent);
            box-shadow: none;
        }
        button.btn.danger,
        button.tx-delete {
            border-color: var(--danger-line);
            background: var(--panel-strong);
            color: var(--danger);
            box-shadow: none;
        }
        button.pwa-gate-dismiss {
            width: auto;
            border-color: transparent;
            background: transparent;
            color: var(--muted);
            box-shadow: none;
            text-decoration: underline;
            font-weight: 600;
        }
        button.account-remove {
            width: var(--control-height);
            padding: 0;
            border-color: transparent;
            background: transparent;
            color: var(--danger);
            box-shadow: none;
        }
        button.primary:hover,
        button.submit:hover,
        button.btn:not(.secondary):not(.danger):hover,
        button.pwa-gate-btn:hover,
        a.b:hover {
            background: var(--accent-hover);
            border-color: var(--accent-hover);
            color: var(--on-accent);
        }
        button.btn.secondary:hover,
        button.secondary:hover,
        button.install:hover,
        button.logout:hover,
        button.install-btn:hover,
        button.pager-btn:hover,
        button.alias-cancel:hover,
        button.alias-option:hover,
        input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):hover,
        select:hover {
            background: var(--hover);
            border-color: var(--line-strong);
            color: var(--text);
        }
        button.alias-option:hover { color: var(--accent); }
        button.btn.danger:hover,
        button.tx-delete:hover,
        button.account-remove:hover {
            background: var(--danger-soft);
            border-color: var(--danger-line);
            color: var(--danger);
        }
        input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):focus-visible,
        select:focus-visible,
        textarea:focus-visible,
        button:focus-visible,
        a.b:focus-visible {
            outline: 2px solid var(--focus);
            outline-offset: 2px;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-soft);
        }
        button:disabled,
        input:disabled,
        select:disabled,
        button.primary:disabled,
        button.btn:disabled,
        button.submit:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            box-shadow: none;
        }
        input[type="file"]:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]),
        input.file:not([type="hidden"]) {
            display: block;
            width: 100%;
            padding: 0;
            padding-inline: 0;
            overflow: hidden;
            color: var(--muted);
            font-weight: 500;
            line-height: var(--control-line);
        }
        input[type="file"]::file-selector-button,
        input.file::file-selector-button {
            height: var(--control-height);
            margin: 0;
            margin-left: 0;
            margin-inline-start: 0;
            margin-inline-end: var(--control-pad-x);
            padding: 0 var(--control-pad-x);
            border: 0;
            border-radius: var(--control-radius) 0 0 var(--control-radius);
            background: var(--accent);
            color: var(--on-accent);
            font: inherit;
            font-size: var(--control-font-size);
            font-weight: 700;
            line-height: var(--control-line);
            vertical-align: middle;
            cursor: pointer;
        }
        input[type="file"]::-webkit-file-upload-button,
        input.file::-webkit-file-upload-button {
            height: var(--control-height);
            margin: 0;
            margin-left: 0;
            margin-inline-start: 0;
            margin-inline-end: var(--control-pad-x);
            padding: 0 var(--control-pad-x);
            border: 0;
            border-radius: var(--control-radius) 0 0 var(--control-radius);
            background: var(--accent);
            color: var(--on-accent);
            font: inherit;
            font-size: var(--control-font-size);
            font-weight: 700;
            line-height: var(--control-line);
            vertical-align: middle;
            cursor: pointer;
        }
        .field,
        .upload-grid,
        .add-line,
        .row-form,
        .inline,
        .account-add-form,
        .grid,
        .two,
        .form-grid,
        .top-actions,
        .pager,
        .alias-options,
        .alias-choice-head {
            gap: var(--control-gap);
        }
        .upload-grid,
        .add-line,
        .row-form,
        .inline,
        .account-add-form,
        .grid,
        .two,
        .top-actions,
        .pager,
        .alias-options {
            align-items: center;
        }
        .upload-grid { align-items: end; }
        .field { align-items: stretch; }
        .top-actions button,
        .pager button,
        .alias-options button,
        button.account-remove,
        button.pwa-gate-dismiss {
            width: auto;
        }
        button.submit,
        button.install,
        button.pwa-gate-btn,
        .form-grid button.btn,
        .form-grid .input,
        .upload-grid button.primary,
        .upload-grid .file,
        .grid button.btn,
        .grid input,
        .grid select,
        .add-line .input,
        .add-line .select,
        .add-line button.btn,
        .row-form .input,
        .row-form .select,
        .inline input,
        .inline select {
            width: 100%;
            max-width: 100%;
        }
        button.submit { margin-top: 0; }
        button.install,
        button.pwa-gate-btn { margin-top: 0; margin-bottom: var(--control-gap); }
        @media (max-width: 719px) {
            .upload-grid,
            .grid,
            .add-line,
            .row-form,
            .account-add-form,
            .two,
            .form-grid.cols {
                grid-template-columns: minmax(0, 1fr);
            }
            .inline,
            .account-add-form { flex-direction: column; align-items: stretch; }
            .upload-grid button.primary,
            .grid button.btn,
            .add-line button.btn,
            .row-form button.btn,
            .account-add-form button.btn,
            .account-add-form .input,
            .account-add-form .select,
            .inline button.btn,
            .inline input,
            .inline select,
            .form-grid button.btn,
            button.submit,
            button.install,
            input.file,
            input.input,
            select.select,
            .grid input,
            .grid select {
                width: 100%;
            }
            .top-actions button,
            .pager button,
            button.account-remove,
            button.pwa-gate-dismiss { width: auto; }
        }
        @media (min-width: 720px) {
            .upload-grid { grid-template-columns: minmax(0, 1fr) auto; align-items: end; }
            .upload-grid button.primary,
            .inline button.btn,
            .account-add-form button.btn,
            .row-form button.btn { width: auto; }
        }
        @media (min-width: 768px) {
            .grid button.btn { width: auto; }
        }
    </style>
</head>
<body data-senders-endpoint="api/senders.php" data-pwa-gate>
<main class="wrap">
    <header class="top"><div class="brand"><img class="brand-icon" src="img.svg" alt=""><div><h1 class="title">Setting</h1><div class="muted">SUBID is the unique identity.</div></div></div><button class="install-btn" type="button" data-pwa-install hidden>Install</button></header>
    <nav class="tabs" aria-label="Admin navigation"><a class="tab" href="index.php">Dashboard</a><a class="tab" href="statistics.php">Statistics</a><a class="tab active" href="senders.php">Setting</a><a class="tab" href="users.php">Users</a></nav>
    <?php if ($errorMessage !== null): ?><div class="msg error"><?= Security::e($errorMessage) ?></div><?php endif; ?>
    <div id="sender-message" class="msg" hidden></div>

    <section class="card">
        <div class="head"><h2>Add sender</h2></div>
        <div class="line-wrap"><form id="sender-create-form" class="add-line" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"><input type="hidden" name="action" value="create">
            <input class="input" type="text" name="sender_name" minlength="2" maxlength="100" placeholder="Sender" required>
            <input class="input" type="text" name="subid" maxlength="100" placeholder="SUBID" required>
            <input class="input" type="text" name="location" minlength="2" maxlength="120" placeholder="Location" required>
            <select class="select" name="team" required><option value="XCTD">XCTD</option><option value="MNX">MNX</option></select>
            <button class="btn" type="submit">Add</button>
        </form></div>
    </section>

    <section class="card">
        <div class="head"><h2>Registered senders</h2><span id="sender-count" class="muted"><?= count($senders) ?> records</span></div>
        <div class="table-wrap"><table><thead><tr><th>Sender</th><th>SUBID</th><th>Location</th><th>Team</th><th>Status</th><th>Save</th><th>Delete</th></tr></thead><tbody id="senders-body">
        <?php foreach ($senders as $sender): ?>
            <tr data-sender-id="<?= (int) $sender['id'] ?>"><td colspan="7"><form class="row-form sender-row-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="sender_id" value="<?= (int) $sender['id'] ?>">
                <input class="input" name="sender_name" value="<?= Security::e($sender['display_name']) ?>" maxlength="100" required>
                <input class="input" name="subid" value="<?= Security::e($sender['alias']) ?>" maxlength="100" placeholder="SUBID" required>
                <input class="input" name="location" value="<?= Security::e($sender['location']) ?>" maxlength="120" placeholder="Location" required>
                <select class="select" name="team"><option value="XCTD"<?= $sender['team'] === 'XCTD' ? ' selected' : '' ?>>XCTD</option><option value="MNX"<?= $sender['team'] === 'MNX' ? ' selected' : '' ?>>MNX</option></select>
                <select class="select" name="is_active"><option value="1"<?= $sender['is_active'] === 1 ? ' selected' : '' ?>>active</option><option value="0"<?= $sender['is_active'] !== 1 ? ' selected' : '' ?>>disabled</option></select>
                <button class="btn secondary" type="submit">Save</button><button class="btn danger sender-delete" type="button">Delete</button>
            </form>
            <div class="accounts-row">
                <div class="accounts-list" data-accounts-list>
                <?php foreach ($sender['accounts'] as $account): ?>
                    <span class="account-chip" data-account-id="<?= (int) $account['id'] ?>"><?= Security::e($account['bank_code']) ?> •••<?= Security::e(substr($account['account_number'], -4)) ?><button type="button" class="account-remove" data-account-id="<?= (int) $account['id'] ?>" aria-label="Remove account">×</button></span>
                <?php endforeach; ?>
                </div>
                <form class="account-add-form" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"><input type="hidden" name="action" value="add_account"><input type="hidden" name="sender_id" value="<?= (int) $sender['id'] ?>">
                    <select class="select account-bank" name="bank_code"></select>
                    <input class="input account-number" name="account_number" maxlength="30" placeholder="Account number">
                    <button class="btn secondary" type="submit">+ Account</button>
                </form>
            </div>
            </td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="pager" id="senders-pager" hidden><button type="button" class="pager-btn" data-pager="senders" data-dir="-1">Prev</button><span class="pager-info" id="senders-pager-info">Page 1 / 1</span><button type="button" class="pager-btn" data-pager="senders" data-dir="1">Next</button></div>
    </section>
</main>
<div id="toast-stack" class="toast-stack" aria-live="polite"></div>
<script src="assets/jquery.php"></script><script src="assets/senders.php"></script><script src="assets/pwa.php" data-sw="sw.php"></script>
</body>
</html>
