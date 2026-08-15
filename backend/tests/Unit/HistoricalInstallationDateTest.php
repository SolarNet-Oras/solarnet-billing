<?php

namespace Tests\Unit;

use App\Services\HistoricalInstallationDate;
use PHPUnit\Framework\TestCase;

class HistoricalInstallationDateTest extends TestCase
{
    public function test_historical_slash_date_is_not_replaced_with_the_migration_date(): void
    {
        $parser = new HistoricalInstallationDate();

        $this->assertSame('2026-08-10', $parser->parse('8/10/2026'));
        $this->assertNotSame('2026-08-15', $parser->parse('8/10/2026'));
    }

    public function test_iso_and_excel_serial_dates_are_calendar_dates(): void
    {
        $parser = new HistoricalInstallationDate();

        $this->assertSame('2026-08-10', $parser->parse('2026-08-10'));
        $this->assertSame('2026-08-10', $parser->parse(46244));
    }

    public function test_missing_or_invalid_historical_dates_require_review(): void
    {
        $parser = new HistoricalInstallationDate();

        $this->assertNull($parser->parse(null));
        $this->assertNull($parser->parse('not a date'));
    }
}
