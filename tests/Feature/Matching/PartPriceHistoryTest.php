<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Matching\Actions\ConfirmMatch;
use RobinsonRyan\Inventory\Matching\Actions\ProposeMatch;
use RobinsonRyan\Inventory\Matching\Actions\RejectMatch;
use RobinsonRyan\Inventory\Matching\Enums\MatchMethod;
use RobinsonRyan\Inventory\Matching\Models\PartMatch;
use RobinsonRyan\Inventory\Matching\Models\PartPriceHistory;
use RobinsonRyan\Inventory\Tests\Support\TestActor;
use RobinsonRyan\Vendor\Purchasing\Enums\DocumentKind;
use RobinsonRyan\Vendor\Purchasing\Enums\LineKind;
use RobinsonRyan\Vendor\Purchasing\Models\PurchaseLine;
use RobinsonRyan\Vendor\Purchasing\Models\VendorDocument;
use RobinsonRyan\Vendor\Purchasing\Models\VendorItem;

/** @param array<string, mixed> $line */
function purchaseOf(VendorItem $vendorItem, string $date = '2026-03-01', array $line = [], DocumentKind $kind = DocumentKind::Invoice): PurchaseLine
{
    $document = VendorDocument::factory()->create([
        'vendor_id' => $vendorItem->vendor_id,
        'document_date' => $date,
        'kind' => $kind,
    ]);

    return PurchaseLine::factory()->create([
        'vendor_document_id' => $document->id,
        'vendor_item_id' => $vendorItem->id,
        ...$line,
    ]);
}

function confirmedMatch(VendorItem $vendorItem, Item $part): PartMatch
{
    return (new ConfirmMatch)->execute(
        (new ProposeMatch)->execute($vendorItem, $part, 0.95, MatchMethod::Gateway),
    );
}

beforeEach(function (): void {
    $this->vendorItem = VendorItem::factory()->create();
    $this->part = Item::factory()->create();
});

it('carries one row per stock line with the document details', function (): void {
    confirmedMatch($this->vendorItem, $this->part);
    $line = purchaseOf($this->vendorItem, '2026-03-14', [
        'quantity' => '2.0000',
        'unit_of_measure' => 'each',
        'unit_price' => '920.0000',
        'extended' => '1840.00',
    ]);

    $row = PartPriceHistory::query()->sole();

    expect($row->purchase_line_id)->toBe($line->id)
        ->and($row->vendor_document_id)->toBe($line->vendor_document_id)
        ->and($row->vendor_id)->toBe($this->vendorItem->vendor_id)
        ->and($row->vendor_item_id)->toBe($this->vendorItem->id)
        ->and($row->item_id)->toBe($this->part->id)
        ->and($row->document_date->format('Y-m-d'))->toBe('2026-03-14')
        ->and($row->quantity)->toBe('2.0000')
        ->and($row->unit_of_measure)->toBe('each')
        ->and($row->unit_price)->toBe('920.0000')
        ->and($row->extended)->toBe('1840.00')
        ->and($row->document_number)->toBe($line->document->number);
});

it('counts consumables as stock and nothing else', function (LineKind $kind, int $rows): void {
    purchaseOf($this->vendorItem, line: ['line_kind' => $kind]);

    expect(PartPriceHistory::query()->count())->toBe($rows);
})->with([
    'part' => [LineKind::Part, 1],
    'consumable' => [LineKind::Consumable, 1],
    'freight' => [LineKind::Freight, 0],
    'tax' => [LineKind::Tax, 0],
    'core charge' => [LineKind::CoreCharge, 0],
    'core credit' => [LineKind::CoreCredit, 0],
    'discount' => [LineKind::Discount, 0],
    'fee' => [LineKind::Fee, 0],
    'service' => [LineKind::Service, 0],
    'other' => [LineKind::Other, 0],
]);

it('leaves credit memos out, and every other document kind in', function (DocumentKind $kind, int $rows): void {
    purchaseOf($this->vendorItem, kind: $kind);

    expect(PartPriceHistory::query()->count())->toBe($rows);
})->with([
    'invoice' => [DocumentKind::Invoice, 1],
    'card receipt' => [DocumentKind::CardReceipt, 1],
    'sales order' => [DocumentKind::SalesOrder, 1],
    'credit memo' => [DocumentKind::CreditMemo, 0],
]);

it('gives a line a part only through a confirmed match', function (): void {
    (new ProposeMatch)->execute($this->vendorItem, $this->part, 0.6, MatchMethod::Gateway);
    purchaseOf($this->vendorItem);

    expect(PartPriceHistory::query()->sole()->item_id)->toBeNull()
        ->and(PartPriceHistory::query()->forItem($this->part)->exists())->toBeFalse();
});

it('drops a rejected match\'s lines from the part and keeps them on the vendor item', function (): void {
    $match = confirmedMatch($this->vendorItem, $this->part);
    $line = purchaseOf($this->vendorItem);

    expect(PartPriceHistory::query()->forItem($this->part)->count())->toBe(1);

    (new RejectMatch)->execute($match, TestActor::named());

    expect(PartPriceHistory::query()->forItem($this->part)->exists())->toBeFalse()
        ->and(PartPriceHistory::query()->forVendorItem($this->vendorItem)->sole()->item_id)->toBeNull()
        ->and(PurchaseLine::query()->whereKey($line->id)->exists())->toBeTrue();
});

it('follows a swap to the new part', function (): void {
    confirmedMatch($this->vendorItem, $this->part);
    purchaseOf($this->vendorItem);
    $replacement = Item::factory()->create();

    (new ConfirmMatch)->execute(
        (new ProposeMatch)->execute($this->vendorItem, $replacement, 0.5, MatchMethod::Person),
        TestActor::named(),
    );

    expect(PartPriceHistory::query()->forItem($this->part)->exists())->toBeFalse()
        ->and(PartPriceHistory::query()->forItem($replacement)->count())->toBe(1);
});

it('never repeats a line when a vendor item has rejected matches beside the confirmed one', function (): void {
    $rejected = (new ProposeMatch)->execute($this->vendorItem, Item::factory()->create(), 0.6, MatchMethod::Gateway);
    (new RejectMatch)->execute($rejected, TestActor::named());
    confirmedMatch($this->vendorItem, $this->part);
    purchaseOf($this->vendorItem);

    expect(PartPriceHistory::query()->count())->toBe(1);
});

it('pools several vendors\' items for one part', function (): void {
    $other = VendorItem::factory()->create();
    confirmedMatch($this->vendorItem, $this->part);
    confirmedMatch($other, $this->part);
    purchaseOf($this->vendorItem);
    purchaseOf($other);

    expect(PartPriceHistory::query()->forItem($this->part)->count())->toBe(2)
        ->and(PartPriceHistory::query()->forItem($this->part->id)->count())->toBe(2)
        ->and(PartPriceHistory::query()->forVendorItem($other)->count())->toBe(1)
        ->and(PartPriceHistory::query()->forVendorItem($other->id)->count())->toBe(1);
});

it('orders by document date', function (): void {
    $middle = purchaseOf($this->vendorItem, '2026-02-01');
    $latest = purchaseOf($this->vendorItem, '2026-03-01');
    $earliest = purchaseOf($this->vendorItem, '2026-01-01');

    expect(PartPriceHistory::query()->chronological()->pluck('purchase_line_id')->all())
        ->toBe([$earliest->id, $middle->id, $latest->id]);
});

it('cannot be written to', function (): void {
    purchaseOf($this->vendorItem);
    $row = PartPriceHistory::query()->sole();

    expect(fn () => $row->update(['unit_price' => '1.0000']))->toThrow(RuntimeException::class)
        ->and(fn () => $row->delete())->toThrow(RuntimeException::class)
        ->and(fn () => PartPriceHistory::query()->create(['unit_price' => '1.0000']))->toThrow(RuntimeException::class);
});

it('migrates down and back up again', function (): void {
    $migrations = glob(__DIR__.'/../../../database/migrations/*_create_part_price_history_view.php');
    $migration = require $migrations[0];

    expect(Schema::hasView('part_price_history'))->toBeTrue();

    $migration->down();

    expect(Schema::hasView('part_price_history'))->toBeFalse();

    $migration->up();
    $migration->up();

    expect(Schema::hasView('part_price_history'))->toBeTrue();
});

it('reads the table names from config when building the view', function (): void {
    DB::statement('create table renamed_lines (like purchase_lines)');
    config([
        'inventory.tables.part_price_history' => 'part_prices',
        'vendor.tables.purchase_lines' => 'renamed_lines',
    ]);
    $migrations = glob(__DIR__.'/../../../database/migrations/*_create_part_price_history_view.php');
    $migration = require $migrations[0];

    $migration->up();

    expect(DB::scalar("select pg_get_viewdef('part_prices'::regclass)"))->toContain('renamed_lines');
});

it('refuses a configured table name that is not an identifier', function (): void {
    config(['vendor.tables.purchase_lines' => 'purchase_lines; drop table items']);
    $migrations = glob(__DIR__.'/../../../database/migrations/*_create_part_price_history_view.php');
    $migration = require $migrations[0];

    expect(fn () => $migration->up())->toThrow(InvalidArgumentException::class);
});
