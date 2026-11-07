<?php

declare(strict_types=1);

use App\Auth;
use App\Installer;
use App\Security;
use App\UserRepository;

require dirname(__DIR__) . '/src/Autoload.php';

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);

$rootPath = dirname(__DIR__);
$installer = new Installer($rootPath);
$errorMessage = null;
$installed = $installer->isInstalled();

// This page is reachable without authentication, and it stays reachable after the
// installer locks itself. Preflight reports the exact PHP patch level, which
// extensions are loaded, and which directories are writable - a free reconnaissance
// report for anyone who requests /install/. It is only actionable while an install
// can still happen, so it is not gathered at all once the application is installed.
$checks = $installed ? [] : $installer->preflight();

/** @return string */
function installPostString(string $key, int $maxLength = 255): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);
    if (strlen($value) > $maxLength) {
        return '';
    }

    return $value;
}

if (!$installed && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
    try {
        if (!Security::validateCsrf(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
            http_response_code(419);
            throw new RuntimeException('Request validation failed. Reload the installer and try again.');
        }

        $host = installPostString('db_host', 253);
        $name = installPostString('db_name', 64);
        $user = installPostString('db_user', 64);
        $pass = isset($_POST['db_pass']) && is_string($_POST['db_pass']) && strlen($_POST['db_pass']) <= 255
            ? $_POST['db_pass']
            : '';
        $portRaw = installPostString('db_port', 5);
        $username = installPostString('username', 50);
        $password = isset($_POST['password']) && is_string($_POST['password']) && strlen($_POST['password']) <= 128
            ? $_POST['password']
            : '';
        $passwordConfirm = isset($_POST['password_confirm']) && is_string($_POST['password_confirm'])
            ? $_POST['password_confirm']
            : '';

        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException('Database host, name, and user are required.');
        }
        if (preg_match('/^[A-Za-z0-9_$.-]{1,64}$/D', $name) !== 1) {
            throw new RuntimeException('Database name contains unsupported characters.');
        }
        if (preg_match('/^[A-Za-z0-9_$.-]{1,64}$/D', $user) !== 1) {
            throw new RuntimeException('Database user contains unsupported characters.');
        }

        $port = filter_var($portRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if (!is_int($port)) {
            throw new RuntimeException('Database port is invalid.');
        }
        if ($password === '' || !hash_equals($password, $passwordConfirm)) {
            throw new RuntimeException('Administrator passwords do not match.');
        }

        $dbConfig = [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
        ];

        $admin = $installer->install($dbConfig, $username, $password);
        Auth::login($admin);
        header('Location: ../index.php', true, 303);
        exit;
    } catch (Throwable $e) {
        error_log('Install request failure: ' . $e->getMessage());
        $errorMessage = ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) && !$e instanceof PDOException
            ? $e->getMessage()
            : 'Installation could not be completed.';
    }
}

$csrfToken = Security::csrfToken();
$allChecksPassed = true;
foreach ($checks as $check) {
    if ($check['ok'] !== true) {
        $allChecksPassed = false;
        break;
    }
}
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
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="manifest" href="../index.php?asset=app-meta">
    <link rel="icon" href="../favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="../assets/icons/icon-192.png">
    <link rel="apple-touch-icon" sizes="180x180" href="../assets/icons/apple-touch-icon.png">
    <title>Auto installer · Bank Receipt Extractor</title>
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
        input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]),
        select,
        textarea {
            font-size: 16px;
            line-height: 1.3;
            min-height: 44px;
        }
        button,
        summary,
        .tab,
        .pwa-gate-btn,
        .pwa-gate-dismiss {
            min-height: 44px;
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
            background:
                radial-gradient(circle at 12% 0%, var(--glow-accent), transparent 28%),
                linear-gradient(180deg, var(--bg-top), var(--bg));
            color: var(--text);
            font-family: var(--font-sans);
            font-size: 15px;
            line-height: 1.45;
            padding:
                max(var(--space-3), env(safe-area-inset-top))
                max(var(--space-3), env(safe-area-inset-right))
                max(var(--space-4), env(safe-area-inset-bottom))
                max(var(--space-3), env(safe-area-inset-left));
            -webkit-font-smoothing: antialiased;
        }
        .wrap { width: min(760px, 100%); margin: 0 auto; min-width: 0; }
        .head { margin: 0 0 var(--space-3); }
        .head h1 { font-size: clamp(1.2rem, 4vw, 1.5rem); margin: 0 0 6px; letter-spacing: -0.02em; }
        .head p, .hint { margin: 0; color: var(--muted); font-size: 0.875rem; line-height: 1.5; }
        .card { border: 1px solid var(--line); background: var(--panel); border-radius: var(--radius-lg); padding: var(--space-3); margin-bottom: var(--space-3); box-shadow: var(--shadow); min-width: 0; }
        .checks { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-2); }
        .check { border: 1px solid var(--line); border-radius: var(--radius); padding: 10px; background: var(--surface); font-size: 0.875rem; overflow-wrap: anywhere; }
        .check b { display: block; font-size: 0.875rem; margin-bottom: 2px; }
        .pass b { color: var(--ok); }
        .fail b { color: var(--danger); }
        .form-grid { display: grid; gap: var(--space-3); }
        .two { display: grid; grid-template-columns: minmax(0, 1fr) 6.5rem; gap: var(--space-2); }
        .field { display: grid; gap: 6px; color: var(--muted); font-size: 0.8125rem; font-weight: 700; }
        .input { width: 100%; min-width: 0; border: 1px solid var(--line-strong); border-radius: var(--radius); background: var(--panel-strong); color: var(--text); padding: 10px 12px; }
        .input:focus-visible { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
        .btn { width: 100%; border: 0; border-radius: var(--radius); background: var(--accent); color: var(--on-accent); font-weight: 700; font-size: 1rem; padding: 10px 12px; cursor: pointer; }
        .btn:hover { background: var(--accent-hover); }
        .btn:disabled { opacity: 0.45; cursor: default; }
        .error, .locked { border: 1px solid var(--danger-border); background: var(--danger-bg); border-radius: var(--radius); padding: 10px 12px; color: var(--danger); font-size: 0.875rem; line-height: 1.5; }
        .locked a { color: var(--accent); }
        .section-title { margin: 0 0 var(--space-2); font-size: 1rem; }
        @media (min-width: 640px) {
            .checks { grid-template-columns: 1fr 1fr; }
            .form-grid.cols { grid-template-columns: 1fr 1fr; }
        }
        @media (min-width: 768px) {
            body { padding: var(--space-5); }
            .card { padding: var(--space-4); }
            .checks { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
    </style>
</head>
<body>
<main class="wrap">
    <header class="head"><h1>Auto installer</h1><p>cPanel/shared-host wizard · browser OCR · PHP 8.3 · MySQL/MariaDB · creates schema + first admin + locks itself.</p></header>

    <?php if (!$installed): ?>
    <section class="card">
        <h2 class="section-title">Server preflight</h2>
        <div class="checks">
            <?php foreach ($checks as $check): ?>
                <div class="check <?= $check['ok'] ? 'pass' : 'fail' ?>">
                    <b><?= $check['ok'] ? 'PASS' : 'FAIL' ?> · <?= Security::e($check['label']) ?></b>
                    <span><?= Security::e($check['detail']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($installed): ?>
        <section class="card"><div class="locked">Installer locked. Existing configuration was detected. <a href="../login.php">Open sign in</a>.</div></section>
    <?php else: ?>
        <?php if ($errorMessage !== null): ?><div class="error" role="status"><?= Security::e($errorMessage) ?></div><?php endif; ?>
        <form method="post" class="form-grid" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
            <section class="card form-grid">
                <h2 class="section-title">1 · Database</h2>
                <p class="hint">Create an empty database + database user in cPanel first. The installer creates only application tables; it never creates or modifies cPanel accounts.</p>
                <div class="two">
                    <label class="field">Host<input class="input" name="db_host" value="<?= Security::e(installPostString('db_host') ?: 'localhost') ?>" maxlength="253" required></label>
                    <label class="field">Port<input class="input" name="db_port" inputmode="numeric" value="<?= Security::e(installPostString('db_port') ?: '3306') ?>" maxlength="5" required></label>
                </div>
                <div class="form-grid cols">
                    <label class="field">Database name<input class="input" name="db_name" value="<?= Security::e(installPostString('db_name', 64)) ?>" maxlength="64" required></label>
                    <label class="field">Database user<input class="input" name="db_user" value="<?= Security::e(installPostString('db_user', 64)) ?>" maxlength="64" required></label>
                </div>
                <label class="field">Database password<input class="input" type="password" name="db_pass" maxlength="255" autocomplete="new-password"></label>
            </section>

            <section class="card form-grid">
                <h2 class="section-title">2 · Initial administrator</h2>
                <label class="field">Username<input class="input" name="username" value="<?= Security::e(installPostString('username', 50)) ?>" minlength="3" maxlength="50" autocomplete="username" required></label>
                <div class="form-grid cols">
                    <label class="field">Password<input class="input" type="password" name="password" minlength="<?= UserRepository::MIN_PASSWORD_LENGTH ?>" maxlength="128" autocomplete="new-password" required></label>
                    <label class="field">Confirm password<input class="input" type="password" name="password_confirm" minlength="<?= UserRepository::MIN_PASSWORD_LENGTH ?>" maxlength="128" autocomplete="new-password" required></label>
                </div>
                <button class="btn" type="submit"<?= $allChecksPassed ? '' : ' disabled' ?>>Install production app</button>
                <?php if (!$allChecksPassed): ?><p class="hint">Resolve every FAIL item before installation.</p><?php endif; ?>
            </section>
        </form>
    <?php endif; ?>
</main>
<script src="../assets/pwa.php" data-sw="../sw.php"></script>
</body>
</html>
