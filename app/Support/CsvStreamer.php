<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams Excel-friendly CSV downloads (FIN-12).
 *
 * Writes a UTF-8 BOM (so Excel renders currency symbols / Urdu correctly) and an
 * optional header block (business name, report title, period, branch) before the
 * data. The $writer callback receives the open file handle and emits rows via fputcsv.
 */
class CsvStreamer
{
    /**
     * @param array<int, array<int, string|int|float|null>> $headerBlock  rows printed before a blank separator
     * @param callable(resource): void                       $writer       emits the data rows
     */
    public static function download(string $filename, array $headerBlock, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($headerBlock, $writer) {
            $fp = fopen('php://output', 'w');

            fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM

            foreach ($headerBlock as $line) {
                fputcsv($fp, $line);
            }
            if (! empty($headerBlock)) {
                fputcsv($fp, []);
            }

            $writer($fp);

            fclose($fp);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Standard header block for a finance statement.
     *
     * @return array<int, array<int, string>>
     */
    public static function financeHeader(string $title, array $meta = []): array
    {
        $business = app()->bound('tenant') ? (app('tenant')->business_name ?? '') : '';

        $block = [];
        if ($business !== '') {
            $block[] = [$business];
        }
        $block[] = [$title];
        foreach ($meta as $label => $value) {
            $block[] = [$label, (string) $value];
        }
        $block[] = ['Generated', now()->format('Y-m-d H:i')];

        return $block;
    }
}
