<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Stock\Data\ReservationData;

describe('ReservationData', function () {
    it('creates from constructor', function () {
        $data = new ReservationData(
            itemId: 'item-123',
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 25.5,
            locationId: 'loc-456',
            lineId: 'line-001',
            expiryHours: 48,
            notes: 'Urgent request',
        );

        expect($data->itemId)->toBe('item-123')
            ->and($data->jobType)->toBe('work_order')
            ->and($data->jobId)->toBe('WO-001')
            ->and($data->quantity)->toBe(25.5)
            ->and($data->locationId)->toBe('loc-456')
            ->and($data->lineId)->toBe('line-001')
            ->and($data->expiryHours)->toBe(48)
            ->and($data->notes)->toBe('Urgent request');
    });

    it('creates with minimal required fields', function () {
        $data = new ReservationData(
            itemId: 'item-123',
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 10,
        );

        expect($data->itemId)->toBe('item-123')
            ->and($data->locationId)->toBeNull()
            ->and($data->lineId)->toBeNull()
            ->and($data->expiryHours)->toBeNull()
            ->and($data->notes)->toBeNull();
    });

    it('creates from array', function () {
        $data = ReservationData::fromArray([
            'item_id' => 'item-123',
            'job_type' => 'invoice',
            'job_id' => 'INV-001',
            'quantity' => 15,
            'location_id' => 'loc-789',
            'expiry_hours' => 24,
        ]);

        expect($data->itemId)->toBe('item-123')
            ->and($data->jobType)->toBe('invoice')
            ->and($data->jobId)->toBe('INV-001')
            ->and($data->quantity)->toBe(15.0)
            ->and($data->locationId)->toBe('loc-789')
            ->and($data->expiryHours)->toBe(24);
    });

    it('converts to array', function () {
        $data = new ReservationData(
            itemId: 'item-123',
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 25,
            locationId: 'loc-456',
        );

        $array = $data->toArray();

        expect($array)->toHaveKey('item_id', 'item-123')
            ->and($array)->toHaveKey('job_type', 'work_order')
            ->and($array)->toHaveKey('job_id', 'WO-001')
            ->and($array)->toHaveKey('quantity', 25)
            ->and($array)->toHaveKey('location_id', 'loc-456')
            ->and($array)->not->toHaveKey('line_id')
            ->and($array)->not->toHaveKey('expiry_hours')
            ->and($array)->not->toHaveKey('notes');
    });

    it('converts to array with all fields', function () {
        $data = new ReservationData(
            itemId: 'item-123',
            jobType: 'work_order',
            jobId: 'WO-001',
            quantity: 25,
            locationId: 'loc-456',
            lineId: 'line-001',
            expiryHours: 72,
            notes: 'Test notes',
        );

        $array = $data->toArray();

        expect($array)->toHaveKey('line_id', 'line-001')
            ->and($array)->toHaveKey('expiry_hours', 72)
            ->and($array)->toHaveKey('notes', 'Test notes');
    });
});
