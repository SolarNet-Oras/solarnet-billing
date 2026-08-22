<?php

namespace Tests\Unit;

use App\Services\CustomerUpdateImportService;
use PHPUnit\Framework\TestCase;

class CustomerUpdateImportServiceTest extends TestCase
{
    public function test_name_matching_ignores_case_accents_and_spacing_without_becoming_fuzzy(): void
    {
        $service = new CustomerUpdateImportService();

        $this->assertSame('ninacalim', $service->normalizeCustomerName(' Niña  Calim '));
        $this->assertSame('ninacalim', $service->normalizeCustomerName('NINA-CALIM'));
        $this->assertNotSame('ninacalim', $service->normalizeCustomerName('Nina Calima'));
    }

    public function test_safe_name_variation_accepts_a_unique_surname_extension_or_reordered_name(): void
    {
        $service = new CustomerUpdateImportService();

        $this->assertTrue($service->namesAreSafeVariation('Rueza Jade', 'Rueza Jade Pormida'));
        $this->assertTrue($service->namesAreSafeVariation('Rueza Jade', 'Pormida Rueza Jade'));
        $this->assertFalse($service->namesAreSafeVariation('Rueza', 'Rueza Jade Pormida'));
        $this->assertFalse($service->namesAreSafeVariation('Jade Cruz', 'Rueza Jade Pormida'));
        $this->assertFalse($service->namesAreSafeVariation('Rueza Jade Pormida', 'Rueza Jade'));
    }

    public function test_due_date_import_uses_only_the_calendar_day(): void
    {
        $service = new CustomerUpdateImportService();

        $this->assertSame(5, $service->dueDayFromCell('9/5/2026'));
        $this->assertSame(10, $service->dueDayFromCell(46244));
        $this->assertSame(31, $service->dueDayFromCell('31'));
        $this->assertNull($service->dueDayFromCell('not a date'));
        $this->assertNull($service->dueDayFromCell('32'));
    }
}
