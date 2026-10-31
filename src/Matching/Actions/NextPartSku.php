<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;

/**
 * The next free `P-######` sku, for a part AFWD numbers itself.
 *
 * The number comes from a Postgres sequence, which two writers cannot share and
 * which never rolls back, so numbers can have gaps. It only has to step past
 * skus a person typed in by hand.
 */
final readonly class NextPartSku
{
    public function execute(): string
    {
        do {
            $sku = sprintf('P-%06d', (int) DB::scalar("select nextval('part_sku_seq')"));
        } while (Item::query()->where('sku', $sku)->exists());

        return $sku;
    }
}
