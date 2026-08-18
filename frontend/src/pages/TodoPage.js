import React, { useCallback, useEffect, useState } from "react";
import api from "../api";
import { Trash2, Pencil, Calendar, Bell, BellOff, CheckCircle2, Circle } from "lucide-react";
import DatePicker from "react-datepicker";
import "react-datepicker/dist/react-datepicker.css";

const EMPTY = { title: "", description: "", due_date: null, priority: "medium", reminder_time: "" };

function TodoPage() {
    const [tasks, setTasks] = useState([]);
    const [form, setForm] = useState(EMPTY);
    const [editingId, setEditingId] = useState(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    const load = useCallback(async () => {
        try {
            const response = await api.get("/todos");
            setTasks(response.data.tasks);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const setField = (field, value) => setForm((prev) => ({ ...prev, [field]: value }));

    const payload = () => ({
        title: form.title,
        description: form.description || null,
        due_date: form.due_date ? new Date(form.due_date).toISOString().split("T")[0] : null,
        priority: form.priority,
        reminder_time: form.reminder_time || null,
    });

    const save = async () => {
        if (!form.title.trim()) return;

        setSaving(true);

        try {
            if (editingId) {
                await api.put(`/todos/${editingId}`, payload());
            } else {
                await api.post("/todos", payload());
            }

            setForm(EMPTY);
            setEditingId(null);
            await load();
        } catch (err) {
            const errors = err.response?.data?.errors;
            alert(errors ? Object.values(errors).flat().join("\n") : "Could not save the task.");
        } finally {
            setSaving(false);
        }
    };

    const startEdit = (task) => {
        setEditingId(task.id);
        setForm({
            title: task.title,
            description: task.description ?? "",
            due_date: task.due_date ? new Date(task.due_date) : null,
            priority: task.priority ?? "medium",
            reminder_time: task.reminder_time ? task.reminder_time.slice(0, 5) : "",
        });
    };

    const cancelEdit = () => { setEditingId(null); setForm(EMPTY); };

    const toggle = async (id) => {
        try {
            await api.patch(`/todos/${id}/toggle`);
            await load();
        } catch (err) { console.error(err); }
    };

    const remove = async (id) => {
        try {
            await api.delete(`/todos/${id}`);
            await load();
        } catch (err) { console.error(err); }
    };

    const priorityStyle = (p) =>
        p === "high" ? "text-red-400 border-red-400/30 bg-red-400/5"
        : p === "low" ? "text-gray-400 border-white/10 bg-white/[0.02]"
        : "text-yellow-400 border-yellow-400/30 bg-yellow-400/5";

    return (
        <div className="min-h-screen bg-black text-white p-8">
            <div className="mb-10">
                <h1 className="text-5xl font-black text-[#0097a7]">My To-Do List</h1>
                <p className="text-gray-400 mt-2">Organize your learning journey and stay productive.</p>
            </div>

            <div className="bg-white/[0.03] border border-white/10 rounded-3xl p-6 mb-10">
                <h2 className="text-2xl font-bold mb-5">{editingId ? "Edit Task" : "Add New Task"}</h2>

                <div className="flex flex-col gap-4">
                    <input
                        type="text"
                        placeholder="Enter task..."
                        value={form.title}
                        onChange={(e) => setField("title", e.target.value)}
                        className="bg-black border border-white/10 rounded-xl p-3 focus:outline-none focus:border-[#0097a7]"
                    />

                    <textarea
                        rows="2"
                        placeholder="Description (optional)"
                        value={form.description}
                        onChange={(e) => setField("description", e.target.value)}
                        className="bg-black border border-white/10 rounded-xl p-3 focus:outline-none focus:border-[#0097a7]"
                    />

                    <div className="grid md:grid-cols-3 gap-4">
                        <div>
                            <label className="block text-left text-sm mb-2 text-gray-300">
                                Deadline
                            </label>
                            <div className="flex items-center gap-2 bg-black border border-white/10 rounded-xl p-3">
                                <Calendar size={18} className="text-[#0097a7]" />
                                <DatePicker
                                    selected={form.due_date}
                                    onChange={(date) => setField("due_date", date)}
                                    dateFormat="yyyy-MM-dd"
                                    placeholderText="Select a date"
                                    className="bg-transparent text-white w-full outline-none"
                                />
                            </div>
                        </div>

                        <div>
                            <label className="block text-left text-sm mb-2 text-gray-300">
                                Priority
                            </label>
                            <select
                                value={form.priority}
                                onChange={(e) => setField("priority", e.target.value)}
                                className="w-full bg-black border border-white/10 rounded-xl p-3 focus:outline-none focus:border-[#0097a7]"
                            >
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>

                        <div>
                            <label className="block text-left text-sm mb-2 text-gray-300 flex items-center gap-2">
                                <Bell size={14} className="text-[#0097a7]" />
                                Reminder time
                            </label>
                            <div className="flex items-center gap-2">
                                <input
                                    type="time"
                                    value={form.reminder_time}
                                    onChange={(e) => setField("reminder_time", e.target.value)}
                                    className="w-full bg-black border border-white/10 rounded-xl p-3 focus:outline-none focus:border-[#0097a7]"
                                />
                                {form.reminder_time && (
                                    <button
                                        type="button"
                                        title="Clear reminder"
                                        onClick={() => setField("reminder_time", "")}
                                        className="p-3 rounded-xl border border-white/10 text-gray-400 hover:text-white hover:border-white/30 transition"
                                    >
                                        <BellOff size={16} />
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="flex gap-3">
                        <button
                            onClick={save}
                            disabled={saving || !form.title.trim()}
                            className={`flex-1 font-bold py-3 rounded-xl transition ${
                                saving || !form.title.trim()
                                    ? "bg-gray-700 text-gray-400 cursor-not-allowed"
                                    : "bg-[#0097a7] text-black hover:scale-[1.02]"
                            }`}
                        >
                            {saving ? "Saving..." : editingId ? "Save Changes" : "Add Task"}
                        </button>

                        {editingId && (
                            <button onClick={cancelEdit} className="px-6 rounded-xl border border-white/10 text-gray-300">
                                Cancel
                            </button>
                        )}
                    </div>
                </div>
            </div>

            <h2 className="text-3xl font-bold mb-6">Tasks ({tasks.length})</h2>

            {loading ? (
                <p className="text-gray-400">Loading...</p>
            ) : tasks.length === 0 ? (
                <div className="text-center text-gray-500 mt-10">No tasks yet.</div>
            ) : (
                <div className="space-y-4">
                    {tasks.map((task) => (
                        <div key={task.id} className="bg-white/[0.03] border border-white/10 rounded-2xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <div className="flex items-start gap-4">
                                <button onClick={() => toggle(task.id)} className="mt-1">
                                    {task.is_completed
                                        ? <CheckCircle2 size={24} className="text-green-400" />
                                        : <Circle size={24} className="text-gray-500" />}
                                </button>

                                <div>
                                    <h3 className={`font-semibold text-lg ${task.is_completed ? "line-through text-gray-500" : ""}`}>
                                        {task.title}
                                    </h3>

                                    {task.description && (
                                        <p className="text-sm text-gray-400 mt-1">{task.description}</p>
                                    )}

                                    <div className="flex flex-wrap items-center gap-3 mt-2 text-sm text-gray-400">
                                        <span className={`px-3 py-1 rounded-full text-xs border capitalize ${priorityStyle(task.priority)}`}>
                                            {task.priority}
                                        </span>

                                        {task.due_date && <span>📅 {task.due_date}</span>}

                                        <span className="flex items-center gap-1">
                                            {task.reminder_time
                                                ? <><Bell size={14} /> {task.reminder_time.slice(0, 5)}</>
                                                : <><BellOff size={14} /> No reminder</>}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div className="flex gap-3">
                                <button onClick={() => startEdit(task)} className="p-3 rounded-xl border border-[#0097a7]/30 text-[#0097a7] hover:bg-[#0097a7]/10">
                                    <Pencil size={18} />
                                </button>
                                <button onClick={() => remove(task.id)} className="p-3 rounded-xl border border-red-400/30 text-red-400 hover:bg-red-400/10">
                                    <Trash2 size={18} />
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export default TodoPage;