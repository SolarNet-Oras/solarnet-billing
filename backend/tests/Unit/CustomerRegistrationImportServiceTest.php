<?php

namespace Tests\Unit;

use App\Services\CustomerRegistrationImportService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerRegistrationImportServiceTest extends TestCase
{
    #[Test]
    public function duplicate_name_comparison_ignores_case_accents_spaces_and_punctuation(): void
    {
        $service = app(CustomerRegistrationImportService::class);

        $this->assertSame($service->normalizeName('Niña Calim'), $service->normalizeName(' NINA-CALIM '));
        $this->assertNotSame($service->normalizeName('Niña Calim'), $service->normalizeName('Nina Calimbas'));
    }

    #[Test]
    public function spreadsheet_dates_and_due_days_are_safely_parsed(): void
    {
        $service = app(CustomerRegistrationImportService::class);

        $this->assertSame('2026-08-15', $service->dateFromCell('8/15/2026'));
        $this->assertSame(15, $service->dueDayFromCell('15'));
        $this->assertSame(5, $service->dueDayFromCell('9/5/2026'));
        $this->assertNull($service->dueDayFromCell('32'));
        $this->assertNull($service->dateFromCell('not a date'));
    }
}
