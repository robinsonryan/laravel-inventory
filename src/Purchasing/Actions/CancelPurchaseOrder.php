<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Actions;

use InvalidArgumentException;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrderLine;
use RobinsonRyan\Inventory\Purchasing\TagDefinitions\PurchaseOrderStatusDefinition;

final class CancelPurchaseOrder
{
    /**
     * Cancel a purchase order.
     */
    public function execute(PurchaseOrder $po, ?string $reason = null): PurchaseOrder
    {
        if (in_array($po->status, PurchaseOrderStatusDefinition::TERMINAL, true)) {
            throw new InvalidArgumentException('Cannot cancel a purchase order that is already received or cancelled.');
        }

        // Cancel remaining quantities on all lines
        foreach ($po->lines as $line) {
            /** @var PurchaseOrderLine $line */
            $pending = $line->getQuantityPending();
            if ($pending > 0) {
                $line->quantity_cancelled = (string) ((float) $line->quantity_cancelled + $pending);
                $line->save();
            }
        }

        // Update PO status and metadata
        $po->status = 'cancelled';
        $po->cancelled_at = now();

        if ($reason !== null) {
            $metadata = $po->metadata ?? [];
            $metadata['cancellation_reason'] = $reason;
            $po->metadata = $metadata;
        }

        $po->save();

        return $po->fresh(['lines', 'vendor']) ?? $po;
    }
}
