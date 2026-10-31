<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Matching\Actions\CreatePartFromVendorItem;
use RobinsonRyan\Inventory\Tests\TestCase;
use RobinsonRyan\Vendor\Purchasing\Models\VendorItem;

function nextPartNumber(): int
{
    return (int) DB::scalar("select nextval('part_sku_seq')");
}

beforeEach(function (): void {
    $this->create = new CreatePartFromVendorItem;
});

it('gives a new part an internal sku from the sequence', function (): void {
    $before = nextPartNumber();

    $part = $this->create->execute(VendorItem::factory()->create());

    expect($part->sku)->toBe(sprintf('P-%06d', $before + 1))
        ->and($part->exists)->toBeTrue()
        ->and($part->is_active)->toBeTrue();
});

it('never hands out the same sku twice', function (): void {
    $first = $this->create->execute(VendorItem::factory()->create());
    $second = $this->create->execute(VendorItem::factory()->create());

    expect($second->sku)->not->toBe($first->sku)
        ->and((int) substr($second->sku, 2))->toBeGreaterThan((int) substr($first->sku, 2));
});

it('skips forward past a sku already taken by another part', function (): void {
    $next = nextPartNumber() + 1;
    Item::factory()->create(['sku' => sprintf('P-%06d', $next)]);
    Item::factory()->create(['sku' => sprintf('P-%06d', $next + 1)]);

    $part = $this->create->execute(VendorItem::factory()->create());

    expect($part->sku)->toBe(sprintf('P-%06d', $next + 2));
});

it('names the part from the argument when given one', function (): void {
    $vendorItem = VendorItem::factory()->create(['description' => 'FORD E350 AXLE SHFT']);

    $part = $this->create->execute($vendorItem, 'Ford E-350 axle shaft');

    expect($part->name)->toBe('Ford E-350 axle shaft')
        ->and($part->description)->toBe('FORD E350 AXLE SHFT');
});

it('names the part from the vendor item description otherwise', function (): void {
    $part = $this->create->execute(VendorItem::factory()->create(['description' => 'Nissan NV3500 brake rotor']));

    expect($part->name)->toBe('Nissan NV3500 brake rotor');
});

it('falls back to the vendor part number when the vendor item has no description', function (): void {
    $vendorItem = VendorItem::factory()->create(['vendor_part_number' => 'PR60-ASF', 'description' => null]);

    expect($this->create->execute($vendorItem)->name)->toBe('PR60-ASF');
});

it('fits a long description into the name and keeps the whole of it on the part', function (): void {
    $description = Str::repeat('Chevrolet Express rear axle shaft ', 10);

    $part = $this->create->execute(VendorItem::factory()->create(['description' => $description]));

    expect(strlen($part->name))->toBeLessThanOrEqual(255)
        ->and($part->description)->toBe($description);
});

it('creates the part the way CreateItem does', function (): void {
    $part = $this->create->execute(VendorItem::factory()->create());

    expect($part->category)->toBe('part')
        ->and($part->tenant_id)->toBe(TestCase::TENANT_ID)
        ->and($part->getKey()[14])->toBe('7');
});
