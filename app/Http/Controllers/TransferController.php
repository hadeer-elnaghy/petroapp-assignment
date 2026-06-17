<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransferRequest;
use App\Http\Resources\StationSummaryResource;
use App\Repositories\TransferRepositoryInterface;
use Illuminate\Http\JsonResponse;

final class TransferController extends Controller
{
    public function __construct(
        private readonly TransferRepositoryInterface $transferRepository
    ) {}

    /**
     * Handle the ingestion of station transfer events.
     *
     * @param StoreTransferRequest $request
     * @return JsonResponse
     */
    public function store(StoreTransferRequest $request): JsonResponse
    {
        $result = $this->transferRepository->ingestBatch(
            $request->validated()['events']
        );

        return response()->json($result);
    }

    /**
     * Get the reconciliation summary for a specific station.
     *
     * @param string $stationId
     * @return StationSummaryResource
     */
    public function summary(string $stationId): StationSummaryResource
    {
        $summary = $this->transferRepository->getStationSummary($stationId);

        return new StationSummaryResource($summary);
    }
}
