<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Forecasting\Data;

final readonly class ForecastData
{
    public function __construct(
        public string $itemId,
        public float $averageDailyUsage,
        public string $trend,
        public float $forecastedDemand,
        public int $daysToStockout,
        public int $recommendedOrderQuantity,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            itemId: $data['item_id'],
            averageDailyUsage: (float) $data['average_daily_usage'],
            trend: $data['trend'],
            forecastedDemand: (float) $data['forecasted_demand'],
            daysToStockout: (int) $data['days_to_stockout'],
            recommendedOrderQuantity: (int) $data['recommended_order_quantity'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'average_daily_usage' => $this->averageDailyUsage,
            'trend' => $this->trend,
            'forecasted_demand' => $this->forecastedDemand,
            'days_to_stockout' => $this->daysToStockout,
            'recommended_order_quantity' => $this->recommendedOrderQuantity,
        ];
    }
}
