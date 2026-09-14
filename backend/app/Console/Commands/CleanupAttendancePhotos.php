<?php

namespace App\Console\Commands;

use App\Models\StaffAttendancePhotoAudit;
use App\Models\StaffAttendanceRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupAttendancePhotos extends Command
{
    protected $signature = 'attendance:cleanup-photos {--dry-run}';
    protected $description = 'Remove expired private attendance photos without deleting attendance records';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) config('attendance.photo_retention_days', 90)));
        $count = 0;
        StaffAttendanceRecord::query()->where(function ($query) use ($cutoff): void {
            $query->whereNotNull('clock_in_photo_path')->where('clock_in_photo_captured_at', '<', $cutoff)
                ->orWhere(fn ($out) => $out->whereNotNull('clock_out_photo_path')->where('clock_out_photo_captured_at', '<', $cutoff));
        })->chunkById(100, function ($records) use (&$count, $cutoff): void {
            foreach ($records as $record) {
                foreach (['clock_in', 'clock_out'] as $prefix) {
                    $pathField = $prefix.'_photo_path';
                    $capturedField = $prefix.'_photo_captured_at';
                    if (! $record->{$pathField} || ! $record->{$capturedField} || $record->{$capturedField}->gte($cutoff)) continue;
                    $count++;
                    if ($this->option('dry-run')) continue;
                    Storage::disk('local')->delete($record->{$pathField});
                    $record->forceFill([$pathField => null])->save();
                    StaffAttendancePhotoAudit::create(['attendance_record_id'=>$record->id,'actor_id'=>null,'event'=>'photo_retention_deleted','photo_type'=>str_replace('_','-',$prefix),'ip_address'=>null]);
                }
            }
        });
        $this->info(($this->option('dry-run') ? 'Eligible' : 'Deleted')." attendance photo(s): {$count}");
        return self::SUCCESS;
    }
}
