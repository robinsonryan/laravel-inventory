<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RobinsonRyan\Inventory\Support\TagQuery;
use RobinsonRyan\Taxon\TagDefinition;

/**
 * Puts a tag attribute onto report rows after they have been fetched.
 *
 * A tag value lives in the tag tables, not in a column, so no report can select
 * it — taxon can filter by a tag but cannot project one. Every report therefore
 * fetches its rows first and asks for the values in one further query, keyed by
 * whichever id the row already carries.
 *
 * Each field lands twice: `<field>_label` is the wording a person reads and is
 * the column the spreadsheet exporters emit, while `<field>_value` is the stable
 * slug an integration matches on. Wording changes; slugs do not, and a consumer
 * that keys off the label breaks the day someone rewrites it. A row whose record
 * carries no value for the definition gets null for both, so the two keys are
 * always present and a missing tag never reads as an empty string.
 */
final class TagRowValues
{
    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  class-string<Model>  $modelClass
     * @param  class-string<TagDefinition>  $definitionClass
     * @param  string  $idKey  the row key holding the record's id
     * @param  string  $field  the field name to write, without the suffix
     * @return Collection<int, array<string, mixed>>
     */
    public static function attach(
        Collection $rows,
        string $modelClass,
        string $definitionClass,
        string $idKey,
        string $field,
    ): Collection {
        $ids = [];

        foreach ($rows as $row) {
            $id = $row[$idKey] ?? null;

            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        $values = $ids === []
            ? []
            : TagQuery::labelsAndValuesFor($modelClass, $definitionClass, $ids);

        /** @var Collection<int, array<string, mixed>> $mapped */
        $mapped = $rows->map(function (array $row) use ($values, $idKey, $field): array {
            $id = $row[$idKey] ?? null;
            $tag = is_string($id) ? ($values[$id] ?? null) : null;

            $row[$field.'_label'] = $tag['label'] ?? null;
            $row[$field.'_value'] = $tag['value'] ?? null;

            return $row;
        });

        return $mapped;
    }
}
