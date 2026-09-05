<?php

namespace App\Services;

use App\Models\RoadmapVersion;
use App\Models\VersionEdge;
use Illuminate\Support\Collection;

/**
 * The order the modules of a version are read in.
 *
 * The rail's order is the graph's order: a module comes after everything it
 * needs. Nothing stores that as a number, and nothing should - the
 * prerequisite edges already say it, and a stored position would be a second
 * answer to the same question, free to drift from the first.
 *
 * Without this the rail came back in whatever order the rows happened to be
 * written, which is very nearly the order they were created. That looked
 * right for a roadmap built in one go, and for a cloned version, because
 * cloning rewrites every module in the order it reads them. It broke the
 * moment a module was added to an existing roadmap: an AI proposal that
 * placed a module after the second of six wrote its row last, so it read as
 * the seventh, however plainly the placement said otherwise. The edges were
 * right the whole time. Nothing was reading them for position.
 */
class RailOrder
{
    /**
     * prerequisites[node_id] = the node ids that must come before it.
     *
     * For callers that have not already built this from the edges they were
     * loading anyway.
     */
    public function prerequisites(RoadmapVersion $version): array
    {
        $prerequisites = [];

        $edges = VersionEdge::with('edge')
            ->where('roadmap_version_id', $version->id)
            ->get()
            ->pluck('edge')
            ->filter();

        foreach ($edges as $edge) {
            $prerequisites[$edge->to_node_id][] = $edge->from_node_id;
        }

        return $prerequisites;
    }

    /**
     * Rail nodes, in the order a student walks them.
     *
     * Modules come out in waves: everything whose prerequisites are already
     * behind it, then everything that unlocked, and so on. Within a wave the
     * order they arrived in stands, so modules the graph says nothing about
     * relative to each other keep the order they already had - adding one
     * module rearranges nothing else.
     *
     * @param Collection<int,\App\Models\VersionNode> $nodes rail nodes only
     * @param array<int,array<int,int>> $prerequisites keyed by node_id
     * @return Collection<int,\App\Models\VersionNode>
     */
    public function sort(Collection $nodes, array $prerequisites): Collection
    {
        $nodes = $nodes->values();

        if ($nodes->count() < 2) {
            return $nodes;
        }

        // Where each module sits in the list as it arrived. This is what
        // breaks ties, and what keeps the result stable.
        $arrived = [];

        foreach ($nodes as $index => $node) {
            $arrived[$node->node_id] = $index;
        }

        // Only a rail module can hold back another one. An edge to something
        // outside this list - a choice, or a module the version dropped -
        // names a prerequisite that will never be placed, and waiting on it
        // would strand everything behind it at the end of the rail.
        $waitingOn = [];

        foreach ($nodes as $node) {
            $waitingOn[$node->node_id] = array_values(array_filter(
                $prerequisites[$node->node_id] ?? [],
                fn ($id) => isset($arrived[$id]) && $id !== $node->node_id
            ));
        }

        $byNodeId = $nodes->keyBy('node_id');
        $placed   = [];
        $sorted   = [];

        while (count($sorted) < $nodes->count()) {
            $ready = [];

            foreach ($waitingOn as $nodeId => $needs) {
                if (isset($placed[$nodeId])) {
                    continue;
                }

                foreach ($needs as $need) {
                    if (! isset($placed[$need])) {
                        continue 2;
                    }
                }

                $ready[] = $nodeId;
            }

            // Nothing can be placed and modules are left: they depend on each
            // other in a loop. VersionGraphRules refuses to write one, but a
            // version from before that rule should still render - out of
            // order beats not at all - so the rest go out as they arrived.
            if (empty($ready)) {
                foreach ($waitingOn as $nodeId => $needs) {
                    if (! isset($placed[$nodeId])) {
                        $ready[] = $nodeId;
                    }
                }
            }

            usort($ready, fn ($a, $b) => $arrived[$a] <=> $arrived[$b]);

            foreach ($ready as $nodeId) {
                $placed[$nodeId] = true;
                $sorted[] = $byNodeId[$nodeId];
            }
        }

        return collect($sorted);
    }

    /** Both halves at once, for callers with nothing loaded yet. */
    public function forVersion(RoadmapVersion $version, Collection $nodes): Collection
    {
        return $this->sort($nodes, $this->prerequisites($version));
    }
}
