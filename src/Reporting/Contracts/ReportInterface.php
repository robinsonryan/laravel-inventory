<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Contracts;

use RobinsonRyan\Inventory\Reporting\Data\ReportData;

interface ReportInterface
{
    /**
     * Generate the report data.
     */
    public function generate(): ReportData;

    /**
     * Get the report title.
     */
    public function getTitle(): string;

    /**
     * Get the column definitions for this report.
     *
     * @return array<int, array{key: string, label: string, type: string, sortable: bool, format: string|null}>
     */
    public function getColumns(): array;
}
