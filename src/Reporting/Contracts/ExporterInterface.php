<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Contracts;

use RobinsonRyan\Inventory\Reporting\Data\ReportData;

interface ExporterInterface
{
    /**
     * Export the report data to a string.
     */
    public function export(ReportData $data): string;

    /**
     * Get the MIME content type for this exporter.
     */
    public function getContentType(): string;

    /**
     * Get the file extension for this exporter.
     */
    public function getFileExtension(): string;
}
