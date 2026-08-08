<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Data;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final readonly class ReportData
{
    /**
     * @param  array<int, ColumnDefinition>  $columns
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public string $title,
        public array $columns,
        public Collection $rows,
        public array $summary = [],
        public ?Carbon $generatedAt = null,
    ) {}

    /**
     * Get a column definition by key.
     *
     * @return array{key: string, label: string, type: string, sortable: bool, format: string|null}|null
     */
    public function getColumn(string $key): ?array
    {
        foreach ($this->columns as $column) {
            if ($column->key === $key) {
                return $column->toArray();
            }
        }

        return null;
    }

    /**
     * Get a summary value by key.
     */
    public function getSummary(string $key): mixed
    {
        return $this->summary[$key] ?? null;
    }

    /**
     * Convert the report data to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'columns' => array_map(fn (ColumnDefinition $col): array => $col->toArray(), $this->columns),
            'rows' => $this->rows->toArray(),
            'summary' => $this->summary,
            'generated_at' => $this->generatedAt?->toIso8601String(),
        ];
    }

    /**
     * Get the column keys in order.
     *
     * @return array<int, string>
     */
    public function getColumnKeys(): array
    {
        return array_map(fn (ColumnDefinition $col): string => $col->key, $this->columns);
    }

    /**
     * Get the column labels in order.
     *
     * @return array<int, string>
     */
    public function getColumnLabels(): array
    {
        return array_map(fn (ColumnDefinition $col): string => $col->label, $this->columns);
    }

    /**
     * Check if the report is empty.
     */
    public function isEmpty(): bool
    {
        return $this->rows->isEmpty();
    }
}
