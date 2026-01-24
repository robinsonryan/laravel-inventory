<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Reporting\Exporters;

use RobinsonRyan\Inventory\Reporting\Contracts\ExporterInterface;
use RobinsonRyan\Inventory\Reporting\Data\ReportData;

final class JsonExporter implements ExporterInterface
{
    public function __construct(
        private readonly bool $prettyPrint = false,
        private readonly bool $includeMetadata = true,
    ) {}

    public function export(ReportData $data): string
    {
        $output = [];

        if ($this->includeMetadata) {
            $output['title'] = $data->title;
            $output['generated_at'] = $data->generatedAt?->toIso8601String();
            $output['columns'] = array_map(fn ($col) => $col->toArray(), $data->columns);
            $output['summary'] = $data->summary;
        }

        // Convert row objects to arrays for clean JSON output
        $output['rows'] = $data->rows->map(fn ($row) => (array) $row)->values()->toArray();

        $flags = JSON_THROW_ON_ERROR;

        if ($this->prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($output, $flags);
    }

    public function getContentType(): string
    {
        return 'application/json';
    }

    public function getFileExtension(): string
    {
        return 'json';
    }
}
