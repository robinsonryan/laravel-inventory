<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Actions;

use RobinsonRyan\Inventory\Catalog\Actions\CreateItem;
use RobinsonRyan\Inventory\Catalog\Data\ItemData;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Vendor\Purchasing\Models\VendorItem;

/**
 * Creates AFWD's own part for a vendor item, numbered by `NextPartSku`.
 */
final readonly class CreatePartFromVendorItem
{
    /**
     * `items.name` is a varchar; a vendor item's description is text.
     */
    private const NAME_LENGTH = 255;

    public function __construct(
        private CreateItem $createItem = new CreateItem,
        private NextPartSku $nextPartSku = new NextPartSku,
    ) {}

    public function execute(VendorItem $vendorItem, ?string $name = null): Item
    {
        return $this->createItem->execute(new ItemData(
            sku: $this->nextPartSku->execute(),
            name: mb_substr($name ?? $vendorItem->description ?? $vendorItem->vendor_part_number, 0, self::NAME_LENGTH),
            tenantId: $vendorItem->tenant_id,
            description: $vendorItem->description,
        ));
    }
}
