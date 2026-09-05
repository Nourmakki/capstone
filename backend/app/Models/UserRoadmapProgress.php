<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Student;
use App\Models\RoadmapVersion;
use App\Models\CompletedNode;
use App\Models\Node;

class UserRoadmapProgress extends Model
{
    use HasFactory;
    protected $fillable = [
    'student_id',
    'roadmap_version_id',
    'current_node_id',
    'status'
];
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
    public function roadmapVersion()
    {
        return $this->belongsTo(RoadmapVersion::class, 'roadmap_version_id');
    }
    public function currentNode()
    {
        return $this->belongsTo(Node::class, 'current_node_id');
    }

    public function completedNodes()
    {
        return $this->hasMany(CompletedNode::class);
    }

    /**
     * Every module of a roadmap this student has finished, in any version.
     *
     * A completion is recorded against the module, not against the copy of
     * the roadmap it was read in: version_nodes are rewritten by every clone,
     * but nodes.id survives one, and what a student finished is the module.
     *
     * Progress rows stay per version - that is where "which version am I
     * reading" belongs - but this is the question the rest of the app is
     * really asking of them. Answered per version, publishing v2 of a roadmap
     * a student was halfway through emptied it in front of them: every module
     * they had finished came back unfinished, the ones they had unlocked
     * locked again, and the roadmap asked them to do the whole thing over
     * because a manager had added one module to the end of it.
     *
     * Scoped to the one roadmap. A module can sit in more than one roadmap,
     * and finishing React's "Testing" is not a claim to have finished the
     * module of that name somewhere else.
     *
     * @return array<int,int> node ids
     */
    public static function completedNodeIdsFor(int $studentId, ?int $roadmapId): array
    {
        if ($roadmapId === null) {
            return [];
        }

        return CompletedNode::whereIn(
            'user_roadmap_progress_id',
            self::query()
                ->where('student_id', $studentId)
                ->whereIn(
                    'roadmap_version_id',
                    RoadmapVersion::where('roadmap_id', $roadmapId)->select('id')
                )
                ->select('id')
        )->distinct()->pluck('node_id')->all();
    }

    /** The same, for a progress row that already knows its roadmap. */
    public function completedNodeIds(): array
    {
        return self::completedNodeIdsFor(
            $this->student_id,
            $this->roadmapVersion?->roadmap_id
        );
    }
}
