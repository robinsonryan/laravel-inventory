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

        if ($data->category !== null) {
            $item->category = $data->category;
        }

        return $item->fresh() ?? $item;
    }
}
