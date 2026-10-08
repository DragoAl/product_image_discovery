<?php

declare(strict_types=1);

namespace Padosoft\ProductImageDiscovery\Jobs\Concerns;

use BackedEnum;
use Padosoft\ProductImageDiscovery\Enums\ProductImageDiscoveryCandidateStatus;
use Padosoft\ProductImageDiscovery\Enums\ProductImageDiscoveryRejectionReason;
use Padosoft\ProductImageDiscovery\Enums\ProductImageDiscoveryRequestStatus;
use Padosoft\ProductImageDiscovery\Jobs\Contracts\PipelineStoreInterface;
use Padosoft\ProductImageDiscovery\Services\Logging\ProductImageEventLogger;

trait SendsRejectedRunToReview
{
    use ScopesCandidatesToSearchRun;

    /**
     * Once every candidate of the current run is rejected by the scorer, put the request in
     * manual_review: candidates were found, and a reviewer can still approve one the scorer got
     * wrong (e.g. a retailer page without the code in its title). The decision step only runs for
     * promoted candidates, so nothing else would set the status. While a candidate is still to
     * verify, or one was promoted (the download/quality chain then owns the status), nothing changes.
     */
    private function sendRejectedRunToReview(PipelineStoreInterface $store, ProductImageEventLogger $logger): void
    {
        $request = $store->getRequest($this->requestId);

        if ($request === null) {
            return;
        }

        $candidates = $this->candidatesOfCurrentRun($store, $request);
        $rejectedStatuses = [
            ProductImageDiscoveryCandidateStatus::LowScoreRejected->value,
            ProductImageDiscoveryCandidateStatus::WrongProduct->value,
            ProductImageDiscoveryCandidateStatus::WrongColor->value,
            ProductImageDiscoveryCandidateStatus::Rejected->value,
        ];

        if ($candidates === []) {
            return;
        }

        foreach ($candidates as $candidate) {
            if (! in_array($this->enumValue($candidate['status'] ?? null), $rejectedStatuses, true)) {
                return;
            }
        }

        $reasonCounts = $this->rejectionReasonCounts($candidates);

        // best_candidate_id and final_score may still point to an earlier run; selected_candidate_id
        // is left alone because it may hold a reviewer's choice.
        $store->updateRequest($this->requestId, [
            'status' => ProductImageDiscoveryRequestStatus::ManualReview->value,
            'rejection_reason' => null,
            'best_candidate_id' => null,
            'final_score' => null,
        ]);

        $logger->record('pipeline.verify.all_rejected', [
            'candidate_count' => count($candidates),
            'prevailing_rejection_reason' => array_key_first($reasonCounts),
            'rejection_reasons' => $reasonCounts,
        ], requestId: $this->requestId);
    }

    /**
     * Rejection reasons by frequency, for the audit event: most frequent first; ties keep the order
     * of the candidates, which the store lists best score first. SOURCE_NOT_ALLOWED only means "not
     * a trusted source" (a score penalty, not a block), so it is reported as LOW_CONFIDENCE.
     *
     * @param list<array<string, mixed>> $candidates
     * @return array<string, int>
     */
    private function rejectionReasonCounts(array $candidates): array
    {
        $counts = [];

        foreach ($candidates as $candidate) {
            $reason = ProductImageDiscoveryRejectionReason::tryFrom((string) $this->enumValue($candidate['rejection_reason'] ?? null));

            if ($reason === null || $reason === ProductImageDiscoveryRejectionReason::SourceNotAllowed) {
                $reason = ProductImageDiscoveryRejectionReason::LowConfidence;
            }

            $counts[$reason->value] = ($counts[$reason->value] ?? 0) + 1;
        }

        $order = array_flip(array_keys($counts));
        uksort($counts, static fn (string $a, string $b): int => $counts[$b] <=> $counts[$a] ?: $order[$a] <=> $order[$b]);

        return $counts;
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
