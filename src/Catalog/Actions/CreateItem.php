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

        // `category` is a taxon tag attribute, so it is deliberately excluded from
        // toModelAttributes()/$fillable and has to be assigned after creation.
        $item->category = $data->category;

        return $item;
    }
}
