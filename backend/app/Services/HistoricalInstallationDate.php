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
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
                return $this->calendarDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
            }
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $matches)) {
                return $this->calendarDate((int) $matches[3], (int) $matches[1], (int) $matches[2]);
            }
        } catch (\Throwable) {
            // Historical source dates must be reviewed, never replaced with today.
        }

        return null;
    }

    private function calendarDate(int $year, int $month, int $day): ?string
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::create($year, $month, $day, 0, 0, 0, 'Asia/Manila')->toDateString();
    }
}
