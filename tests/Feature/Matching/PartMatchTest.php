<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Exceptions\CannotConfirmMatchException;
use RobinsonRyan\Inventory\Matching\Actions\ConfirmMatch;
use RobinsonRyan\Inventory\Matching\Actions\ProposeMatch;
use RobinsonRyan\Inventory\Matching\Actions\RejectMatch;
use RobinsonRyan\Inventory\Matching\Enums\MatchMethod;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Matching\Models\PartMatch;
use RobinsonRyan\Inventory\Tests\Support\TestActor;
use RobinsonRyan\Inventory\Tests\TestCase;
use RobinsonRyan\Vendor\Purchasing\Models\VendorItem;

/** @param array<string, mixed> $attributes */
function rawMatch(VendorItem $vendorItem, ?Item $item, string $status, array $attributes = []): void
{
    DB::table('part_matches')->insert([
        'vendor_item_id' => $vendorItem->id,
        'item_id' => $item?->id,
        'status' => $status,
        'method' => 'gateway',
        'confidence' => 0.5,
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    $this->propose = new ProposeMatch;
    $this->confirm = new ConfirmMatch;
    $this->reject = new RejectMatch;
    $this->person = TestActor::named();
    $this->vendorItem = VendorItem::factory()->create();
    $this->part = Item::factory()->create();
    $this->otherPart = Item::factory()->create();
});

describe('the part_matches table', function (): void {
    it('has the database generate a uuid v7 key', function (): void {
        $default = DB::scalar(
            "select column_default from information_schema.columns where table_name = 'part_matches' and column_name = 'id'",
        );

        $match = $this->propose->execute($this->vendorItem, $this->part, 0.7, MatchMethod::Gateway);

        expect($default)->toContain('uuidv7')
            ->and($match->getKey())->toBeString()
            ->and($match->getKey()[14])->toBe('7');
    });

    it('stamps the tenant', function (): void {
        $match = $this->propose->execute($this->vendorItem, $this->part, 0.7, MatchMethod::Gateway);

        expect($match->tenant_id)->toBe(TestCase::TENANT_ID);
    });

    it('refuses a second confirmed match for one vendor item at the database', function (): void {
        rawMatch($this->vendorItem, $this->part, 'confirmed');

        expect(fn () => DB::transaction(fn () => rawMatch($this->vendorItem, $this->otherPart, 'confirmed')))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('allows one confirmed match per vendor item, not one in the table', function (): void {
        rawMatch($this->vendorItem, $this->part, 'confirmed');
        rawMatch(VendorItem::factory()->create(), $this->part, 'confirmed');

        expect(PartMatch::query()->count())->toBe(2);
    });

    it('refuses a second proposal for one pairing at the database', function (): void {
        rawMatch($this->vendorItem, $this->part, 'proposed');

        expect(fn () => DB::transaction(fn () => rawMatch($this->vendorItem, $this->part, 'proposed')))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('refuses a second new-part proposal for one vendor item at the database', function (): void {
        rawMatch($this->vendorItem, null, 'proposed');

        expect(fn () => DB::transaction(fn () => rawMatch($this->vendorItem, null, 'proposed')))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('lets rejected rows pile up beside a confirmed one', function (): void {
        rawMatch($this->vendorItem, $this->part, 'confirmed');
        rawMatch($this->vendorItem, $this->otherPart, 'vetoed');
        rawMatch($this->vendorItem, Item::factory()->create(), 'vetoed');

        expect(PartMatch::query()->count())->toBe(3);
    });
});

describe('ProposeMatch', function (): void {
    it('creates a proposal carrying its confidence and method', function (): void {
        $match = $this->propose->execute($this->vendorItem, $this->part, 0.72, MatchMethod::Gateway);

        expect($match->status)->toBe(MatchStatus::Proposed)
            ->and($match->method)->toBe(MatchMethod::Gateway)
            ->and($match->confidence)->toBe(0.72)
            ->and($match->item_id)->toBe($this->part->id)
            ->and($match->vendor_item_id)->toBe($this->vendorItem->id);
    });

    it('refreshes the open proposal for a pairing instead of adding a row', function (): void {
        $first = $this->propose->execute($this->vendorItem, $this->part, 0.6, MatchMethod::Gateway);
        $second = $this->propose->execute($this->vendorItem, $this->part, 0.8, MatchMethod::ExactRepeat);

        expect($second->id)->toBe($first->id)
            ->and($second->confidence)->toBe(0.8)
            ->and($second->method)->toBe(MatchMethod::ExactRepeat)
            ->and(PartMatch::query()->count())->toBe(1);
    });

    it('records a new-part proposal with no part and the proposed name', function (): void {
        $match = $this->propose->execute(
            $this->vendorItem,
            null,
            0.4,
            MatchMethod::Gateway,
            ['name' => 'Axle shaft', 'description' => 'Ford E-350 rear axle shaft'],
        );

        expect($match->item_id)->toBeNull()
            ->and($match->new_part)->toBe(['name' => 'Axle shaft', 'description' => 'Ford E-350 rear axle shaft'])
            ->and($match->status)->toBe(MatchStatus::Proposed);
    });

    it('never downgrades a confirmed match for the same part', function (): void {
        $confirmed = $this->confirm->execute(
            $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway),
        );

        $again = $this->propose->execute($this->vendorItem, $this->part, 0.3, MatchMethod::Gateway);

        expect($again->id)->toBe($confirmed->id)
            ->and($again->status)->toBe(MatchStatus::Confirmed)
            ->and($again->confidence)->toBe(0.95)
            ->and(PartMatch::query()->count())->toBe(1);
    });

    it('records a proposal for review when the vendor item is confirmed to another part', function (): void {
        $confirmed = $this->confirm->execute(
            $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway),
        );

        $proposal = $this->propose->execute($this->vendorItem, $this->otherPart, 0.91, MatchMethod::Gateway);

        expect($proposal->status)->toBe(MatchStatus::Proposed)
            ->and($proposal->item_id)->toBe($this->otherPart->id)
            ->and($confirmed->refresh()->status)->toBe(MatchStatus::Confirmed)
            ->and(PartMatch::query()->needsReview()->pluck('id')->all())->toBe([$proposal->id]);
    });

    it('does not revive a rejected pairing when a document is re-posted', function (): void {
        $match = $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway);
        $this->confirm->execute($match);
        $this->reject->execute($match, $this->person, 'Wrong side');

        $again = $this->propose->execute($this->vendorItem, $this->part, 1.0, MatchMethod::ExactRepeat);

        expect($again->id)->toBe($match->id)
            ->and($again->status)->toBe(MatchStatus::Vetoed)
            ->and($again->confidence)->toBe(0.95)
            ->and($again->method)->toBe(MatchMethod::Gateway)
            ->and($again->note)->toBe('Wrong side')
            ->and(PartMatch::query()->where('item_id', $this->part->id)->count())->toBe(1)
            ->and(PartMatch::query()->where('status', MatchStatus::Confirmed)->exists())->toBeFalse()
            ->and(PartMatch::query()->where('status', MatchStatus::Proposed)->whereNotNull('item_id')->exists())->toBeFalse();
    });

    it('does not revive a pairing a person replaced with another part', function (): void {
        $old = $this->confirm->execute(
            $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway),
        );
        $this->confirm->execute(
            $this->propose->execute($this->vendorItem, $this->otherPart, 0.6, MatchMethod::Person),
            $this->person,
        );

        $again = $this->propose->execute($this->vendorItem, $this->part, 0.99, MatchMethod::Gateway);

        expect($again->id)->toBe($old->id)
            ->and($again->status)->toBe(MatchStatus::Vetoed);
    });

    it('fills the open empty proposal when a part is proposed', function (): void {
        $rejected = $this->propose->execute($this->vendorItem, $this->part, 0.6, MatchMethod::Gateway);
        $this->reject->execute($rejected, $this->person);

        $proposal = $this->propose->execute($this->vendorItem, $this->otherPart, 0.7, MatchMethod::Gateway);

        expect($proposal->item_id)->toBe($this->otherPart->id)
            ->and(PartMatch::query()->needsReview()->pluck('id')->all())->toBe([$proposal->id]);
    });

    it('does not offer a new part again after a person rejected that', function (): void {
        $newPart = $this->propose->execute($this->vendorItem, null, 0.95, MatchMethod::Gateway, ['name' => 'Axle shaft']);
        $this->reject->execute($newPart, $this->person);

        $again = $this->propose->execute($this->vendorItem, null, 0.97, MatchMethod::Gateway, ['name' => 'Axle shaft']);

        expect($again->id)->toBe($newPart->id)
            ->and($again->status)->toBe(MatchStatus::Vetoed);
    });
});

describe('ConfirmMatch', function (): void {
    it('confirms by rule when there is no actor', function (): void {
        $match = $this->confirm->execute(
            $this->propose->execute($this->vendorItem, $this->part, 0.94, MatchMethod::Gateway),
        );

        expect($match->status)->toBe(MatchStatus::Confirmed)
            ->and($match->confirmed_by_type)->toBe('rule')
            ->and($match->confirmed_by_id)->toBeNull()
            ->and($match->confirmed_at)->not->toBeNull()
            ->and($match->isConfirmedByRule())->toBeTrue();
    });

    it('records the person who confirmed', function (): void {
        $match = $this->confirm->execute(
            $this->propose->execute($this->vendorItem, $this->part, 0.5, MatchMethod::Person),
            $this->person,
        );

        expect($match->confirmed_by_type)->toBe($this->person->getMorphClass())
            ->and($match->confirmed_by_id)->toBe($this->person->getKey())
            ->and($match->isConfirmedByRule())->toBeFalse();
    });

    it('records a person standing behind an automatic match', function (): void {
        $match = $this->confirm->execute(
            $this->propose->execute($this->vendorItem, $this->part, 0.94, MatchMethod::Gateway),
        );

        $this->confirm->execute($match, $this->person);

        expect($match->refresh()->confirmed_by_id)->toBe($this->person->getKey());
    });

    it('leaves a confirmed match as it is when the rule confirms it again', function (): void {
        $match = $this->confirm->execute(
            $this->propose->execute($this->vendorItem, $this->part, 0.5, MatchMethod::Person),
            $this->person,
        );

        $this->confirm->execute($match);

        expect($match->refresh()->confirmed_by_id)->toBe($this->person->getKey());
    });

    it('refuses an inactive part, whoever is asking', function (?object $actor): void {
        $inactive = Item::factory()->inactive()->create();
        $match = $this->propose->execute($this->vendorItem, $inactive, 0.95, MatchMethod::Gateway);

        expect(fn () => $this->confirm->execute($match, $actor))
            ->toThrow(CannotConfirmMatchException::class, 'inactive');

        expect($match->refresh()->status)->toBe(MatchStatus::Proposed);
    })->with([
        'by rule' => [null],
        'by a person' => [TestActor::named()],
    ]);

    it('refuses a second confirmed match by rule', function (): void {
        $first = $this->confirm->execute(
            $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway),
        );
        $second = $this->propose->execute($this->vendorItem, $this->otherPart, 0.96, MatchMethod::Gateway);

        expect(fn () => $this->confirm->execute($second))
            ->toThrow(CannotConfirmMatchException::class, 'already confirmed');

        expect($first->refresh()->status)->toBe(MatchStatus::Confirmed)
            ->and($second->refresh()->status)->toBe(MatchStatus::Proposed);
    });

    it('swaps parts for a person, rejecting the old match and keeping its row', function (): void {
        $old = $this->confirm->execute(
            $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway),
        );
        $new = $this->propose->execute($this->vendorItem, $this->otherPart, 0.5, MatchMethod::Person);

        $this->confirm->execute($new, $this->person);

        expect($old->refresh()->status)->toBe(MatchStatus::Vetoed)
            ->and($old->vetoed_by_id)->toBe($this->person->getKey())
            ->and($old->vetoed_at)->not->toBeNull()
            ->and($new->refresh()->status)->toBe(MatchStatus::Confirmed)
            ->and(PartMatch::query()->where('vendor_item_id', $this->vendorItem->id)->count())->toBe(2);
    });

    it('refuses a proposal with no part yet', function (): void {
        $match = $this->propose->execute($this->vendorItem, null, 0.95, MatchMethod::Gateway, ['name' => 'Axle shaft']);

        expect(fn () => $this->confirm->execute($match, $this->person))
            ->toThrow(CannotConfirmMatchException::class, 'no part');
    });

    it('refuses to confirm a rejected pairing by rule', function (): void {
        $match = $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway);
        $this->reject->execute($match, $this->person);

        expect(fn () => $this->confirm->execute($match))
            ->toThrow(CannotConfirmMatchException::class, 'rejected');

        expect($match->refresh()->status)->toBe(MatchStatus::Vetoed);
    });

    it('lets a person undo a rejection, which clears the queue', function (): void {
        $match = $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway);
        $this->reject->execute($match, $this->person);

        $this->confirm->execute($match, $this->person);

        expect($match->refresh()->status)->toBe(MatchStatus::Confirmed)
            ->and(PartMatch::query()->needsReview()->exists())->toBeFalse();
    });

    it('answers the open empty proposal, by rule or by a person', function (?object $actor): void {
        $this->propose->execute($this->vendorItem, null, 0.4, MatchMethod::Gateway, ['name' => 'Axle shaft']);

        $this->confirm->execute(
            $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway),
            $actor,
        );

        expect(PartMatch::query()->needsReview()->exists())->toBeFalse();
    })->with([
        'by rule' => [null],
        'by a person' => [TestActor::named()],
    ]);

    it('takes a person choosing one part as rejecting the parts left open', function (): void {
        $passedOver = $this->propose->execute($this->vendorItem, $this->part, 0.6, MatchMethod::Gateway);
        $chosen = $this->propose->execute($this->vendorItem, $this->otherPart, 0.5, MatchMethod::Person);

        $this->confirm->execute($chosen, $this->person);

        expect($passedOver->refresh()->status)->toBe(MatchStatus::Vetoed)
            ->and(PartMatch::query()->needsReview()->exists())->toBeFalse();
    });

    it('leaves a rule confirmation\'s rivals open for a person to look at', function (): void {
        $rival = $this->propose->execute($this->vendorItem, $this->part, 0.6, MatchMethod::Gateway);

        $this->confirm->execute($this->propose->execute($this->vendorItem, $this->otherPart, 0.95, MatchMethod::Gateway));

        expect($rival->refresh()->status)->toBe(MatchStatus::Proposed)
            ->and(PartMatch::query()->needsReview()->pluck('id')->all())->toBe([$rival->id]);
    });
});

describe('RejectMatch', function (): void {
    it('marks the match rejected and records who and why', function (): void {
        $match = $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway);
        $this->confirm->execute($match);

        $rejected = $this->reject->execute($match, $this->person, 'Wrong side');

        expect($rejected->status)->toBe(MatchStatus::Vetoed)
            ->and($rejected->vetoed_at)->not->toBeNull()
            ->and($rejected->vetoed_by_type)->toBe($this->person->getMorphClass())
            ->and($rejected->vetoed_by_id)->toBe($this->person->getKey())
            ->and($rejected->note)->toBe('Wrong side')
            ->and($rejected->item_id)->toBe($this->part->id)
            ->and($rejected->confirmed_at)->not->toBeNull();
    });

    it('deletes nothing and moves nothing else', function (): void {
        $rejected = $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway);
        $elsewhere = $this->confirm->execute(
            $this->propose->execute(VendorItem::factory()->create(), $this->part, 0.95, MatchMethod::Gateway),
        );

        $this->reject->execute($rejected, $this->person);

        expect(PartMatch::query()->whereKey($rejected->id)->exists())->toBeTrue()
            ->and(VendorItem::query()->whereKey($this->vendorItem->id)->exists())->toBeTrue()
            ->and(Item::query()->whereKey($this->part->id)->exists())->toBeTrue()
            ->and($elsewhere->refresh()->status)->toBe(MatchStatus::Confirmed);
    });

    it('puts the vendor item back in the queue through a new proposal with no part', function (): void {
        $match = $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway);
        $this->confirm->execute($match);

        $this->reject->execute($match, $this->person);

        $queued = PartMatch::query()->needsReview()->get();

        expect($queued)->toHaveCount(1)
            ->and($queued->first()->vendor_item_id)->toBe($this->vendorItem->id)
            ->and($queued->first()->item_id)->toBeNull()
            ->and($queued->first()->status)->toBe(MatchStatus::Proposed)
            ->and($queued->first()->id)->not->toBe($match->id);
    });

    it('adds no empty proposal while another part is still open', function (): void {
        $match = $this->propose->execute($this->vendorItem, $this->part, 0.6, MatchMethod::Gateway);
        $open = $this->propose->execute($this->vendorItem, $this->otherPart, 0.55, MatchMethod::Gateway);

        $this->reject->execute($match, $this->person);

        expect(PartMatch::query()->needsReview()->pluck('id')->all())->toBe([$open->id]);
    });

    it('adds no empty proposal while the vendor item stays confirmed elsewhere', function (): void {
        $this->confirm->execute($this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway));
        $rival = $this->propose->execute($this->vendorItem, $this->otherPart, 0.6, MatchMethod::Gateway);

        $this->reject->execute($rival, $this->person);

        expect(PartMatch::query()->needsReview()->exists())->toBeFalse();
    });

    it('keeps a vendor item in the queue once when its empty proposal is rejected', function (): void {
        $match = $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway);
        $this->reject->execute($match, $this->person);
        $empty = PartMatch::query()->needsReview()->sole();

        $this->reject->execute($empty, $this->person);

        expect(PartMatch::query()->needsReview()->count())->toBe(1);
    });

    it('rejecting twice changes nothing the second time', function (): void {
        $match = $this->propose->execute($this->vendorItem, $this->part, 0.95, MatchMethod::Gateway);
        $this->reject->execute($match, $this->person, 'Wrong side');

        $this->reject->execute($match, TestActor::named('019fdd00-0000-7000-8000-00000000a002'), 'Changed my mind');

        expect($match->refresh()->note)->toBe('Wrong side')
            ->and($match->vetoed_by_id)->toBe($this->person->getKey())
            ->and(PartMatch::query()->count())->toBe(2);
    });
});

describe('PartMatch::needsReview', function (): void {
    it('holds proposals and leaves confirmed matches out', function (): void {
        $proposal = $this->propose->execute($this->vendorItem, $this->part, 0.6, MatchMethod::Gateway);
        $this->confirm->execute(
            $this->propose->execute(VendorItem::factory()->create(), $this->part, 0.95, MatchMethod::Gateway),
        );

        expect(PartMatch::query()->needsReview()->pluck('id')->all())->toBe([$proposal->id]);
    });

    it('holds a rejected match whose vendor item has nothing open or confirmed', function (): void {
        rawMatch($this->vendorItem, $this->part, 'vetoed');

        expect(PartMatch::query()->needsReview()->count())->toBe(1);
    });

    it('holds such a vendor item once however many rejections it has', function (): void {
        rawMatch($this->vendorItem, $this->part, 'vetoed');
        rawMatch($this->vendorItem, $this->otherPart, 'vetoed');

        expect(PartMatch::query()->needsReview()->count())->toBe(1);
    });

    it('leaves a rejected match out once its vendor item is confirmed', function (): void {
        rawMatch($this->vendorItem, $this->part, 'vetoed');
        rawMatch($this->vendorItem, $this->otherPart, 'confirmed');

        expect(PartMatch::query()->needsReview()->exists())->toBeFalse();
    });
});
