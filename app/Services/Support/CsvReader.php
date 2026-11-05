<?php

declare(strict_types=1);

namespace App\Services\Support;

use InvalidArgumentException;
use SplFileObject;

/**
 * Local: read a spreadsheet CSV export — `,` or `;` delimited, optional UTF-8 BOM, blank rows skipped.
 */
final class CsvReader
{
    /**
     * @return array{0: list<string>, 1: list<list<string>>} headers, data rows
     */
    public function read(string $path): array
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException("Cannot read {$path}");
        }

        $file = new SplFileObject($path);
        $firstLine = (string) $file->fgets();
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $file->rewind();
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
        $file->setCsvControl($delimiter, '"', '\\');

        $headers = null;
        $rows = [];

        foreach ($file as $line) {
            if (! is_array($line) || $line === [null]) {
                continue;
            }

            $line = array_values(array_map(fn (mixed $cell): string => trim((string) $cell), $line));

            if ($headers === null) {
                $line[0] = (string) preg_replace('/^\x{FEFF}/u', '', $line[0]); // Excel's UTF-8 BOM
                $headers = $line;

                continue;
            }

            if (implode('', $line) !== '') {
                $rows[] = $line;
            }
        }

        if ($headers === null) {
            throw new InvalidArgumentException('The CSV file is empty.');
        }

        return [$headers, $rows];
    }
}
