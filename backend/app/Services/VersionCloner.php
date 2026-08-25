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
     * @return array<int,int> old version_node id => new version_node id
     */
    public function copy(RoadmapVersion $source, RoadmapVersion $target): array
    {
        $nodeMap = [];

        foreach (VersionNode::where('roadmap_version_id', $source->id)->get() as $node) {
            $clone = VersionNode::create([
                'roadmap_version_id' => $target->id,
                'node_id'            => $node->node_id,
            ]);

            $nodeMap[$node->id] = $clone->id;
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