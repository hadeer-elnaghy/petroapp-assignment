<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Transfer;
use Illuminate\Support\Facades\DB;

final class DatabaseTransferRepository implements TransferRepositoryInterface
{
    /**
     * @inheritDoc
     */
    public function ingestBatch(array $events): array
    {
        $uniqueIncoming = [];
        $internalDuplicatesCount = 0;

        foreach ($events as $event) {
            $eventId = $event['event_id'];
            if (isset($uniqueIncoming[$eventId])) {
                $internalDuplicatesCount++;
                continue;
            }
            $uniqueIncoming[$eventId] = $event;
        }

        if (empty($uniqueIncoming)) {
            return [
                'inserted' => 0,
                'duplicates' => $internalDuplicatesCount,
            ];
        }

        $insertedCount = DB::transaction(function () use ($uniqueIncoming): int {
            $records = array_map(function (array $event): array {
                return [
                    'event_id' => $event['event_id'],
                    'station_id' => $event['station_id'],
                    'amount' => $event['amount'],
                    'status' => $event['status'],
                    'created_at' => $event['created_at'],
                ];
            }, array_values($uniqueIncoming));

            return Transfer::insertOrIgnore($records);
        });

        $externalDuplicatesCount = count($uniqueIncoming) - $insertedCount;
        $totalDuplicates = $internalDuplicatesCount + $externalDuplicatesCount;

        return [
            'inserted' => $insertedCount,
            'duplicates' => $totalDuplicates,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getStationSummary(string $stationId): array
    {
        $summary = Transfer::where('station_id', $stationId)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) as total_approved_amount,
                COUNT(*) as events_count
            ")
            ->first();

        return [
            'station_id' => $stationId,
            'total_approved_amount' => (float) ($summary?->total_approved_amount ?? 0.0),
            'events_count' => (int) ($summary?->events_count ?? 0),
        ];
    }
}
