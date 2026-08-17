<?php

namespace App\Jobs;

use App\Models\FinalGracePeriodWarning;
use App\Services\FinalGracePeriodWarningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class SendFinalGracePeriodWarning implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300];
    public int $timeout = 30;

    public function __construct(public string $warningId)
    {
    }

    public function handle(FinalGracePeriodWarningService $warnings): void
    {
        if ($warnings->deliver($this->warningId) === 'retry') {
            throw new RuntimeException('Temporary final grace-period notification delivery failure; retrying the reserved channel.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $warning = FinalGracePeriodWarning::find($this->warningId);
        if (!$warning || in_array($warning->status, ['sent', 'invalid', 'skipped'], true)) {
            return;
        }

        $warning->forceFill([
            'status' => 'failed',
            'failure_reason' => $warning->failure_reason ?: substr((string) $exception?->getMessage(), 0, 500),
        ])->save();
    }
}
