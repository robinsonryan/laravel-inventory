<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Exceptions\CannotClassifyException;
use RobinsonRyan\Inventory\Matching\Enums\ClassificationAxis;
use RobinsonRyan\Inventory\Matching\Enums\MatchStatus;
use RobinsonRyan\Inventory\Matching\Models\PartClassification;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartCategoryDefinition;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartFitmentDefinition;
use RobinsonRyan\Taxon\Models\Tag;

/**
 * Confirms a classification and puts its tag on the part, by rule when there is
 * no actor.
 *
 * Fitment adds to what is there. A part has one category, so confirming one
 * replaces the last: the old row is vetoed and points at its replacement, which
 * is the history the spec asks for. The rule may replace its own choice but
 * never a person's, and it never creates a category.
 */
final readonly class ConfirmClassification
{
    public function __construct(private ManageCategories $categories = new ManageCategories) {}

    /**
     * @throws CannotClassifyException
     */
    public function execute(PartClassification $classification, ?Model $actor = null): PartClassification
    {
        return DB::transaction(function () use ($classification, $actor): PartClassification {
            PartClassification::lockItem($classification->item_id);
            $classification->refresh();

            if ($classification->status === MatchStatus::Confirmed) {
                if ($actor instanceof Model) {
                    $classification->markConfirmed($actor);
                    $classification->save();
                }

                return $classification;
            }

            if ($classification->status === MatchStatus::Vetoed && ! $actor instanceof Model) {
                throw CannotClassifyException::rejected($classification->id);
            }

            $item = Item::query()->findOrFail($classification->item_id);
            $tag = $this->tagFor($classification, $actor);

            if ($classification->axis === ClassificationAxis::Category) {
                $this->replacePrevious($classification, $item, $actor);
            }

            $classification->tag_id = $tag->getKey();
            $classification->replaced_by_id = null;
            $classification->markConfirmed($actor);
            $classification->save();

            if ($classification->axis === ClassificationAxis::Fitment) {
                $item->addTagAs(PartFitmentDefinition::class, $tag->slug);
            } else {
                $item->tags()->syncWithoutDetaching([$tag->getKey()]);
            }

            return $classification;
        });
    }

    /**
     * The tree can change after a proposal, so a tag is checked again, and a name
     * is looked up again before it is treated as new. A name two categories share
     * is not new, it is unanswered, so even a person gets asked which.
     */
    private function tagFor(PartClassification $classification, ?Model $actor): Tag
    {
        if ($classification->tag_id !== null) {
            $tag = Tag::query()->findOrFail($classification->tag_id);
            $classification->axis->assertHolds($tag);

            return $tag;
        }

        $name = (string) $classification->proposed_value;
        $named = PartCategoryDefinition::named($name);

        if ($named->count() > 1) {
            throw CannotClassifyException::ambiguousCategory($name);
        }

        $tag = $named->first();

        if ($tag instanceof Tag) {
            return $tag;
        }

        if (! $actor instanceof Model) {
            throw CannotClassifyException::newCategoryNeedsReview($name);
        }

        return $this->categories->create($name);
    }

    private function replacePrevious(PartClassification $classification, Item $item, ?Model $actor): void
    {
        $previous = PartClassification::query()
            ->confirmed()
            ->where('item_id', $classification->item_id)
            ->where('axis', ClassificationAxis::Category->value)
            ->whereKeyNot($classification->id)
            ->first();

        if (! $previous instanceof PartClassification) {
            return;
        }

        if (! $actor instanceof Model && ! $previous->isConfirmedByRule()) {
            throw CannotClassifyException::personChoseCategory($classification->item_id);
        }

        $item->tags()->detach($previous->tag_id);

        $previous->markVetoed($actor);
        $previous->replaced_by_id = $classification->id;
        $previous->save();
    }
}
