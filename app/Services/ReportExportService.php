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
                fputcsv($handle, $row);
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
                    fputcsv($handle, $rowMapper($record));
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
