<?php

namespace App\Services;

use App\Models\RoadmapVersion;
use App\Models\VersionNode;
use Illuminate\Validation\ValidationException;

/**
 * What makes a version fit to publish, in one place.
 *
 * Publishing is the moment students can see a version, and a module that
 * opens onto three empty tabs is worse than one that is not there yet - the
 * student cannot tell "nothing was written" from "something failed to load",
 * and there is nothing for them to do either way.
 *
 * So every module must carry at least one tutorial, one quiz and one project
 * before any of them go live. Sibling of VersionGraphRules, which says what
 * makes the graph valid; this says what makes it ready.
 */
class VersionPublishRules
{
    /**
     * The three tabs a module must have something in. Keyed by relation, so
     * the message can name what is missing in the manager's own words.
     */
    private const REQUIRED = [
        'tutorials' => 'tutorial',
        'quizzes'   => 'quiz',
        'projects'  => 'project',
    ];

    /**
     * Check a version as it now stands.
     *
     * Approved content only: pending material is invisible to students, so a
     * module whose only quiz is awaiting review would publish as an empty tab
     * anyway. "Ready" has to mean ready for the person reading it.
     *
     * @throws ValidationException
     */
    public function assertReadyToPublish(RoadmapVersion $version): void
    {
        $nodes = VersionNode::with('node')
            ->inRail()
            ->withCount([
                'tutorials as approved_tutorials' => fn ($q) => $q->approved(),
                'quizzes as approved_quizzes'     => fn ($q) => $q->approved(),
                'projects as approved_projects'   => fn ($q) => $q->approved(),
            ])
            ->where('roadmap_version_id', $version->id)
            ->get();

        // An empty version has no modules to be incomplete, which would let it
        // slip through the loop below and publish a roadmap leading nowhere.
        if ($nodes->isEmpty()) {
            throw ValidationException::withMessages([
                'status' => 'This version has no modules yet, so there is nothing to publish.',
            ]);
        }

        $incomplete = [];
        $choiceRules = new NodeChoiceRules;

        foreach ($nodes as $versionNode) {
            // A half-built choice node - one button, or none marked
            // recommended - is refused with its own message rather than
            // folded into the missing-material list.
            $choiceRules->assertReadyToPublish($versionNode);

            // A module that hands off to a roadmap, or offers choices, holds
            // no material of its own: the material is on what it opens.
            if ($versionNode->kind() !== 'standard') {
                continue;
            }

            // Announced deliberately, its material still being written. This
            // is the one case where an empty module may go live - and the
            // reason the flag is stored rather than inferred from "has no
            // material", which is also true of one nobody filled in.
            if ($versionNode->coming_soon) {
                continue;
            }

            $missing = [];

            foreach (self::REQUIRED as $relation => $label) {
                if ($versionNode->{'approved_' . $relation} === 0) {
                    $missing[] = $label;
                }
            }

            if ($missing !== []) {
                $title = $versionNode->node->title ?? 'Untitled module';
                $incomplete[] = "{$title} (no " . $this->readableList($missing) . ')';
            }
        }

        if ($incomplete === []) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => 'Every module needs at least one approved tutorial, quiz and '
                . 'project before this version can be published. Still missing: '
                . implode('; ', $incomplete) . '.',
        ]);
    }

    /** "tutorial, quiz and project" rather than a comma-separated list. */
    private function readableList(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' or ' . $last;
    }
}
