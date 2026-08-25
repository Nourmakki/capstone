<?php

namespace App\Services;

use App\Models\Submission;
use InvalidArgumentException;

/**
 * Translates the AI project assessment into the three columns a submission
 * has for it.
 *
 * The agent returns a structured judgement - one entry per acceptance
 * criterion, with evidence, plus bonus categories, strengths, improvements
 * and constraint violations. The database has ai_score and ai_feedback, so
 * the structure is flattened into readable text here.
 *
 * That flattening is lossy: the per-criterion breakdown becomes prose and
 * stops being queryable. Keeping it intact needs an ai_metadata JSON column,
 * which is a schema change and a separate decision.
 *
 * Mirrors AI-System/learning_workflow/tools/assessment_scoring.py.
 */
class AiGradingService
{
    /** Must stay in step with STATUS_SCORE_VALUES on the Python side. */
    private const STATUS_VALUES = [
        'met'            => 1.0,
        'mostly_met'     => 0.75,
        'partially_met'  => 0.5,
        'minimally_met'  => 0.25,
        'not_met'        => 0.0,
        'not_verifiable' => 0.0,
    ];

    private const STATUS_LABELS = [
        'met'            => 'met',
        'mostly_met'     => 'mostly met',
        'partially_met'  => 'partially met',
        'minimally_met'  => 'minimally met',
        'not_met'        => 'not met',
        'not_verifiable' => 'could not verify',
    ];

    /**
     * @param array      $assessment ProjectAssessmentResult, as returned by the agent
     * @param array|null $score      the calculate_assessment_score() block, recomputed if absent
     */
    public function applyAssessment(Submission $submission, array $assessment, ?array $score = null): Submission
    {
        $criteria = $assessment['criterion_assessments'] ?? [];

        if (empty($criteria)) {
            throw new InvalidArgumentException('The assessment has no criterion results, so it cannot be scored.');
        }

        $score ??= $this->calculateScore($assessment);

        $submission->update([
            // Rounded, not cast: (int) 87.99 truncates to 87.
            'ai_score'       => (int) round($score['total_score']),
            'ai_feedback'    => $this->composeFeedback($assessment, $score),
            'ai_reviewed_at' => now(),
            // The judgement as it arrived. ai_feedback is a readable summary
            // of this; anything that needs the structure reads it from here.
            'ai_metadata'    => [
                'assessment' => $assessment,
                'score'      => $score,
            ],
        ]);

        return $submission->fresh();
    }

    /**
     * Only used when the agent sends the assessment without its score block.
     */
    public function calculateScore(array $assessment): array
    {
        $criteria = $assessment['criterion_assessments'] ?? [];

        $total = 0.0;

        foreach ($criteria as $criterion) {
            $status = $criterion['status'] ?? 'not_verifiable';
            $total += self::STATUS_VALUES[$status] ?? 0.0;
        }

        $base = count($criteria) > 0 ? ($total / count($criteria)) * 100 : 0.0;

        $bonus = (int) ($assessment['meaningful_extra_features']['score'] ?? 0)
               + (int) ($assessment['creativity_and_innovation']['score'] ?? 0);

        return [
            'base_score'      => round($base, 2),
            'bonus_score'     => $bonus,
            'total_score'     => round($base + $bonus, 2),
            'max_base_score'  => 100,
            'max_bonus_score' => 10,
        ];
    }

    /**
     * The per-criterion verdicts are the useful part for a student, so they
     * lead. Everything else follows in the order it is worth reading.
     */
    private function composeFeedback(array $assessment, array $score): string
    {
        $lines = [];

        if (! empty($assessment['overall_feedback'])) {
            $lines[] = trim($assessment['overall_feedback']);
            $lines[] = '';
        }

        $base  = $score['base_score'] ?? 0;
        $bonus = $score['bonus_score'] ?? 0;

        $lines[] = $bonus > 0
            ? "Criteria {$base}/100, bonus +{$bonus}."
            : "Criteria {$base}/100.";
        $lines[] = '';

        $lines[] = 'How each requirement was judged';

        foreach ($assessment['criterion_assessments'] ?? [] as $criterion) {
            $label = self::STATUS_LABELS[$criterion['status'] ?? ''] ?? $criterion['status'] ?? '';
            $lines[] = "- {$criterion['criterion']} - {$label}";

            if (! empty($criterion['feedback'])) {
                $lines[] = "  {$criterion['feedback']}";
            }
        }

        foreach ([
            'strengths'             => 'What worked',
            'improvements'          => 'What to improve',
            'constraint_violations' => 'Requirements broken',
        ] as $key => $heading) {
            $items = array_filter($assessment[$key] ?? []);

            if (empty($items)) {
                continue;
            }

            $lines[] = '';
            $lines[] = $heading;

            foreach ($items as $item) {
                $lines[] = "- {$item}";
            }
        }

        foreach ([
            'meaningful_extra_features' => 'Extra features',
            'creativity_and_innovation' => 'Creativity',
        ] as $key => $heading) {
            $category = $assessment[$key] ?? null;

            if (! $category || empty($category['explanation'])) {
                continue;
            }

            $lines[] = '';
            $lines[] = "{$heading} (+{$category['score']}): {$category['explanation']}";
        }

        return implode("\n", $lines);
    }
}
