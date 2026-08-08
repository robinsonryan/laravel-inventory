<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Concerns\HasImmutableAuditTrail;
use RobinsonRyan\Inventory\Concerns\HasUuid7;

describe('HasUuid7', function (): void {
    it('generates uuid7 format ids', function (): void {
        // Test the newUniqueId method from the trait
        $trait = new class
        {
            use HasUuid7;

            public function generate(): string
            {
                return $this->newUniqueId();
            }
        };

        $id = $trait->generate();

        // UUID7 format: 8-4-4-4-12 characters
        expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
    });

    it('returns string key type', function (): void {
        $trait = new class
        {
            use HasUuid7;
        };

        expect($trait->getKeyType())->toBe('string');
    });

    it('does not use incrementing', function (): void {
        $trait = new class
        {
            use HasUuid7;
        };

        expect($trait->getIncrementing())->toBeFalse();
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
