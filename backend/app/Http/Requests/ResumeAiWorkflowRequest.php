<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ResumeAiWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::in([
                    'approve',
                    'edit',
                    'regenerate',
                    'reject',
                ]),
            ],

            'stage' => [
                'nullable',
                'string',
                Rule::in([
                    'trends',
                    'nodes',
                    'placement',
                ]),
            ],

            'data' => [
                'nullable',
                'array',
            ],

            'regenerate_after_edit' => [
                'sometimes',
                'boolean',
            ],

            'feedback' => [
                'nullable',
                'string',
                'min:1',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // ---------------------------------------------------------
                // TOP-LEVEL EXTRA FIELDS
                // ---------------------------------------------------------

                $allowedFields = [
                    'action',
                    'stage',
                    'data',
                    'regenerate_after_edit',
                    'feedback',
                ];

                $unexpectedFields = array_diff(
                    array_keys($this->all()),
                    $allowedFields
                );

                if ($unexpectedFields !== []) {
                    foreach ($unexpectedFields as $field) {
                        $validator->errors()->add(
                            $field,
                            "Unexpected field: {$field}."
                        );
                    }

                    return;
                }

                // ---------------------------------------------------------
                // COMMON VALUES
                // ---------------------------------------------------------

                $action = $this->input('action');
                $stage = $this->input('stage');
                $data = $this->input('data');
                $feedback = $this->input('feedback');

                $regenerateAfterEdit = (bool) $this->input(
                    'regenerate_after_edit',
                    false
                );

                if (
                    $feedback !== null
                    && trim((string) $feedback) === ''
                ) {
                    $validator->errors()->add(
                        'feedback',
                        'Feedback must not be empty.'
                    );

                    return;
                }

                // ---------------------------------------------------------
                // APPROVE
                // ---------------------------------------------------------

                if ($action === 'approve') {
                    if ($stage !== null) {
                        $validator->errors()->add(
                            'stage',
                            'Approve must not contain a stage.'
                        );
                    }

                    if ($data !== null) {
                        $validator->errors()->add(
                            'data',
                            'Approve must not contain edited data.'
                        );
                    }

                    if ($regenerateAfterEdit) {
                        $validator->errors()->add(
                            'regenerate_after_edit',
                            'Approve cannot regenerate outputs after editing.'
                        );
                    }

                    if ($feedback !== null) {
                        $validator->errors()->add(
                            'feedback',
                            'Approve must not contain feedback.'
                        );
                    }

                    return;
                }

                // ---------------------------------------------------------
                // REJECT
                // ---------------------------------------------------------

                if ($action === 'reject') {
                    if ($stage !== null) {
                        $validator->errors()->add(
                            'stage',
                            'Reject must not contain a stage.'
                        );
                    }

                    if ($data !== null) {
                        $validator->errors()->add(
                            'data',
                            'Reject must not contain edited data.'
                        );
                    }

                    if ($regenerateAfterEdit) {
                        $validator->errors()->add(
                            'regenerate_after_edit',
                            'Reject cannot regenerate outputs after editing.'
                        );
                    }

                    return;
                }

                // ---------------------------------------------------------
                // REGENERATE
                // ---------------------------------------------------------

                if ($action === 'regenerate') {
                    if ($stage === null) {
                        $validator->errors()->add(
                            'stage',
                            'Regenerate must contain a stage.'
                        );
                    }

                    if ($data !== null) {
                        $validator->errors()->add(
                            'data',
                            'Regenerate must not contain edited data.'
                        );
                    }

                    if ($regenerateAfterEdit) {
                        $validator->errors()->add(
                            'regenerate_after_edit',
                            'regenerate_after_edit is only used with edit actions.'
                        );
                    }

                    if ($feedback !== null) {
                        $validator->errors()->add(
                            'feedback',
                            'Regenerate must not contain feedback.'
                        );
                    }

                    return;
                }

                // ---------------------------------------------------------
                // EDIT - COMMON RULES
                // ---------------------------------------------------------

                if ($stage === null) {
                    $validator->errors()->add(
                        'stage',
                        'Edit must contain a stage.'
                    );
                }

                if (! is_array($data)) {
                    $validator->errors()->add(
                        'data',
                        'Edit must contain a data object.'
                    );
                }

                if ($feedback !== null) {
                    $validator->errors()->add(
                        'feedback',
                        'Edit must not contain feedback.'
                    );
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // ---------------------------------------------------------
                // EDIT - EXPECTED DATA FIELD FOR EACH STAGE
                // ---------------------------------------------------------

                $expectedFields = match ($stage) {
                    'trends' => [
                        'trends',
                    ],

                    'nodes' => [
                        'node_suggestions',
                    ],

                    'placement' => [
                        'placement_suggestions',
                        'existing_edges_to_remove',
                    ],
                };

                $actualFields = array_keys($data);

                sort($expectedFields);
                sort($actualFields);

                if ($actualFields !== $expectedFields) {
                    $validator->errors()->add(
                        'data',
                        "Edited data does not match the '{$stage}' stage."
                    );

                    return;
                }

                // =========================================================
                // TREND EDIT VALIDATION
                // =========================================================

                if ($stage === 'trends') {
                    $trends = $data['trends'];

                    if (
                        ! is_array($trends)
                        || ! array_is_list($trends)
                    ) {
                        $validator->errors()->add(
                            'data.trends',
                            "'trends' must be a list."
                        );

                        return;
                    }

                    if ($trends === []) {
                        $validator->errors()->add(
                            'data.trends',
                            "'trends' must not be empty."
                        );

                        return;
                    }

                    foreach ($trends as $index => $trend) {
                        if (
                            ! is_array($trend)
                            || array_is_list($trend)
                        ) {
                            $validator->errors()->add(
                                "data.trends.{$index}",
                                'Every trend must be an object.'
                            );

                            continue;
                        }

                        $expectedTrendFields = [
                            'confidence_score',
                            'description',
                            'sources',
                            'title',
                        ];

                        $actualTrendFields = array_keys($trend);

                        sort($expectedTrendFields);
                        sort($actualTrendFields);

                        if (
                            $actualTrendFields
                            !== $expectedTrendFields
                        ) {
                            $validator->errors()->add(
                                "data.trends.{$index}",
                                'Trend contains missing or unexpected fields.'
                            );

                            continue;
                        }

                        if (
                            ! is_string($trend['title'])
                            || trim($trend['title']) === ''
                        ) {
                            $validator->errors()->add(
                                "data.trends.{$index}.title",
                                'Trend title must be a non-empty string.'
                            );
                        }

                        if (
                            ! is_string($trend['description'])
                            || trim($trend['description']) === ''
                        ) {
                            $validator->errors()->add(
                                "data.trends.{$index}.description",
                                'Trend description must be a non-empty string.'
                            );
                        }

                        if (
                            ! is_numeric(
                                $trend['confidence_score']
                            )
                            || $trend['confidence_score'] < 0
                            || $trend['confidence_score'] > 100
                        ) {
                            $validator->errors()->add(
                                "data.trends.{$index}.confidence_score",
                                'Confidence score must be between 0 and 100.'
                            );
                        }

                        $sources = $trend['sources'];

                        if (
                            ! is_array($sources)
                            || ! array_is_list($sources)
                        ) {
                            $validator->errors()->add(
                                "data.trends.{$index}.sources",
                                'Sources must be a list.'
                            );

                            continue;
                        }

                        foreach (
                            $sources
                            as $sourceIndex => $source
                        ) {
                            if (
                                ! is_array($source)
                                || array_is_list($source)
                            ) {
                                $validator->errors()->add(
                                    "data.trends.{$index}.sources.{$sourceIndex}",
                                    'Every source must be an object.'
                                );

                                continue;
                            }

                            $expectedSourceFields = [
                                'source_name',
                                'url',
                            ];

                            $actualSourceFields = array_keys(
                                $source
                            );

                            sort($expectedSourceFields);
                            sort($actualSourceFields);

                            if (
                                $actualSourceFields
                                !== $expectedSourceFields
                            ) {
                                $validator->errors()->add(
                                    "data.trends.{$index}.sources.{$sourceIndex}",
                                    'Source must contain source_name and url only.'
                                );

                                continue;
                            }

                            if (
                                ! is_string(
                                    $source['source_name']
                                )
                                || trim(
                                    $source['source_name']
                                ) === ''
                            ) {
                                $validator->errors()->add(
                                    "data.trends.{$index}.sources.{$sourceIndex}.source_name",
                                    'Source name must be a non-empty string.'
                                );
                            }

                            if (
                                ! is_string($source['url'])
                                || trim($source['url']) === ''
                            ) {
                                $validator->errors()->add(
                                    "data.trends.{$index}.sources.{$sourceIndex}.url",
                                    'Source URL must be a non-empty string.'
                                );
                            }
                        }
                    }

                    return;
                }

                // =========================================================
                // NODE EDIT VALIDATION
                // =========================================================

                if ($stage === 'nodes') {
                    $nodes = $data[
                        'node_suggestions'
                    ];

                    if (
                        ! is_array($nodes)
                        || ! array_is_list($nodes)
                    ) {
                        $validator->errors()->add(
                            'data.node_suggestions',
                            "'node_suggestions' must be a list."
                        );

                        return;
                    }

                    if ($nodes === []) {
                        $validator->errors()->add(
                            'data.node_suggestions',
                            "'node_suggestions' must not be empty."
                        );

                        return;
                    }

                    foreach ($nodes as $index => $node) {
                        if (
                            ! is_array($node)
                            || array_is_list($node)
                        ) {
                            $validator->errors()->add(
                                "data.node_suggestions.{$index}",
                                'Every node suggestion must be an object.'
                            );

                            continue;
                        }

                        $expectedNodeFields = [
                            'based_on_trend',
                            'description',
                            'difficulty',
                            'title',
                        ];

                        $actualNodeFields = array_keys(
                            $node
                        );

                        sort($expectedNodeFields);
                        sort($actualNodeFields);

                        if (
                            $actualNodeFields
                            !== $expectedNodeFields
                        ) {
                            $validator->errors()->add(
                                "data.node_suggestions.{$index}",
                                'Node suggestion contains missing or unexpected fields.'
                            );

                            continue;
                        }

                        if (
                            ! is_string($node['title'])
                            || trim($node['title']) === ''
                        ) {
                            $validator->errors()->add(
                                "data.node_suggestions.{$index}.title",
                                'Node title must be a non-empty string.'
                            );
                        }

                        if (
                            ! is_string(
                                $node['description']
                            )
                            || trim(
                                $node['description']
                            ) === ''
                        ) {
                            $validator->errors()->add(
                                "data.node_suggestions.{$index}.description",
                                'Node description must be a non-empty string.'
                            );
                        }

                        if (
                            ! is_string(
                                $node['difficulty']
                            )
                            || ! in_array(
                                $node['difficulty'],
                                [
                                    'beginner',
                                    'intermediate',
                                    'advanced',
                                ],
                                true
                            )
                        ) {
                            $validator->errors()->add(
                                "data.node_suggestions.{$index}.difficulty",
                                'Difficulty must be beginner, intermediate, or advanced.'
                            );
                        }

                        if (
                            ! is_string(
                                $node['based_on_trend']
                            )
                            || trim(
                                $node['based_on_trend']
                            ) === ''
                        ) {
                            $validator->errors()->add(
                                "data.node_suggestions.{$index}.based_on_trend",
                                'based_on_trend must be a non-empty string.'
                            );
                        }
                    }

                    return;
                }

                // =========================================================
                // PLACEMENT EDIT VALIDATION
                // =========================================================

                $placements = $data[
                    'placement_suggestions'
                ];

                $edgesToRemove = $data[
                    'existing_edges_to_remove'
                ];

                if (
                    ! is_array($placements)
                    || ! array_is_list($placements)
                ) {
                    $validator->errors()->add(
                        'data.placement_suggestions',
                        'Placement suggestions must be a list.'
                    );

                    return;
                }

                if ($placements === []) {
                    $validator->errors()->add(
                        'data.placement_suggestions',
                        'Placement suggestions must not be empty.'
                    );

                    return;
                }

                if (
                    ! is_array($edgesToRemove)
                    || ! array_is_list($edgesToRemove)
                ) {
                    $validator->errors()->add(
                        'data.existing_edges_to_remove',
                        'Existing edges to remove must be a list.'
                    );

                    return;
                }

                foreach (
                    $placements
                    as $index => $placement
                ) {
                    if (
                        ! is_array($placement)
                        || array_is_list($placement)
                    ) {
                        $validator->errors()->add(
                            "data.placement_suggestions.{$index}",
                            'Every placement must be an object.'
                        );

                        continue;
                    }

                    $expectedPlacementFields = [
                        'direct_prerequisite_node_ids',
                        'direct_successor_node_ids',
                        'explanation',
                        'recommended_predecessor_node_ids',
                        'suggested_node_id',
                        'suggested_node_title',
                    ];

                    $actualPlacementFields = array_keys(
                        $placement
                    );

                    sort($expectedPlacementFields);
                    sort($actualPlacementFields);

                    if (
                        $actualPlacementFields
                        !== $expectedPlacementFields
                    ) {
                        $validator->errors()->add(
                            "data.placement_suggestions.{$index}",
                            'Placement contains missing or unexpected fields.'
                        );

                        continue;
                    }

                    if (
                        ! is_int(
                            $placement['suggested_node_id']
                        )
                        || $placement[
                            'suggested_node_id'
                        ] <= 0
                    ) {
                        $validator->errors()->add(
                            "data.placement_suggestions.{$index}.suggested_node_id",
                            'Suggested node ID must be a positive integer.'
                        );
                    }

                    if (
                        ! is_string(
                            $placement[
                                'suggested_node_title'
                            ]
                        )
                        || trim(
                            $placement[
                                'suggested_node_title'
                            ]
                        ) === ''
                    ) {
                        $validator->errors()->add(
                            "data.placement_suggestions.{$index}.suggested_node_title",
                            'Suggested node title must be a non-empty string.'
                        );
                    }

                    if (
                        ! is_string(
                            $placement['explanation']
                        )
                        || trim(
                            $placement['explanation']
                        ) === ''
                    ) {
                        $validator->errors()->add(
                            "data.placement_suggestions.{$index}.explanation",
                            'Explanation must be a non-empty string.'
                        );
                    }

                    $relationshipFields = [
                        'direct_prerequisite_node_ids',
                        'direct_successor_node_ids',
                        'recommended_predecessor_node_ids',
                    ];

                    foreach (
                        $relationshipFields
                        as $fieldName
                    ) {
                        $ids = $placement[
                            $fieldName
                        ];

                        if (
                            ! is_array($ids)
                            || ! array_is_list($ids)
                        ) {
                            $validator->errors()->add(
                                "data.placement_suggestions.{$index}.{$fieldName}",
                                "{$fieldName} must be a list."
                            );

                            continue;
                        }

                        foreach (
                            $ids
                            as $idIndex => $nodeId
                        ) {
                            if (
                                ! is_int($nodeId)
                                || $nodeId <= 0
                            ) {
                                $validator->errors()->add(
                                    "data.placement_suggestions.{$index}.{$fieldName}.{$idIndex}",
                                    'Node ID must be a positive integer.'
                                );
                            }
                        }
                    }

                    // A suggested node must participate in the hard graph.
                    // Recommendations do not create graph edges.
                    if (
                        is_array(
                            $placement[
                                'direct_prerequisite_node_ids'
                            ]
                        )
                        && is_array(
                            $placement[
                                'direct_successor_node_ids'
                            ]
                        )
                        && $placement[
                            'direct_prerequisite_node_ids'
                        ] === []
                        && $placement[
                            'direct_successor_node_ids'
                        ] === []
                    ) {
                        $validator->errors()->add(
                            "data.placement_suggestions.{$index}",
                            'A placement must contain at least one direct prerequisite or direct successor.'
                        );
                    }
                }

                // ---------------------------------------------------------
                // EXISTING EDGES TO REMOVE
                // ---------------------------------------------------------

                foreach (
                    $edgesToRemove
                    as $index => $edge
                ) {
                    if (
                        ! is_array($edge)
                        || array_is_list($edge)
                    ) {
                        $validator->errors()->add(
                            "data.existing_edges_to_remove.{$index}",
                            'Every removed edge must be an object.'
                        );

                        continue;
                    }

                    $expectedEdgeFields = [
                        'from_node_id',
                        'to_node_id',
                    ];

                    $actualEdgeFields = array_keys(
                        $edge
                    );

                    sort($expectedEdgeFields);
                    sort($actualEdgeFields);

                    if (
                        $actualEdgeFields
                        !== $expectedEdgeFields
                    ) {
                        $validator->errors()->add(
                            "data.existing_edges_to_remove.{$index}",
                            'Removed edge must contain from_node_id and to_node_id only.'
                        );

                        continue;
                    }

                    foreach (
                        [
                            'from_node_id',
                            'to_node_id',
                        ]
                        as $fieldName
                    ) {
                        if (
                            ! is_int(
                                $edge[$fieldName]
                            )
                            || $edge[$fieldName] <= 0
                        ) {
                            $validator->errors()->add(
                                "data.existing_edges_to_remove.{$index}.{$fieldName}",
                                "{$fieldName} must be a positive integer."
                            );
                        }
                    }
                }

                if ($regenerateAfterEdit) {
                    $validator->errors()->add(
                        'regenerate_after_edit',
                        'Placement has no downstream output to regenerate.'
                    );
                }
            },
        ];
    }
}