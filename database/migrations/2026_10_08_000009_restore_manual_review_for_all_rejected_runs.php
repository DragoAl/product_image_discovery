<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Padosoft\ProductImageDiscovery\Services\Support\SearchRun;

return new class extends Migration
{
    /**
     * v1.2.1 closed requests whose candidates were all rejected as no_candidates_found with a
     * rejection_reason, although candidates were found: put them back in manual_review. Requests
     * where the search really found nothing never get a rejection_reason, and requests whose
     * latest run has no candidate are left alone.
     */
    public function up(): void
    {
        DB::table('product_image_discovery_requests')
            ->where('status', 'no_candidates_found')
            ->whereNotNull('rejection_reason')
            ->select(['id', 'raw_payload'])
            ->chunkById(200, function ($requests): void {
                foreach ($requests as $request) {
                    $rawPayload = is_string($request->raw_payload) ? json_decode($request->raw_payload, true) : $request->raw_payload;
                    $searchRun = SearchRun::current($rawPayload);

                    if ($searchRun === null) {
                        continue;
                    }

                    $hasCandidates = DB::table('product_image_discovery_candidates')
                        ->where('request_id', $request->id)
                        ->where('search_run', $searchRun)
                        ->exists();

                    if (! $hasCandidates) {
                        continue;
                    }

                    DB::table('product_image_discovery_requests')
                        ->where('id', $request->id)
                        ->update([
                            'status' => 'manual_review',
                            'rejection_reason' => null,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Data repair only: nothing to undo.
    }
};
