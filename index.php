<?php

declare(strict_types=1);

use App\Auth;
use App\BankReceiptParser;
use App\Database;
use App\MoneyFormatter;
use App\PaymentCalculator;
use App\PwaManifest;
use App\ReceiptData;
use App\Security;
use App\SummaryPresenter;
use App\SummaryRepository;
use App\TeamRepository;
use App\TransactionPresenter;
use App\TransactionRepository;
use App\UploadService;
use App\UserRepository;
use App\WeeklyObligationPresenter;
use App\WeeklyObligationService;

require __DIR__ . '/src/Autoload.php';

if (PwaManifest::isRequest()) {
    PwaManifest::respond(__DIR__);
}

Security::startSession();
$nonce = Security::nonce();
Security::sendHeaders($nonce);
Auth::requireLogin();
$currentUser = Auth::user();
$isAjaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$errorMessage = null;
$successMessage = isset($_SESSION['flash_success']) && is_string($_SESSION['flash_success'])
    ? $_SESSION['flash_success']
    : null;
unset($_SESSION['flash_success']);

/** @param array<string,mixed> $payload */
function dashboardRespondJson(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    try {
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('Dashboard JSON encoding failure: ' . $e->getMessage());
        http_response_code(500);
        echo '{"ok":false,"message":"Unable to encode response."}';
    }
    exit;
}

/** @param array<string,int|string|null> $row */
function dashboardRespondDuplicateReceipt(
    bool $isAjaxRequest,
    array $row,
    array $summary,
    array $weekly
): never {
    if ($isAjaxRequest) {
        dashboardRespondJson(200, [
            'ok' => true,
            'duplicate' => true,
            'message' => 'Receipt already saved.',
            'transaction' => TransactionPresenter::present($row),
            'summary' => $summary,
            'weekly' => $weekly,
        ]);
    }

    $_SESSION['flash_success'] = 'Receipt already saved.';
    header('Location: index.php', true, 303);
    exit;
}

/**
 * Renders a week's Monday-Sunday span as "D-D/MM/YY" (e.g. "7-13/09/26"), matching
 * weekLabelFor() in assets/app.php so a live-inserted row's week header (built
 * client-side) never disagrees with a full page render. Widens to include the month
 * and/or year on both sides when the week spans a month or year boundary (e.g.
 * "28/09-4/10/26", "28/12/26-3/01/27").
 */
function dashboardWeekLabel(DateTimeImmutable $start, DateTimeImmutable $end): string
{
    if ($start->format('Y-m') === $end->format('Y-m')) {
        return $start->format('j') . '-' . $end->format('j') . '/' . $end->format('m/y');
    }
    if ($start->format('Y') === $end->format('Y')) {
        return $start->format('j/m') . '-' . $end->format('j/m') . '/' . $end->format('y');
    }

    return $start->format('j/m/y') . '-' . $end->format('j/m/y');
}

/**
 * Groups Final output rows into Monday-Sunday weeks (same boundary as
 * WeeklyObligationService), keyed off created_at (upload date, in the app's
 * configured timezone) - not the OCR-derived receipt_date, which can be missing or
 * wrong. created_at is a server-generated timestamp every row always has, so this
 * needs no fallback and no client/server boundary computation to keep in sync (a
 * live-inserted row is, by definition, always uploaded during "this week" - see
 * currentWeekStartKey() in assets/app.php). The current week renders open; older
 * weeks render collapsed, newest first.
 *
 * @param list<array<string,mixed>> $transactions
 * @return list<array{week_start:string,label:string,is_current:bool,rows:list<array<string,mixed>>}>
 */
function dashboardGroupTransactionsByUploadWeek(array $transactions, string $timezone): array
{
    $zone = new DateTimeZone($timezone);
    $now = new DateTimeImmutable('now', $zone);
    $currentWeekStart = WeeklyObligationService::weekStartForDate($now)->format('Y-m-d');

    $groups = [];
    foreach ($transactions as $transaction) {
        $datePart = substr((string) ($transaction['created_at'] ?? ''), 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $datePart, $zone) ?: $now;
        $weekStart = WeeklyObligationService::weekStartForDate($date)->format('Y-m-d');

        if (!isset($groups[$weekStart])) {
            $weekStartDate = DateTimeImmutable::createFromFormat('!Y-m-d', $weekStart, $zone) ?: $now;
            $weekEndDate = WeeklyObligationService::weekEndForStart($weekStartDate);
            $groups[$weekStart] = [
                'week_start' => $weekStart,
                'label' => dashboardWeekLabel($weekStartDate, $weekEndDate),
                'is_current' => $weekStart === $currentWeekStart,
                'rows' => [],
            ];
        }
        $groups[$weekStart]['rows'][] = $transaction;
    }

    krsort($groups);

    return array_values($groups);
}

try {
    /** @var array<string,mixed> $config */
    $config = require __DIR__ . '/config/app.php';
    $uploadConfig = is_array($config['upload'] ?? null) ? $config['upload'] : [];
    $ocrConfig = is_array($config['ocr'] ?? null) ? $config['ocr'] : [];
    $dbConfig = is_array($config['db'] ?? null) ? $config['db'] : [];
    $realtimeConfig = is_array($config['realtime'] ?? null) ? $config['realtime'] : [];
    $appConfig = is_array($config['app'] ?? null) ? $config['app'] : [];
    $timezone = isset($appConfig['timezone']) && is_string($appConfig['timezone']) ? $appConfig['timezone'] : 'Asia/Jakarta';
    try {
        new DateTimeZone($timezone);
    } catch (Throwable $e) {
        $timezone = 'Asia/Jakarta';
    }

    $pollMs = max(1000, min((int) ($realtimeConfig['poll_ms'] ?? 2500), 60000));
    $hiddenPollMs = max($pollMs, min((int) ($realtimeConfig['hidden_poll_ms'] ?? 10000), 120000));

    $pdo = Database::connect($dbConfig);
    $userRepository = new UserRepository($pdo);
    Auth::syncUser($userRepository);
    $currentUser = Auth::user();
    $isAdmin = Auth::isAdmin();
    $transactionRepository = new TransactionRepository($pdo);
    $summaryRepository = new SummaryRepository($pdo);
    $weeklyService = new WeeklyObligationService($pdo, $timezone);
    $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $weeklyService->sync(WeeklyObligationService::operatingNow($now));
    $weekly = WeeklyObligationPresenter::presentForDisplay($weeklyService, $now);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        Auth::requireAdmin();
        if (!Security::validateCsrf(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
            http_response_code(419);
            throw new RuntimeException('Request validation failed. Reload the page and try again.');
        }

        $upload = null;
        try {
            $uploadService = new UploadService(
                __DIR__ . '/storage/uploads',
                (int) ($uploadConfig['max_bytes'] ?? 8 * 1024 * 1024),
                (int) ($uploadConfig['max_pixels'] ?? 40_000_000)
            );
            $file = $_FILES['receipt'] ?? null;
            if (!is_array($file)) {
                throw new RuntimeException('Please select a receipt image.');
            }
            $upload = $uploadService->receive($file);

            // exit() does not run finally, and every success path below exits: the
            // AJAX 201, the 303 redirect, and dashboardRespondDuplicateReceipt().
            // The finally block therefore only ever fired on throw, so a validated
            // image survived in storage/uploads on each successful save. A shutdown
            // handler is the one cleanup that outlives exit; the is_file() guard
            // keeps it idempotent with the finally below.
            $uploadPath = $upload->path;
            register_shutdown_function(static function () use ($uploadPath): void {
                if (is_file($uploadPath)) {
                    @unlink($uploadPath);
                }
            });

            $existingRow = $transactionRepository->findByImageSha256($upload->sha256);
            if ($existingRow !== null) {
                dashboardRespondDuplicateReceipt(
                    $isAjaxRequest,
                    $existingRow,
                    SummaryPresenter::present($summaryRepository->dashboard(null, $timezone)),
                    $weekly
                );
            }

            $ocrText = $_POST['ocr_text'] ?? null;
            $maxOcrTextBytes = max(1_000, min((int) ($ocrConfig['max_text_bytes'] ?? 100_000), 250_000));
            if (!is_string($ocrText)) {
                throw new RuntimeException('Browser OCR result is missing. Reload the page and try again.');
            }
            $ocrText = trim($ocrText);
            if ($ocrText === '' || strlen($ocrText) > $maxOcrTextBytes || str_contains($ocrText, "\0")) {
                throw new RuntimeException('Browser OCR result is invalid.');
            }

            $receipt = (new BankReceiptParser())->parse($ocrText);
            $selectedSenderIdRaw = $_POST['selected_sender_id'] ?? null;
            $selectedSenderId = null;
            if ($selectedSenderIdRaw !== null && $selectedSenderIdRaw !== '') {
                if (!is_string($selectedSenderIdRaw) || preg_match('/^[1-9]\d{0,18}$/D', $selectedSenderIdRaw) !== 1) {
                    throw new RuntimeException('Selected SUBID is invalid. Transaction rejected.');
                }
                $selectedSenderId = (int) $selectedSenderIdRaw;
            }

            // Database membership is authoritative. For duplicate sender names the browser must select
            // one active SUBID first; the server revalidates that selected record against OCR sender data.
            $teamRepository = new TeamRepository($pdo, $timezone);
            if ($receipt->senderName !== '') {
                $sender = $teamRepository->findActiveSender($receipt->senderName, $selectedSenderId);
            } elseif ($receipt->sourceBankCode !== null && $receipt->sourceAccountMask !== null
                && $teamRepository->accountMaskHasAnyMatch($receipt->sourceBankCode, $receipt->sourceAccountMask)) {
                // No sender name was printed on the receipt (e.g. myBCA's own "Transfer
                // Berhasil" screen); fall back to matching the masked source account
                // against a registered account number instead.
                $sender = $teamRepository->findActiveSenderByAccount($receipt->sourceBankCode, $receipt->sourceAccountMask, $selectedSenderId);
            } else {
                // Neither a name nor a matching account was found at all - the picker UI
                // (api/sender-options.php) already offered every active sender for the
                // admin to choose from, so $selectedSenderId is authoritative here.
                $sender = $teamRepository->findActiveSenderManually($selectedSenderId);
            }
            $teamMemberId = (int) $sender['id'];
            $lockName = 'receipt-subid-' . (string) $teamMemberId;
            $lockStatement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 5)');
            $lockStatement->execute(['lock_name' => $lockName]);
            if ((int) $lockStatement->fetchColumn() !== 1) {
                throw new RuntimeException('SUBID is busy. Please retry.');
            }

            try {
                $operatingNow = WeeklyObligationService::operatingNow($now);
                $weeklyService->sync($operatingNow);
                if (!$weeklyService->canAcceptPayment($teamMemberId, $operatingNow)) {
                    throw new RuntimeException('SUBID is already paid for the tracked week.');
                }

                $team = $sender['team'];
                $canonicalReceipt = new ReceiptData(
                $sender['display_name'],
                $receipt->sourceAccountLast4,
                $receipt->originalAmount,
                $receipt->referenceNo,
                $receipt->receiptDate,
                $receipt->receiptTime
            );
            $adjustedAmount = (new PaymentCalculator())->adjustedAmount($canonicalReceipt->originalAmount, $team);
            try {
                $transactionId = $transactionRepository->save(
                    $canonicalReceipt,
                    $teamMemberId,
                    (string) $sender['alias'],
                    $team,
                    $adjustedAmount,
                    $upload->sha256
                );
            } catch (Throwable $e) {
                if (!TransactionRepository::isDuplicateKeyException($e)) {
                    throw $e;
                }

                $existingRow = $transactionRepository->findByImageSha256($upload->sha256);
                if ($existingRow === null) {
                    // Same image already stored is one cause of 1062; the other is
                    // this sender having filed this reference number before, with a
                    // different image. That second case used to fall through to the
                    // generic handler, which classifies PDOException as unsafe and
                    // answers "Unable to load the application data." - true but
                    // useless. Asking the table which cause it was avoids parsing
                    // the driver's error text. Throwing here (rather than exiting)
                    // lets the finally below release the lock as usual.
                    $clash = $transactionRepository->findByMemberReference(
                        $teamMemberId,
                        $canonicalReceipt->referenceNo
                    );
                    if ($clash !== null) {
                        throw new RuntimeException(
                            'Reference number ' . (string) $canonicalReceipt->referenceNo
                            . ' is already recorded for this SUBID. Transaction rejected.'
                        );
                    }

                    throw $e;
                }

                // dashboardRespondDuplicateReceipt() exits and exit() skips the
                // finally below, so the named lock must be handed back here. No
                // mutation is left under the lock at this point: the insert already
                // failed on the duplicate key and only the existing row is reported.
                // RELEASE_LOCK on a name this session no longer holds is a no-op,
                // so this stays safe next to the finally.
                $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $releaseStatement->execute(['lock_name' => $lockName]);

                dashboardRespondDuplicateReceipt(
                    $isAjaxRequest,
                    $existingRow,
                    SummaryPresenter::present($summaryRepository->dashboard(null, $timezone)),
                    $weekly
                );
            }

                $weeklyService->sync($operatingNow);
                $weekly = WeeklyObligationPresenter::presentForDisplay($weeklyService, $now);
            } finally {
                $releaseStatement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $releaseStatement->execute(['lock_name' => $lockName]);
            }

            $message = sprintf(
                '%s %s %s',
                $canonicalReceipt->senderName,
                $team,
                MoneyFormatter::formatIdr($adjustedAmount)
            );

            if ($isAjaxRequest) {
                $savedRow = $transactionRepository->findById($transactionId);
                if ($savedRow === null) {
                    throw new RuntimeException('Transaction was saved but could not be reloaded.');
                }
                dashboardRespondJson(201, [
                    'ok' => true,
                    'message' => $message,
                    'transaction' => TransactionPresenter::present($savedRow),
                    'summary' => SummaryPresenter::present($summaryRepository->dashboard(null, $timezone)),
                    'weekly' => $weekly,
                ]);
            }

            $_SESSION['flash_success'] = $message;
            header('Location: index.php', true, 303);
            exit;
        } finally {
            if ($upload !== null && is_file($upload->path)) {
                @unlink($upload->path);
            }
        }
    }

    $transactions = $transactionRepository->findRecent();
    $transactionWeeks = dashboardGroupTransactionsByUploadWeek($transactions, $timezone);
    $summary = SummaryPresenter::present($summaryRepository->dashboard(null, $timezone));
} catch (Throwable $e) {
    error_log('Application failure: ' . $e->getMessage());
    $errorMessage = ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) && !$e instanceof PDOException
        ? $e->getMessage()
        : 'Unable to load the application data.';

    if ($isAjaxRequest && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
        $status = http_response_code();
        if ($status < 400) {
            $status = ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) && !$e instanceof PDOException ? 422 : 500;
        }
        dashboardRespondJson($status, ['ok' => false, 'message' => $errorMessage]);
    }

    $transactions = [];
    $transactionWeeks = [];
    $summary = [
        'week' => ['label' => 'Current week', 'total' => 'IDR 0', 'count' => 0, 'teams' => ['XCTD' => 'IDR 0', 'MNX' => 'IDR 0']],
        'month' => ['label' => 'Current month', 'total' => 'IDR 0', 'count' => 0, 'teams' => ['XCTD' => 'IDR 0', 'MNX' => 'IDR 0']],
        'year' => ['label' => 'Current year', 'total' => 'IDR 0', 'count' => 0, 'teams' => ['XCTD' => 'IDR 0', 'MNX' => 'IDR 0']],
        'all' => ['label' => 'All time', 'total' => 'IDR 0', 'count' => 0, 'teams' => ['XCTD' => 'IDR 0', 'MNX' => 'IDR 0']],
    ];
    $weekly = [
        'label' => 'Current week',
        'week_start' => '',
        'week_end' => '',
        'paid' => 0,
        'pending' => 0,
        'outstanding_senders' => 0,
        'outstanding_weeks' => 0,
        'rows' => [],
    ];
    $pollMs = 2500;
    $hiddenPollMs = 10000;
}

$csrfToken = Security::csrfToken();
$lastId = isset($transactions[0]['id']) ? (int) $transactions[0]['id'] : 0;
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
    <title>Bank Receipt Extractor</title>
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
        .summary-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-2); margin-bottom: var(--space-3); }
        .summary { min-width: 0; padding: var(--space-3); background: linear-gradient(160deg, var(--panel-strong), var(--accent-soft)); }
        .summary-label { color: var(--muted); font-size: 0.75rem; line-height: 1.3; }
        .summary-total {
            margin: 4px 0;
            color: var(--text);
            font-size: clamp(1.2rem, 4.5vw, 1.5rem);
            font-weight: 700;
            letter-spacing: -0.03em;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }
        .summary-teams { display: flex; flex-wrap: wrap; gap: 8px 14px; color: var(--muted); font-size: 0.8125rem; }
        .summary-teams b { color: var(--text); font-weight: 700; }
        .section-title { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2); margin-bottom: var(--space-2); }
        .section-title h2 { margin: 0; color: var(--text); font-size: 0.95rem; font-weight: 700; letter-spacing: -0.015em; }
        .meta { display: flex; align-items: center; gap: var(--space-2); }
        .count, .live-state { color: var(--muted); font-size: 0.8125rem; white-space: nowrap; }
        .live-state::before { content: ""; display: inline-block; width: 8px; height: 8px; margin-right: 6px; border-radius: 50%; background: var(--muted); vertical-align: 1px; }
        .live-state.live-ok { color: var(--ok); }
        .live-state.live-ok::before { background: var(--ok); box-shadow: 0 0 0 3px var(--ok-soft); }
        .live-state.live-warn { color: var(--warn); }
        .live-state.live-warn::before { background: var(--warn); box-shadow: 0 0 0 3px var(--warn-soft); }
        .weekly-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-2); margin-bottom: var(--space-2); }
        .weekly-metric { min-width: 0; padding: 8px; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface); }
        .weekly-metric span, .weekly-metric b { display: block; overflow-wrap: anywhere; }
        .weekly-metric span { color: var(--muted); font-size: 0.75rem; line-height: 1.25; }
        .weekly-metric b { margin-top: 4px; font-size: 1rem; font-weight: 700; line-height: 1.15; }
        .weekly-table, .table-wrap {
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--panel-strong);
        }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            font-size: 0.875rem;
            line-height: 1.35;
            vertical-align: middle;
            overflow-wrap: anywhere;
        }
        th { background: var(--surface); color: var(--muted); font-size: 0.75rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; }
        tbody tr:last-child td { border-bottom: 0; }
        .right { text-align: right; }
        .final { color: var(--accent); font-weight: 700; }
        .empty { padding: 16px; color: var(--muted); text-align: center; }
        .week-group { margin-bottom: var(--space-2); border: 1px solid var(--line); border-radius: var(--radius); background: var(--panel-strong); overflow: hidden; }
        .week-group:last-child { margin-bottom: 0; }
        .week-summary { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); padding: 8px 12px; cursor: pointer; font-size: 0.875rem; font-weight: 700; color: var(--text); }
        .week-summary .count { color: var(--muted); font-size: 0.75rem; font-weight: 600; }
        .week-group .table-wrap { border: 0; border-top: 1px solid var(--line); border-radius: 0; }
        .tx-delete {
            min-width: 44px;
            padding: 8px 12px;
            border: 1px solid var(--danger-line);
            border-radius: var(--radius);
            background: var(--panel-strong);
            color: var(--danger);
            font: inherit;
            font-size: 0.8125rem;
            font-weight: 700;
            cursor: pointer;
        }
        .tx-delete:hover { background: var(--danger-soft); }
        .status-pill {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 2px 8px;
            border: 1px solid var(--line);
            border-radius: var(--radius-pill);
            background: var(--surface);
            color: var(--muted);
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 1.2;
        }
        .status-pill.paid { border-color: var(--ok-line); background: var(--ok-soft); color: var(--ok); }
        .status-pill.pending { border-color: var(--warn-line); background: var(--warn-soft); color: var(--warn); }
        .status-pill.disabled { border-color: var(--line); background: var(--surface); color: var(--muted); }
        .carry { color: var(--warn); font-weight: 700; }
        .carry.zero { color: var(--muted); font-weight: 600; }
        .upload-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--space-2); align-items: end; }
        .field { display: grid; gap: 4px; color: var(--muted); font-size: 0.8125rem; font-weight: 700; }
        .file {
            width: 100%;
            min-width: 0;
            padding: 8px 10px;
            border: 1px dashed var(--line-strong);
            border-radius: var(--radius);
            background: var(--panel-strong);
            color: var(--muted);
        }
        .file:hover { background: var(--hover); }
        .file:focus-visible { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
        .file::file-selector-button {
            min-height: 32px;
            margin-right: 10px;
            padding: 6px 10px;
            border: 0;
            border-radius: var(--radius-sm);
            background: var(--accent);
            color: var(--on-accent);
            font: inherit;
            font-size: 0.875rem;
            font-weight: 700;
        }
        .primary {
            width: 100%;
            padding: 10px 14px;
            border: 0;
            background: var(--accent);
            box-shadow: var(--shadow-sm);
            color: var(--on-accent);
            font-size: 0.9375rem;
            font-weight: 700;
            cursor: pointer;
        }
        .primary:hover { background: var(--accent-hover); }
        .primary:disabled { opacity: 0.55; cursor: wait; }
        .auto-note { margin-top: 4px; color: var(--muted); font-size: 0.8125rem; }
        .alias-choice { margin-top: var(--space-2); padding: var(--space-3); border: 1px solid var(--line-strong); border-radius: var(--radius); background: var(--accent-soft); }
        .alias-choice[hidden] { display: none; }
        .alias-choice-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--space-2); margin-bottom: var(--space-2); }
        .alias-choice-head > div { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px; min-width: 0; }
        .alias-choice-head b { font-size: 0.875rem; }
        .alias-choice-head span { color: var(--muted); font-size: 0.8125rem; overflow-wrap: anywhere; }
        .alias-cancel { padding: 8px 12px; color: var(--muted); font-size: 0.8125rem; background: var(--panel-strong); }
        .alias-options { display: flex; flex-wrap: wrap; gap: var(--space-2); }
        .alias-option { min-height: 44px; padding: 8px 12px; border: 1px solid var(--line-strong); background: var(--panel-strong); color: var(--accent); font-size: 0.875rem; font-weight: 700; }
        .alias-option small { margin-left: 4px; color: var(--muted); font-size: 0.75rem; font-weight: 600; }
        .alias-option:hover, .alias-option:focus-visible { background: var(--hover); }
        .ocr-progress { margin-top: var(--space-2); }
        .ocr-progress[hidden] { display: none; }
        .ocr-progress-head { display: flex; justify-content: space-between; gap: var(--space-2); margin-bottom: 4px; color: var(--muted); font-size: 0.8125rem; }
        .ocr-progress-track { height: 6px; overflow: hidden; border-radius: var(--radius-pill); background: var(--bar-track); }
        .ocr-progress-track progress { display: block; width: 100%; height: 6px; border: 0; background: var(--bar-track); appearance: none; }
        .ocr-progress-track progress::-webkit-progress-bar { background: var(--bar-track); }
        .ocr-progress-track progress::-webkit-progress-value { background: var(--accent); }
        .ocr-progress-track progress::-moz-progress-bar { background: var(--accent); }
        .read-only { margin: 0; color: var(--muted); font-size: 0.875rem; line-height: 1.45; }
        .toast-stack {
            position: fixed;
            left: 50%;
            bottom: max(var(--space-3), env(safe-area-inset-bottom));
            z-index: 9999;
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
            text-align: center;
            opacity: 0;
            transform: translateY(6px);
            transition: opacity var(--ease), transform var(--ease);
        }
        .toast-show { opacity: 1; transform: none; }
        .toast-ok { color: var(--ok); }
        .toast-error { color: var(--danger); }
        .pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: var(--space-2); margin-top: var(--space-2); }
        .pager[hidden] { display: none; }
        .pager-info { color: var(--muted); font-size: 0.8125rem; font-weight: 700; }
        .pager-btn { min-width: 44px; padding: 8px 12px; border: 1px solid var(--line-strong); background: var(--panel-strong); color: var(--text); font-size: 0.875rem; font-weight: 700; cursor: pointer; }
        .pager-btn:disabled { opacity: 0.45; cursor: default; }
        .page-hidden { display: none !important; }
        .receipt-date { display: table-cell; }
        @media (min-width: 720px) {
            .upload-grid { grid-template-columns: minmax(0, 1fr) auto; }
            .primary { width: auto; }
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
        input[type="file"],
        input.file {
            display: block;
            width: 100%;
            padding: 0 var(--control-pad-x) 0 0;
            color: var(--muted);
            font-weight: 500;
            line-height: var(--control-line);
        }
        input[type="file"]::file-selector-button,
        input.file::file-selector-button {
            height: var(--control-line);
            margin: 0 var(--control-gap) 0 0;
            padding: 0 var(--control-pad-x);
            border: 0;
            border-right: var(--control-border) solid var(--accent-hover);
            border-radius: calc(var(--control-radius) - var(--control-border)) 0 0 calc(var(--control-radius) - var(--control-border));
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

        /* Weekly status stays full width; each record is one horizontal line. */
        #weekly-obligations {
            width: 100%;
            max-width: none;
            margin-right: 0;
            margin-left: 0;
        }
        #weekly-obligations .section-title,
        #weekly-obligations .weekly-metrics,
        #weekly-obligations .weekly-table,
        #weekly-obligations .pager,
        .card:has(#transactions-weeks) > .section-title,
        #transactions-weeks,
        #transactions-weeks .week-group,
        #transactions-weeks .table-wrap {
            width: 100%;
            max-width: none;
            min-width: 0;
        }
        #weekly-obligations .section-title,
        .card:has(#transactions-weeks) > .section-title,
        #transactions-weeks .week-summary {
            flex-wrap: nowrap;
            min-width: 0;
        }
        #weekly-obligations .section-title h2,
        .card:has(#transactions-weeks) > .section-title h2,
        #transactions-weeks .week-summary > span:first-child {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        #weekly-obligations .section-title .count,
        .card:has(#transactions-weeks) > .section-title .meta,
        #transactions-weeks .week-summary .count {
            flex: 0 0 auto;
            white-space: nowrap;
        }
        #weekly-obligations .weekly-metrics {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        #weekly-obligations .weekly-metric span,
        #weekly-obligations .weekly-metric b {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        #weekly-obligations .weekly-table,
        #transactions-weeks .table-wrap {
            display: block;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
        }
        #weekly-obligations .weekly-table table,
        #transactions-weeks .table-wrap table {
            display: table;
            width: 100%;
            min-width: max(100%, 34rem);
            border-collapse: collapse;
            table-layout: auto;
        }
        #weekly-obligations .weekly-table thead,
        #transactions-weeks .table-wrap thead { display: table-header-group; }
        #weekly-obligations .weekly-table tbody,
        #transactions-weeks .table-wrap tbody { display: table-row-group; }
        #weekly-obligations .weekly-table tr,
        #transactions-weeks .table-wrap tr { display: table-row; }
        #weekly-obligations .weekly-table th,
        #weekly-obligations .weekly-table td,
        #transactions-weeks .table-wrap th,
        #transactions-weeks .table-wrap td {
            display: table-cell;
            width: auto;
            max-width: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: middle;
        }
        #transactions-weeks .table-wrap .receipt-date { display: table-cell; }
    </style>
</head>
<body
    data-realtime-endpoint="api/transactions.php"
    data-sender-options-endpoint="api/sender-options.php"
    data-last-id="<?= $lastId ?>"
    data-poll-ms="<?= $pollMs ?>"
    data-hidden-poll-ms="<?= $hiddenPollMs ?>"
    data-ocr-language="eng"
    data-ocr-worker="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/worker.min.js"
    data-ocr-core="https://cdn.jsdelivr.net/npm/tesseract.js-core@5.1.1"
    data-ocr-lang="https://cdn.jsdelivr.net/npm/@tesseract.js-data/eng@1.0.0/4.0.0_best_int"
    data-pwa-gate
>
<main class="wrap">
    <header class="top">
        <div class="brand"><img class="brand-icon" src="img.svg" alt=""><div><h1 class="title">Bank Receipt Extractor</h1><p class="sub">Auto OCR → registered sender validation.</p></div></div>
        <div class="top-actions">
            <?php if (is_array($currentUser)): ?><span class="role"><?= Security::e($currentUser['username']) ?> · <?= Security::e($currentUser['role']) ?></span><?php endif; ?>
            <button class="install-btn" type="button" data-pwa-install hidden>Install</button>
            <form class="logout-form" method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>"><button class="logout" type="submit">Sign out</button></form>
        </div>
    </header>
    <nav class="tabs" aria-label="Application navigation"><a class="tab active" href="index.php">Dashboard</a><a class="tab" href="statistics.php">Statistics</a><?php if ($isAdmin): ?><a class="tab" href="senders.php">Setting</a><a class="tab" href="users.php">Users</a><?php endif; ?></nav>

    <?php if ($successMessage !== null): ?><div class="msg ok" role="status"><?= Security::e($successMessage) ?></div><?php endif; ?>
    <?php if ($errorMessage !== null): ?><div class="msg error" role="status"><?= Security::e($errorMessage) ?></div><?php endif; ?>
    <div id="ajax-message" class="msg" role="status" hidden></div>

    <section class="summary-grid" aria-label="This week payment summary">
        <?php foreach (['week'] as $period): $item = $summary[$period]; ?>
            <article class="summary" data-summary-period="<?= Security::e($period) ?>">
                <div class="summary-label" data-summary-label><?= Security::e((string) $item['label']) ?> · <span data-summary-count><?= (int) $item['count'] ?></span> rows</div>
                <div class="summary-total" data-summary-total><?= Security::e((string) $item['total']) ?></div>
                <div class="summary-teams"><span>XCTD <b data-summary-team="XCTD"><?= Security::e((string) $item['teams']['XCTD']) ?></b></span><span>MNX <b data-summary-team="MNX"><?= Security::e((string) $item['teams']['MNX']) ?></b></span></div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="card" id="weekly-obligations" aria-label="Weekly payment obligations">
        <div class="section-title"><h2>Weekly payment status</h2><span id="weekly-label" class="count"><?= Security::e((string) ($weekly['label'] ?? '')) ?></span></div>
        <div class="weekly-metrics">
            <div class="weekly-metric"><span>Paid</span><b id="weekly-paid"><?= (int) ($weekly['paid'] ?? 0) ?></b></div>
            <div class="weekly-metric"><span>Pending</span><b id="weekly-pending"><?= (int) ($weekly['pending'] ?? 0) ?></b></div>
            <div class="weekly-metric"><span>Carry-forward</span><b id="weekly-outstanding"><?= (int) ($weekly['outstanding_weeks'] ?? 0) ?> weeks</b></div>
        </div>
        <div class="weekly-table"><table aria-label="Weekly sender payment status"><thead><tr><th>SUBID</th><th>Location</th><th>Team</th><th>This week</th><th class="right">Carry</th></tr></thead><tbody id="weekly-status-body">
        <?php $weeklyRows = is_array($weekly['rows'] ?? null) ? $weekly['rows'] : []; ?>
        <?php if ($weeklyRows === []): ?><tr id="weekly-empty-row"><td colspan="5" class="empty">No registered sender obligations.</td></tr><?php else: ?>
            <?php foreach ($weeklyRows as $row): $status = (string) ($row['current_status'] ?? 'pending'); $carry = (int) ($row['outstanding_weeks'] ?? 0); ?>
                <tr data-weekly-sender-id="<?= (int) ($row['sender_id'] ?? 0) ?>"><td><?= Security::e((string) ($row['alias'] ?? '')) ?></td><td><?= Security::e((string) ($row['location'] ?? '')) ?></td><td><?= Security::e((string) ($row['team'] ?? '')) ?></td><td><span class="status-pill <?= Security::e($status) ?>"><?= Security::e(ucfirst($status)) ?></span></td><td class="right <?= $carry > 0 ? 'carry' : 'carry zero' ?>"><?= $carry > 0 ? Security::e((string) $carry . ' wk') : '—' ?></td></tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody></table></div>
        <div class="pager" id="weekly-pager" hidden><button type="button" class="pager-btn" data-pager="weekly" data-dir="-1">Prev</button><span class="pager-info" id="weekly-pager-info">Page 1 / 1</span><button type="button" class="pager-btn" data-pager="weekly" data-dir="1">Next</button></div>
    </section>

    <?php if ($isAdmin): ?>
        <section class="card">
            <div class="section-title"><h2>Upload receipt</h2><span class="count">Auto extract &amp; save</span></div>
            <form id="upload-form" method="post" action="index.php" enctype="multipart/form-data" autocomplete="off" class="upload-grid">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                <input id="ocr-text" type="hidden" name="ocr_text" value="">
                <input id="selected-sender-id" type="hidden" name="selected_sender_id" value="">
                <label class="field">Receipt image · max 8 MB<input id="receipt-file" class="file" type="file" name="receipt" accept="image/jpeg,image/png,image/webp" required></label>
                <button id="upload-submit" class="primary" type="submit">Extract &amp; Save</button>
            </form>
            <div id="alias-choice" class="alias-choice" role="group" aria-labelledby="alias-choice-title" hidden>
                <div class="alias-choice-head"><div><b id="alias-choice-title">Choose SUBID</b><span id="alias-choice-sender"></span></div><button id="alias-choice-cancel" class="alias-cancel" type="button">Cancel</button></div>
                <p class="muted">This sender name has more than one active SUBID: choose one before the image is uploaded to the server.</p>
                <div id="alias-options" class="alias-options"></div>
            </div>
            <div id="ocr-progress" class="ocr-progress" role="status" aria-live="polite" hidden><div class="ocr-progress-head"><span id="ocr-progress-label">Preparing OCR…</span><span id="ocr-progress-value">0%</span></div><div class="ocr-progress-track"><progress id="ocr-progress-bar" max="100" value="0"></progress></div></div>
        </section>
    <?php else: ?>
        <section class="card"><p class="read-only">Read-only account. Live totals and transactions remain enabled; write actions are blocked server-side.</p></section>
    <?php endif; ?>

    <section class="card">
        <div class="section-title"><h2>Final output</h2><div class="meta"><span id="live-status" class="live-state live-ok">Live</span><span id="row-count" class="count"><?= count($transactions) ?> rows</span></div></div>
        <div id="transactions-weeks" data-can-delete="<?= $isAdmin ? '1' : '0' ?>">
        <?php if ($transactionWeeks === []): ?>
            <div class="empty" id="transactions-empty">No transactions found.</div>
        <?php else: ?>
            <?php foreach ($transactionWeeks as $weekGroup): ?>
            <details class="week-group" data-week-start="<?= Security::e($weekGroup['week_start']) ?>"<?= $weekGroup['is_current'] ? ' open' : '' ?>>
                <summary class="week-summary"><span><?= Security::e($weekGroup['label']) ?></span><span class="count" data-week-count><?= count($weekGroup['rows']) ?> rows</span></summary>
                <div class="table-wrap"><table aria-label="Final transaction output"><thead><tr><th>SUBID</th><th>Team</th><th class="right">Final</th><th class="receipt-date">Receipt date</th><?php if ($isAdmin): ?><th class="right">Delete</th><?php endif; ?></tr></thead><tbody>
                <?php foreach ($weekGroup['rows'] as $transaction): ?>
                    <tr data-id="<?= (int) ($transaction['id'] ?? 0) ?>">
                        <td><?= Security::e((string) (($transaction['sender_alias'] ?? null) ?: '—')) ?></td>
                        <td><?= Security::e((string) ($transaction['team'] ?? '')) ?></td>
                        <td class="right final"><?= Security::e(MoneyFormatter::formatIdr((string) ($transaction['adjusted_amount'] ?? '0'))) ?></td>
                        <td class="receipt-date"><?= Security::e((string) (($transaction['receipt_date'] ?? null) ?: ($transaction['created_at'] ?? ''))) ?></td>
                        <?php if ($isAdmin): ?><td class="right"><button type="button" class="tx-delete" data-id="<?= (int) ($transaction['id'] ?? 0) ?>">Delete</button></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </details>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </section>
</main>
<div id="toast-stack" class="toast-stack" aria-live="polite" aria-atomic="false"></div>
<script src="assets/jquery.php"></script>
<!-- SRI hash of tesseract.js@5.1.1/dist/tesseract.min.js (66695 bytes). CSP already
     confines scripts to this origin, but the origin itself stays trusted blindly
     without it: a tampered bundle would run inside an admin session that can post
     transactions. Recompute and update this hash whenever the pinned version above
     changes, otherwise the browser refuses the script and OCR stops loading. -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js" integrity="sha384-GJqSu7vueQ9qN0E9yLPb3Wtpd7OrgK8KmYzC8T1IysG1bcvxvIO4qtYR/D3A991F" crossorigin="anonymous"></script>
<script src="assets/app.php"></script>
<script src="assets/pwa.php" data-sw="sw.php"></script>
</body>
</html>
