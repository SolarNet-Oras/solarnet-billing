<?php

namespace Tests\Unit;

use App\Models\StaffAttendanceRecord;
use PHPUnit\Framework\TestCase;

class StaffAttendanceRecordTest extends TestCase
{
    public function test_timezone_less_punches_are_read_as_utc(): void
    {
        $record = new StaffAttendanceRecord();
        $record->setRawAttributes([
            'clocked_in_at' => '2026-09-26 00:20:35',
            'clocked_out_at' => '2026-09-26 09:03:56',
        ]);

        $this->assertSame('2026-09-26 00:20:35', $record->clocked_in_at->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $record->clocked_in_at->timezoneName);
        $this->assertSame('08:20:35 AM', $record->clocked_in_at->copy()->timezone('Asia/Manila')->format('h:i:s A'));
        $this->assertSame('05:03:56 PM', $record->clocked_out_at->copy()->timezone('Asia/Manila')->format('h:i:s A'));
        $this->assertSame(523, $record->clocked_in_at->diffInMinutes($record->clocked_out_at));
    }
}
