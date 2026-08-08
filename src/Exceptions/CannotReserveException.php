<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Exceptions;

use InvalidArgumentException;

/**
 * A reservation was refused, with the numbers a host application needs to react.
 *
 * Two refusals reach a host through this class and they call for opposite
 * responses, so they are never merged into one message:
 *
 * - There is not enough stock anywhere. Reordering is the answer.
 * - There is enough stock, but no one location holds it all. A reservation is
 *   held against a single stock record, so a quantity spread over two shelves
 *   cannot be held even when the sum covers it. Reordering here would be wrong;
 *   consolidating the stock is the answer.
 *
 * Extends InvalidArgumentException because that is what the reservation service
 * threw before this class existed — code catching the old type still works.
 */
final class CannotReserveException extends InvalidArgumentException
{
    /**
     * @param  float  $availableQuantity  summed across every matching location
     * @param  float|null  $largestLocationQuantity  the fullest single location,
     *                                               set only on the fragmented refusal
     */
    private function __construct(
        string $message,
        public readonly string $itemId,
        public readonly float $requestedQuantity,
        public readonly float $availableQuantity,
        public readonly ?float $largestLocationQuantity = null,
        public readonly ?string $largestLocationId = null,
        public readonly ?string $largestLocationName = null,
    ) {
        parent::__construct($message);
    }

    /**
     * There is not enough unreserved stock for this item anywhere.
     */
    public static function notEnoughStock(string $itemId, float $requested, float $available): self
    {
        return new self(
            sprintf(
                'Insufficient available stock for item %s. Requested: %s, available across all locations: %s.',
                $itemId,
                self::formatQuantity($requested),
                self::formatQuantity($available),
            ),
            $itemId,
            $requested,
            $available,
        );
    }

    /**
     * The stock exists, but it is spread across locations rather than missing.
     *
     * The message deliberately never says "insufficient": claiming a shortage
     * when the units are sitting on the shelves sends a host app off to reorder
     * stock it already owns.
     */
    public static function notAvailableInOneLocation(
        string $itemId,
        float $requested,
        float $available,
        float $largestLocationQuantity,
        string $largestLocationId,
        string $largestLocationName,
    ): self {
        return new self(
            sprintf(
                '%s units of item %s are not available in a single location. Largest available: %s at %s.',
                self::formatQuantity($requested),
                $itemId,
                self::formatQuantity($largestLocationQuantity),
                $largestLocationName,
            ),
            $itemId,
            $requested,
            $available,
            $largestLocationQuantity,
            $largestLocationId,
            $largestLocationName,
        );
    }

    /**
     * Whether the stock exists and is merely spread out, rather than missing.
     */
    public function isFragmented(): bool
    {
        return $this->largestLocationQuantity !== null;
    }

    /**
     * Quantities read as quantities — "60" and "25.5", not "60.0000".
     */
    private static function formatQuantity(float $quantity): string
    {
        $formatted = number_format($quantity, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
