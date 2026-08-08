<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Forecasting\Actions\DismissRecommendation;
use RobinsonRyan\Inventory\Forecasting\Actions\GenerateRecommendations;
use RobinsonRyan\Inventory\Forecasting\Models\ReorderRecommendation;
use RobinsonRyan\Inventory\Stock\Data\AddStockData;
use RobinsonRyan\Inventory\Stock\Models\Location;
use RobinsonRyan\Inventory\Stock\Services\StockService;

beforeEach(function (): void {
    $this->generateRecommendations = App::make(GenerateRecommendations::class);
    $this->dismissRecommendation = App::make(DismissRecommendation::class);
    $this->stockService = App::make(StockService::class);
    $this->location = Location::factory()->warehouse()->create();
});

describe('ReorderRecommendation Model', function (): void {
    it('creates recommendation', function (): void {
        $item = Item::factory()->create();

        $recommendation = ReorderRecommendation::factory()
            ->forItem($item->id)
            ->pending()
            ->create();

        expect($recommendation)->toBeInstanceOf(ReorderRecommendation::class)
            ->and($recommendation->item_id)->toBe($item->id)
            ->and($recommendation->isPending())->toBeTrue()
            ->and($recommendation->isActioned())->toBeFalse()
            ->and($recommendation->isDismissed())->toBeFalse();
    });

    it('loads item relationship', function (): void {
        $item = Item::factory()->create(['name' => 'Test Part']);

        $recommendation = ReorderRecommendation::factory()
            ->forItem($item->id)
            ->create();

        $recommendation->load('item');

        expect($recommendation->item)->not->toBeNull()
            ->and($recommendation->item->name)->toBe('Test Part');
    });
});

describe('GenerateRecommendations Action', function (): void {
    it('generates recommendations for items below reorder point', function (): void {
        // Create items below reorder point
        $item1 = Item::factory()->create([
            'min_stock_level' => 100,
            'is_active' => true,
        ]);
        $item2 = Item::factory()->create([
            'min_stock_level' => 50,
            'is_active' => true,
        ]);

        // Add stock below reorder points
        $this->stockService->addStock(new AddStockData(
            itemId: $item1->id,
            locationId: $this->location->id,
            quantity: 30,
        ));
        $this->stockService->addStock(new AddStockData(
            itemId: $item2->id,
            locationId: $this->location->id,
            quantity: 20,
        ));

        $recommendations = $this->generateRecommendations->execute();

        expect($recommendations)->toHaveCount(2);
    });

    it('generates recommendations for specific items only', function (): void {
        $item1 = Item::factory()->create([
            'min_stock_level' => 100,
            'is_active' => true,
        ]);
        $item2 = Item::factory()->create([
            'min_stock_level' => 50,
            'is_active' => true,
        ]);

        $this->stockService->addStock(new AddStockData(
            itemId: $item1->id,
            locationId: $this->location->id,
            quantity: 30,
        ));
        $this->stockService->addStock(new AddStockData(
            itemId: $item2->id,
            locationId: $this->location->id,
            quantity: 20,
        ));

        // Only generate for item1
        $recommendations = $this->generateRecommendations->execute([$item1->id]);

        expect($recommendations)->toHaveCount(1)
            ->and($recommendations->first()->item_id)->toBe($item1->id);
    });
});

describe('DismissRecommendation Action', function (): void {
    it('dismisses recommendation', function (): void {
        $item = Item::factory()->create();

        $recommendation = ReorderRecommendation::factory()
            ->forItem($item->id)
            ->pending()
            ->create();

        $dismissed = $this->dismissRecommendation->execute($recommendation);

        expect($dismissed->isDismissed())->toBeTrue()
            ->and($dismissed->dismissed_at)->not->toBeNull();
    });

    it('throws exception when dismissing already actioned recommendation', function (): void {
        $item = Item::factory()->create();
        $poId = (string) Symfony\Component\Uid\Uuid::v7();

        $recommendation = ReorderRecommendation::factory()
            ->forItem($item->id)
            ->actioned($poId)
            ->create();

        expect(fn () => $this->dismissRecommendation->execute($recommendation))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws exception when dismissing already dismissed recommendation', function (): void {
        $item = Item::factory()->create();

        $recommendation = ReorderRecommendation::factory()
            ->forItem($item->id)
            ->dismissed()
            ->create();

        expect(fn () => $this->dismissRecommendation->execute($recommendation))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('ReorderRecommendation Status Methods', function (): void {
    it('identifies pending recommendations', function (): void {
        $item = Item::factory()->create();

        $pending = ReorderRecommendation::factory()
            ->forItem($item->id)
            ->pending()
            ->create();

        expect($pending->isPending())->toBeTrue()
            ->and($pending->isActioned())->toBeFalse()
            ->and($pending->isDismissed())->toBeFalse();
    });

    it('identifies actioned recommendations', function (): void {
        $item = Item::factory()->create();
        $poId = (string) Symfony\Component\Uid\Uuid::v7();

        $actioned = ReorderRecommendation::factory()
            ->forItem($item->id)
            ->actioned($poId)
            ->create();

        expect($actioned->isPending())->toBeFalse()
            ->and($actioned->isActioned())->toBeTrue()
            ->and($actioned->isDismissed())->toBeFalse();
    });

    it('identifies dismissed recommendations', function (): void {
        $item = Item::factory()->create();

        $dismissed = ReorderRecommendation::factory()
            ->forItem($item->id)
            ->dismissed()
            ->create();

        expect($dismissed->isPending())->toBeFalse()
            ->and($dismissed->isActioned())->toBeFalse()
            ->and($dismissed->isDismissed())->toBeTrue();
    });
});
