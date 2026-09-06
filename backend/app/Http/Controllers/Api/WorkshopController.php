<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompletedNode;
use App\Models\Student;
use App\Models\UserRoadmapProgress;
use App\Models\VersionNode;
use App\Models\Workshop;
use Illuminate\Http\Request;

class WorkshopController extends Controller
{
    /**
     * Every upcoming workshop.
     */
    public function index()
    {
        $workshops = Workshop::with('versionNode.node')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn ($w) => $this->payload($w));

        return response()->json(['workshops' => $workshops]);
    }

    /**
     * Workshops matched to what the student is studying and where they are.
     */
    public function recommended(Request $request)
    {
        $user = $request->user();

        // Non-students have no progress; they still get location matching.
        $student = Student::where('user_id', $user->id)->first();

        $country = $this->countryFrom($user->location);

        // Nodes across every roadmap version this student has started.
        $versionIds = $student
            ? UserRoadmapProgress::where('student_id', $student->id)->pluck('roadmap_version_id')
            : collect();

        $nodeIds = VersionNode::whereIn('roadmap_version_id', $versionIds)->pluck('id');

        // A student who has not completed a node yet has no progress rows.
        // Filtering by topic there would hide everything, so fall back to
        // matching on location alone until they start a roadmap.
        $matchByTopic = $nodeIds->isNotEmpty();

        $query = Workshop::with('versionNode.node')
            ->where('scheduled_at', '>=', now());

        if ($matchByTopic) {
            $query->where(function ($q) use ($nodeIds) {
                $q->whereIn('version_node_id', $nodeIds)
                  ->orWhereNull('version_node_id');
            });
        }

        $workshops = $query->orderBy('scheduled_at')
            ->get()
            // Online workshops (no country) are relevant to everyone.
            ->filter(fn ($w) => $w->country === null || $country === null || $w->country === $country)
            ->map(fn ($w) => $this->payload($w))
            ->values();

        return response()->json([
            'country'        => $country,
            'matched_by'     => $matchByTopic ? 'topic_and_location' : 'location',
            'has_progress'   => $matchByTopic,
            'workshops'      => $workshops,
        ]);
    }

    /**
     * "Beirut, Lebanon" -> "Lebanon". Free text, so this is a heuristic.
     */
    private function countryFrom(?string $location): ?string
    {
        if (! $location) {
            return null;
        }

        $parts = array_map('trim', explode(',', $location));

        return end($parts) ?: null;
    }

    private function payload(Workshop $workshop): array
    {
        return [
            'id'               => $workshop->id,
            'title'            => $workshop->title,
            'description'      => $workshop->description,
            'scheduled_at'     => $workshop->scheduled_at,
            'duration_minutes' => $workshop->duration_minutes,
            'location'         => $workshop->location,
            'country'          => $workshop->country,
            'is_online'        => $workshop->country === null,
            'url'              => $workshop->url,
            'topic'            => $workshop->versionNode->node->title ?? null,

            // What the workshop agent finds on a listing and a person filling
            // the form in rarely knows: who is running it, who is speaking,
            // and whether it costs anything.
            'organizer'        => $workshop->organizer,
            'speaker'          => $workshop->speaker,
            'cost_type'        => $workshop->cost_type,
            'price'            => $workshop->price,
        ];
    }
}