<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Exceptions\CannotConfirmMatchException;
use RobinsonRyan\Inventory\Matching\Actions\ConfirmClassification;
use RobinsonRyan\Inventory\Matching\Actions\ConfirmMatch;
use RobinsonRyan\Inventory\Matching\Actions\CreatePartFromVendorItem;
use RobinsonRyan\Inventory\Matching\Actions\ManageCategories;
use RobinsonRyan\Inventory\Matching\Actions\ProposeClassification;
use RobinsonRyan\Inventory\Matching\Actions\ProposeMatch;
use RobinsonRyan\Inventory\Matching\Actions\RejectMatch;
use RobinsonRyan\Inventory\Matching\Enums\ClassificationAxis;
use RobinsonRyan\Inventory\Matching\Enums\MatchMethod;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Matching\Models\PartMatch;
use RobinsonRyan\Inventory\Matching\Models\PartPriceHistory;
use RobinsonRyan\Inventory\Tests\Support\TestActor;
use RobinsonRyan\Vendor\Purchasing\Models\PurchaseLine;
use RobinsonRyan\Vendor\Purchasing\Models\VendorDocument;
use RobinsonRyan\Vendor\Purchasing\Models\VendorItem;

it('carries a vendor item from first sight, through a veto and a re-post, to the right part', function (): void {
    $vendorItem = VendorItem::factory()->create(['description' => 'Ford E-350 axle shaft']);
    $document = VendorDocument::factory()->create(['vendor_id' => $vendorItem->vendor_id]);
    $line = PurchaseLine::factory()->create([
        'vendor_document_id' => $document->id,
        'vendor_item_id' => $vendorItem->id,
    ]);
    $propose = new ProposeMatch;
    $confirm = new ConfirmMatch;
    $reject = new RejectMatch;
    $person = TestActor::named();

    // First post: nothing in the catalog looks right, so it is a new part.
    $proposal = $propose->execute($vendorItem, null, 0.3, MatchMethod::Gateway, ['name' => 'Axle shaft']);

    expect(PartMatch::query()->needsReview()->pluck('id')->all())->toBe([$proposal->id])
        ->and(PartPriceHistory::query()->sole()->item_id)->toBeNull();

    // The part gets created, confirmed confidently by rule, and classified.
    $wrongPart = (new CreatePartFromVendorItem)->execute($vendorItem, 'Axle shaft');
    $match = $confirm->execute($propose->execute($vendorItem, $wrongPart, 0.95, MatchMethod::Gateway));
    (new ManageCategories)->create('Axles & differentials');
    (new ConfirmClassification)->execute(
        (new ProposeClassification)->execute($wrongPart, ClassificationAxis::Category, 'Axles & differentials', 0.95),
    );
    (new ConfirmClassification)->execute(
        (new ProposeClassification)->execute($wrongPart, ClassificationAxis::Fitment, 'Ford', 0.95),
    );

    expect($match->status)->toBe(MatchStatus::Confirmed)
        ->and($match->isConfirmedByRule())->toBeTrue()
        ->and($wrongPart->partCategory)->toBe('axles-differentials')
        ->and($wrongPart->fitment)->toBe(['ford'])
        ->and(PartMatch::query()->needsReview()->exists())->toBeFalse()
        ->and(PartPriceHistory::query()->forItem($wrongPart)->count())->toBe(1);

    // A person rejects it: back in the queue, off the part, and nothing gone.
    $reject->execute($match, $person, 'This is the front shaft');

    expect(PartMatch::query()->needsReview()->sole()->id)->toBe($match->id)
        ->and(PartPriceHistory::query()->forItem($wrongPart)->exists())->toBeFalse()
        ->and(PartPriceHistory::query()->forVendorItem($vendorItem)->sole()->purchase_line_id)->toBe($line->id);

    // The document is re-posted. The proposer still thinks the same; nothing moves.
    $repost = $propose->execute($vendorItem, $wrongPart, 0.99, MatchMethod::Gateway);

    expect($repost->status)->toBe(MatchStatus::Vetoed)
        ->and(fn (): PartMatch => $confirm->execute($repost))->toThrow(CannotConfirmMatchException::class);

    expect(PartPriceHistory::query()->forItem($wrongPart)->exists())->toBeFalse()
        ->and(PartMatch::query()->needsReview()->count())->toBe(1);

    // A person picks the right part, and the queue empties.
    $rightPart = Item::factory()->create();
    $confirm->execute($propose->execute($vendorItem, $rightPart, 1.0, MatchMethod::Person), $person);

    expect(PartMatch::query()->needsReview()->exists())->toBeFalse()
        ->and(PartPriceHistory::query()->forItem($rightPart)->sole()->purchase_line_id)->toBe($line->id)
        ->and(PartMatch::query()->where('vendor_item_id', $vendorItem->id)->confirmed()->sole()->item_id)->toBe($rightPart->id)
        ->and($match->refresh()->status)->toBe(MatchStatus::Vetoed)
        ->and($match->note)->toBe('This is the front shaft');
});
