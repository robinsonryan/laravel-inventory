<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Stock\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobinsonRyan\Inventory\Stock\Models\Stock;
use RobinsonRyan\Inventory\Stock\Models\StockMovement;

final class ExecuteTransformation
{
    /**
     * Execute a transformation: consume inputs and produce outputs.
     *
     * @return Model The created assembly record
     */
    public function execute(
        Model $transformation,
        string $locationId,
        float $quantity = 1.0
    ): Model {
        return DB::transaction(function () use ($transformation, $locationId, $quantity): Model {
            // Validate transformation has required relationships
            if (! method_exists($transformation, 'inputs') || ! method_exists($transformation, 'outputs')) {
                throw new InvalidArgumentException(
                    'Transformation model must have inputs() and outputs() relationships.'
                );
            }

            $inputs = $transformation->inputs;
            $outputs = $transformation->outputs;

            if ($inputs->isEmpty()) {
                throw new InvalidArgumentException('Transformation has no inputs defined.');
            }

            if ($outputs->isEmpty()) {
                throw new InvalidArgumentException('Transformation has no outputs defined.');
            }

            // Verify sufficient stock for all inputs
            foreach ($inputs as $input) {
                $requiredQuantity = (float) $input->quantity * $quantity;
                $availableStock = $this->getAvailableStock($input->item_id, $locationId);

                if ($availableStock < $requiredQuantity) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Insufficient stock for input item %s. Required: %.4f, Available: %.4f',
                            $input->item_id,
                            $requiredQuantity,
                            $availableStock
                        )
                    );
                }
            }

            // Create assembly record
            $assemblyModel = config('inventory.models.assembly');
            /** @var Model */
            $assembly = $assemblyModel::query()->create([
                'transformation_id' => $transformation->id,
                'location_id' => $locationId,
                'quantity' => $quantity,
                'assembled_at' => now(),
                'actor_type' => $this->getActorType(),
                'actor_id' => $this->getActorId(),
            ]);

            // Consume inputs
            foreach ($inputs as $input) {
                $consumeQuantity = (float) $input->quantity * $quantity;
                $this->consumeStock(
                    $input->item_id,
                    $locationId,
                    $consumeQuantity,
                    $assembly
                );
            }

            // Produce outputs
            foreach ($outputs as $output) {
                $produceQuantity = (float) $output->quantity * $quantity;
                $this->produceStock(
                    $output->item_id,
                    $locationId,
                    $produceQuantity,
                    $output->cost ?? null,
                    $assembly
                );
            }

            return $assembly;
        });
    }

    private function getAvailableStock(string $itemId, string $locationId): float
    {
        /** @var Stock|null */
        $stock = $this->getStockModel()::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->first();

        if ($stock === null) {
            return 0;
        }

        return (float) $stock->quantity - (float) $stock->reserved_quantity;
    }

    private function consumeStock(
        string $itemId,
        string $locationId,
        float $quantity,
        Model $assembly
    ): void {
        /** @var Stock */
        $stock = $this->getStockModel()::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->firstOrFail();

        $stock->quantity = (float) $stock->quantity - $quantity;
        $stock->save();

        /** @var StockMovement */
        $movement = $this->getMovementModel()::query()->create([
            'item_id' => $itemId,
            'from_location_id' => $locationId,
            'to_location_id' => null,
            'quantity' => $quantity,
            'cost' => $stock->cost_basis,
            'reference_type' => get_class($assembly),
            'reference_id' => $assembly->id,
            'actor_type' => $this->getActorType(),
            'actor_id' => $this->getActorId(),
            'notes' => 'Consumed for transformation',
        ]);

        $movement->type = 'consumption';
    }

    private function produceStock(
        string $itemId,
        string $locationId,
        float $quantity,
        ?float $cost,
        Model $assembly
    ): void {
        /** @var Stock */
        $stock = $this->getStockModel()::query()->firstOrCreate(
            [
                'item_id' => $itemId,
                'location_id' => $locationId,
            ],
            [
                'quantity' => 0,
                'reserved_quantity' => 0,
            ]
        );

        $stock->quantity = (float) $stock->quantity + $quantity;

        if ($cost !== null) {
            $stock->cost_basis = $cost;
        }

        $stock->save();

        /** @var StockMovement */
        $movement = $this->getMovementModel()::query()->create([
            'item_id' => $itemId,
            'from_location_id' => null,
            'to_location_id' => $locationId,
            'quantity' => $quantity,
            'cost' => $cost,
            'reference_type' => get_class($assembly),
            'reference_id' => $assembly->id,
            'actor_type' => $this->getActorType(),
            'actor_id' => $this->getActorId(),
            'notes' => 'Produced from transformation',
        ]);

        $movement->type = 'production';
    }

    private function getActorType(): ?string
    {
        $guard = config('inventory.actor.guard', 'web');
        $user = Auth::guard($guard)->user();

        return $user !== null ? get_class($user) : null;
    }

    private function getActorId(): ?string
    {
        $guard = config('inventory.actor.guard', 'web');
        $user = Auth::guard($guard)->user();

        return $user?->getAuthIdentifier() !== null ? (string) $user->getAuthIdentifier() : null;
    }

    /**
     * @return class-string<Stock>
     */
    private function getStockModel(): string
    {
        return config('inventory.models.stock', Stock::class);
    }

    /**
     * @return class-string<StockMovement>
     */
    private function getMovementModel(): string
    {
        return config('inventory.models.stock_movement', StockMovement::class);
    }
}
