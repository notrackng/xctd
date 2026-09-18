<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;

final class ReportingPresenter
{
    /** @param array<string,mixed> $report @return array<string,mixed> */
    public static function present(array $report): array
    {
        $currentMonth = is_array($report['current_month'] ?? null) ? $report['current_month'] : [];
        $teams = is_array($currentMonth['teams'] ?? null) ? $currentMonth['teams'] : [];
        $history = [];
        foreach (is_array($report['weekly_history'] ?? null) ? $report['weekly_history'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $history[] = [
                'key' => (string) ($row['key'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'total_raw' => (string) ($row['total'] ?? '0'),
                'XCTD_raw' => (string) ($row['XCTD'] ?? '0'),
                'MNX_raw' => (string) ($row['MNX'] ?? '0'),
                'total' => MoneyFormatter::formatIdr((string) ($row['total'] ?? '0')),
                'XCTD' => MoneyFormatter::formatIdr((string) ($row['XCTD'] ?? '0')),
                'MNX' => MoneyFormatter::formatIdr((string) ($row['MNX'] ?? '0')),
                'count' => max(0, (int) ($row['count'] ?? 0)),
            ];
        }

        $paidHistory = [];
        foreach (is_array($report['paid_history'] ?? null) ? $report['paid_history'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $paidHistory[] = TransactionPresenter::present($row);
        }

        $carryHistory = [];
        foreach (is_array($report['carry_history'] ?? null) ? $report['carry_history'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $weekStart = (string) ($row['week_start'] ?? '');
            $weekEnd = (string) ($row['week_end'] ?? '');
            $carryHistory[] = [
                'sender_id' => max(0, (int) ($row['team_member_id'] ?? 0)),
                'alias' => (string) ($row['alias'] ?? ''),
                'sender_name' => (string) ($row['display_name'] ?? ''),
                'team' => (string) ($row['team'] ?? ''),
                'location' => (string) ($row['location'] ?? ''),
                'week_start' => $weekStart,
                'week' => self::weekLabel($weekStart, $weekEnd),
                'status' => (string) ($row['status'] ?? 'unpaid'),
            ];
        }

        $changes = [];
        foreach (['week', 'month', 'year'] as $period) {
            $item = is_array($report['changes'][$period] ?? null) ? $report['changes'][$period] : [];
            $changes[$period] = [
                'label' => (string) ($item['label'] ?? ucfirst($period)),
                'current' => MoneyFormatter::formatIdr((string) ($item['current'] ?? '0')),
                'previous' => MoneyFormatter::formatIdr((string) ($item['previous'] ?? '0')),
                'direction' => in_array(($item['direction'] ?? ''), ['up', 'down', 'flat'], true) ? $item['direction'] : 'flat',
                'percent' => is_numeric($item['percent'] ?? null) ? (float) $item['percent'] : null,
            ];
        }

        return [
            'generated_at' => (string) ($report['generated_at'] ?? ''),
            'current_month' => [
                'label' => (string) ($currentMonth['label'] ?? ''),
                'total' => MoneyFormatter::formatIdr((string) ($currentMonth['total'] ?? '0')),
                'count' => max(0, (int) ($currentMonth['count'] ?? 0)),
                'teams' => [
                    'XCTD' => [
                        'total_raw' => (string) (($teams['XCTD']['total'] ?? '0')),
                        'total' => MoneyFormatter::formatIdr((string) (($teams['XCTD']['total'] ?? '0'))),
                        'count' => max(0, (int) ($teams['XCTD']['count'] ?? 0)),
                    ],
                    'MNX' => [
                        'total_raw' => (string) (($teams['MNX']['total'] ?? '0')),
                        'total' => MoneyFormatter::formatIdr((string) (($teams['MNX']['total'] ?? '0'))),
                        'count' => max(0, (int) ($teams['MNX']['count'] ?? 0)),
                    ],
                ],
            ],
            'weekly_history' => $history,
            'paid_history' => $paidHistory,
            'carry_history' => $carryHistory,
            'changes' => $changes,
        ];
    }

    private static function weekLabel(string $weekStart, string $weekEnd): string
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $weekStart);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $weekEnd);
        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
            return $weekStart;
        }

        return $start->format('d M') . '–' . $end->format('d M Y');
    }
}
