<?php

namespace App\Http\Requests;

use App\Models\Roadmap;
use App\Services\NodeChoiceRules;
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
     * The tree is three levels deep at most, and that is enforced here rather
     * than left to good intentions.
     *
     *   Web Development        a subject, holding paths
     *     Frontend             a path, with modules of its own
     *       React              a module too big to be one module
     *
     * Three is the AI system's limit too: GeneratedChildRoadmap refuses a
     * child roadmap that itself contains a separate_roadmap choice. A fourth
     * level would have nowhere to be shown and nothing to generate it.
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

                if (! $parent) {
                    return;
                }

                $rules = new NodeChoiceRules;

                // The parent's depth plus this one. A parent already at the
                // limit has no room beneath it.
                if ($rules->depthOf($parent) >= NodeChoiceRules::MAX_ROADMAP_DEPTH) {
                    $validator->errors()->add(
                        'parent_roadmap_id',
                        "\"{$parent->title}\" is already three levels down, which is as deep as "
                            . 'roadmaps go. Put this material on a module there instead.'
                    );

                    return;
                }

                // Moving a roadmap carries its own topics with it, so the
                // deepest of them has to still fit underneath the new parent.
                if ($roadmap) {
                    $ownDepth = $this->subtreeDepth($roadmap);

                    if ($rules->depthOf($parent) + $ownDepth > NodeChoiceRules::MAX_ROADMAP_DEPTH) {
                        $validator->errors()->add(
                            'parent_roadmap_id',
                            "\"{$roadmap->title}\" has topics of its own, and moving it under "
                                . "\"{$parent->title}\" would push them past three levels. "
                                . 'Move or delete its topics first.'
                        );
                    }
                }
            },
        ];
    }

    /** How many levels a roadmap carries beneath it, itself counted as one. */
    private function subtreeDepth(Roadmap $roadmap): int
    {
        $deepest = 1;

        foreach ($roadmap->children as $child) {
            $deepest = max($deepest, 1 + $this->subtreeDepth($child));
        }

        return $deepest;
    }
}