<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportService
{
    public function streamCsv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $this->sanitizeRow($row));
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function streamFromQuery(string $filename, array $headers, Builder $query, callable $rowMapper, int $chunkSize = 500): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $query, $rowMapper, $chunkSize) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            $query->chunk($chunkSize, function ($records) use ($handle, $rowMapper) {
                foreach ($records as $record) {
                    fputcsv($handle, $this->sanitizeRow($rowMapper($record)));
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** @return array<int, mixed> */
    private function sanitizeRow(array $row): array
    {
        return array_map(function (mixed $value): mixed {
            if (is_string($value) && $value !== '' && ! is_numeric($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
                return "'".$value;
            }

            return $value;
        }, $row);
    }
}
