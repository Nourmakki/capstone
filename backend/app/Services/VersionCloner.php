<?php

namespace App\Services;

use App\Models\ExternalResources;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\VersionEdge;
use App\Models\VersionNode;

class VersionCloner
{
    /**
     * Copy one version's graph and material into another.
     *
     * Every structural column comes across, not just the node reference.
     * Copying node_id alone flattened the version: a choice became a rail
     * module, a module that opened a roadmap became a dead end, and
     * coming-soon marks were lost. The flattening then failed the graph
     * rules on the way out - three choices with no prerequisites read as
     * three extra places to start - so committing an AI proposal onto any
     * roadmap with a choice on it was refused, blaming the proposal for
     * something the copy had done.
     *
     * @return array<int,int> old version_node id => new version_node id
     */
    public function copy(RoadmapVersion $source, RoadmapVersion $target): array
    {
        $nodeMap = [];
        $sourceNodes = VersionNode::where('roadmap_version_id', $source->id)->get();

        foreach ($sourceNodes as $node) {
            $clone = VersionNode::create([
                'roadmap_version_id'    => $target->id,
                'node_id'               => $node->node_id,
                'links_to_roadmap_id'   => $node->links_to_roadmap_id,
                'stage'                 => $node->stage,
                'stage_title'           => $node->stage_title,
                'is_recommended'        => $node->is_recommended,
                'recommendation_reason' => $node->recommendation_reason,
                'coming_soon'           => $node->coming_soon,
                // parent_version_node_id is deliberately absent here: it
                // points at a sibling row that may not exist yet. Set below,
                // once every clone has an id to point at.
            ]);

            $nodeMap[$node->id] = $clone->id;
        }

        // The choice relationships, remapped into the new version. Copied
        // verbatim they would point at the source version's rows, so the new
        // version's choices would hang off the old version's modules.
        foreach ($sourceNodes as $node) {
            if (! $node->parent_version_node_id) {
                continue;
            }

            $parent = $nodeMap[$node->parent_version_node_id] ?? null;

            if ($parent) {
                VersionNode::whereKey($nodeMap[$node->id])
                    ->update(['parent_version_node_id' => $parent]);
            }
        }

        foreach (VersionEdge::where('roadmap_version_id', $source->id)->get() as $edge) {
            VersionEdge::create([
                'roadmap_version_id' => $target->id,
                'edge_id'            => $edge->edge_id,
            ]);
        }

        $this->copyMaterial($nodeMap);

        return $nodeMap;
    }

    /**
     * Material is keyed to version_node_id, so copying nodes alone would
     * leave every module in the new version empty.
     */
    private function copyMaterial(array $nodeMap): void
    {
        foreach ($nodeMap as $oldNodeId => $newNodeId) {
            foreach (Tutorial::where('version_node_id', $oldNodeId)->get() as $tutorial) {
                $copy = $tutorial->replicate();
                $copy->version_node_id = $newNodeId;
                $copy->save();
            }

            foreach (ExternalResources::where('version_node_id', $oldNodeId)->get() as $resource) {
                $copy = $resource->replicate();
                $copy->version_node_id = $newNodeId;
                $copy->save();
            }

            foreach (Project::where('version_node_id', $oldNodeId)->get() as $project) {
                $copy = $project->replicate();
                $copy->version_node_id = $newNodeId;
                $copy->save();
            }

            foreach (Quiz::where('version_node_id', $oldNodeId)->get() as $quiz) {
                $copy = $quiz->replicate();
                $copy->version_node_id = $newNodeId;
                $copy->save();

                foreach (QuizQuestion::where('quiz_id', $quiz->id)->get() as $question) {
                    $questionCopy = $question->replicate();
                    $questionCopy->quiz_id = $copy->id;
                    $questionCopy->save();
                }
            }
        }
    }
}