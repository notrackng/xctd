<?php

declare(strict_types=1);

use App\Auth;
use App\Database;
use App\LoginThrottle;
use App\Security;
use App\UserRepository;

require __DIR__ . '/src/Autoload.php';

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);

if (Auth::user() !== null) {
    header('Location: index.php', true, 302);
    exit;
}

$errorMessage = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $errorMessage = 'Request validation failed. Reload the page and try again.';
    } else {
        // Session check runs BEFORE try - prevents message being swallowed by catch.
        // It is only the cheap first line: a client can reset it by discarding
        // the cookie, so LoginThrottle below is the authoritative limit.
        $blockedUntil = $_SESSION['login_block_until'] ?? 0;

        if (is_int($blockedUntil) && $blockedUntil > time()) {
            usleep(250_000);
            http_response_code(429);
            $errorMessage = 'Too many sign-in attempts. Try again later.';
        } else {
            $username = isset($_POST['username']) && is_string($_POST['username'])
                ? $_POST['username']
                : '';

            $password = isset($_POST['password']) && is_string($_POST['password'])
                ? $_POST['password']
                : '';

            try {
                /** @var array<string, mixed> $config */
                $config = require __DIR__ . '/config/app.php';

                $dbConfig = is_array($config['db'] ?? null)
                    ? $config['db']
                    : [];

                $securityConfig = is_array($config['security'] ?? null)
                    ? $config['security']
                    : [];

                $trustedHeader = isset($securityConfig['trusted_proxy_header'])
                    && is_string($securityConfig['trusted_proxy_header'])
                    && $securityConfig['trusted_proxy_header'] !== ''
                    ? $securityConfig['trusted_proxy_header']
                    : null;

                $pdo = Database::connect($dbConfig);

                $throttle = new LoginThrottle($pdo);
                $address = LoginThrottle::clientAddress($trustedHeader);
                $retryAfter = $address !== null ? $throttle->retryAfter($address) : 0;

                if ($retryAfter > 0) {
                    usleep(250_000);
                    header('Retry-After: ' . (string) $retryAfter);
                    http_response_code(429);
                    $errorMessage = 'Too many sign-in attempts. Try again later.';
                } else {
                    $repository = new UserRepository($pdo);

                    $user = $repository->authenticate($username, $password);

                    if ($user === null) {
                        usleep(250_000);

                        if ($address !== null) {
                            $throttle->recordFailure($address, $username);
                        }

                        $failures = $_SESSION['login_failures'] ?? 0;
                        $failures = is_int($failures) ? $failures + 1 : 1;

                        if ($failures >= 5) {
                            $_SESSION['login_block_until'] = time() + 300;
                            $_SESSION['login_failures'] = 0;
                            http_response_code(429);
                            $errorMessage = 'Too many sign-in attempts. Try again later.';
                        } else {
                            $_SESSION['login_failures'] = $failures;
                            $errorMessage = 'Invalid username or password.';
                        }
                    } else {
                        if ($address !== null) {
                            $throttle->clear($address);
                        }

                        unset(
                            $_SESSION['login_failures'],
                            $_SESSION['login_block_until']
                        );

                        Auth::login($user);

                        header('Location: index.php', true, 302);
                        exit;
                    }
                }
            } catch (Throwable $e) {
                error_log('Login failure: ' . $e->getMessage());
                $errorMessage = 'Unable to sign in right now.';
            }
        }
    }
}

$csrfToken = Security::csrfToken();

$configured = is_file(__DIR__ . '/config/private.php')
    || is_file(__DIR__ . '/config/installed.lock');
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width,initial-scale=1,viewport-fit=cover"
    >

    <meta name="theme-color" content="#f4efe6" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1b1916" media="(prefers-color-scheme: dark)">
    <meta name="color-scheme" content="light dark">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <link rel="manifest" href="index.php?asset=app-meta">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link
        rel="icon"
        type="image/png"
        sizes="192x192"
        href="assets/icons/icon-192.png"
    >
    <link
        rel="apple-touch-icon"
        sizes="180x180"
        href="assets/icons/apple-touch-icon.png"
    >

    <title>Sign in · Bank Receipt Extractor</title>

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
            display: grid;
            align-items: start;
            justify-items: center;
            padding:
                max(28px, env(safe-area-inset-top))
                max(var(--space-4), env(safe-area-inset-right))
                max(var(--space-4), env(safe-area-inset-bottom))
                max(var(--space-4), env(safe-area-inset-left));
            background:
                radial-gradient(circle at 15% 0%, var(--glow-accent), transparent 32%),
                radial-gradient(circle at 90% 100%, var(--glow-stone), transparent 34%),
                linear-gradient(180deg, var(--bg-top), var(--bg));
            color: var(--text);
            font-family: var(--font-sans);
            font-size: 15px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            position: relative;
            width: min(420px, 100%);
            padding: var(--space-4);
            overflow: visible;
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            background: var(--panel);
            box-shadow: var(--shadow-lg);
        }
        .content { position: relative; z-index: 1; }
        .brand-row { display: flex; align-items: center; gap: var(--space-3); margin-bottom: var(--space-3); }
        .app-icon-shell { width: 44px; height: 44px; flex: 0 0 44px; display: grid; place-items: center; }
        .app-icon { display: block; width: 36px; height: 36px; object-fit: contain; }
        .brand-copy { min-width: 0; flex: 1; }
        .eyebrow {
            display: flex;
            align-items: center;
            gap: 6px;
            margin: 0 0 4px;
            color: var(--accent);
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .status-dot { width: 8px; height: 8px; flex: 0 0 8px; border-radius: 50%; background: var(--ok); box-shadow: 0 0 0 3px var(--ok-soft); }
        h1 { margin: 0; color: var(--text); font-size: 1.15rem; font-weight: 700; line-height: 1.25; letter-spacing: -0.02em; }
        .sub { margin: 4px 0 0; color: var(--muted); font-size: 0.875rem; line-height: 1.4; }
        .divider { height: 1px; margin: var(--space-3) 0; background: var(--line); }
        form { margin: 0; }
        .field { display: grid; gap: 6px; margin: 0 0 var(--space-3); color: var(--muted); font-size: 0.8125rem; font-weight: 700; line-height: 1.3; }
        input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line-strong);
            border-radius: var(--radius);
            background: var(--panel-strong);
            color: var(--text);
            transition: border-color var(--ease), box-shadow var(--ease), background-color var(--ease);
        }
        input:hover { border-color: var(--line-strong); }
        input:focus-visible { border-color: var(--accent); background: var(--hover); box-shadow: 0 0 0 3px var(--accent-soft); }
        button {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: background-color var(--ease), border-color var(--ease), box-shadow var(--ease);
        }
        .submit { margin-top: 2px; border: 0; background: var(--accent); box-shadow: var(--shadow-sm); color: var(--on-accent); }
        .submit:hover { background: var(--accent-hover); }
        .install { margin-top: var(--space-2); border: 1px solid var(--line-strong); background: var(--panel-strong); color: var(--text); }
        .install:hover { background: var(--hover); }
        .install[hidden] { display: none; }
        .error { margin-top: var(--space-3); padding: 10px 12px; border: 1px solid var(--danger-border); border-radius: var(--radius); background: var(--danger-bg); color: var(--danger); font-size: 0.875rem; line-height: 1.45; }
        .setup { margin: var(--space-3) 0 0; color: var(--soft); font-size: 0.8125rem; line-height: 1.45; }
        .setup a { color: var(--accent); font-weight: 700; text-decoration: none; }
        .setup a:hover { text-decoration: underline; }
        .pwa-gate {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: max(var(--space-4), env(safe-area-inset-top)) var(--space-4) max(var(--space-4), env(safe-area-inset-bottom));
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
        .pwa-gate-card h2 { margin: 0 0 6px; font-size: 1.05rem; font-weight: 700; }
        .pwa-gate-card p { margin: 0 0 10px; color: var(--muted); font-size: 0.875rem; line-height: 1.5; }
        .pwa-gate-steps { display: grid; gap: 6px; margin: 0 0 12px; padding: 10px; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface); text-align: left; font-size: 0.8125rem; line-height: 1.45; color: var(--text); }
        .pwa-gate-steps b { display: block; margin-bottom: 2px; color: var(--text); }
        .pwa-gate-btn { display: flex; align-items: center; justify-content: center; width: 100%; margin-bottom: 6px; border: 0; border-radius: var(--radius); background: var(--accent); color: var(--on-accent); font: inherit; font-size: 1rem; font-weight: 700; cursor: pointer; }
        .pwa-gate-dismiss { display: inline-flex; align-items: center; justify-content: center; width: auto; padding: 8px 12px; border: 0; background: transparent; color: var(--muted); font: inherit; font-size: 0.875rem; text-decoration: underline; cursor: pointer; }
        body.pwa-gate-locked { overflow: hidden; }
    </style>
</head>

<body data-pwa-gate>

<section class="card" aria-labelledby="login-title">
    <div class="content">

        <header class="brand-row">
            <div class="app-icon-shell">
                <img
                    class="app-icon"
                    src="img.svg"
                    alt=""
                    aria-hidden="true"
                >
            </div>

            <div class="brand-copy">
                <p class="eyebrow">
                    <span class="status-dot" aria-hidden="true"></span>
                    Secure access
                </p>

                <h1 id="login-title">Bank Receipt Extractor</h1>

                <p class="sub">
                    Auto OCR &#8594; registered sender validation.
                </p>
            </div>
        </header>

        <div class="divider" aria-hidden="true"></div>

        <form method="post" autocomplete="off">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= Security::e($csrfToken) ?>"
            >

            <label class="field">
                Username
                <input
                    type="text"
                    name="username"
                    maxlength="50"
                    required
                    autocomplete="username"
                    spellcheck="false"
                    autocapitalize="none"
                >
            </label>

            <label class="field">
                Password
                <input
                    type="password"
                    name="password"
                    maxlength="128"
                    required
                    autocomplete="current-password"
                >
            </label>

            <button class="submit" type="submit">
                Sign in
            </button>
        </form>

        <button
            class="install"
            type="button"
            data-pwa-install
            hidden
        >
            Install app
        </button>

        <?php if ($errorMessage !== null): ?>
            <div class="error" role="status">
                <?= Security::e($errorMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (!$configured): ?>
            <p class="setup">
                First deployment?
                <a href="install/">Open auto installer</a>.
            </p>
        <?php endif; ?>

    </div>
</section>

<script
    src="assets/pwa.php"
    data-sw="sw.php"
    nonce="<?= Security::e($nonce) ?>"
></script>

</body>
</html>