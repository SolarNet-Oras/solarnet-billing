<?php

namespace Tests\Unit;

use App\Services\TechnicianMacMatchService;
use PHPUnit\Framework\TestCase;

class TechnicianMacMatchServiceTest extends TestCase
{
    public function test_it_marks_one_final_mac_character_difference_as_safe_to_correct(): void
    {
        $result = (new TechnicianMacMatchService())->compare('88:65:9F:97:D0:4A', '88:65:9F:97:D0:41');

        $this->assertSame('last_character_correction', $result['type']);
        $this->assertSame(91.7, $result['score']);
        $this->assertSame([11], $result['different_positions']);
        $this->assertSame('88:65:9F:97:D0:41', $result['lease_mac']);
    }

    public function test_it_keeps_a_non_final_one_character_difference_in_confirmation_flow(): void
    {
        $result = (new TechnicianMacMatchService())->compare('88:65:9F:97:D1:41', '88:65:9F:97:D0:41');

        $this->assertSame('fuzzy_90_plus', $result['type']);
        $this->assertSame([9], $result['different_positions']);
    }
}
