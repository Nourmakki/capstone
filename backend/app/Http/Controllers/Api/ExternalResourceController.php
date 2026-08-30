<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalResources;
use App\Models\VersionNode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The reading list on a module: documentation, articles, courses.
 *
 * Until now these could only arrive through the AI commit path, so a manager
 * building a roadmap by hand got tutorials, quizzes and projects but an empty
 * Resources tab with no way to fill it.
 *
 * Unlike a tutorial, these are editable - a link and a title are things a
 * human can sensibly correct, and Nour's EditableLearningStage agrees:
 * quiz, project and external_resources are editable, tutorial is not.
 */
class ExternalResourceController extends Controller
{
    /** Matches the external_resources.type enum. */
    private const TYPES = ['documentation', 'article', 'course', 'social_account'];

    private function isStaff(Request $request): bool
    {
        return $request->user()->role === 'MaterialManager';
    }

    private function rules(): array
    {
        return [
            'version_node_id' => ['required', 'exists:version_nodes,id'],
            'title'           => ['required', 'string', 'max:255'],
            'url'             => ['required', 'url', 'max:255'],
            'type'            => ['required', Rule::in(self::TYPES)],
        ];
    }

    /**
     * Everything on one module. Students see only approved resources; a
     * manager sees every status, so they can tell what is still waiting.
     */
    public function index(Request $request, $versionNodeId)
    {
        $resources = ExternalResources::where('version_node_id', $versionNodeId)
            ->when(! $this->isStaff($request), fn ($q) => $q->approved())
            ->orderBy('type')
            ->get();

        return response()->json($resources->map(fn ($r) => $this->payload($r)));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        // Nothing reaches a student unreviewed, including a manager's own work.
        $resource = ExternalResources::create($data + ['review_status' => 'pending']);

        return response()->json([
            'message'  => 'Resource added.',
            'resource' => $this->payload($resource),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $resource = ExternalResources::find($id);

        if (! $resource) {
            return response()->json(['message' => 'That resource no longer exists.'], 404);
        }

        $data = $request->validate($this->rules());

        $resource->update($data);

        // Approved content that changed has not been approved in its new form.
        $changed = array_values(array_intersect_key([
            'title' => 'title',
            'url'   => 'link',
            'type'  => 'type',
        ], $resource->getChanges()));

        if ($changed !== [] && $resource->review_status === 'approved') {
            $last = array_pop($changed);
            $text = $changed === [] ? $last : implode(', ', $changed) . ' and ' . $last;

            $resource->sendBackForReview(ucfirst($text) . ' changed.');
        }

        return response()->json([
            'message'  => 'Resource updated.',
            'resource' => $this->payload($resource->fresh()),
        ]);
    }

    public function destroy($id)
    {
        $resource = ExternalResources::find($id);

        if (! $resource) {
            return response()->json(['message' => 'That resource no longer exists.'], 404);
        }

        $resource->delete();

        return response()->json(['message' => 'Resource deleted.']);
    }

    private function payload(ExternalResources $resource): array
    {
        return [
            'id'              => $resource->id,
            'version_node_id' => $resource->version_node_id,
            'title'           => $resource->title,
            'url'             => $resource->url,
            'type'            => $resource->type,
            'review_status'   => $resource->review_status,
            'review_notes'    => $resource->review_notes,
            'from_ai'         => $resource->ai_metadata !== null,
        ];
    }
}
