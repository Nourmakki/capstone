import React, { useCallback, useEffect, useRef, useState } from "react";
import api from "../../api";
import { Plus, Pencil, Trash2, X, Lock, Layers } from "lucide-react";

const EMPTY = {
    title: "",
    description: "",
    difficulty: "beginner",
    prerequisite_node_ids: [],
};

/**
 * Order the list the way a student would walk it: a module appears after
 * everything it depends on. Without this the modules show in insertion
 * order, which reads as noise once a few edits have happened.
 */
function inLearningOrder(modules) {
    const depth = new Map();

    const depthOf = (module, seen = new Set()) => {
        if (depth.has(module.node_id)) return depth.get(module.node_id);
        if (seen.has(module.node_id)) return 0;

        seen.add(module.node_id);

        const value = module.prerequisite_node_ids.reduce((deepest, id) => {
            const parent = modules.find((m) => m.node_id === id);
            return parent ? Math.max(deepest, depthOf(parent, seen) + 1) : deepest;
        }, 0);

        depth.set(module.node_id, value);
        return value;
    };

    return [...modules].sort(
        (a, b) => depthOf(a) - depthOf(b) || a.version_node_id - b.version_node_id
    );
}

function VersionModulesEditor({ versionId, onChanged }) {
    const [data, setData] = useState(null);
    const [form, setForm] = useState(EMPTY);
    const [editingId, setEditingId] = useState(null);
    const [busy, setBusy] = useState(false);

    // The form sits above a list that can be several screens tall, so clicking
    // a module's pencil while scrolled down looks like nothing happened.
    const formRef = useRef(null);

    useEffect(() => {
        if (!editingId) return;

        const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

        formRef.current?.scrollIntoView({
            behavior: reduceMotion ? "auto" : "smooth",
            block: "start",
        });
    }, [editingId]);

    const load = useCallback(async () => {
        try {
            const response = await api.get(`/roadmap-versions/${versionId}/modules`);
            setData(response.data);
        } catch (err) {
            console.error(err);
        }
    }, [versionId]);

    useEffect(() => { load(); }, [load]);

    const fail = (err) => {
        const errors = err.response?.data?.errors;
        alert(errors
            ? Object.values(errors).flat().join("\n")
            : (err.response?.data?.message ?? "Something went wrong."));
    };

    const save = async () => {
        setBusy(true);

        try {
            if (editingId) {
                await api.put(`/version-nodes/${editingId}`, form);
            } else {
                await api.post(`/roadmap-versions/${versionId}/modules`, form);
            }

            setForm(EMPTY);
            setEditingId(null);
            await load();
            onChanged?.();
        } catch (err) {
            fail(err);
        } finally {
            setBusy(false);
        }
    };

    const startEdit = (module) => {
        setEditingId(module.version_node_id);
        setForm({
            title: module.title,
            description: module.description,
            difficulty: module.difficulty,
            prerequisite_node_ids: module.prerequisite_node_ids,
        });
    };

    const cancelEdit = () => { setEditingId(null); setForm(EMPTY); };

    const remove = async (module) => {
        if (!window.confirm(`Remove "${module.title}" from this version?`)) return;

        try {
            await api.delete(`/version-nodes/${module.version_node_id}`);
            if (editingId === module.version_node_id) cancelEdit();
            await load();
            onChanged?.();
        } catch (err) {
            fail(err);
        }
    };

    const togglePrerequisite = (nodeId) => {
        setForm((prev) => ({
            ...prev,
            prerequisite_node_ids: prev.prerequisite_node_ids.includes(nodeId)
                ? prev.prerequisite_node_ids.filter((id) => id !== nodeId)
                : [...prev.prerequisite_node_ids, nodeId],
        }));
    };

    if (!data) {
        return <p className="text-gray-400">Loading modules...</p>;
    }

    const modules = inLearningOrder(data.modules);
    const editable = data.version.editable;
    const titleOf = (nodeId) =>
        data.modules.find((m) => m.node_id === nodeId)?.title ?? `#${nodeId}`;

    // The module being edited cannot be its own prerequisite.
    const candidates = modules.filter((m) => m.version_node_id !== editingId);
    const canSave = form.title.trim() && form.description.trim();

    return (
        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
            <div className="flex items-center gap-3 mb-2">
                <Layers className="text-[#0097a7]" />
                <h2 className="text-3xl font-bold">
                    Modules · v{data.version.version_number}
                </h2>
                <span className="text-xs px-3 py-1 rounded-full bg-white/5 text-gray-400">
                    {modules.length}
                </span>
            </div>

            <p className="text-gray-400 mb-8">
                The modules in this version and what has to come before each one.
            </p>

            {!editable && (
                <div className="mb-8 flex items-start gap-3 rounded-2xl border border-yellow-400/30 bg-yellow-400/5 p-4">
                    <Lock size={18} className="text-yellow-400 shrink-0 mt-0.5" />
                    <p className="text-sm text-yellow-200">
                        This version is {data.version.status}, so it is read-only.
                        Students are following it - create a new draft version to make changes.
                    </p>
                </div>
            )}

            {editable && (
                <div ref={formRef}
                     className="scroll-mt-6 rounded-2xl border border-white/10 bg-black/30 p-6 mb-8">
                    <div className="flex items-center justify-between mb-6">
                        <h3 className="text-xl font-bold flex items-center gap-2">
                            {editingId ? <Pencil size={18} /> : <Plus size={18} />}
                            {editingId ? "Edit Module" : "New Module"}
                        </h3>

                        {editingId && (
                            <button onClick={cancelEdit}
                                className="flex items-center gap-2 text-sm text-gray-400 hover:text-white">
                                <X size={16} /> Cancel
                            </button>
                        )}
                    </div>

                    <div className="grid md:grid-cols-2 gap-4">
                        <input type="text" placeholder="Module title (e.g. React Hooks)"
                            value={form.title}
                            onChange={(e) => setForm({ ...form, title: e.target.value })}
                            className="p-3 rounded-xl bg-black/40 border border-white/10 text-white" />

                        <select value={form.difficulty}
                            onChange={(e) => setForm({ ...form, difficulty: e.target.value })}
                            className="p-3 rounded-xl bg-black/40 border border-white/10 text-white">
                            <option value="beginner">Beginner</option>
                            <option value="intermediate">Intermediate</option>
                            <option value="advanced">Advanced</option>
                        </select>
                    </div>

                    <textarea rows="2" placeholder="What this module covers"
                        value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                        className="w-full mt-4 p-3 rounded-xl bg-black/40 border border-white/10 text-white" />

                    <p className="text-sm text-gray-300 mt-6 mb-3">
                        Comes after
                        <span className="text-gray-500"> — a student must finish these first</span>
                    </p>

                    {candidates.length === 0 ? (
                        <p className="text-xs text-gray-500">
                            Nothing to depend on yet. This will be the first module.
                        </p>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            {candidates.map((m) => {
                                const on = form.prerequisite_node_ids.includes(m.node_id);

                                return (
                                    <button key={m.node_id} onClick={() => togglePrerequisite(m.node_id)}
                                        className={`px-4 py-2 rounded-xl text-sm border transition ${
                                            on
                                                ? "bg-[#0097a7] text-black font-semibold border-[#0097a7]"
                                                : "bg-black/30 border-white/10 text-gray-300 hover:border-[#0097a7]/40"
                                        }`}>
                                        {m.title}
                                    </button>
                                );
                            })}
                        </div>
                    )}

                    <button onClick={save} disabled={busy || !canSave}
                        className={`mt-6 font-bold px-8 py-3 rounded-xl transition ${
                            busy || !canSave
                                ? "bg-gray-700 text-gray-400 cursor-not-allowed"
                                : "bg-[#0097a7] text-black hover:scale-105"
                        }`}>
                        {busy ? "Saving..." : editingId ? "Save Changes" : "Add Module"}
                    </button>
                </div>
            )}

            {modules.length === 0 ? (
                <p className="text-gray-500">
                    No modules yet. This version has nothing for students to do.
                </p>
            ) : (
                <div className="space-y-3">
                    {modules.map((m, index) => (
                        <div key={m.version_node_id}
                             className="rounded-2xl border border-white/10 bg-black/20 p-5">
                            <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                                <div className="flex gap-4 min-w-0">
                                    <span className="text-xs text-gray-500 mt-1 shrink-0 w-12">
                                        STAGE {index + 1}
                                    </span>

                                    <div className="min-w-0">
                                        <div className="flex items-center gap-3 flex-wrap">
                                            <h3 className="text-lg font-bold">{m.title}</h3>
                                            <span className="text-xs text-gray-500 capitalize">
                                                {m.difficulty}
                                            </span>
                                        </div>

                                        <p className="text-sm text-gray-400 mt-1">{m.description}</p>

                                        <div className="flex flex-wrap items-center gap-2 mt-3">
                                            {m.prerequisite_node_ids.length === 0 ? (
                                                <span className="text-xs text-gray-500">
                                                    Starting module
                                                </span>
                                            ) : (
                                                <>
                                                    <span className="text-xs text-gray-500">after</span>
                                                    {m.prerequisite_node_ids.map((id) => (
                                                        <span key={id}
                                                              className="text-xs px-3 py-1 rounded-full border border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2]">
                                                            {titleOf(id)}
                                                        </span>
                                                    ))}
                                                </>
                                            )}
                                        </div>

                                        <p className="text-xs text-gray-500 mt-3">
                                            {m.material_counts.tutorials} tutorials ·{" "}
                                            {m.material_counts.quizzes} quizzes ·{" "}
                                            {m.material_counts.projects} projects
                                        </p>
                                    </div>
                                </div>

                                {editable && (
                                    <div className="flex gap-3 shrink-0">
                                        <button onClick={() => startEdit(m)}
                                            className="p-3 rounded-xl border border-[#0097a7]/30 text-[#0097a7] hover:bg-[#0097a7]/10">
                                            <Pencil size={18} />
                                        </button>
                                        <button onClick={() => remove(m)}
                                            className="p-3 rounded-xl border border-red-400/30 text-red-400 hover:bg-red-400/10">
                                            <Trash2 size={18} />
                                        </button>
                                    </div>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export default VersionModulesEditor;
