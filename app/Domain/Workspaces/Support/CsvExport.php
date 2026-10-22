<?php

namespace App\Domain\Workspaces\Support;

use League\Csv\EscapeFormula;
use League\Csv\Writer;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<int|string|null>>  $rows
     */
    public static function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $stream = fopen('php://output', 'w');

            if ($stream === false) {
                throw new RuntimeException('Unable to open the CSV export stream.');
            }

            $csv = Writer::createFromStream($stream);
            $csv->addFormatter(new EscapeFormula);
            $csv->insertOne($headers);

            foreach ($rows as $row) {
                $csv->insertOne($row);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
