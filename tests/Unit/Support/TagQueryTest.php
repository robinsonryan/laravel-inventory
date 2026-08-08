<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Catalog\TagDefinitions\ItemCategoryDefinition;
use RobinsonRyan\Inventory\Reconciliation\TagDefinitions\CycleCountStatusDefinition;
use RobinsonRyan\Inventory\Support\TagQuery;
use Symfony\Component\Uid\Uuid;

describe('TagQuery', function (): void {
    it('filters a raw query builder down to the records carrying a tag value', function (): void {
        $part = Item::factory()->create();
        $part->category = 'part';

        $fluid = Item::factory()->create();
        $fluid->category = 'fluid';

        Item::factory()->create(); // untagged

        $skus = DB::table('items')
            ->whereIn('items.id', TagQuery::filterIdsByTagValue(Item::class, ItemCategoryDefinition::class, 'part'))
            ->pluck('sku')
            ->all();

        expect($skus)->toBe([$part->sku]);
    });

    it('returns no rows when nothing carries the value', function (): void {
        Item::factory()->create()->category = 'part';

        $count = DB::table('items')
            ->whereIn('items.id', TagQuery::filterIdsByTagValue(Item::class, ItemCategoryDefinition::class, 'labor'))
            ->count();

        expect($count)->toBe(0);
    });

    it('projects labels and values for many records in one query', function (): void {
        $part = Item::factory()->create();
        $part->category = 'part';

        $fluid = Item::factory()->create();
        $fluid->category = 'fluid';

        $untagged = Item::factory()->create();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $map = TagQuery::labelsAndValuesFor(
            Item::class,
            ItemCategoryDefinition::class,
            [$part->id, $fluid->id, $untagged->id],
        );

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($queries)->toHaveCount(1)
            ->and($map)->toBe([
                $part->id => ['label' => 'Part', 'value' => 'part'],
                $fluid->id => ['label' => 'Fluid', 'value' => 'fluid'],
            ]);
    });

    it('omits ids that carry no value for the definition', function (): void {
        $untagged = Item::factory()->create();

        expect(TagQuery::labelsAndValuesFor(Item::class, ItemCategoryDefinition::class, [$untagged->id]))
            ->toBe([]);
    });

    it('returns an empty map without querying when given no ids', function (): void {
        expect(TagQuery::labelsAndValuesFor(Item::class, ItemCategoryDefinition::class, []))->toBe([]);
    });

    it('never confuses one definition with another', function (): void {
        $item = Item::factory()->create();
        $item->category = 'part';

        // A definition the item cannot possibly carry a value for.
        expect(TagQuery::labelsAndValuesFor(Item::class, CycleCountStatusDefinition::class, [$item->id]))
            ->toBe([]);
    });

    it('reads the slugified spelling taxon actually stores', function (): void {
        $item = Item::factory()->create();
        $item->category = 'part';

        $map = TagQuery::labelsAndValuesFor(Item::class, ItemCategoryDefinition::class, [$item->id]);

        expect($map[$item->id]['value'])->toBe('part')
            ->and($map[$item->id]['label'])->toBe('Part');
    });

    it('says so when a pivot row hands back an id it cannot key on', function (): void {
        $item = Item::factory()->create();
        $item->category = 'part';

        $valueTagId = DB::table('taggables')->where('taggable_id', $item->id)->value('tag_id');

        // Taxon lets a host tag models with integer keys (`taggable_id_type`),
        // and the pivot then hands back an int. Dropping those rows produces an
        // empty map for every record in the report — a whole column silently
        // blank, with nothing anywhere saying why.
        Schema::create('int_keyed_taggables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tag_id');
            $table->unsignedBigInteger('taggable_id');
            $table->string('taggable_type');
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });

        DB::table('int_keyed_taggables')->insert([
            'id' => (string) Uuid::v7(),
            'tag_id' => $valueTagId,
            'taggable_id' => 7,
            'taggable_type' => (new Item)->getMorphClass(),
        ]);

        config(['taxon.tables.taggables' => 'int_keyed_taggables']);

        TagQuery::labelsAndValuesFor(Item::class, ItemCategoryDefinition::class, [7]);
    })->throws(RuntimeException::class, 'has a non-string "model_id"');

    it('rejects a model that carries no tags at all', function (): void {
        TagQuery::filterIdsByTagValue(
            Illuminate\Foundation\Auth\User::class,
            ItemCategoryDefinition::class,
            'part',
        );
    })->throws(InvalidArgumentException::class);
});
