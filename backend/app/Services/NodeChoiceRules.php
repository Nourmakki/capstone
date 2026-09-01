<?php

namespace App\Services;

use App\Models\Roadmap;
use App\Models\VersionNode;
use Illuminate\Validation\ValidationException;

/**
 * What makes a node's choices valid, mirroring the AI system's schema.
 *
 * Every rule here has a counterpart in her ChoiceRoadmapNode and RoadmapChoice
 * validators. They are restated rather than referenced because the two halves
 * write to the same tables from different sides - the AI commit path and the
 * manual editor - and a rule enforced on only one of them is not a rule.
 *
 * Kept in step with AI-System/roadmap_workflow/schema.py.
 */
class NodeChoiceRules
{
    /**
     * A choice node must offer a real choice.
     *
     * Her ChoiceRoadmapNode has `min_length=2`: one button is not a choice, it
     * is a module with an extra click in front of it.
     */
    private const MINIMUM_CHOICES = 2;

    /** Roadmaps nest three deep: a topic, its paths, and a path's own roadmap. */
    public const MAX_ROADMAP_DEPTH = 3;

    /**
     * Check one node's choices as they are being edited.
     *
     * Only what must hold at every moment. Her validators see a finished node
     * arrive from the agent in one piece; a manager builds one up a button at
     * a time, and a rule that demands two choices would refuse the first one
     * and make the second unreachable.
     *
     * Completeness - two choices, one of them recommended - is asked at
     * publish time instead, where the other "is this ready" questions live.
     *
     * @throws ValidationException
     */
    public function assertValid(VersionNode $node): void
    {
        $choices = $node->choices()->with('node')->get();

        if ($choices->isEmpty()) {
            return;
        }

        $this->assertUniqueTitles($choices);
        $this->assertAtMostOneRecommended($choices);

        foreach ($choices as $choice) {
            $this->assertRecommendationHasReason($choice);
            $this->assertChoiceIsNotItselfAChoiceNode($choice);
        }
    }

    /**
     * What must be true before students see it.
     *
     * @throws ValidationException
     */
    public function assertReadyToPublish(VersionNode $node): void
    {
        $choices = $node->choices()->with('node')->get();

        if ($choices->isEmpty()) {
            return;
        }

        $title = $node->node->title ?? 'This module';

        if ($choices->count() < self::MINIMUM_CHOICES) {
            throw ValidationException::withMessages([
                'choices' => "\"{$title}\" offers one choice, which is not a choice. "
                    . 'Add another, or move its material onto the module itself.',
            ]);
        }

        // Deliberately "at most one", where her schema says exactly one.
        //
        // Hers models alternatives - React or Vue or Angular - where picking
        // one means skipping the others, so a recommendation is the answer to
        // "which?". Ours also has to carry choices that are parts rather than
        // alternatives: HTML, CSS and JavaScript are all three required, and
        // recommending one of them says nothing.
        //
        // This is a superset of her rule, so anything the agent generates
        // still passes - a node with exactly one recommended is valid here too.
        if ($choices->where('is_recommended', true)->count() > 1) {
            throw ValidationException::withMessages([
                'choices' => "\"{$title}\" has more than one choice marked recommended, "
                    . 'which leaves a student no better off than none.',
            ]);
        }
    }

    /** Her: "Roadmap choices must have unique titles within a choice node." */
    private function assertUniqueTitles($choices): void
    {
        $titles = $choices->map(fn ($c) => mb_strtolower(trim($c->node->title ?? '')));

        if ($titles->unique()->count() !== $titles->count()) {
            throw ValidationException::withMessages([
                'choices' => 'Two choices on this module have the same name. '
                    . 'A student picking between them would have nothing to go on.',
            ]);
        }
    }

    /**
     * Half of her "exactly one recommended": the half that can be checked
     * mid-edit. None yet is fine while the node is being built; two is never
     * fine, and catching it now names the pair that clash.
     */
    private function assertAtMostOneRecommended($choices): void
    {
        $recommended = $choices->where('is_recommended', true);

        if ($recommended->count() <= 1) {
            return;
        }

        throw ValidationException::withMessages([
            'choices' => 'Only one choice can be recommended. These are: '
                . $recommended->map(fn ($c) => $c->node->title)->implode(', ') . '.',
        ]);
    }

    /** Her: a recommended choice must include a recommendation_reason. */
    private function assertRecommendationHasReason(VersionNode $choice): void
    {
        if ($choice->is_recommended && blank($choice->recommendation_reason)) {
            throw ValidationException::withMessages([
                'choices' => "\"{$choice->node->title}\" is the recommended choice, so it needs a "
                    . 'reason saying why - that sentence is the whole value of recommending it.',
            ]);
        }
    }

    /** Choices are one level deep: her schema has no nested choices. */
    private function assertChoiceIsNotItselfAChoiceNode(VersionNode $choice): void
    {
        if ($choice->choices()->exists()) {
            throw ValidationException::withMessages([
                'choices' => "\"{$choice->node->title}\" is a choice, so it cannot offer choices of "
                    . 'its own. Give it a roadmap instead if it needs that much room.',
            ]);
        }
    }

    /**
     * The same rule from the other end, asked before the row is written.
     *
     * Checking the parent's children after the fact does not catch this: the
     * new grandchild has no children of its own, so it looks fine. What is
     * wrong is where it was attached.
     *
     * @throws ValidationException
     */
    public function assertCanHoldChoices(VersionNode $parent): void
    {
        if ($parent->parent_version_node_id !== null) {
            throw ValidationException::withMessages([
                'parent_version_node_id' => "\"{$parent->node->title}\" is itself a choice, so it cannot "
                    . 'offer choices of its own. Give it a roadmap instead if it needs that much room.',
            ]);
        }

        if ($parent->links_to_roadmap_id !== null) {
            throw ValidationException::withMessages([
                'parent_version_node_id' => "\"{$parent->node->title}\" already opens a roadmap, so it has "
                    . 'nowhere to show choices. A module does one or the other.',
            ]);
        }
    }

    /**
     * How deep a roadmap sits: 1 for a topic, 2 for a path, 3 for a path's
     * own roadmap.
     */
    public function depthOf(Roadmap $roadmap): int
    {
        $depth  = 1;
        $parent = $roadmap->parent;

        while ($parent !== null && $depth < 10) {
            $depth++;
            $parent = $parent->parent;
        }

        return $depth;
    }

    /**
     * A node may only hand off to a roadmap while there is a layer left.
     *
     * Her GeneratedChildRoadmap refuses choices with separate_roadmap inside a
     * child roadmap, which is the same rule from the other end: three layers,
     * and the third is the last.
     *
     * @throws ValidationException
     */
    public function assertCanLinkToRoadmap(VersionNode $node, Roadmap $target): void
    {
        $host = $node->roadmapVersion?->roadmap;

        if ($host && $this->depthOf($host) >= self::MAX_ROADMAP_DEPTH) {
            throw ValidationException::withMessages([
                'links_to_roadmap_id' => "\"{$host->title}\" is already a roadmap opened from another "
                    . 'roadmap, and three layers is the limit. Put the material on this module '
                    . 'directly, or offer it as choices.',
            ]);
        }

        if ($host && $target->id === $host->id) {
            throw ValidationException::withMessages([
                'links_to_roadmap_id' => 'A module cannot open the roadmap it is part of.',
            ]);
        }
    }
}
