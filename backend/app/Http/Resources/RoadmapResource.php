<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoadmapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // whenLoaded() returns a MissingValue object rather than null, so
        // reading through it crashes. Check the relation directly instead:
        // store() returns a fresh model with nothing eager-loaded.
        $latest = $this->relationLoaded('versions')
            ? $this->versions->first()
            : null;

        return [
            'id'             => $this->id,
            'title'          => $this->title,
            'description'    => $this->description,
            'review_status'  => $this->review_status,
            // So an author can see why their roadmap was turned down.
            'review_notes'   => $this->review_notes,
            'latest_version' => $latest
                ? new RoadmapVersionResource($latest)
                : null,
            'node_count'     => $latest && $latest->relationLoaded('versionNodes')
                ? $latest->versionNodes->count()
                : 0,
        ];
    }
}
