<?php

declare(strict_types=1);

namespace App\DTOs\Dashboard;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * A dashboard date range in London business days (D20): `today`, `7d`, `30d` or `custom` (from/to, inclusive).
 * `start`/`end` are London-local; use `startUtc()`/`endUtc()` for queries (end is exclusive).
 */
final readonly class DateRange
{
    public const PRESETS = ['today', '7d', '30d', 'custom'];

    public function __construct(
        public string $preset,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    public static function fromRequest(?string $preset, ?string $from, ?string $to, string $timezone): self
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();

        if ($preset === 'custom') {
            $start = self::parseDate($from, $timezone);
            $end = self::parseDate($to, $timezone);

            if ($start !== null && $end !== null) {
                [$start, $end] = $start->greaterThan($end) ? [$end, $start] : [$start, $end];

                // Keep a custom range sane: at most one year.
                return new self('custom', max($start, $end->subDays(365)), $end->addDay());
            }
        }

        return match ($preset) {
            '7d' => new self('7d', $today->subDays(6), $today->addDay()),
            '30d' => new self('30d', $today->subDays(29), $today->addDay()),
            default => new self('today', $today, $today->addDay()),
        };
    }

    public function startUtc(): CarbonImmutable
    {
        return $this->start->utc();
    }

    public function endUtc(): CarbonImmutable
    {
        return $this->end->utc();
    }

    /**
     * @return array{preset: string, from: string, to: string}
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->start->toDateString(),
            'to' => $this->end->subDay()->toDateString(),
        ];
    }

    private static function parseDate(?string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

            // Reject overflowing dates such as 2026-02-31.
            return $date instanceof CarbonImmutable && $date->toDateString() === $value ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }
}
