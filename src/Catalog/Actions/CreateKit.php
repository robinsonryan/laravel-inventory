<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Data\KitData;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Catalog\Models\Kit;
use RobinsonRyan\Inventory\Catalog\Models\KitItem;

final class CreateKit
{
    public function execute(KitData $data): Kit
    {
        return DB::transaction(function () use ($data): Kit {
            // Get or create the item for this kit
            $itemId = $data->itemId;

            if ($itemId === null) {
                // Create a new item for this kit
                $item = Item::create([
                    'sku' => 'KIT-'.strtoupper(uniqid()),
                    'name' => $data->name,
                    'description' => $data->description,
                    'is_active' => $data->isActive,
                ]);
                $itemId = $item->id;
            }

            // Create the kit
            $kit = Kit::create([
                'item_id' => $itemId,
                'name' => $data->name,
                'description' => $data->description,
                'is_active' => $data->isActive,
            ]);

            // Add kit items
            foreach ($data->items as $index => $itemData) {
                KitItem::create([
                    'kit_id' => $kit->id,
                    'item_id' => $itemData['item_id'],
                    'quantity' => $itemData['quantity'] ?? 1,
                    'is_optional' => $itemData['is_optional'] ?? false,
                    'sort_order' => $itemData['sort_order'] ?? $index,
                ]);
            }

            return $kit->load(['item', 'items']);
        });
    }
}
