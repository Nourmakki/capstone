import React, { useCallback, useEffect, useRef, useState } from "react";
import api from "../../api";
import { Pencil, Trash2, X, Lock, Layers, ChevronRight } from "lucide-react";
import ModuleMaterial from "../../components/ModuleMaterial";

const EMPTY = {
    title: "",
    description: "",
    difficulty: "beginner",
    prerequisite_node_ids: [],
    // Blank means ungrouped, which is a perfectly good roadmap - the rail
    // numbers each module instead of showing stage headings.
    stage: "",
    stage_title: "",
    is_recommended: false,
};

/**
 * Group the modules into the separate paths a student could take, each one
 * walked from its starting module to its end.
 *
 * Ordering by dependency depth instead would interleave independent paths -
 * a module could sit several rows below the one it directly follows, which
 * reads as though something comes between them.
 *
 * @returns {Array<Array>} one array per path, in walking order
 */
function intoPaths(modules) {
    const byNodeId = new Map(modules.map((m) => [m.node_id, m]));

    // successors[x] = the modules that list x as a prerequisite
    const successors = new Map();

    for (const module of modules) {
        for (const id of module.prerequisite_node_ids) {
            if (!successors.has(id)) successors.set(id, []);
            successors.get(id).push(module);
        }
    }

    const placed = new Set();
    const paths = [];

    // Follow one branch to its end before starting the next.
    const walk = (module, path) => {
        if (placed.has(module.version_node_id)) return;

        placed.add(module.version_node_id);
        path.push(module);

        const next = (successors.get(module.node_id) ?? [])
            .slice()
            .sort((a, b) => a.version_node_id - b.version_node_id);

        for (const child of next) {
            // A module with several prerequisites belongs to the path that
            // reaches it last, once everything it needs is already placed.
            const ready = child.prerequisite_node_ids.every(
                (id) => !byNodeId.has(id) || placed.has(byNodeId.get(id).version_node_id)
            );

            if (ready) walk(child, path);
        }
    };

    const starts = modules
        .filter((m) => m.prerequisite_node_ids.length === 0)
        .sort((a, b) => a.version_node_id - b.version_node_id);

    for (const start of starts) {
        const path = [];
        walk(start, path);
        if (path.length) paths.push(path);
    }

    // Anything left is only reachable through a cycle, which the API refuses
    // to create - but a stray row should still be visible, not hidden.
    const orphans = modules.filter((m) => !placed.has(m.version_node_id));

    if (orphans.length) paths.push(orphans);

    return paths;
}

function VersionModulesEditor({ versionId, onChanged }) {
    const [data, setData] = useState(null);
    const [form, setForm] = useState(EMPTY);
    const [editingId, setEditingId] = useState(null);

    // Which module has its material open. One at a time: the point is to look
    // at a module, not to scroll past every module's material at once.
    const [openMaterial, setOpenMaterial] = useState(null);
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
            // Edit only. Modules come from the roadmap workflows; a manager
            // corrects what they generated rather than adding to it.
            await api.put(`/version-nodes/${editingId}`, form);

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
            stage: module.stage ?? "",
            stage_title: module.stage_title ?? "",
            is_recommended: Boolean(module.is_recommended),
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

    const paths = intoPaths(data.modules);
    const modules = paths.flat();
    const editable = data.version.editable;
    const titleOf = (nodeId) =>
        data.modules.find((m) => m.node_id === nodeId)?.title ?? `#${nodeId}`;

    // The module being edited cannot be its own prerequisite.
    const candidates = modules.filter((m) => m.version_node_id !== editingId);
    // Nothing to save until a module has been chosen to edit.
    const canSave = editingId && form.title.trim() && form.description.trim();

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
                            <Pencil size={18} />
                            {editingId ? "Edit Module" : "Pick a module to edit"}
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

                    {/* Grouping. Modules sharing a stage number are drawn under
                        one heading on the student's rail; leaving it blank keeps
                        the roadmap flat, which is what every roadmap is until
                        someone groups it. */}
                    <div className="grid md:grid-cols-[110px_1fr_auto] gap-4 mt-4">
                        <input
                            type="number"
                            min="1"
                            max="99"
                            placeholder="Stage"
                            value={form.stage}
                            onChange={(e) => setForm({ ...form, stage: e.target.value })}
                            className="p-3 rounded-xl bg-black/40 border border-white/10 text-white"
                        />

                        <input
                            type="text"
                            placeholder="Stage name (e.g. Foundations) — modules with the same number share it"
                            value={form.stage_title}
                            onChange={(e) => setForm({ ...form, stage_title: e.target.value })}
                            className="p-3 rounded-xl bg-black/40 border border-white/10 text-white"
                        />

                        <label className="flex items-center gap-3 px-4 rounded-xl bg-black/40 border border-white/10 cursor-pointer select-none">
                            <input
                                type="checkbox"
                                checked={form.is_recommended}
                                onChange={(e) => setForm({ ...form, is_recommended: e.target.checked })}
                                className="w-4 h-4 accent-[#0097a7]"
                            />
                            <span className="text-sm text-gray-300 whitespace-nowrap">
                                Recommended
                            </span>
                        </label>
                    </div>

                    {form.is_recommended && !form.stage && (
                        <p className="text-xs text-yellow-300 mt-2">
                            The badge marks the one worth starting with among several
                            in the same stage. On an ungrouped module there is nothing
                            for it to stand out from.
                        </p>
                    )}

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
                        {busy ? "Saving..." : "Save Changes"}
                    </button>
                </div>
            )}

            {modules.length === 0 ? (
                <p className="text-gray-500">
                    No modules yet. This version has nothing for students to do.
                </p>
            ) : (
                <div className="space-y-10">
                    {paths.map((path, pathIndex) => (
                    <div key={path[0].version_node_id} className="space-y-3">

                    {/* Naming each path stops the stage numbers from reading
                        as one sequence when the roadmap actually branches. */}
                    {paths.length > 1 && (
                        <div className="flex items-center gap-3 mb-1">
                            <span className="text-xs px-3 py-1 rounded-full border border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2]">
                                Path {pathIndex + 1}
                            </span>
                            <span className="text-xs text-gray-500">
                                starts at {path[0].title} · {path.length} module{path.length === 1 ? "" : "s"}
                            </span>
                        </div>
                    )}

                    {path.map((m, index) => (
                        <div key={m.version_node_id}
                             className="rounded-2xl border border-white/10 bg-black/20 p-5">
                            <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                                <div className="flex gap-4 min-w-0">
                                    <span className="text-xs text-gray-500 mt-1 shrink-0 w-12">
                                        STEP {index + 1}
                                    </span>

                                    <div className="min-w-0">
                                        <div className="flex items-center gap-3 flex-wrap">
                                            <h3 className="text-lg font-bold">{m.title}</h3>
                                            <span className="text-xs text-gray-500 capitalize">
                                                {m.difficulty}
                                            </span>

                                            {/* So the grouping is visible here and not
                                                only on the student's rail. */}
                                            {m.stage && (
                                                <span className="text-xs px-3 py-1 rounded-full border border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2]">
                                                    Stage {m.stage}
                                                    {m.stage_title ? ` · ${m.stage_title}` : ""}
                                                </span>
                                            )}

                                            {m.is_recommended && (
                                                <span className="text-[10px] font-bold tracking-widest px-2.5 py-1 rounded-full bg-[#0097a7] text-black">
                                                    RECOMMENDED
                                                </span>
                                            )}

                                            {/* What this module does when a student
                                                clicks it. Without this the three kinds
                                                are indistinguishable here, and a module
                                                that opens a roadmap looks like one that
                                                simply has no material yet. */}
                                            {m.kind === "roadmap" && (
                                                <span className="text-xs px-3 py-1 rounded-full border border-purple-400/30 bg-purple-500/10 text-purple-300">
                                                    opens {m.links_to_roadmap?.title ?? "a roadmap"}
                                                </span>
                                            )}

                                            {m.kind === "choice" && (
                                                <span className="text-xs px-3 py-1 rounded-full border border-yellow-400/30 bg-yellow-400/10 text-yellow-200">
                                                    {m.choices.length} choices
                                                </span>
                                            )}
                                        </div>

                                        <p className="text-sm text-gray-400 mt-1">{m.description}</p>

                                        {/* The buttons this module offers. They are not
                                            steps in the rail, so they are shown here
                                            rather than as modules of their own. */}
                                        {m.kind === "choice" && (
                                            <div className="flex flex-wrap items-center gap-2 mt-3">
                                                {m.choices.map((c) => (
                                                    <span key={c.version_node_id}
                                                        className={`text-xs px-3 py-1 rounded-full border ${
                                                            c.is_recommended
                                                                ? "border-[#0097a7]/40 bg-[#0097a7]/10 text-[#00b0c2]"
                                                                : "border-white/10 bg-white/5 text-gray-300"
                                                        }`}
                                                        title={c.recommendation_reason ?? c.description}>
                                                        {c.title}
                                                        {/* A choice usually shares its
                                                            roadmap's name, so name it
                                                            only when it differs. */}
                                                        {c.links_to_roadmap
                                                            ? c.links_to_roadmap.title === c.title
                                                                ? " →"
                                                                : ` → ${c.links_to_roadmap.title}`
                                                            : ""}
                                                        {c.is_recommended ? " ★" : ""}
                                                    </span>
                                                ))}
                                            </div>
                                        )}

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

                                        {/* The counts are the handle; the material
                                            itself opens underneath. Tutorials and
                                            resources had pages of their own, so
                                            answering "what is on this module"
                                            meant three screens and a good memory. */}
                                        <button
                                            onClick={() => setOpenMaterial(
                                                openMaterial === m.version_node_id ? null : m.version_node_id
                                            )}
                                            className="text-xs text-gray-500 mt-3 flex items-center gap-1.5 hover:text-[#00b0c2] transition"
                                        >
                                            <ChevronRight
                                                size={12}
                                                className={`transition-transform ${
                                                    openMaterial === m.version_node_id ? "rotate-90" : ""
                                                }`}
                                            />
                                            {m.material_counts.tutorials} tutorials ·{" "}
                                            {m.material_counts.quizzes} quizzes ·{" "}
                                            {m.material_counts.projects} projects
                                        </button>

                                        {openMaterial === m.version_node_id && (
                                            <div className="mt-4 pt-4 border-t border-white/10">
                                                <ModuleMaterial
                                                    versionNodeId={m.version_node_id}
                                                    editable={editable}
                                                    onChanged={load}
                                                />
                                            </div>
                                        )}
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
                    ))}
                </div>
            )}
        </div>
    );
}

export default VersionModulesEditor;
