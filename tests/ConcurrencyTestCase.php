<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Tests;

/**
 * The base class for tests that need two connections working at once.
 *
 * Every other test in this package runs inside a transaction that is rolled back
 * at the end, which is what keeps 500-odd tests from treading on each other. It
 * also makes a race impossible to observe: work that has not been committed is
 * invisible to any other connection, so a second connection cannot see the
 * fixture, cannot contend for its rows, and cannot lose to it. A concurrency
 * test that ran inside that transaction would be testing nothing.
 *
 * So this base class drops the transaction. The price is that these tests write
 * for real, into the database every other test shares, and must therefore clean
 * up after themselves by hand — see the `afterEach` in each test file. Nothing
 * else should extend this: an ordinary test that skips the transaction is just a
 * test that leaks.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    /**
     * Deliberately does not open one. See the class docblock.
     */
    public function beginDatabaseTransaction(): void
    {
        //
    }
}
