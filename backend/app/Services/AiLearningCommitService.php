<?php

namespace App\Services;

use App\Models\ExternalResources;
use App\Models\NodeLearningWorkflow;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\Tutorial;
use App\Models\VersionNode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes one approved learning-workflow run into the module it belongs to.
 *
 * The agents return more than these tables have columns for, so each mapping
 * flattens what the app reads and keeps the original in ai_metadata. Nothing
 * is discarded.
 */
class AiLearningCommitService
{
    /**
     * Her quiz agent produces four question categories, all four-option
     * multiple choice. Our quiz_questions.type drives auto-grading and only
     * knows three values, so every category maps to multiple_choice and the
     * original category is kept in ai_metadata.
     */
    private const QUESTION_TYPE = 'multiple_choice';

    /**
     * Her resource vocabulary and ours share no values, so the mapping has to
     * be explicit. Anything unrecognised becomes an article.
     */
    private const RESOURCE_TYPES = [
        'official_documentation' => 'documentation',
        'technical_reference'    => 'documentation',
        'github_repository'      => 'article',
    ];

    /**
     * @throws RuntimeException when the run carries nothing this can write
     */
    public function commit(NodeLearningWorkflow $workflow, array $data): array
    {
        $versionNode = $workflow->versionNode;

        if (! $versionNode) {
            throw new RuntimeException('That module no longer exists.');
        }

        $created = DB::transaction(function () use ($versionNode, $data) {
            return [
                'tutorial'  => $this->commitTutorial($versionNode, $data['tutorial_result'] ?? null),
                'quiz'      => $this->commitQuiz($versionNode, $data['quiz_result'] ?? null),
                'project'   => $this->commitProject($versionNode, $data['project_result'] ?? null),
                'resources' => $this->commitResources($versionNode, $data['external_resources_result'] ?? null),
            ];
        });

        // A commit that wrote nothing is a failure, and it used to be reported
        // as a success: every mapper below returns 0 for a shape it does not
        // recognise, so a payload that had changed - a renamed status, a
        // missing key - committed four zeros, marked the run done, and
        // answered "Material added to the module". The manager then went
        // looking for material that was never written, with the run now
        // marked committed so it could not be retried.
        //
        // Said out loud instead, with what the run actually contained, and
        // the run left uncommitted so it can be committed again once the
        // cause is fixed.
        if (array_sum($created) === 0) {
            throw new RuntimeException(
                'Nothing could be taken from this run: ' . $this->explainEmpty($data)
                . ' The run has been left open, so it can be committed again once that is resolved.'
            );
        }

        $workflow->update([
            'status'       => 'committed',
            'committed_at' => now(),
        ]);

        return $created;
    }

    /**
     * Why a run yielded nothing, in terms of what it held.
     *
     * Guessing is worse than useless here - the manager needs to know whether
     * the AI produced nothing or whether we failed to read what it produced.
     */
    private function explainEmpty(array $data): string
    {
        if ($data === []) {
            return 'it carried no results at all.';
        }

        $notes = [];

        $tutorial = $data['tutorial_result'] ?? null;

        if (! $tutorial) {
            $notes[] = 'no tutorial result';
        } elseif (($tutorial['status'] ?? null) !== 'ready') {
            // The one status this service reads. Her schema allows "ready" and
            // "learning_failed"; anything else means her wording moved.
            $notes[] = 'the tutorial reports status "' . ($tutorial['status'] ?? 'none') . '"';
        } elseif (empty($tutorial['tutorial'])) {
            $notes[] = 'the tutorial result holds no video';
        }

        if (empty($data['quiz_result']['questions'] ?? [])) {
            $notes[] = 'no quiz questions';
        }

        if (empty($data['project_result']['title'] ?? null)) {
            $notes[] = 'no project';
        }

        if (empty($data['external_resources_result']['resources'] ?? [])) {
            $notes[] = 'no external resources';
        }

        return $notes === []
            ? 'its results were present but empty.'
            : implode(', ', $notes) . '.';
    }

    private function commitTutorial(VersionNode $versionNode, ?array $result): int
    {
        // The agent reports learning_failed when it cannot find a good video.
        if (! $result || ($result['status'] ?? null) !== 'ready' || empty($result['tutorial'])) {
            return 0;
        }

        $tutorial = $result['tutorial'];
        $coverage = $result['coverage'] ?? null;

        Tutorial::create([
            'version_node_id' => $versionNode->id,
            'title'           => $tutorial['title'],
            'url'             => $tutorial['url'],
            // She returns no description; the coverage summary is what a
            // student would want to read, and the reason is the fallback.
            'description'     => $coverage['summary'] ?? $tutorial['selection_reason'],
            'source_name'     => $tutorial['channel_name'],
            'review_status'   => 'pending',
            'ai_metadata'     => $result,
        ]);

        return 1;
    }

    private function commitQuiz(VersionNode $versionNode, ?array $result): int
    {
        $questions = $result['questions'] ?? [];

        if (empty($questions)) {
            return 0;
        }

        $quiz = Quiz::create([
            'version_node_id' => $versionNode->id,
            // She returns no quiz title, and the module's name is the honest one.
            'title'           => $versionNode->node->title . ' Quiz',
            'score'           => 0,
            'review_status'   => 'pending',
            'ai_metadata'     => ['tutorial_resource_id' => $result['tutorial_resource_id'] ?? null],
        ]);

        foreach ($questions as $question) {
            $options = collect($question['options'] ?? []);

            // Our options column is a list of strings and the answer is the
            // text itself, so the A/B/C/D key has to be resolved here.
            $correct = $options->firstWhere('key', $question['correct_option']);

            if (! $correct) {
                throw new RuntimeException(
                    "Question \"{$question['question']}\" marks option {$question['correct_option']} correct, but no such option was given."
                );
            }

            QuizQuestion::create([
                'quiz_id'     => $quiz->id,
                'question'    => $question['question'],
                'type'        => self::QUESTION_TYPE,
                'options'     => $options->pluck('text')->all(),
                'answer'      => $correct['text'],
                // Holds correct_answer_explanation, which gives the answer
                // away - QuizQuestion keeps ai_metadata hidden for that reason.
                'ai_metadata' => $question,
            ]);
        }

        return count($questions);
    }

    private function commitProject(VersionNode $versionNode, ?array $result): int
    {
        if (! $result || empty($result['title'])) {
            return 0;
        }

        Project::create([
            'version_node_id' => $versionNode->id,
            'title'           => $result['title'],
            'description'     => $this->projectBrief($result),
            // She returns no difficulty. The module already has one, and a
            // second copy would only create a way for them to disagree.
            'difficulty'      => $versionNode->node->difficulty,
            'review_status'   => 'pending',
            'ai_metadata'     => $result,
        ]);

        return 1;
    }

    /**
     * Eight structured sections flattened into the one text column the app
     * reads. The structure itself survives in ai_metadata.
     */
    private function projectBrief(array $result): string
    {
        $lines = [$result['overview']];

        if (! empty($result['activity_context'])) {
            $lines[] = '';
            $lines[] = $result['activity_context'];
        }

        if (! empty($result['learning_objective'])) {
            $lines[] = '';
            $lines[] = 'What you will learn';
            $lines[] = $result['learning_objective'];
        }

        foreach ([
            'tasks'                  => 'Tasks',
            'technical_requirements' => 'Requirements',
            'constraints'            => 'Constraints',
            'deliverables'           => 'Deliverables',
            'acceptance_criteria'    => 'Done when',
        ] as $key => $heading) {
            $items = array_filter($result[$key] ?? []);

            if (empty($items)) {
                continue;
            }

            $lines[] = '';
            $lines[] = $heading;

            foreach ($items as $item) {
                $lines[] = "- {$item}";
            }
        }

        return implode("\n", $lines);
    }

    private function commitResources(VersionNode $versionNode, ?array $result): int
    {
        $resources = $result['resources'] ?? [];

        foreach ($resources as $resource) {
            ExternalResources::create([
                'version_node_id' => $versionNode->id,
                'title'           => $resource['title'],
                'url'             => $resource['url'],
                'type'            => self::RESOURCE_TYPES[$resource['resource_type']] ?? 'article',
                'review_status'   => 'pending',
                'ai_metadata'     => $resource,
            ]);
        }

        return count($resources);
    }
}