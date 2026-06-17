<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StationSummaryResource extends JsonResource
{
    /**
     * Disable the default data wrapping.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'station_id' => $this->resource['station_id'],
            'total_approved_amount' => $this->resource['total_approved_amount'],
            'events_count' => $this->resource['events_count'],
        ];
    }
}
