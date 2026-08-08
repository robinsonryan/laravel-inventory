<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Tests\Support;

use RuntimeException;

/**
 * Runs `competing-writer.php` in its own process and hands the test a handshake.
 *
 * The shape every concurrency test uses:
 *
 *   $competitor = CompetingWriter::start([...]);
 *   $competitor->awaitLock();      // the competitor now holds the row, uncommitted
 *   expect($competitor->hasCommitted())->toBeFalse();
 *   ... the test makes its own attempt, which blocks or is refused ...
 *   $competitor->finish();         // reap the process, surface any failure
 *
 * `awaitLock()` is what makes the tests deterministic rather than a stopwatch
 * race: the test never starts until the competitor has provably taken the lock.
 * `hasCommitted()` closes the other half of that window — see its docblock.
 */
final class CompetingWriter
{
    /**
     * The handshake files this competitor may leave behind, by suffix.
     */
    private const MARKER_SUFFIXES = ['', '.committed', '.error'];

    private bool $closed = false;

    /**
     * @param  resource  $process
     */
    private function __construct(
        private $process,
        private readonly string $marker,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  see competing-writer.php
     */
    public static function start(array $payload): self
    {
        $marker = sys_get_temp_dir().'/inventory-competitor-'.bin2hex(random_bytes(8));

        foreach (self::MARKER_SUFFIXES as $suffix) {
            if (file_exists($marker.$suffix)) {
                unlink($marker.$suffix);
            }
        }

        // The marker is its own argument so that a competitor which cannot even
        // decode its payload can still report the failure. See competing-writer.php.
        $command = sprintf(
            '%s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__.'/competing-writer.php'),
            escapeshellarg($marker),
            escapeshellarg(json_encode($payload, JSON_THROW_ON_ERROR)),
        );

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the competing writer process.');
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return new self($process, $marker);
    }

    /**
     * Block until the competitor holds its row lock.
     *
     * @throws RuntimeException if the competitor died, or never got the lock
     */
    public function awaitLock(int $timeoutMs = 20_000): void
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            if (file_exists($this->marker.'.error')) {
                $this->fail('Competing writer failed: '.file_get_contents($this->marker.'.error'));
            }

            if (file_exists($this->marker)) {
                return;
            }

            // A competitor that died without writing anything — a parse error, a
            // missing extension, a signal — would otherwise be waited out for the
            // full timeout before the test failed for the wrong reason.
            if (! $this->isRunning()) {
                $this->fail('Competing writer exited before it reported holding its lock.');
            }

            usleep(5_000);
        }

        $this->fail('Competing writer never reported holding its lock.');
    }

    /**
     * Has the competitor already committed and let its lock go?
     *
     * Tests assert this is false immediately before their own contended attempt.
     * Without that, a slow test process can reach its locking statement after the
     * hold window has closed: the attempt then runs uncontended, still passes, and
     * has quietly stopped proving anything about concurrency at all.
     */
    public function hasCommitted(): bool
    {
        return file_exists($this->marker.'.committed');
    }

    /**
     * Wait for the competitor to exit and clean up its handshake files.
     *
     * @throws RuntimeException if it exited non-zero
     */
    public function finish(): void
    {
        $status = $this->close();

        $error = file_exists($this->marker.'.error')
            ? (string) file_get_contents($this->marker.'.error')
            : null;

        $this->cleanUpMarkers();

        if ($status !== 0) {
            throw new RuntimeException('Competing writer exited with '.$status.': '.($error ?? 'no message'));
        }
    }

    /**
     * Last resort for a test that failed between `start()` and `finish()`.
     *
     * A failing expectation aborts the test before `finish()` runs, which would
     * otherwise leave a live child process and three files in the temp directory.
     * Marker names are random per competitor, so a stray file could never make a
     * later test pass falsely — this is tidiness, not correctness.
     */
    public function __destruct()
    {
        if (! $this->closed && $this->isRunning()) {
            // Abort its transaction rather than waiting out the hold: the test is
            // already over, and the locks it holds are in nobody's way but the
            // next test's.
            proc_terminate($this->process);
        }

        $this->close();
        $this->cleanUpMarkers();
    }

    /**
     * Reap the process, returning its exit status. Safe to call twice.
     */
    private function close(): int
    {
        if ($this->closed) {
            return 0;
        }

        $this->closed = true;

        return proc_close($this->process);
    }

    private function isRunning(): bool
    {
        // proc_get_status caches the exit code on the handle, so consulting it
        // here does not stop proc_close() from reporting the real status later.
        return (bool) proc_get_status($this->process)['running'];
    }

    private function cleanUpMarkers(): void
    {
        foreach (self::MARKER_SUFFIXES as $suffix) {
            if (file_exists($this->marker.$suffix)) {
                unlink($this->marker.$suffix);
            }
        }
    }

    /**
     * @throws RuntimeException
     */
    private function fail(string $message): never
    {
        $this->close();
        $this->cleanUpMarkers();

        throw new RuntimeException($message);
    }
}
