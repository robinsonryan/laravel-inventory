<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Exceptions\CannotClassifyException;
use RobinsonRyan\Inventory\Matching\Actions\ConfirmClassification;
use RobinsonRyan\Inventory\Matching\Actions\ManageCategories;
use RobinsonRyan\Inventory\Matching\Actions\ProposeClassification;
use RobinsonRyan\Inventory\Matching\Actions\RejectClassification;
use RobinsonRyan\Inventory\Matching\Enums\ClassificationAxis;
use RobinsonRyan\Inventory\Matching\Enums\MatchMethod;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Matching\Models\PartClassification;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartCategoryDefinition;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartFitmentDefinition;
use RobinsonRyan\Inventory\Support\TagValueInstaller;
use RobinsonRyan\Inventory\Support\TaxonTables;
use RobinsonRyan\Inventory\Tests\Support\TestActor;
use RobinsonRyan\Inventory\Tests\TestCase;
use RobinsonRyan\Taxon\Models\Tag;

beforeEach(function (): void {
    $this->propose = new ProposeClassification;
    $this->confirm = new ConfirmClassification;
    $this->reject = new RejectClassification;
    $this->categories = new ManageCategories;
    $this->person = TestActor::named();
    $this->part = Item::factory()->create();

    $this->axles = $this->categories->create('Axles & differentials');
    $this->brakes = $this->categories->create('Brakes');
    $this->accessories = $this->categories->create('Accessories');
    $this->bumpers = $this->categories->create('Bumpers', $this->accessories);
});

function taggedWith(Item $item, Tag $tag): bool
{
    return DB::table(TaxonTables::taggables())
        ->where('tag_id', $tag->getKey())
        ->where('taggable_id', $item->getKey())
        ->exists();
}

describe('the tag definitions', function (): void {
    it('are installed with the package', function (): void {
        expect(TagValueInstaller::DEFINITIONS)
            ->toContain(PartCategoryDefinition::class, PartFitmentDefinition::class);
    });

    it('declares fitment as multi-select over the three makes', function (): void {
        expect(PartFitmentDefinition::$slug)->toBe('part-fitment')
            ->and(PartFitmentDefinition::tag()->single_select)->toBeFalse()
            ->and(PartFitmentDefinition::values())->toEqualCanonicalizing(['ford', 'gm', 'nissan'])
            ->and(PartFitmentDefinition::valueTag('ford')->name)->toBe('Ford')
            ->and(PartFitmentDefinition::valueTag('gm')->name)->toBe('GM')
            ->and(PartFitmentDefinition::valueTag('nissan')->name)->toBe('Nissan');
    });

    it('keeps the part category apart from the flat item category', function (): void {
        $part = Item::factory()->part()->create();

        $this->confirm->execute($this->propose->execute($part, ClassificationAxis::Category, 'Brakes', 0.95));

        expect(PartCategoryDefinition::$slug)->toBe('part-category')
            ->and($part->category)->toBe('part')
            ->and($part->partCategory)->toBe('brakes');
    });
});

describe('the part_classifications table', function (): void {
    it('has the database generate a uuid v7 key', function (): void {
        $default = DB::scalar(
            "select column_default from information_schema.columns where table_name = 'part_classifications' and column_name = 'id'",
        );

        $classification = $this->propose->execute($this->part, ClassificationAxis::Fitment, 'Ford', 0.9);

        expect($default)->toContain('uuidv7')
            ->and($classification->getKey()[14])->toBe('7')
            ->and($classification->tenant_id)->toBe(TestCase::TENANT_ID);
    });

    it('refuses two confirmed categories for one part at the database', function (): void {
        $insert = fn (Tag $tag) => DB::table('part_classifications')->insert([
            'item_id' => $this->part->id,
            'axis' => 'category',
            'tag_id' => $tag->getKey(),
            'status' => 'confirmed',
            'method' => 'gateway',
        ]);

        $insert($this->axles);

        expect(fn () => DB::transaction(fn () => $insert($this->brakes)))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('fitment', function (): void {
    it('becomes a tag only once confirmed', function (): void {
        $classification = $this->propose->execute($this->part, ClassificationAxis::Fitment, 'Ford', 0.91);

        expect($classification->status)->toBe(MatchStatus::Proposed)
            ->and($classification->confidence)->toBe(0.91)
            ->and($classification->method)->toBe(MatchMethod::Gateway)
            ->and($this->part->fitment)->toBe([]);

        $this->confirm->execute($classification);

        expect($this->part->fitment)->toBe(['ford'])
            ->and($classification->refresh()->status)->toBe(MatchStatus::Confirmed)
            ->and($classification->confirmed_by_type)->toBe('rule');
    });

    it('holds several makes at once, confirming one never dropping another', function (): void {
        foreach (['Ford', 'GM'] as $make) {
            $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Fitment, $make, 0.95));
        }

        expect($this->part->fitment)->toBe(['ford', 'gm'])
            ->and($this->part->fitsAllMakes())->toBeFalse();
    });

    it('reads as all makes when all three are present', function (): void {
        foreach (['Nissan', 'Ford', 'GM'] as $make) {
            $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Fitment, $make, 0.95));
        }

        expect($this->part->fitsAllMakes())->toBeTrue();
    });

    it('reads the two GM makes as GM', function (string $make): void {
        $classification = $this->propose->execute($this->part, ClassificationAxis::Fitment, $make, 0.95);
        $this->confirm->execute($classification);

        expect($this->part->fitment)->toBe(['gm']);
    })->with(['GM', 'gm', 'Chevrolet', 'Chevy', 'chevy', 'GMC']);

    it('refuses a make outside the list', function (): void {
        expect(fn () => $this->propose->execute($this->part, ClassificationAxis::Fitment, 'Tractor', 0.9))
            ->toThrow(CannotClassifyException::class, 'Tractor');

        expect(PartClassification::query()->exists())->toBeFalse();
    });

    it('rejecting removes that tag and keeps the row and the other makes', function (): void {
        $ford = $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Fitment, 'Ford', 0.95));
        $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Fitment, 'GM', 0.95));

        $this->reject->execute($ford, $this->person, 'Does not fit');

        expect($this->part->fitment)->toBe(['gm'])
            ->and($ford->refresh()->status)->toBe(MatchStatus::Vetoed)
            ->and($ford->vetoed_by_id)->toBe($this->person->getKey())
            ->and($ford->vetoed_at)->not->toBeNull()
            ->and($ford->note)->toBe('Does not fit');
    });

    it('rejecting a proposal touches no tag', function (): void {
        $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Fitment, 'GM', 0.95));
        $ford = $this->propose->execute($this->part, ClassificationAxis::Fitment, 'Ford', 0.5);

        $this->reject->execute($ford, $this->person);

        expect($this->part->fitment)->toBe(['gm']);
    });

    it('does not revive a rejected make when a document is re-posted', function (): void {
        $ford = $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Fitment, 'Ford', 0.95));
        $this->reject->execute($ford, $this->person);

        $again = $this->propose->execute($this->part, ClassificationAxis::Fitment, 'ford', 0.99);

        expect($again->id)->toBe($ford->id)
            ->and($again->status)->toBe(MatchStatus::Vetoed)
            ->and($again->confidence)->toBe(0.95)
            ->and($this->part->fitment)->toBe([]);
    });

    it('never downgrades a confirmed make', function (): void {
        $ford = $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Fitment, 'Ford', 0.95));

        $again = $this->propose->execute($this->part, ClassificationAxis::Fitment, 'Ford', 0.2);

        expect($again->id)->toBe($ford->id)
            ->and($again->status)->toBe(MatchStatus::Confirmed)
            ->and($again->confidence)->toBe(0.95);
    });

    it('refreshes an open proposal instead of adding a row', function (): void {
        $first = $this->propose->execute($this->part, ClassificationAxis::Fitment, 'Ford', 0.6);
        $second = $this->propose->execute($this->part, ClassificationAxis::Fitment, 'Ford', 0.8);

        expect($second->id)->toBe($first->id)
            ->and($second->confidence)->toBe(0.8)
            ->and(PartClassification::query()->count())->toBe(1);
    });

    it('is found by the fitment scope', function (): void {
        $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Fitment, 'Ford', 0.95));
        $gmPart = Item::factory()->create();
        $this->confirm->execute($this->propose->execute($gmPart, ClassificationAxis::Fitment, 'GM', 0.95));
        Item::factory()->create();

        expect(Item::query()->withFitment('ford')->pluck('id')->all())->toBe([$this->part->id])
            ->and(Item::query()->withFitment(['ford', 'gm'])->count())->toBe(2);
    });
});

describe('category', function (): void {
    it('points a proposal at the existing category, at any depth', function (): void {
        $classification = $this->propose->execute($this->part, ClassificationAxis::Category, 'bumpers', 0.9);

        expect($classification->tag_id)->toBe($this->bumpers->getKey())
            ->and($classification->proposed_value)->toBeNull();
    });

    it('becomes a tag only once confirmed, and reads back from any depth', function (): void {
        $classification = $this->propose->execute($this->part, ClassificationAxis::Category, 'Bumpers', 0.95);

        expect($this->part->partCategory)->toBeNull();

        $this->confirm->execute($classification);

        expect($this->part->partCategory)->toBe('bumpers')
            ->and($this->part->partCategoryTag()?->is($this->bumpers))->toBeTrue();
    });

    it('accepts a category named by a person when two share a name', function (): void {
        $shopPaint = $this->categories->create('Paint', $this->axles);
        $bodyPaint = $this->categories->create('Paint', $this->accessories);

        $ambiguous = $this->propose->execute($this->part, ClassificationAxis::Category, 'Paint', 0.95);
        $chosen = $this->propose->execute(
            $this->part,
            ClassificationAxis::Category,
            'Paint',
            1.0,
            ['tag' => $bodyPaint, 'method' => MatchMethod::Person],
        );

        expect($ambiguous->tag_id)->toBeNull()
            ->and($chosen->tag_id)->toBe($bodyPaint->getKey())
            ->and($chosen->tag_id)->not->toBe($shopPaint->getKey())
            ->and($chosen->method)->toBe(MatchMethod::Person);
    });

    it('replaces the previous confirmed category and keeps the old row as history', function (): void {
        $old = $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Category, 'Brakes', 0.95));
        $new = $this->confirm->execute(
            $this->propose->execute($this->part, ClassificationAxis::Category, 'Bumpers', 0.5),
            $this->person,
        );

        expect($old->refresh()->status)->toBe(MatchStatus::Vetoed)
            ->and($old->replaced_by_id)->toBe($new->id)
            ->and($old->vetoed_by_id)->toBe($this->person->getKey())
            ->and($old->tag_id)->toBe($this->brakes->getKey())
            ->and(taggedWith($this->part, $this->brakes))->toBeFalse()
            ->and(taggedWith($this->part, $this->bumpers))->toBeTrue()
            ->and($this->part->partCategory)->toBe('bumpers');
    });

    it('lets the rule replace a category the rule chose', function (): void {
        $old = $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Category, 'Brakes', 0.91));
        $new = $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Category, 'Bumpers', 0.95));

        expect($old->refresh()->replaced_by_id)->toBe($new->id)
            ->and($old->vetoed_by_type)->toBe('rule')
            ->and($this->part->partCategory)->toBe('bumpers');
    });

    it('never lets the rule replace a category a person chose', function (): void {
        $chosen = $this->confirm->execute(
            $this->propose->execute($this->part, ClassificationAxis::Category, 'Brakes', 0.6),
            $this->person,
        );
        $proposal = $this->propose->execute($this->part, ClassificationAxis::Category, 'Bumpers', 0.99);

        expect(fn () => $this->confirm->execute($proposal))
            ->toThrow(CannotClassifyException::class, 'person');

        expect($chosen->refresh()->status)->toBe(MatchStatus::Confirmed)
            ->and($this->part->partCategory)->toBe('brakes');
    });

    it('records a category that does not exist yet without creating it', function (): void {
        $classification = $this->propose->execute($this->part, ClassificationAxis::Category, 'Running boards', 0.96);

        expect($classification->tag_id)->toBeNull()
            ->and($classification->proposed_value)->toBe('Running boards')
            ->and($classification->status)->toBe(MatchStatus::Proposed)
            ->and(PartCategoryDefinition::findByName('Running boards'))->toBeNull();
    });

    it('never auto-confirms a new category', function (): void {
        $classification = $this->propose->execute($this->part, ClassificationAxis::Category, 'Running boards', 0.99);

        expect(fn () => $this->confirm->execute($classification))
            ->toThrow(CannotClassifyException::class, 'Running boards');

        expect($classification->refresh()->status)->toBe(MatchStatus::Proposed)
            ->and(PartCategoryDefinition::findByName('Running boards'))->toBeNull();
    });

    it('creates the new category when a person confirms it', function (): void {
        $classification = $this->propose->execute($this->part, ClassificationAxis::Category, 'Running boards', 0.6);

        $this->confirm->execute($classification, $this->person);

        $created = PartCategoryDefinition::findByName('Running boards');

        expect($created)->not->toBeNull()
            ->and($created->name)->toBe('Running boards')
            ->and($classification->refresh()->tag_id)->toBe($created->getKey())
            ->and($classification->status)->toBe(MatchStatus::Confirmed)
            ->and($this->part->partCategory)->toBe('running-boards');
    });

    it('uses a category created since the proposal instead of raising the refusal', function (): void {
        $classification = $this->propose->execute($this->part, ClassificationAxis::Category, 'Running boards', 0.95);
        $created = $this->categories->create('Running boards', $this->accessories);

        $this->confirm->execute($classification);

        expect($classification->refresh()->tag_id)->toBe($created->getKey())
            ->and(taggedWith($this->part, $created))->toBeTrue();
    });

    it('rejecting a confirmed category removes the tag and keeps the row', function (): void {
        $classification = $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Category, 'Bumpers', 0.95));

        $this->reject->execute($classification, $this->person);

        expect($this->part->partCategory)->toBeNull()
            ->and($classification->refresh()->status)->toBe(MatchStatus::Vetoed)
            ->and($classification->tag_id)->toBe($this->bumpers->getKey());
    });

    it('does not revive a rejected category when a document is re-posted', function (): void {
        $classification = $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Category, 'Bumpers', 0.95));
        $this->reject->execute($classification, $this->person);

        $again = $this->propose->execute($this->part, ClassificationAxis::Category, 'Bumpers', 0.99);

        expect($again->id)->toBe($classification->id)
            ->and($again->status)->toBe(MatchStatus::Vetoed);
    });

    it('does not revive a rejected new category, even after it comes to exist', function (): void {
        $classification = $this->propose->execute($this->part, ClassificationAxis::Category, 'Running boards', 0.6);
        $this->reject->execute($classification, $this->person);
        $this->categories->create('Running boards', null);

        $again = $this->propose->execute($this->part, ClassificationAxis::Category, 'running boards', 0.99);

        expect($again->id)->toBe($classification->id)
            ->and($again->status)->toBe(MatchStatus::Vetoed);
    });

    it('does not revive a category that was replaced', function (): void {
        $old = $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Category, 'Brakes', 0.95));
        $this->confirm->execute(
            $this->propose->execute($this->part, ClassificationAxis::Category, 'Bumpers', 0.5),
            $this->person,
        );

        $again = $this->propose->execute($this->part, ClassificationAxis::Category, 'Brakes', 0.99);

        expect($again->id)->toBe($old->id)
            ->and($again->status)->toBe(MatchStatus::Vetoed);
    });

    it('refuses to confirm a rejected category by rule and lets a person undo it', function (): void {
        $classification = $this->propose->execute($this->part, ClassificationAxis::Category, 'Bumpers', 0.95);
        $this->reject->execute($classification, $this->person);

        expect(fn () => $this->confirm->execute($classification))
            ->toThrow(CannotClassifyException::class, 'rejected');

        $this->confirm->execute($classification, $this->person);

        expect($classification->refresh()->status)->toBe(MatchStatus::Confirmed)
            ->and($this->part->partCategory)->toBe('bumpers');
    });

    it('reinstating a replaced category clears its replacement link', function (): void {
        $old = $this->confirm->execute($this->propose->execute($this->part, ClassificationAxis::Category, 'Brakes', 0.95));
        $new = $this->confirm->execute(
            $this->propose->execute($this->part, ClassificationAxis::Category, 'Bumpers', 0.5),
            $this->person,
        );

        $this->confirm->execute($old, $this->person);

        expect($old->refresh()->status)->toBe(MatchStatus::Confirmed)
            ->and($old->replaced_by_id)->toBeNull()
            ->and($new->refresh()->replaced_by_id)->toBe($old->id)
            ->and($this->part->partCategory)->toBe('brakes');
    });
});

it('keeps an optional model-year range in the part metadata', function (): void {
    $part = Item::factory()->create(['metadata' => ['model_year_from' => 2009, 'model_year_to' => 2014]]);

    expect($part->refresh()->metadata)->toBe(['model_year_from' => 2009, 'model_year_to' => 2014]);
});
