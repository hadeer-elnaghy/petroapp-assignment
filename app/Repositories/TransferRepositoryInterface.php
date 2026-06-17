<?php

declare(strict_types=1);

namespace App\Repositories;

interface TransferRepositoryInterface
{
    /**
     * Ingest a batch of transfer events safely and idempotently.
     *
     * @param array<int, array{
     *     event_id: string,
     *     station_id: string,
     *     amount: float|int,
     *     status: string,
     *     created_at: string
     * }> $events
     * @return array{inserted: int, duplicates: int}
     */
    public function ingestBatch(array $events): array;

    /**
     * Get the reconciliation summary for a specific station.
     *
     * @param string $stationId
     * @return array{station_id: string, total_approved_amount: float, events_count: int}
     */
    public function getStationSummary(string $stationId): array;
}
