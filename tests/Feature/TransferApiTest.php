<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TransferApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear in-memory cache before each test
        Cache::flush();
    }

    /**
     * 1. Test that batch insert returns correct inserted/duplicates.
     */
    public function test_batch_insert_returns_correct_inserted_and_duplicates(): void
    {
        config(['transfers.storage_driver' => 'database']);

        $payload = [
            'events' => [
                ['event_id' => 'E1', 'station_id' => 'S1', 'amount' => 100.00, 'status' => 'approved', 'created_at' => '2026-02-19T10:00:00Z'],
                ['event_id' => 'E2', 'station_id' => 'S1', 'amount' => 50.00, 'status' => 'approved', 'created_at' => '2026-02-19T11:00:00Z'],
                ['event_id' => 'E2', 'station_id' => 'S1', 'amount' => 50.00, 'status' => 'approved', 'created_at' => '2026-02-19T11:00:00Z'], // internal duplicate
            ]
        ];

        $response = $this->postJson('/transfers', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'inserted' => 2,
                'duplicates' => 1,
            ]);
    }

    /**
     * 2. Test that duplicate events do not change totals.
     */
    public function test_duplicate_event_does_not_change_totals(): void
    {
        config(['transfers.storage_driver' => 'database']);

        // First ingestion of event E1
        $payload1 = [
            'events' => [
                ['event_id' => 'E1', 'station_id' => 'S1', 'amount' => 100.00, 'status' => 'approved', 'created_at' => '2026-02-19T10:00:00Z'],
            ]
        ];
        $this->postJson('/transfers', $payload1)->assertStatus(200);

        // Verify total is 100
        $this->getJson('/stations/S1/summary')
            ->assertStatus(200)
            ->assertJson([
                'total_approved_amount' => 100.00,
                'events_count' => 1,
            ]);

        // Duplicate ingestion of event E1 with different parameters (should be ignored entirely)
        $payload2 = [
            'events' => [
                ['event_id' => 'E1', 'station_id' => 'S1', 'amount' => 500.00, 'status' => 'approved', 'created_at' => '2026-02-19T10:00:00Z'],
            ]
        ];
        $response = $this->postJson('/transfers', $payload2);
        $response->assertStatus(200)
            ->assertJson([
                'inserted' => 0,
                'duplicates' => 1,
            ]);

        // Verify total is still 100 and count is still 1 (idempotency holds, totals unchanged)
        $this->getJson('/stations/S1/summary')
            ->assertStatus(200)
            ->assertJson([
                'total_approved_amount' => 100.00,
                'events_count' => 1,
            ]);
    }

    /**
     * 3. Test that out-of-order arrival still produces same totals.
     */
    public function test_out_of_order_arrival_still_produces_same_totals(): void
    {
        config(['transfers.storage_driver' => 'database']);

        // Ingest events out of order (E2 created at 11:00, then E1 created at 10:00)
        $payload1 = [
            'events' => [
                ['event_id' => 'E2', 'station_id' => 'S1', 'amount' => 50.00, 'status' => 'approved', 'created_at' => '2026-02-19T11:00:00Z'],
            ]
        ];
        $this->postJson('/transfers', $payload1)->assertStatus(200);

        $payload2 = [
            'events' => [
                ['event_id' => 'E1', 'station_id' => 'S1', 'amount' => 100.00, 'status' => 'approved', 'created_at' => '2026-02-19T10:00:00Z'],
            ]
        ];
        $this->postJson('/transfers', $payload2)->assertStatus(200);

        // Verify summary totals are deterministic regardless of arrival order
        $this->getJson('/stations/S1/summary')
            ->assertStatus(200)
            ->assertJson([
                'total_approved_amount' => 150.00,
                'events_count' => 2,
            ]);
    }

    /**
     * 4. Test that concurrent/overlapping ingestion of same IDs doesn't double count.
     */
    public function test_concurrent_ingestion_of_same_ids_does_not_double_count(): void
    {
        config(['transfers.storage_driver' => 'database']);

        // Simulate concurrent insertion by attempting to insert the same batch twice.
        // Thanks to database-level unique constraints and insertOrIgnore transactions,
        // duplicate event_ids are safely ignored.
        $payload = [
            'events' => [
                ['event_id' => 'E1', 'station_id' => 'S1', 'amount' => 100.00, 'status' => 'approved', 'created_at' => '2026-02-19T10:00:00Z'],
            ]
        ];

        // Hit 1
        $response1 = $this->postJson('/transfers', $payload);
        $response1->assertStatus(200)->assertJson(['inserted' => 1, 'duplicates' => 0]);

        // Hit 2 (concurrent overlap simulation)
        $response2 = $this->postJson('/transfers', $payload);
        $response2->assertStatus(200)->assertJson(['inserted' => 0, 'duplicates' => 1]);

        // Verify total approved amount is still only counted once
        $this->getJson('/stations/S1/summary')
            ->assertStatus(200)
            ->assertJson([
                'total_approved_amount' => 100.00,
                'events_count' => 1,
            ]);
    }

    /**
     * 5. Test summary endpoint correctness per station.
     */
    public function test_summary_endpoint_correctness_per_station(): void
    {
        config(['transfers.storage_driver' => 'database']);

        $payload = [
            'events' => [
                ['event_id' => 'E1', 'station_id' => 'S1', 'amount' => 100.00, 'status' => 'approved', 'created_at' => '2026-02-19T10:00:00Z'],
                ['event_id' => 'E2', 'station_id' => 'S1', 'amount' => 50.00, 'status' => 'pending', 'created_at' => '2026-02-19T11:00:00Z'],
                ['event_id' => 'E3', 'station_id' => 'S2', 'amount' => 200.00, 'status' => 'approved', 'created_at' => '2026-02-19T12:00:00Z'], // different station
            ]
        ];

        $this->postJson('/transfers', $payload)->assertStatus(200);

        // Check S1
        $this->getJson('/stations/S1/summary')
            ->assertStatus(200)
            ->assertJson([
                'station_id' => 'S1',
                'total_approved_amount' => 100.00, // E1 approved. E2 is pending.
                'events_count' => 2, // E1 + E2
            ]);

        // Check S2
        $this->getJson('/stations/S2/summary')
            ->assertStatus(200)
            ->assertJson([
                'station_id' => 'S2',
                'total_approved_amount' => 200.00,
                'events_count' => 1,
            ]);
    }

    /**
     * 6. Test validation failure behavior (fail-fast: reject whole batch).
     */
    public function test_validation_failure_behavior_fail_fast(): void
    {
        config(['transfers.storage_driver' => 'database']);

        $payload = [
            'events' => [
                ['event_id' => 'E1', 'station_id' => 'S1', 'amount' => 100.00, 'status' => 'approved', 'created_at' => '2026-02-19T10:00:00Z'],
                ['event_id' => 'E2', 'station_id' => 'S1', 'amount' => -50.00, 'status' => 'approved', 'created_at' => '2026-02-19T11:00:00Z'], // invalid negative amount
            ]
        ];

        // Validation fails, whole batch must be rejected (fail-fast)
        $response = $this->postJson('/transfers', $payload);

        $response->assertStatus(400)
            ->assertJsonStructure(['message', 'errors']);

        // Verify that even the valid event E1 was NOT inserted
        $this->assertDatabaseMissing('transfers', ['event_id' => 'E1']);
    }

    /**
     * Test that non-ISO8601 dates are rejected.
     */
    public function test_rejects_non_iso8601_dates(): void
    {
        config(['transfers.storage_driver' => 'database']);

        $payload = [
            'events' => [
                ['event_id' => 'E1', 'station_id' => 'S1', 'amount' => 100.00, 'status' => 'approved', 'created_at' => '2026-02-19 10:00:00'], // not ISO8601 (missing T and timezone)
            ]
        ];

        $response = $this->postJson('/transfers', $payload);

        $response->assertStatus(400)
            ->assertJsonValidationErrors('events.0.created_at');
    }

    /**
     * Extra: Verify that the flow works identically for the In-Memory storage driver.
     */
    public function test_in_memory_driver_flow_conforms_to_contract(): void
    {
        config(['transfers.storage_driver' => 'in_memory']);

        $payload = [
            'events' => [
                ['event_id' => 'E1', 'station_id' => 'S1', 'amount' => 100.00, 'status' => 'approved', 'created_at' => '2026-02-19T10:00:00Z'],
                ['event_id' => 'E2', 'station_id' => 'S1', 'amount' => 50.00, 'status' => 'pending', 'created_at' => '2026-02-19T11:00:00Z'],
                ['event_id' => 'E1', 'station_id' => 'S1', 'amount' => 100.00, 'status' => 'approved', 'created_at' => '2026-02-19T10:00:00Z'], // duplicate
            ]
        ];

        // Ingestion
        $this->postJson('/transfers', $payload)
            ->assertStatus(200)
            ->assertJson([
                'inserted' => 2,
                'duplicates' => 1,
            ]);

        // Summary
        $this->getJson('/stations/S1/summary')
            ->assertStatus(200)
            ->assertJson([
                'station_id' => 'S1',
                'total_approved_amount' => 100.00,
                'events_count' => 2,
            ]);
    }
}
