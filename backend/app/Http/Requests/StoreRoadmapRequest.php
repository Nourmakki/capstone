<?php

namespace App\Http\Requests;

use App\Models\Roadmap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRoadmapRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware already restricts this to Admin.
        return true;
    }

    public function rules(): array
    {
        $roadmapId = $this->route('roadmap')?->id;

        return [
            'title' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string',
                'max:255',
                'unique:roadmaps,title' . ($roadmapId ? ",{$roadmapId}" : ''),
            ],
            'description' => [
                $this->isMethod('POST') ? 'required' : 'sometimes',
                'string',
            ],
            // A topic under a broader roadmap: Frontend under Web Development.
            // Null, or absent, means this is a top-level roadmap.
            'parent_roadmap_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:roadmaps,id',
            ],
        ];
    }

    /**
     * The tree is exactly two levels deep, and that is enforced here rather
     * than left to good intentions.
     *
     * Students are shown top-level roadmaps and one level of children; a
     * grandchild appears in neither list, so it would be content nobody can
     * reach - the worst kind of bug, because everything looks like it worked.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $parentId = $this->input('parent_roadmap_id');

                if (blank($parentId)) {
                    return;
                }

                $roadmap = $this->route('roadmap');

                if ($roadmap && (int) $parentId === $roadmap->id) {
                    $validator->errors()->add(
                        'parent_roadmap_id',
                        'A roadmap cannot be a topic of itself.'
                    );

                    return;
                }

                $parent = Roadmap::find($parentId);

                if ($parent && $parent->parent_roadmap_id !== null) {
                    $validator->errors()->add(
                        'parent_roadmap_id',
                        "\"{$parent->title}\" is itself a topic, so it cannot hold topics of its own. "
                            . 'Pick the roadmap above it instead.'
                    );

                    return;
                }

                // Demoting a roadmap that already has topics would push those
                // topics to a third level without anyone asking for it.
                if ($roadmap && $roadmap->children()->exists()) {
                    $validator->errors()->add(
                        'parent_roadmap_id',
                        "\"{$roadmap->title}\" already has topics of its own, so it cannot become a topic. "
                            . 'Move or delete its topics first.'
                    );
                }
            },
        ];
    }
}