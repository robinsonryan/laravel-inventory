<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Catalog\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobinsonRyan\Inventory\Catalog\Data\TransformationData;
use RobinsonRyan\Inventory\Catalog\Models\Transformation;
use RobinsonRyan\Inventory\Catalog\Models\TransformationInput;
use RobinsonRyan\Inventory\Catalog\Models\TransformationOutput;

final class DefineTransformation
{
    public function execute(TransformationData $data): Transformation
    {
        $this->validate($data);

        return DB::transaction(function () use ($data): Transformation {
            // Create the transformation
            $transformation = Transformation::create([
                'name' => $data->name,
                'description' => $data->description,
                'is_active' => $data->isActive,
                'metadata' => $data->metadata,
            ]);

            // Add inputs
            foreach ($data->inputs as $input) {
                TransformationInput::create([
                    'transformation_id' => $transformation->id,
                    'item_id' => $input['item_id'],
                    'quantity' => $input['quantity'],
                ]);
            }

            // Add outputs
            foreach ($data->outputs as $output) {
                TransformationOutput::create([
                    'transformation_id' => $transformation->id,
                    'item_id' => $output['item_id'],
                    'quantity' => $output['quantity'],
                ]);
            }

            return $transformation->load(['inputs.item', 'outputs.item']);
        });
    }

    private function validate(TransformationData $data): void
    {
        if ($data->inputs === []) {
            throw new InvalidArgumentException('Transformation must have at least one input.');
        }

        if ($data->outputs === []) {
            throw new InvalidArgumentException('Transformation must have at least one output.');
        }
    }
}
