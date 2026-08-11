<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoadmapVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'roadmap_id'     => $this->roadmap_id,
            'version_number' => $this->version_number,
            'status'         => $this->status,
        ];
    }
}