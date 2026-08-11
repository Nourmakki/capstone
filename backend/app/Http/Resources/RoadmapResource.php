<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoadmapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $latest = $this->whenLoaded('versions', fn () => $this->versions->first());

        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'latest_version' => $latest
                ? new RoadmapVersionResource($latest)
                : null,
            'node_count'  => $latest ? $latest->versionNodes->count() : 0,
        ];
    }
}