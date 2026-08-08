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
 *   ... the test makes its own attempt, which blocks or is refused ...
 *   $competitor->finish();         // reap the process, surface any failure
 *
 * `awaitLock()` is what makes the tests deterministic rather than a stopwatch
 * race: the test never starts until the competitor has provably taken the lock.
 */
final class CompetingWriter
{
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

        foreach (['', '.committed', '.error'] as $suffix) {
            if (file_exists($marker.$suffix)) {
                unlink($marker.$suffix);
            }
        }

        $payload['marker'] = $marker;

        $command = sprintf(
            '%s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__.'/competing-writer.php'),
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
                throw new RuntimeException(
                    'Competing writer failed: '.file_get_contents($this->marker.'.error'),
                );
            }

            if (file_exists($this->marker)) {
                return;
            }

            usleep(5_000);
        }

        throw new RuntimeException('Competing writer never reported holding its lock.');
    }

    /**
     * Wait for the competitor to exit and clean up its handshake files.
     *
     * @throws RuntimeException if it exited non-zero
     */
    public function finish(): void
    {
        $status = proc_close($this->process);

        $error = file_exists($this->marker.'.error')
            ? (string) file_get_contents($this->marker.'.error')
            : null;

        foreach (['', '.committed', '.error'] as $suffix) {
            if (file_exists($this->marker.$suffix)) {
                unlink($this->marker.$suffix);
            }
        }

        if ($status !== 0) {
            throw new RuntimeException('Competing writer exited with '.$status.': '.($error ?? 'no message'));
        }
    }
}
