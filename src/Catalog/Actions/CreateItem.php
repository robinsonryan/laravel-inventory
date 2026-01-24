<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Actions;

use RobinsonRyan\Inventory\Catalog\Data\ItemData;
use RobinsonRyan\Inventory\Catalog\Models\Item;

final class CreateItem
{
    public function execute(ItemData $data): Item
    {
        $item = Item::create($data->toModelAttributes());

        if ($data->category !== null) {
            $item->category = $data->category;
        }

        return $item;
    }
}
