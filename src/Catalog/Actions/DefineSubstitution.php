<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Catalog\Models\ItemSubstitution;

final class DefineSubstitution
{
    public function execute(
        Item $item,
        Item $substitute,
        int $priority = 0,
        float $ratio = 1.0,
        bool $bidirectional = false,
        ?string $notes = null,
    ): ItemSubstitution {
        return DB::transaction(function () use ($item, $substitute, $priority, $ratio, $bidirectional, $notes): ItemSubstitution {
            // Create the primary substitution
            $substitution = ItemSubstitution::create([
                'item_id' => $item->id,
                'substitute_item_id' => $substitute->id,
                'priority' => $priority,
                'ratio' => $ratio,
                'is_bidirectional' => $bidirectional,
                'notes' => $notes,
            ]);

            // If bidirectional, create the reverse substitution
            if ($bidirectional) {
                // Calculate inverse ratio for reverse direction
                $inverseRatio = $ratio > 0 ? 1.0 / $ratio : 1.0;

                ItemSubstitution::firstOrCreate(
                    [
                        'item_id' => $substitute->id,
                        'substitute_item_id' => $item->id,
                    ],
                    [
                        'priority' => $priority,
                        'ratio' => $inverseRatio,
                        'is_bidirectional' => true,
                        'notes' => $notes,
                    ]
                );
            }

            return $substitution->load(['item', 'substituteItem']);
        });
    }
}
