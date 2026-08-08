<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the Eloquent class backing an inventory concept.
 *
 * Every model in this package is swappable via `config('inventory.models.*')`.
 * Reading that config directly yields `mixed`, which both defeats static
 * analysis of the relations it feeds and lets a typo in a host application's
 * config reach `belongsTo()`/`hasMany()` as a bare string. This resolver
 * validates the configured value and falls back to the package default.
 */
final class ModelResolver
{
    /**
     * Resolve a model class that is contractually a subtype of the package default.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $default
     * @return class-string<TModel>
     */
    public static function resolve(string $key, string $default): string
    {
        $configured = config("inventory.models.{$key}");

        if (! is_string($configured) || ! is_a($configured, $default, true)) {
            return $default;
        }

        /** @var class-string<TModel> $configured */
        return $configured;
    }

    /**
     * Resolve a model class the host application may replace with any model.
     *
     * Used for concepts this package does not own — the catalog item is the
     * common case — where only the base Model contract can be guaranteed.
     *
     * The parameter is deliberately a plain `string` rather than
     * `class-string<Model>`: typing it more precisely lets static analysis infer
     * the *default's* concrete class as the relation's related model, which is
     * exactly the assumption this method exists to avoid.
     *
     * @return class-string<Model>
     */
    public static function resolveAny(string $key, string $default): string
    {
        $configured = config("inventory.models.{$key}");

        $resolved = is_string($configured) && is_a($configured, Model::class, true)
            ? $configured
            : $default;

        /** @var class-string<Model> $resolved */
        return $resolved;
    }
}
