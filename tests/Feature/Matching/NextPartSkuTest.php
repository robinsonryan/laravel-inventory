<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Matching\Actions\CreatePartFromVendorItem;
use RobinsonRyan\Inventory\Matching\Actions\NextPartSku;
use RobinsonRyan\Vendor\Purchasing\Models\VendorItem;

function lastPartSkuNumber(): int
{
    return (int) DB::scalar("select nextval('part_sku_seq')");
}

it('numbers a part from the sequence without needing a vendor item', function (): void {
    $last = lastPartSkuNumber();

    expect((new NextPartSku)->execute())->toBe(sprintf('P-%06d', $last + 1));
});

it('never hands out the same sku twice', function (): void {
    $next = new NextPartSku;

    expect($next->execute())->not->toBe($next->execute());
});

it('steps past skus a person typed in by hand', function (): void {
    $next = lastPartSkuNumber() + 1;
    Item::factory()->create(['sku' => sprintf('P-%06d', $next)]);
    Item::factory()->create(['sku' => sprintf('P-%06d', $next + 1)]);

    expect((new NextPartSku)->execute())->toBe(sprintf('P-%06d', $next + 2));
});

it('shares its numbering with CreatePartFromVendorItem', function (): void {
    $sku = (new NextPartSku)->execute();

    $part = (new CreatePartFromVendorItem)->execute(VendorItem::factory()->create());

    expect((int) substr($part->sku, 2))->toBe((int) substr($sku, 2) + 1);
});
