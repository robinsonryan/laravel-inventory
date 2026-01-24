<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Catalog\Actions\DefineTransformation;
use RobinsonRyan\Inventory\Catalog\Data\TransformationData;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Catalog\Models\Transformation;

describe('Transformation', function (): void {
    it('creates a transformation with inputs and outputs', function (): void {
        $input1 = Item::factory()->create(['name' => 'Raw Material A']);
        $input2 = Item::factory()->create(['name' => 'Raw Material B']);
        $output = Item::factory()->create(['name' => 'Finished Product']);

        $data = new TransformationData(
            name: 'Assembly Process',
            description: 'Combines raw materials into finished product',
            inputs: [
                ['item_id' => $input1->id, 'quantity' => 2.0],
                ['item_id' => $input2->id, 'quantity' => 1.0],
            ],
            outputs: [
                ['item_id' => $output->id, 'quantity' => 1.0],
            ],
        );

        $action = new DefineTransformation;
        $transformation = $action->execute($data);

        expect($transformation)->toBeInstanceOf(Transformation::class)
            ->and($transformation->name)->toBe('Assembly Process')
            ->and($transformation->inputs)->toHaveCount(2)
            ->and($transformation->outputs)->toHaveCount(1);

        $firstInput = $transformation->inputs->firstWhere('item_id', $input1->id);
        expect((float) $firstInput->quantity)->toBe(2.0)
            ->and($firstInput->item->name)->toBe('Raw Material A');
    });

    it('validates transformation has at least one input', function (): void {
        $output = Item::factory()->create();

        $data = new TransformationData(
            name: 'Invalid Transformation',
            inputs: [],
            outputs: [
                ['item_id' => $output->id, 'quantity' => 1.0],
            ],
        );

        $action = new DefineTransformation;

        expect(fn () => $action->execute($data))
            ->toThrow(InvalidArgumentException::class, 'Transformation must have at least one input.');
    });

    it('validates transformation has at least one output', function (): void {
        $input = Item::factory()->create();

        $data = new TransformationData(
            name: 'Invalid Transformation',
            inputs: [
                ['item_id' => $input->id, 'quantity' => 1.0],
            ],
            outputs: [],
        );

        $action = new DefineTransformation;

        expect(fn () => $action->execute($data))
            ->toThrow(InvalidArgumentException::class, 'Transformation must have at least one output.');
    });

    it('creates transformation via factory', function (): void {
        $input = Item::factory()->create();
        $output = Item::factory()->create();

        $transformation = Transformation::factory()
            ->withInputs([['item' => $input, 'quantity' => 2.0]])
            ->withOutputs([['item' => $output, 'quantity' => 1.0]])
            ->create();

        expect($transformation->inputs)->toHaveCount(1)
            ->and($transformation->outputs)->toHaveCount(1)
            ->and((float) $transformation->inputs->first()->quantity)->toBe(2.0);
    });

    it('tracks input and output items', function (): void {
        $rawMaterial = Item::factory()->create(['name' => 'Steel Rod']);
        $finishedProduct = Item::factory()->create(['name' => 'Bolt']);

        $transformation = Transformation::factory()->create(['name' => 'Bolt Manufacturing']);

        $transformation->inputs()->create([
            'item_id' => $rawMaterial->id,
            'quantity' => 0.5,
        ]);

        $transformation->outputs()->create([
            'item_id' => $finishedProduct->id,
            'quantity' => 10.0,
        ]);

        // Verify the item can see its transformations
        expect($rawMaterial->transformationInputs)->toHaveCount(1)
            ->and($rawMaterial->transformationInputs->first()->transformation->name)->toBe('Bolt Manufacturing')
            ->and($finishedProduct->transformationOutputs)->toHaveCount(1);
    });

    it('supports multiple outputs from one transformation', function (): void {
        $input = Item::factory()->create(['name' => 'Crude Oil']);
        $output1 = Item::factory()->create(['name' => 'Gasoline']);
        $output2 = Item::factory()->create(['name' => 'Diesel']);
        $output3 = Item::factory()->create(['name' => 'Kerosene']);

        $data = new TransformationData(
            name: 'Oil Refining',
            inputs: [
                ['item_id' => $input->id, 'quantity' => 100.0],
            ],
            outputs: [
                ['item_id' => $output1->id, 'quantity' => 40.0],
                ['item_id' => $output2->id, 'quantity' => 35.0],
                ['item_id' => $output3->id, 'quantity' => 20.0],
            ],
        );

        $action = new DefineTransformation;
        $transformation = $action->execute($data);

        expect($transformation->outputs)->toHaveCount(3);

        $totalOutput = $transformation->outputs->sum(fn ($o) => (float) $o->quantity);
        expect($totalOutput)->toBe(95.0);
    });

    it('stores metadata with transformation', function (): void {
        $input = Item::factory()->create();
        $output = Item::factory()->create();

        $data = new TransformationData(
            name: 'Process with Metadata',
            inputs: [['item_id' => $input->id, 'quantity' => 1.0]],
            outputs: [['item_id' => $output->id, 'quantity' => 1.0]],
            metadata: [
                'labor_hours' => 2.5,
                'equipment_required' => ['lathe', 'drill'],
            ],
        );

        $action = new DefineTransformation;
        $transformation = $action->execute($data);

        expect($transformation->metadata)->toBe([
            'labor_hours' => 2.5,
            'equipment_required' => ['lathe', 'drill'],
        ]);
    });
});
