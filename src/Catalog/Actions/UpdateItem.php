<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Actions;

use RobinsonRyan\Inventory\Catalog\Data\ItemData;
use RobinsonRyan\Inventory\Catalog\Models\Item;

final class UpdateItem
{
    public function execute(Item $item, ItemData $data): Item
    {
        $item->update($data->toModelAttributes());

        // `category` is a taxon tag attribute, so it is deliberately excluded from
        // toModelAttributes()/$fillable and has to be assigned separately.
        $item->category = $data->category;

        return $item->fresh() ?? $item;
    }
}
