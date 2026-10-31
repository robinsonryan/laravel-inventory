<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Matching\Enums\ClassificationAxis;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Matching\Models\PartClassification;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartFitmentDefinition;
use RobinsonRyan\Taxon\Models\Tag;

/**
 * Rejects a classification, taking its tag off the part if it had one. The row
 * stays as the record, and stops the value being proposed again.
 */
final class RejectClassification
{
    public function execute(PartClassification $classification, Model $actor, ?string $note = null): PartClassification
    {
        return DB::transaction(function () use ($classification, $actor, $note): PartClassification {
            PartClassification::lockItem($classification->item_id);
            $classification->refresh();

            if ($classification->status === MatchStatus::Vetoed) {
                return $classification;
            }

            $wasConfirmed = $classification->status === MatchStatus::Confirmed;

            $classification->markVetoed($actor, $note);
            $classification->save();

            if ($wasConfirmed) {
                $this->removeTag($classification);
            }

            return $classification;
        });
    }

    private function removeTag(PartClassification $classification): void
    {
        $item = Item::query()->findOrFail($classification->item_id);
        $tag = Tag::query()->findOrFail($classification->tag_id);

        if ($classification->axis === ClassificationAxis::Fitment) {
            $item->removeTag(PartFitmentDefinition::$slug, $tag->slug);

            return;
        }

        $item->tags()->detach($tag->getKey());
    }
}
