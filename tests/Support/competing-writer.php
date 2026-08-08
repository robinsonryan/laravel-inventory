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
 * Usage: php competing-writer.php '<marker path>' '<json payload>'
 *
 * The marker path is its own argument rather than a payload key, and everything
 * else — including decoding the payload — happens inside the try below. A
 * competitor that dies on a malformed payload can still write "<marker>.error",
 * so the parent fails in milliseconds with the real reason instead of waiting
 * out its whole timeout on a handshake that is never coming.
 *
 * Marker files:
 *   <marker>            touched once the process holds its lock; the test waits
 *                       for this before making its own attempt
 *   <marker>.committed  touched after the commit; a test reads this to prove it
 *                       reached its own blocking statement while the lock was
 *                       still held, rather than after the competitor let go
 *   <marker>.error      written instead of either on any failure
 *
 * Payload keys:
 *   host, port, database, username, password  the connection to open
 *   mode        "reserve" (raise a hold on a stock row),
 *               "fulfill" (close a reservation and consume its stock), or
 *               "release" (close a reservation and hand its hold back)
 *   hold_ms     how long to keep the lock before committing
 *   stock_table, reservations_table
 *   stock_id, quantity                        every mode
 *   reservation_id                            fulfill and release modes
 */

$marker = $argv[1] ?? '';

if ($marker === '') {
    fwrite(STDERR, 'competing-writer.php needs a marker path as its first argument.'.PHP_EOL);

    exit(1);
}

try {
    /** @var array<string, mixed> $payload */
    $payload = json_decode($argv[2] ?? '', true, 512, JSON_THROW_ON_ERROR);

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
        // A competing close. Both shapes claim the reservation first and then
        // touch its stock — the same order every closing path in
        // ReservationService uses.
        //
        //   fulfill: give the hold back and take the units off the shelf
        //   release: give the hold back and leave the units where they are
        $reservationsTable = (string) $payload['reservations_table'];
        $closedColumn = $payload['mode'] === 'release' ? 'cancelled_at' : 'fulfilled_at';

        $claim = $pdo->prepare("select id from {$reservationsTable} where id = ? for update");
        $claim->execute([$payload['reservation_id']]);
        $claim->fetch();

        $close = $pdo->prepare(
            "update {$reservationsTable} set {$closedColumn} = now(), updated_at = now() where id = ?",
        );
        $close->execute([$payload['reservation_id']]);

        $consume = $payload['mode'] === 'release'
            ? $pdo->prepare(
                "update {$stockTable}
                    set reserved_quantity = reserved_quantity - ?, updated_at = now()
                  where id = ?",
            )
            : $pdo->prepare(
                "update {$stockTable}
                    set reserved_quantity = reserved_quantity - ?, quantity = quantity - ?, updated_at = now()
                  where id = ?",
            );

        $consume->execute($payload['mode'] === 'release'
            ? [$quantity, $payload['stock_id']]
            : [$quantity, $quantity, $payload['stock_id']]);
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
