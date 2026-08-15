<?php

namespace App\Services;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/** Parses an Excel historical calendar date without using the current date. */
class HistoricalInstallationDate
{
    public function parse(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }

            $date = trim((string) $value);
            foreach (['Y-m-d', 'n/j/Y', 'm/d/Y'] as $format) {
                $parsed = Carbon::createFromFormat('!'.$format, $date);
                if ($parsed && $parsed->format($format) === $date) {
                    return $parsed->toDateString();
                }
            }
        } catch (\Throwable) {
            // Historical source dates must be reviewed, never replaced with today.
        }

        return null;
    }
}
