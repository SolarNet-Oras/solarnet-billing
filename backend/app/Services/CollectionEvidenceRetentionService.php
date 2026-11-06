<?php

namespace App\Services;

use App\Models\CollectionRegistrationEvidence;
use App\Models\CollectionVisit;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;

class CollectionEvidenceRetentionService
{
    public function __construct(private readonly CollectionEvidencePreviewService $previews) {}

    public function purgeVisitPhotosIfSettled(string $customerId): int
    {
        if (Invoice::query()->where('customer_id',$customerId)->unpaid()->exists()) return 0;

        $visits=CollectionVisit::query()
            ->where('customer_id',$customerId)
            ->whereNotNull('photo_path')
            ->get(['id','photo_path']);

        foreach ($visits as $visit) {
            if (filled($visit->photo_path)) Storage::disk('local')->delete($visit->photo_path);
            $this->previews->delete($visit->id);
            $visit->update(['photo_path'=>null]);
        }

        return $visits->count();
    }

    public function purgeRouterLabelPhoto(CollectionRegistrationEvidence $evidence): bool
    {
        $deleted=filled($evidence->router_label_photo_path)
            ? Storage::disk('local')->delete($evidence->router_label_photo_path)
            : false;
        $evidence->update(['router_label_photo_path'=>null]);

        return $deleted;
    }
}
