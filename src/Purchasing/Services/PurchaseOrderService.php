<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Purchasing\Services;

use Illuminate\Support\Collection;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrder;
use RobinsonRyan\Inventory\Purchasing\Models\PurchaseOrderLine;
use RobinsonRyan\Inventory\Purchasing\TagDefinitions\PurchaseOrderStatusDefinition;

final class PurchaseOrderService
{
    /**
     * Generate the next PO number for the tenant.
     */
    public function generatePoNumber(?string $tenantId = null): string
    {
        $prefix = 'PO-';
        $date = now()->format('Ymd');

        $lastPo = $this->getPurchaseOrderModel()::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('po_number', 'like', "{$prefix}{$date}-%")
            ->orderBy('po_number', 'desc')
            ->first();

        $sequence = 1;
        if ($lastPo !== null) {
            $parts = explode('-', $lastPo->po_number);
            $lastSequence = (int) end($parts);
            $sequence = $lastSequence + 1;
        }

        return sprintf('%s%s-%04d', $prefix, $date, $sequence);
    }

    /**
     * Calculate and update totals for a purchase order.
     */
    public function calculateTotals(PurchaseOrder $po): void
    {
        $subtotal = $po->lines()->sum(\Illuminate\Support\Facades\DB::raw('quantity * unit_cost'));

        $po->subtotal = (string) $subtotal;
        $po->total = (string) ((float) $subtotal + (float) $po->tax + (float) $po->shipping);
        $po->save();
    }

    /**
     * Check if a purchase order is fully received.
     */
    public function isFullyReceived(PurchaseOrder $po): bool
    {
        return $po->lines->every(fn (PurchaseOrderLine $line) => $line->isFullyReceived());
    }

    /**
     * Check if a purchase order has any received lines.
     */
    public function hasReceivedLines(PurchaseOrder $po): bool
    {
        return $po->lines->contains(fn (PurchaseOrderLine $line) => (float) $line->quantity_received > 0);
    }

    /**
     * Get all open purchase orders for a vendor.
     *
     * @return Collection<int, PurchaseOrder>
     */
    public function getOpenForVendor(string $vendorId): Collection
    {
        return $this->getPurchaseOrderModel()::query()
            ->where('vendor_id', $vendorId)
            ->withTagIn(PurchaseOrderStatusDefinition::$slug, PurchaseOrderStatusDefinition::OPEN)
            ->with('lines')
            ->orderBy('order_date', 'desc')
            ->get();
    }

    /**
     * Get lines pending receipt for a purchase order.
     *
     * @return Collection<int, PurchaseOrderLine>
     */
    public function getPendingLines(PurchaseOrder $po): Collection
    {
        return $po->lines->filter(fn (PurchaseOrderLine $line) => ! $line->isFullyReceived());
    }

    /**
     * Update the PO status based on received quantities.
     */
    public function updateStatus(PurchaseOrder $po): void
    {
        $po->load('lines');

        if ($this->isFullyReceived($po)) {
            $po->status = 'received';
        } elseif ($this->hasReceivedLines($po)) {
            $po->status = 'partial';
        }
    }

    /**
     * Generate the next receipt number.
     */
    public function generateReceiptNumber(?string $tenantId = null): string
    {
        $prefix = 'RCV-';
        $date = now()->format('Ymd');

        $lastReceipt = $this->getReceiptModel()::query()
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->where('receipt_number', 'like', "{$prefix}{$date}-%")
            ->orderBy('receipt_number', 'desc')
            ->first();

        $sequence = 1;
        if ($lastReceipt !== null) {
            $parts = explode('-', $lastReceipt->receipt_number);
            $lastSequence = (int) end($parts);
            $sequence = $lastSequence + 1;
        }

        return sprintf('%s%s-%04d', $prefix, $date, $sequence);
    }

    /**
     * @return class-string<PurchaseOrder>
     */
    private function getPurchaseOrderModel(): string
    {
        return config('inventory.models.purchase_order', PurchaseOrder::class);
    }

    /**
     * @return class-string
     */
    private function getReceiptModel(): string
    {
        return config('inventory.models.receipt', \RobinsonRyan\Inventory\Purchasing\Models\Receipt::class);
    }
}
