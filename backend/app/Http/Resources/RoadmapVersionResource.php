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
            'title'          => $this->title,
            // What the UI shows on the version badge.
            'label'          => 'v' . $this->version_number
                . ($this->title ? ' · ' . $this->title : ''),
            'status'         => $this->status,
            'review_status'  => $this->review_status,
            'created_at'     => $this->created_at,
        ];
    }
}