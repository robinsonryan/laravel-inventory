<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Actions;

use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Exceptions\CannotClassifyException;
use RobinsonRyan\Inventory\Matching\Enums\ClassificationAxis;
use RobinsonRyan\Inventory\Matching\Enums\MatchMethod;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Matching\Models\PartClassification;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartCategoryDefinition;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartFitmentDefinition;
use RobinsonRyan\Taxon\Models\Tag;

/**
 * Records a proposed fitment or category. Puts no tag on the part; only a
 * confirmed classification does that.
 *
 * Like ProposeMatch, a decision already made about this value wins, so the row
 * handed back may be confirmed or vetoed rather than proposed. A category name
 * that matches nothing is recorded as text and goes to review.
 */
final class ProposeClassification
{
    /**
     * @param  string  $value  a make, or a category name
     * @param  array{method?: MatchMethod, note?: string, tag?: Tag}  $context  `tag` names the category
     *                                                                          outright, for a person who picked it
     *
     * @throws CannotClassifyException when the value is not a make a part can fit, or `tag` is not on this axis
     */
    public function execute(
        Item $item,
        ClassificationAxis $axis,
        string $value,
        float $confidence,
        array $context = [],
    ): PartClassification {
        return DB::transaction(function () use ($item, $axis, $value, $confidence, $context): PartClassification {
            PartClassification::lockItem($item->id);

            $tag = $context['tag'] ?? $this->tagFor($axis, $value);

            if ($tag instanceof Tag) {
                $axis->assertHolds($tag);
            }

            $rows = PartClassification::query()
                ->where('item_id', $item->id)
                ->where('axis', $axis->value)
                ->get()
                ->filter(fn (PartClassification $row): bool => $row->isFor($tag, $value));

            $decided = $rows->first(fn (PartClassification $row): bool => $row->status === MatchStatus::Confirmed)
                ?? $rows->first(fn (PartClassification $row): bool => $row->status === MatchStatus::Vetoed);

            if ($decided instanceof PartClassification) {
                return $decided;
            }

            $classification = $rows->first() ?? new PartClassification([
                'tenant_id' => $item->tenant_id,
                'item_id' => $item->id,
                'axis' => $axis,
            ]);

            $classification->fill([
                'tag_id' => $tag?->getKey(),
                'proposed_value' => $tag instanceof Tag ? null : $value,
                'status' => MatchStatus::Proposed,
                'confidence' => $confidence,
                'method' => $context['method'] ?? MatchMethod::Gateway,
                'note' => $context['note'] ?? null,
            ])->save();

            return $classification;
        });
    }

    private function tagFor(ClassificationAxis $axis, string $value): ?Tag
    {
        if ($axis === ClassificationAxis::Category) {
            return PartCategoryDefinition::findByName($value);
        }

        $make = PartFitmentDefinition::normalize($value);

        if ($make === null) {
            throw CannotClassifyException::unknownFitment($value);
        }

        return PartFitmentDefinition::valueTag($make);
    }
}
