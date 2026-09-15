<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Concerns\HasImmutableAuditTrail;

describe('HasUuid7', function (): void {
    it('lets PostgreSQL generate the key and hydrates it back onto the model', function (): void {
        $item = Item::factory()->create();

        // Version nibble 7 and variant nibble 8-b: a UUID7 the database made,
        // not a UUID4 or anything PHP minted.
        expect($item->id)->toBeString()
            ->and($item->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i')
            ->and($item->getIncrementing())->toBeTrue()
            ->and($item->getKeyType())->toBe('string');
    });

    it('does not set the key before the insert', function (): void {
        $seen = 'unset';

        Item::creating(function (Item $model) use (&$seen): void {
            $seen = $model->getKey();
        });

        Item::factory()->create();

        expect($seen)->toBeNull();
    });
});

describe('HasImmutableAuditTrail', function (): void {
    it('reports immutability based on config', function (): void {
        config(['inventory.rules.movement_immutable' => true]);
        expect(HasImmutableAuditTrail::isImmutable())->toBeTrue();

        config(['inventory.rules.movement_immutable' => false]);
        expect(HasImmutableAuditTrail::isImmutable())->toBeFalse();
    });
});
