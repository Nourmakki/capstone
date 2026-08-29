<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\VersionNode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Editing the quizzes and projects on a module.
 *
 * Most of this material arrives from the AI, so correcting it is the common
 * case and creating from scratch is the fallback for modules it has not
 * covered. Either way an edit sends the item back for review - approved
 * content that changes has not been approved in its new form.
 */
class MaterialAuthoringController extends Controller
{
    /** Only these can be graded without a human. */
    private const QUESTION_TYPES = ['multiple_choice', 'true_false', 'short_answer'];

    /**
     * How many options each type carries. A multiple choice question with two
     * options is a true/false question wearing the wrong label, and one with
     * five reads badly next to the rest, so the count is fixed rather than
     * left to whoever is typing.
     */
    private const OPTION_COUNT = [
        'multiple_choice' => 4,
        'true_false'      => 2,
    ];

    private const DIFFICULTIES = ['beginner', 'intermediate', 'advanced'];

    /**
     * Everything on one module, with answers included - this is the manager's
     * view, and they need to see what the AI marked correct.
     */
    public function index(VersionNode $versionNode)
    {
        $versionNode->load('node');

        return response()->json([
            'module'   => [
                'version_node_id' => $versionNode->id,
                'title'           => $versionNode->node->title,
                'difficulty'      => $versionNode->node->difficulty,
            ],
            'quizzes'  => $versionNode->quizzes()->with('questions')->get()->map(fn ($quiz) => [
                'id'            => $quiz->id,
                'title'         => $quiz->title,
                'review_status' => $quiz->review_status,
                'review_notes'  => $quiz->review_notes,
                'from_ai'       => $quiz->ai_metadata !== null,
                'questions'     => $quiz->questions->map(fn ($q) => [
                    // Sent back on update so the row survives the edit.
                    'id'          => $q->id,
                    'question'    => $q->question,
                    'type'        => $q->type,
                    'options'     => $q->options ?? [],
                    // Deliberately exposed: the manager is checking it.
                    'answer'      => $q->answer,
                    'explanation' => $q->ai_metadata['correct_answer_explanation'] ?? null,
                ]),
            ]),
            'projects' => $versionNode->projects()->get()->map(fn ($project) => [
                'id'            => $project->id,
                'title'         => $project->title,
                'description'   => $project->description,
                'difficulty'    => $project->difficulty,
                'github_url'    => $project->github_url,
                'review_status' => $project->review_status,
                'review_notes'  => $project->review_notes,
                'from_ai'       => $project->ai_metadata !== null,
            ]),
        ]);
    }

    // -----------------------------------------------------------------
    // Quizzes
    // -----------------------------------------------------------------

    public function storeQuiz(Request $request, VersionNode $versionNode)
    {
        // No quiz yet, so any id sent would correctly fail validation.
        $data = $this->validateQuiz($request);

        $quiz = DB::transaction(function () use ($data, $versionNode) {
            $quiz = Quiz::create([
                'version_node_id' => $versionNode->id,
                'title'           => $data['title'],
                'score'           => 0,
                'review_status'   => 'pending',
            ]);

            $this->syncQuestions($quiz, $data['questions']);

            return $quiz;
        });

        return response()->json([
            'message' => 'Quiz created.',
            'quiz_id' => $quiz->id,
        ], 201);
    }

    /**
     * The whole quiz is sent every time - title and the full question set -
     * so editing, adding and removing a question are all the same call.
     */
    public function updateQuiz(Request $request, Quiz $quiz)
    {
        $data = $this->validateQuiz($request, $quiz);

        DB::transaction(function () use ($data, $quiz) {
            $quiz->update(['title' => $data['title']]);

            // The questions are a separate model, so the quiz row can be
            // untouched while its content has changed completely. Without
            // this an approved quiz would keep its approval after an answer
            // was rewritten.
            $questionsChanged = $this->syncQuestions($quiz, $data['questions']);

            $this->sendBackForReview($quiz, $questionsChanged);
        });

        return response()->json(['message' => 'Quiz updated.']);
    }

    public function destroyQuiz(Quiz $quiz)
    {
        // Attempts point at the quiz; the column is nullable, so a student's
        // score survives even though the quiz it belonged to is gone.
        DB::transaction(function () use ($quiz) {
            QuizQuestion::where('quiz_id', $quiz->id)->delete();
            $quiz->delete();
        });

        return response()->json(['message' => 'Quiz deleted.']);
    }

    private function validateQuiz(Request $request, ?Quiz $quiz = null): array
    {
        return $request->validate([
            'title'                 => ['required', 'string', 'max:255'],
            'questions'             => ['required', 'array', 'min:1'],
            // Present for a question that already exists, absent for a new
            // one. Scoped to this quiz so a payload cannot adopt another
            // quiz's question by guessing an id.
            'questions.*.id'        => [
                'nullable',
                'integer',
                Rule::exists('quiz_questions', 'id')->where(
                    fn ($q) => $q->where('quiz_id', $quiz?->id ?? 0)
                ),
            ],
            'questions.*.question'  => ['required', 'string', 'max:2000'],
            'questions.*.type'      => ['required', Rule::in(self::QUESTION_TYPES)],
            'questions.*.options'   => ['array'],
            'questions.*.options.*' => ['string', 'max:500'],
            'questions.*.answer'    => ['required', 'string', 'max:500'],
        ]);
    }

    /**
     * Update the questions that came back with an id, create the ones that
     * did not, and delete the ones no longer present.
     *
     * Ids are kept rather than rewritten because past submissions store their
     * answers keyed by question id - ProfessorController::quizAnswers joins on
     * it. Replacing the set wholesale would leave every earlier attempt
     * showing no answers at all.
     *
     * @return bool whether anything was actually added, changed or removed
     */
    private function syncQuestions(Quiz $quiz, array $questions): bool
    {
        $kept    = [];
        $changed = false;

        foreach ($questions as $question) {
            $options = array_values(array_filter($question['options'] ?? []));

            // A chooseable question whose answer is not among its options can
            // never be passed, and nothing else would catch it.
            if ($question['type'] !== 'short_answer') {
                $expected = self::OPTION_COUNT[$question['type']];

                // Blanks were stripped above, so a half-filled set of four
                // arrives here as a short one and is caught by the same check.
                if (count($options) !== $expected) {
                    abort(422, "\"{$question['question']}\" needs exactly {$expected} options.");
                }

                $matches = array_filter(
                    $options,
                    fn ($o) => strcasecmp(trim($o), trim($question['answer'])) === 0
                );

                if (empty($matches)) {
                    abort(422, "The answer to \"{$question['question']}\" is not one of its options.");
                }
            }

            $attributes = [
                'question' => $question['question'],
                'type'     => $question['type'],
                'options'  => $question['type'] === 'short_answer' ? null : $options,
                'answer'   => $question['answer'],
            ];

            if (! empty($question['id'])) {
                $existing = QuizQuestion::where('quiz_id', $quiz->id)
                    ->whereKey($question['id'])
                    ->first();

                if ($existing) {
                    $existing->update($attributes);
                    $changed = $changed || $existing->wasChanged();
                    $kept[]  = $existing->id;

                    continue;
                }
            }

            $kept[]  = QuizQuestion::create($attributes + ['quiz_id' => $quiz->id])->id;
            $changed = true;
        }

        // Only what the manager actually removed. Those answers do become
        // unmatchable, which is honest - the question is gone.
        $removed = QuizQuestion::where('quiz_id', $quiz->id)
            ->whereNotIn('id', $kept)
            ->delete();

        return $changed || $removed > 0;
    }

    // -----------------------------------------------------------------
    // Projects
    // -----------------------------------------------------------------

    public function storeProject(Request $request, VersionNode $versionNode)
    {
        $data = $this->validateProject($request, $versionNode);

        $project = Project::create([
            'version_node_id' => $versionNode->id,
            'title'           => $data['title'],
            'description'     => $data['description'],
            'difficulty'      => $data['difficulty'],
            'github_url'      => $data['github_url'] ?? null,
            'review_status'   => 'pending',
        ]);

        return response()->json([
            'message'    => 'Project created.',
            'project_id' => $project->id,
        ], 201);
    }

    public function updateProject(Request $request, Project $project)
    {
        $data = $this->validateProject($request, $project->versionNode);

        $project->update([
            'title'       => $data['title'],
            'description' => $data['description'],
            'difficulty'  => $data['difficulty'],
            'github_url'  => $data['github_url'] ?? null,
        ]);

        $this->sendBackForReview($project);

        return response()->json(['message' => 'Project updated.']);
    }

    public function destroyProject(Project $project)
    {
        $project->delete();

        return response()->json(['message' => 'Project deleted.']);
    }

    private function validateProject(Request $request, ?VersionNode $versionNode): array
    {
        $data = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'difficulty'  => ['nullable', Rule::in(self::DIFFICULTIES)],
            'github_url'  => ['nullable', 'url', 'max:255'],
        ]);

        // The module already carries a difficulty, so default to it rather
        // than making someone restate it.
        $data['difficulty'] ??= $versionNode?->node->difficulty ?? 'beginner';

        return $data;
    }

    // -----------------------------------------------------------------

    /**
     * Approved content that changes has not been approved in its new form.
     */
    private function sendBackForReview($model, bool $alsoChanged = false): void
    {
        if (($model->wasChanged() || $alsoChanged) && $model->review_status === 'approved') {
            $model->update([
                'review_status' => 'pending',
                'reviewed_by'   => null,
                'reviewed_at'   => null,
                'review_notes'  => null,
            ]);
        }
    }
}