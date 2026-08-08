<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RobinsonRyan\Taxon\TagDefinition;
use RuntimeException;

/**
 * Reads taxon tag attributes from a raw query builder.
 *
 * `category`, `type` and `status` read like columns on the models that declare
 * them, but they are tags: a row in `taggables` pointing at a child of the
 * definition's tag. The reports are built on `DB::table()` rather than Eloquent,
 * so they cannot use the magic attribute accessor and cannot `select` these
 * names. This class gives them the two operations they actually need — filter by
 * a value, and attach values to rows already fetched — without any report
 * hand-writing a tag join.
 *
 * Filtering delegates to taxon's own `withTagIn` scope rather than reimplementing
 * it, so the pivot's scope columns and the definition's parent/child shape stay
 * taxon's business.
 */
final class TagQuery
{
    /**
     * A subquery of the ids whose tag attribute holds the given value.
     *
     * Intended for `->whereIn("items.id", TagQuery::filterIdsByTagValue(...))`.
     * The value is slugified by taxon, so callers may pass display spelling.
     *
     * @param  class-string<Model>  $modelClass  a model using RobinsonRyan\Taxon\HasTags
     * @param  class-string<TagDefinition>  $definitionClass
     */
    public static function filterIdsByTagValue(string $modelClass, string $definitionClass, string $value): QueryBuilder
    {
        $model = new $modelClass;

        if (! method_exists($model, 'scopeWithTagIn')) {
            throw new InvalidArgumentException(
                "Model {$modelClass} does not use RobinsonRyan\\Taxon\\HasTags and has no tag attributes.",
            );
        }

        $query = $model->newQuery();
        $model->scopeWithTagIn($query, $definitionClass::$slug, $value);

        return $query->toBase()->select($model->getQualifiedKeyName());
    }

    /**
     * The display label and stored value of a tag attribute, for many records at once.
     *
     * One query, keyed by model id. An id whose record carries no value for this
     * definition is simply absent from the result — the caller decides what an
     * untagged record should read as.
     *
     * @param  class-string<Model>  $modelClass
     * @param  class-string<TagDefinition>  $definitionClass
     * @param  iterable<array-key, string>  $modelIds
     * @return array<string, array{label: string, value: string}>
     */
    public static function labelsAndValuesFor(string $modelClass, string $definitionClass, iterable $modelIds): array
    {
        $ids = [];

        foreach ($modelIds as $modelId) {
            $ids[] = $modelId;
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $tags = TaxonTables::tags();
        $pivot = TaxonTables::taggables();

        // Two passes over the tag table: once for the value tag carried by the
        // pivot row, once for its parent — which is what pins the row to this
        // definition rather than to any other tag the record happens to carry.
        $rows = DB::table($pivot)
            ->join($tags.' as tag_value', $pivot.'.tag_id', '=', 'tag_value.id')
            ->join($tags.' as tag_definition', 'tag_value.parent_id', '=', 'tag_definition.id')
            ->where($pivot.'.taggable_type', (new $modelClass)->getMorphClass())
            ->whereIn($pivot.'.taggable_id', $ids)
            ->where('tag_definition.slug', $definitionClass::$slug)
            ->whereNull('tag_definition.parent_id')
            ->select([
                $pivot.'.taggable_id as model_id',
                'tag_value.name as label',
                'tag_value.slug as value',
            ])
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $row = (array) $row;

            // Dropping a row here would hand the caller a map that is short, or
            // empty, with nothing to say why — a whole column of a report blank
            // and no error anywhere. This map is keyed by string id and holds
            // string wording, so anything else is a structural mismatch between
            // the host's tables and what this package reads, and it is said out
            // loud.
            $map[self::stringOrFail($row, 'model_id', $modelClass)] = [
                'label' => self::stringOrFail($row, 'label', $modelClass),
                'value' => self::stringOrFail($row, 'value', $modelClass),
            ];
        }

        return $map;
    }

    /**
     * Read one column of a tag row, refusing anything that is not a string.
     *
     * @param  array<string, mixed>  $row
     * @param  class-string<Model>  $modelClass
     */
    private static function stringOrFail(array $row, string $column, string $modelClass): string
    {
        $value = $row[$column] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException(sprintf(
                'Tag row for %s has a non-string "%s" (%s). Inventory keys tag lookups by string id, '
                .'so check the id type of the taggables table.',
                $modelClass,
                $column,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
