<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Actions;

use InvalidArgumentException;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;

final class SubmitPurchaseOrder
{
    /**
     * Submit a purchase order, changing status from draft to submitted.
     */
    public function execute(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== 'draft') {
            throw new InvalidArgumentException('Only draft purchase orders can be submitted.');
        }

        if ($po->lines->isEmpty()) {
            throw new InvalidArgumentException('Cannot submit a purchase order with no lines.');
        }

        $po->status = 'submitted';
        $po->submitted_at = now();
        $po->save();

        return $po->fresh(['lines', 'vendor']);
    }
}
