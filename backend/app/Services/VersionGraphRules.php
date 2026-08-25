<?php

namespace App\Services;

use App\Models\RoadmapVersion;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use Illuminate\Validation\ValidationException;

/**
 * What makes a version's graph valid, in one place.
 *
 * Both the manual editor and the AI commit have to agree on this, so the
 * rules live here rather than being restated in each.
 */
class VersionGraphRules
{
    /**
     * Check a version as it now stands. Callers run this at the end of a
     * transaction so a rejected change rolls back.
     *
     * @throws ValidationException
     */
    public function assertValid(RoadmapVersion $version): void
    {
        $nodeIds = VersionNode::where('roadmap_version_id', $version->id)
            ->pluck('node_id');

        if ($nodeIds->isEmpty()) {
            return;
        }

        $edges = VersionEdge::with('edge')
            ->where('roadmap_version_id', $version->id)
            ->get()
            ->pluck('edge')
            ->filter();

        // prerequisites[x] = the nodes that must come before x
        $prerequisites = [];

        foreach ($edges as $edge) {
            $prerequisites[$edge->to_node_id][] = $edge->from_node_id;
        }

        $this->assertAcyclic($nodeIds->all(), $prerequisites);
        $this->assertSingleRoot($version, $nodeIds->all(), $prerequisites);
    }

    /**
     * A loop would leave every module in it permanently locked, since none
     * of them could ever have its prerequisites met.
     */
    private function assertAcyclic(array $nodeIds, array $prerequisites): void
    {
        foreach ($nodeIds as $start) {
            $seen  = [];
            $stack = $prerequisites[$start] ?? [];

            while ($stack) {
                $current = array_pop($stack);

                if ($current === $start) {
                    throw ValidationException::withMessages([
                        'prerequisite_node_ids' => 'That would create a loop - these modules would depend on each other and none could ever be unlocked.',
                    ]);
                }

                if (isset($seen[$current])) {
                    continue;
                }

                $seen[$current] = true;

                foreach ($prerequisites[$current] ?? [] as $parent) {
                    $stack[] = $parent;
                }
            }
        }
    }

    /**
     * Exactly one module may have no prerequisites.
     *
     * A module can still open several others - branching is fine. What is not
     * allowed is a second place to *start*, which would split the roadmap into
     * disconnected pieces and leave the map view with no single origin to draw
     * from.
     */
    private function assertSingleRoot(RoadmapVersion $version, array $nodeIds, array $prerequisites): void
    {
        $roots = array_values(array_filter(
            $nodeIds,
            fn ($id) => empty($prerequisites[$id])
        ));

        if (count($roots) === 1) {
            return;
        }

        // No root at all means every module is inside a loop, which the cycle
        // check above has already rejected - but be explicit rather than
        // reporting "0 starting modules" as if it were the same problem.
        if (count($roots) === 0) {
            throw ValidationException::withMessages([
                'prerequisite_node_ids' => 'Every module depends on another one, so the roadmap has no starting point.',
            ]);
        }

        $titles = VersionNode::with('node')
            ->where('roadmap_version_id', $version->id)
            ->whereIn('node_id', $roots)
            ->get()
            ->map(fn ($vn) => $vn->node->title)
            ->implode(', ');

        throw ValidationException::withMessages([
            'prerequisite_node_ids' => 'A roadmap must have exactly one starting module. '
                . "These have no prerequisites: {$titles}. "
                . 'Give all but one of them a prerequisite.',
        ]);
    }
}
