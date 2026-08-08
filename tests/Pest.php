<?php

declare(strict_types=1);

use RobinsonRyan\Inventory\Tests\ConcurrencyTestCase;
use RobinsonRyan\Inventory\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// Concurrency tests run without the per-test transaction, because a race between
// two connections cannot happen inside one. See ConcurrencyTestCase.
uses(ConcurrencyTestCase::class)->in('Concurrency');
