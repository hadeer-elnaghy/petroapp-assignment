<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\Cache;

final class InMemoryTransferRepository implements TransferRepositoryInterface
{
    private const CACHE_KEY = 'in_memory_transfers';
    private const LOCK_KEY = 'in_memory_transfers_lock';

    /**
     * @inheritDoc
     */
    public function ingestBatch(array $events): array
    {
        $lock = Cache::lock(self::LOCK_KEY, 10);

        return $lock->block(5, function () use ($events): array {
            $transfers = Cache::get(self::CACHE_KEY, []);

            $inserted = 0;
            $duplicates = 0;
            $uniqueIds = array_column($transfers, 'event_id');
            $existingIds = array_combine($uniqueIds, $uniqueIds);

            $uniqueIncoming = [];
            foreach ($events as $event) {
                $eventId = $event['event_id'];
                if (isset($uniqueIncoming[$eventId])) {
                    $duplicates++;
                    continue;
                }
                $uniqueIncoming[$eventId] = $event;
            }

            foreach ($uniqueIncoming as $event) {
                $eventId = $event['event_id'];
                if (isset($existingIds[$eventId])) {
                    $duplicates++;
                } else {
                    $transfers[] = [
                        'event_id' => $event['event_id'],
                        'station_id' => $event['station_id'],
                        'amount' => (float) $event['amount'],
                        'status' => $event['status'],
                        'created_at' => $event['created_at'],
                    ];
                    $existingIds[$eventId] = $eventId;
                    $inserted++;
                }
            }

            Cache::put(self::CACHE_KEY, $transfers);

            return [
                'inserted' => $inserted,
                'duplicates' => $duplicates,
            ];
        });
    }

    /**
     * @inheritDoc
     */
    public function getStationSummary(string $stationId): array
    {
        $transfers = Cache::get(self::CACHE_KEY, []);

        $totalApprovedAmount = 0.0;
        $eventsCount = 0;

        foreach ($transfers as $transfer) {
            if ($transfer['station_id'] === $stationId) {
                $eventsCount++;
                if ($transfer['status'] === 'approved') {
                    $totalApprovedAmount += (float) $transfer['amount'];
                }
            }
        }

        return [
            'station_id' => $stationId,
            'total_approved_amount' => $totalApprovedAmount,
            'events_count' => $eventsCount,
        ];
    }
}
