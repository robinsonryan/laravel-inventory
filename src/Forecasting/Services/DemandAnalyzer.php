<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Forecasting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;

final class DemandAnalyzer
{
    private const TREND_THRESHOLD = 0.10; // 10% change threshold

    /**
     * Calculate average daily usage over a period.
     */
    public function calculateAverageDailyUsage(string $itemId, ?int $days = 90): float
    {
        $days ??= 90;

        $from = Carbon::now()->subDays($days);
        $to = Carbon::now();

        $totalConsumption = $this->getTotalConsumption($itemId, $from, $to);

        if ($totalConsumption <= 0) {
            return 0.0;
        }

        return $totalConsumption / $days;
    }

    /**
     * Calculate usage trend (increasing, decreasing, stable).
     */
    public function calculateTrend(string $itemId, ?int $days = 90): string
    {
        $days ??= 90;

        $halfPeriod = (int) floor($days / 2);

        // Get usage for first half of period
        $firstHalfFrom = Carbon::now()->subDays($days);
        $firstHalfTo = Carbon::now()->subDays($halfPeriod);
        $firstHalfUsage = $this->getTotalConsumption($itemId, $firstHalfFrom, $firstHalfTo);

        // Get usage for second half of period (more recent)
        $secondHalfFrom = Carbon::now()->subDays($halfPeriod);
        $secondHalfTo = Carbon::now();
        $secondHalfUsage = $this->getTotalConsumption($itemId, $secondHalfFrom, $secondHalfTo);

        // Handle edge case where both are zero
        if ($firstHalfUsage <= 0 && $secondHalfUsage <= 0) {
            return 'stable';
        }

        // Handle edge case where first half is zero but second has usage
        if ($firstHalfUsage <= 0 && $secondHalfUsage > 0) {
            return 'increasing';
        }

        // Calculate percentage change
        $percentageChange = ($secondHalfUsage - $firstHalfUsage) / $firstHalfUsage;

        if ($percentageChange >= self::TREND_THRESHOLD) {
            return 'increasing';
        }

        if ($percentageChange <= -self::TREND_THRESHOLD) {
            return 'decreasing';
        }

        return 'stable';
    }

    /**
     * Get total consumption in period.
     */
    public function getTotalConsumption(string $itemId, Carbon $from, Carbon $to): float
    {
        // Sum up all "consumption" type movements (outgoing stock)
        $consumption = $this->getMovementModel()::query()
            ->where('item_id', $itemId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('from_location_id')
            ->whereNull('to_location_id')
            ->sum('quantity');

        return abs((float) $consumption);
    }

    /**
     * Get consumption by period (daily, weekly, monthly).
     *
     * @return Collection<int, array{period: string, consumption: float}>
     */
    public function getConsumptionByPeriod(
        string $itemId,
        string $period = 'daily',
        ?int $periods = 30,
    ): Collection {
        $periodExpression = $this->periodExpression($period);

        $daysPerPeriod = match ($period) {
            'weekly' => 7,
            'monthly' => 30,
            default => 1,
        };

        $from = Carbon::now()->subDays($periods * $daysPerPeriod);

        $results = $this->getMovementModel()::query()
            ->selectRaw("{$periodExpression} as period, SUM(ABS(quantity)) as consumption")
            ->where('item_id', $itemId)
            ->where('created_at', '>=', $from)
            ->whereNotNull('from_location_id')
            ->whereNull('to_location_id')
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        return $results->map(fn (StockMovement $row): array => [
            'period' => (string) $row->getAttribute('period'),
            'consumption' => (float) $row->getAttribute('consumption'),
        ])->values();
    }

    /**
     * Build a driver-appropriate SQL expression that buckets `created_at` into a
     * period label. DATE_FORMAT() is MySQL-only; this package also runs on
     * PostgreSQL (its own DDEV) and SQLite (the test suite).
     *
     * @return literal-string
     */
    private function periodExpression(string $period): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql' => match ($period) {
                'weekly' => "to_char(created_at, 'IYYY-IW')",
                'monthly' => "to_char(created_at, 'YYYY-MM')",
                default => "to_char(created_at, 'YYYY-MM-DD')",
            },
            'sqlite' => match ($period) {
                'weekly' => "strftime('%Y-%W', created_at)",
                'monthly' => "strftime('%Y-%m', created_at)",
                default => "strftime('%Y-%m-%d', created_at)",
            },
            default => match ($period) {
                'weekly' => "DATE_FORMAT(created_at, '%Y-%W')",
                'monthly' => "DATE_FORMAT(created_at, '%Y-%m')",
                default => "DATE_FORMAT(created_at, '%Y-%m-%d')",
            },
        };
    }

    /**
     * Forecast future demand based on average daily usage.
     */
    public function forecast(string $itemId, int $days = 30): float
    {
        $averageDailyUsage = $this->calculateAverageDailyUsage($itemId);
        $trend = $this->calculateTrend($itemId);

        // Apply trend adjustment
        $trendMultiplier = match ($trend) {
            'increasing' => 1.1, // Expect 10% more usage
            'decreasing' => 0.9, // Expect 10% less usage
            default => 1.0,
        };

        return $averageDailyUsage * $days * $trendMultiplier;
    }

    /**
     * @return class-string<StockMovement>
     */
    private function getMovementModel(): string
    {
        return config('inventory.models.stock_movement', StockMovement::class);
    }
}
