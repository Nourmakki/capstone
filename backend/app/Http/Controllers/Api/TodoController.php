<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTodoRequest;
use App\Models\ToDoTask;
use Illuminate\Http\Request;

class TodoController extends Controller
{
    public function index(Request $request)
    {
        $tasks = ToDoTask::where('user_id', $request->user()->id)
            ->orderBy('is_completed')
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderBy('due_date')
            ->get()
            ->map(fn ($t) => $this->payload($t));

        return response()->json(['tasks' => $tasks]);
    }

    public function store(StoreTodoRequest $request)
    {
        $task = ToDoTask::create([
            'user_id' => $request->user()->id,
            ...$request->validated(),
        ]);

        return response()->json(['task' => $this->payload($task)], 201);
    }

    public function update(StoreTodoRequest $request, ToDoTask $todo)
    {
        $this->authorizeOwner($request, $todo);

        $todo->update($request->validated());

        return response()->json(['task' => $this->payload($todo->fresh())]);
    }

    /**
     * Flip completion without sending the whole task.
     */
    public function toggle(Request $request, ToDoTask $todo)
    {
        $this->authorizeOwner($request, $todo);

        $todo->update(['is_completed' => ! $todo->is_completed]);

        return response()->json(['task' => $this->payload($todo->fresh())]);
    }

    public function destroy(Request $request, ToDoTask $todo)
    {
        $this->authorizeOwner($request, $todo);

        $todo->delete();

        return response()->json(['message' => 'Task deleted.']);
    }

    private function authorizeOwner(Request $request, ToDoTask $todo): void
    {
        abort_if($todo->user_id !== $request->user()->id, 403, 'This is not your task.');
    }

    private function payload(ToDoTask $task): array
    {
        return [
            'id'            => $task->id,
            'title'         => $task->title,
            'description'   => $task->description,
            'due_date'      => $task->due_date?->toDateString(),
            'priority'      => $task->priority,
            'reminder_time' => $task->reminder_time,
            'is_completed'  => $task->is_completed,
            'created_at'    => $task->created_at,
        ];
    }
}