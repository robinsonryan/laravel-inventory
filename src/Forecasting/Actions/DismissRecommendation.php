<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Forecasting\Actions;

use Carbon\Carbon;
use RobinsonRyan\Inventory\Forecasting\Models\ReorderRecommendation;

final class DismissRecommendation
{
    /**
     * Dismiss a recommendation (not needed).
     *
     * @throws \InvalidArgumentException If recommendation is already actioned or dismissed
     */
    public function execute(ReorderRecommendation $recommendation): ReorderRecommendation
    {
        if ($recommendation->isActioned()) {
            throw new \InvalidArgumentException('Cannot dismiss a recommendation that has already been actioned.');
        }

        if ($recommendation->isDismissed()) {
            throw new \InvalidArgumentException('Recommendation has already been dismissed.');
        }

        $recommendation->dismissed_at = Carbon::now();
        $recommendation->save();

        return $recommendation;
    }
}
