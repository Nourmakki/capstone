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

        // A parent is a chooser - it has children instead of modules, so the
        // client shows its specializations rather than trying to open it.
        $children = $this->relationLoaded('children')
            ? $this->children
            : collect();

        return [
            'id'                => $this->id,
            'parent_roadmap_id' => $this->parent_roadmap_id,
            // Enough to link back to the roadmap this one branched off.
            // Deliberately not a RoadmapResource: the parent would render its
            // children, one of which is this roadmap.
            'parent'            => $this->relationLoaded('parent') && $this->parent
                ? ['id' => $this->parent->id, 'title' => $this->parent->title]
                : null,
            'children'          => $children->isEmpty()
                ? []
                : RoadmapResource::collection($children),
            'title'          => $this->title,
            'description'    => $this->description,
            'review_status'  => $this->review_status,
            // So an author can see why their roadmap was turned down.
            'review_notes'   => $this->review_notes,
            'latest_version' => $latest
                ? new RoadmapVersionResource($latest)
                : null,
            // Rail modules only. Counting choices would advertise seven
            // modules on a roadmap whose rail has four, and the student would
            // be reading about HTML and CSS as though they were steps.
            'node_count'     => $latest && $latest->relationLoaded('versionNodes')
                ? $latest->versionNodes->whereNull('parent_version_node_id')->count()
                : 0,
        ];
    }
}
