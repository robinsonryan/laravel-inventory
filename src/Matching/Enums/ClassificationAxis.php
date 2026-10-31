<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Matching\Enums;

use RobinsonRyan\Inventory\Exceptions\CannotClassifyException;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartCategoryDefinition;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartFitmentDefinition;
use RobinsonRyan\Taxon\Models\Tag;

enum ClassificationAxis: string
{
    case Fitment = 'fitment';
    case Category = 'category';

    /**
     * Refuse a tag that does not belong to this axis, so one named outright
     * cannot land on the part as something it is not.
     *
     * @throws CannotClassifyException
     */
    public function assertHolds(Tag $tag): void
    {
        if ($this === self::Fitment && ! PartFitmentDefinition::contains($tag)) {
            throw CannotClassifyException::unknownFitment($tag->name);
        }

        if ($this === self::Category && ! PartCategoryDefinition::contains($tag)) {
            throw CannotClassifyException::notACategory($tag->name);
        }
    }
}
