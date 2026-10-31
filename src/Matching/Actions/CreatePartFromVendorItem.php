<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Actions\CreateItem;
use RobinsonRyan\Inventory\Catalog\Data\ItemData;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Vendor\Purchasing\Models\VendorItem;

/**
 * Creates AFWD's own part for a vendor item, numbered `P-######`.
 *
 * The number comes from a Postgres sequence, which two writers cannot share and
 * which never rolls back, so numbers can have gaps. It only has to step past
 * skus a person typed in by hand.
 */
final readonly class CreatePartFromVendorItem
{
    /**
     * `items.name` is a varchar; a vendor item's description is text.
     */
    private const NAME_LENGTH = 255;

    public function __construct(private CreateItem $createItem = new CreateItem) {}

    public function execute(VendorItem $vendorItem, ?string $name = null): Item
    {
        return $this->createItem->execute(new ItemData(
            sku: $this->nextSku(),
            name: mb_substr($name ?? $vendorItem->description ?? $vendorItem->vendor_part_number, 0, self::NAME_LENGTH),
            tenantId: $vendorItem->tenant_id,
            description: $vendorItem->description,
        ));
    }

    private function nextSku(): string
    {
        do {
            $sku = sprintf('P-%06d', (int) DB::scalar("select nextval('part_sku_seq')"));
        } while (Item::query()->where('sku', $sku)->exists());

        return $sku;
    }
}
