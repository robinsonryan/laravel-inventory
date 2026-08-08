<?php

declare(strict_types=1);

/*
 * A second warehouse terminal, running in its own operating-system process.
 *
 * The concurrency tests need two database connections doing conflicting work at
 * genuinely the same moment. One PHP process cannot do that: the instant the
 * second connection hits a row lock it blocks, and because the first connection
 * lives in the same process there is nobody left to commit and release it. So
 * the competitor is a separate process, driven over a file handshake.
 *
 * It talks to PostgreSQL through plain PDO and boots nothing — no Laravel, no
 * package, no autoloader. That is deliberate. Its job is not to re-run the code
 * under test; it is to put the database into the state a competing caller would
 * leave it in — an uncommitted write holding an exclusive lock on the row the
 * test's own connection is about to want. Any writer produces that state,
 * whatever code it is running, so simulating it in raw SQL is honest.
 *
 * Usage: php competing-writer.php '<json payload>'
 *
 * Payload keys:
 *   host, port, database, username, password  the connection to open
 *   mode        "reserve" (raise a hold on a stock row) or
 *               "fulfill" (close a reservation and consume its stock)
 *   marker      path the process touches once it holds the lock; the test waits
 *               for this file before making its own attempt. On failure it
 *               writes "<marker>.error" instead, so a test never hangs on a
 *               competitor that died.
 *   hold_ms     how long to keep the lock before committing
 *   stock_table, reservations_table
 *   stock_id, quantity                        both modes
 *   reservation_id                            fulfill mode only
 */

$argument = $argv[1] ?? '';

/** @var array<string, mixed> $payload */
$payload = json_decode($argument, true, 512, JSON_THROW_ON_ERROR);

$marker = (string) $payload['marker'];

try {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=%s', $payload['host'], $payload['port'], $payload['database']),
        (string) $payload['username'],
        (string) $payload['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    // Do not wait forever for a lock the test failed to release. Without this a
    // broken test wedges the whole suite instead of failing it.
    $pdo->exec("set lock_timeout = '15s'");

    $stockTable = (string) $payload['stock_table'];
    $quantity = (string) $payload['quantity'];

    $pdo->beginTransaction();

    if ($payload['mode'] === 'reserve') {
        // Exactly the shape of a competing reserve(): claim the row, then raise
        // its hold.
        $claim = $pdo->prepare("select id from {$stockTable} where id = ? for update");
        $claim->execute([$payload['stock_id']]);
        $claim->fetch();

        $hold = $pdo->prepare(
            "update {$stockTable} set reserved_quantity = reserved_quantity + ?, updated_at = now() where id = ?",
        );
        $hold->execute([$quantity, $payload['stock_id']]);
    } else {
        // A competing fulfilment: close the reservation, give its hold back and
        // take the units off the shelf.
        $reservationsTable = (string) $payload['reservations_table'];

        $claim = $pdo->prepare("select id from {$reservationsTable} where id = ? for update");
        $claim->execute([$payload['reservation_id']]);
        $claim->fetch();

        $close = $pdo->prepare(
            "update {$reservationsTable} set fulfilled_at = now(), updated_at = now() where id = ?",
        );
        $close->execute([$payload['reservation_id']]);

        $consume = $pdo->prepare(
            "update {$stockTable}
                set reserved_quantity = reserved_quantity - ?, quantity = quantity - ?, updated_at = now()
              where id = ?",
        );
        $consume->execute([$quantity, $quantity, $payload['stock_id']]);
    }

    // The lock is held from here until the commit below. The test is released to
    // make its own attempt, and must reach its blocking statement inside the
    // hold window.
    file_put_contents($marker, 'held');

    usleep(((int) $payload['hold_ms']) * 1000);

    $pdo->commit();

    file_put_contents($marker.'.committed', 'committed');
} catch (Throwable $failure) {
    file_put_contents($marker.'.error', $failure->getMessage());

    exit(1);
}

exit(0);
