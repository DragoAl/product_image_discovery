<?php

declare(strict_types=1);

namespace Padosoft\ProductImageDiscovery\Jobs\Concerns;

use BackedEnum;
use Padosoft\ProductImageDiscovery\Enums\ProductImageDiscoveryCandidateStatus;
use Padosoft\ProductImageDiscovery\Enums\ProductImageDiscoveryRejectionReason;
use Padosoft\ProductImageDiscovery\Enums\ProductImageDiscoveryRequestStatus;
use Padosoft\ProductImageDiscovery\Jobs\Contracts\PipelineStoreInterface;
use Padosoft\ProductImageDiscovery\Jobs\SearchProductImageJob;
use Padosoft\ProductImageDiscovery\Services\Logging\ProductImageEventLogger;
use Padosoft\ProductImageDiscovery\Services\Support\SearchEscalation;

trait HandlesRejectedRun
{
    use ScopesCandidatesToSearchRun;

    abstract protected function dispatchIfPossible(object $job): void;

    /**
     * Once every candidate of the current run is rejected by the scorer, search again with the
     * next query family (see SearchEscalation) while the run has queries and steps left; then put
     * the request in manual_review: candidates were found, and a reviewer can still approve one
     * the scorer got wrong (e.g. a retailer page without the code in its title). The decision step
     * only runs for promoted candidates, so nothing else would set the status. While a candidate
     * is still to verify, or one was promoted (the download/quality chain then owns the status),
     * nothing changes.
     */
    private function handleRejectedRun(PipelineStoreInterface $store, ProductImageEventLogger $logger): void
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

        if ($this->escalateSearch($store, $logger, $request, count($candidates), $reasonCounts)) {
            return;
        }

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
     * Start the next step of the run: candidates found so far stay in the run, so a later
     * manual_review still shows all of them. Returns true when the run goes on searching, also
     * when another job of the run already started that step.
     *
     * @param array<string, mixed> $request
     * @param array<string, int> $reasonCounts
     */
    private function escalateSearch(
        PipelineStoreInterface $store,
        ProductImageEventLogger $logger,
        array $request,
        int $candidateCount,
        array $reasonCounts,
    ): bool {
        $search = $request['context']['search'] ?? [];
        $search = is_array($search) ? $search : [];
        $nextStep = SearchEscalation::nextStep($search);

        if ($nextStep === null) {
            return false;
        }

        $run = (int) $search['run'];

        if (! SearchEscalation::claim($this->requestId, $run, $nextStep)) {
            return true;
        }

        $store->updateRequest($this->requestId, [
            'status' => ProductImageDiscoveryRequestStatus::Searching->value,
        ]);
        // execution is replaced, not merged, by the next step: a shorter result list must not
        // keep the tail of this one. The extract guard is reopened for the new results.
        $store->mergeRequestContext($this->requestId, [
            'search' => ['completed_at' => null, 'execution' => null],
            'extract' => ['completed_at' => null],
        ]);

        $logger->record('pipeline.search.escalated', [
            'run' => $run,
            'step' => $nextStep,
            'families_tried' => SearchEscalation::familiesTried(array_values(array_filter($search['executions'] ?? [], 'is_array'))),
            'candidate_count' => $candidateCount,
            'rejection_reasons' => $reasonCounts,
        ], requestId: $this->requestId);

        $this->dispatchIfPossible(new SearchProductImageJob($this->requestId, continuesRun: $run));

        return true;
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
