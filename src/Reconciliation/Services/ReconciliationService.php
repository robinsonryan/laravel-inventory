<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reconciliation\Services;

use Illuminate\Support\Collection;
use RobinsonRyan\Inventory\Reconciliation\Models\CycleCount;
use RobinsonRyan\Inventory\Reconciliation\TagDefinitions\CycleCountStatusDefinition;
use RobinsonRyan\Inventory\Stock\Models\Stock;

final class ReconciliationService
{
    /**
     * Generate the next count number for the tenant.
     */
    public function generateCountNumber(?string $tenantId = null): string
    {
        $prefix = 'CC-';
        $date = now()->format('Ymd');

        $lastCount = $this->getCycleCountModel()::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('count_number', 'like', "{$prefix}{$date}-%")
            ->orderBy('count_number', 'desc')
            ->first();

        $sequence = 1;
        if ($lastCount !== null) {
            $parts = explode('-', $lastCount->count_number);
            $lastSequence = (int) end($parts);
            $sequence = $lastSequence + 1;
        }

        return sprintf('%s%s-%04d', $prefix, $date, $sequence);
    }

    /**
     * Get items to count for a specific location.
     *
     * @return Collection<int, Stock>
     */
    public function getItemsToCount(string $locationId): Collection
    {
        return $this->getStockModel()::query()
            ->where('location_id', $locationId)
            ->where('quantity', '>', 0)
            ->with('item')
            ->get();
    }

    /**
     * Calculate total variance value for a cycle count.
     */
    public function calculateVarianceValue(CycleCount $count): float
    {
        $count->load('lines.stock');
        $total = 0.0;

        foreach ($count->lines as $line) {
            if ($line->variance !== null && (float) $line->variance !== 0.0) {
                $costBasis = $line->stock->cost_basis ?? 0;
                $total += (float) $line->variance * (float) $costBasis;
            }
        }

        return $total;
    }

    /**
     * Get all cycle counts requiring approval (pending status).
     *
     * @return Collection<int, CycleCount>
     */
    public function getCountsRequiringApproval(?string $tenantId = null): Collection
    {
        return $this->getCycleCountModel()::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->withTagIn(CycleCountStatusDefinition::$slug, 'pending')
            ->with(['lines', 'location'])
            ->orderBy('completed_at', 'asc')
            ->get();
    }

    /**
     * Check if a cycle count can be approved.
     */
    public function canApprove(CycleCount $count): bool
    {
        // Must be in pending status
        if ($count->status !== 'pending') {
            return false;
        }

        // Must be fully counted
        return $count->isFullyCounted();
    }

    /**
     * Check if a cycle count can be completed.
     */
    public function canComplete(CycleCount $count): bool
    {
        // Must be in-progress — taxon stores tag values slugified.
        if ($count->status !== 'in-progress') {
            return false;
        }

        // Must have all lines counted
        return $count->isFullyCounted();
    }

    /**
     * Check if a cycle count can be cancelled.
     */
    public function canCancel(CycleCount $count): bool
    {
        // Cannot cancel approved counts
        return ! in_array($count->status, CycleCountStatusDefinition::TERMINAL, true);
    }

    /**
     * Get open cycle counts.
     *
     * @return Collection<int, CycleCount>
     */
    public function getOpenCounts(?string $tenantId = null): Collection
    {
        return $this->getCycleCountModel()::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->withAnyTagIn(CycleCountStatusDefinition::$slug, CycleCountStatusDefinition::OPEN)
            ->with(['lines', 'location'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get lines with variances for a cycle count.
     *
     * @return Collection<int, \RobinsonRyan\Inventory\Reconciliation\Models\CycleCountLine>
     */
    public function getLinesWithVariances(CycleCount $count): Collection
    {
        return $count->lines()
            ->whereNotNull('variance')
            ->where('variance', '!=', 0)
            ->with(['item', 'stock'])
            ->get();
    }

    /**
     * @return class-string<CycleCount>
     */
    private function getCycleCountModel(): string
    {
        return config('inventory.models.cycle_count', CycleCount::class);
    }

    /**
     * @return class-string<Stock>
     */
    private function getStockModel(): string
    {
        return config('inventory.models.stock', Stock::class);
    }
}
