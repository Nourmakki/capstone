import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import { Video, Plus, Pencil, Trash2, ExternalLink, X } from "lucide-react";

const EMPTY = {
    title: "",
    url: "",
    description: "",
    source_name: "",
    difficulty: "beginner",
};

function AdminTutorials({ onChanged }) {
    const [roadmaps, setRoadmaps] = useState([]);
    const [roadmapId, setRoadmapId] = useState(null);
    const [versions, setVersions] = useState([]);
    const [versionId, setVersionId] = useState(null);
    const [nodes, setNodes] = useState([]);
    const [nodeId, setNodeId] = useState(null);

    const [tutorials, setTutorials] = useState([]);
    const [form, setForm] = useState(EMPTY);
    const [editingId, setEditingId] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);

    const loadRoadmaps = useCallback(async () => {
        try {
            const response = await api.get("/roadmaps");
            const list = response.data.data ?? [];
            setRoadmaps(list);
            setRoadmapId((prev) => prev ?? list[0]?.id ?? null);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { loadRoadmaps(); }, [loadRoadmaps]);

    // Roadmap -> its versions
    useEffect(() => {
        if (!roadmapId) return;

        const run = async () => {
            try {
                const response = await api.get(`/roadmaps/${roadmapId}/versions`);
                const list = response.data.data ?? [];
                setVersions(list);
                setVersionId(list[0]?.id ?? null);
                setNodes([]);
                setNodeId(null);
                setTutorials([]);
            } catch (err) {
                console.error(err);
            }
        };

        run();
    }, [roadmapId]);

    // Version -> its modules
    useEffect(() => {
        if (!versionId) return;

        const run = async () => {
            try {
                const response = await api.get(`/roadmap-versions/${versionId}/graph`);
                const list = response.data.nodes ?? [];
                setNodes(list);
                setNodeId(list[0]?.version_node_id ?? null);
            } catch (err) {
                console.error(err);
            }
        };

        run();
    }, [versionId]);

    const loadTutorials = useCallback(async () => {
        if (!nodeId) {
            setTutorials([]);
            return;
        }

        try {
            const response = await api.get(`/version-nodes/${nodeId}/tutorials`);
            setTutorials(response.data);
        } catch (err) {
            console.error(err);
        }
    }, [nodeId]);

    useEffect(() => { loadTutorials(); }, [loadTutorials]);

    const save = async () => {
        if (!nodeId) return;

        setBusy(true);

        try {
            const payload = { ...form, version_node_id: nodeId };

            if (editingId) {
                await api.put(`/tutorials/${editingId}`, payload);
            } else {
                await api.post("/tutorials", payload);
            }

            setForm(EMPTY);
            setEditingId(null);
            await loadTutorials();
            onChanged?.();
        } catch (err) {
            const errors = err.response?.data?.errors;
            alert(errors
                ? Object.values(errors).flat().join("\n")
                : (err.response?.data?.message ?? "Could not save the tutorial."));
        } finally {
            setBusy(false);
        }
    };

    const startEdit = (t) => {
        setEditingId(t.id);
        setForm({
            title: t.title,
            url: t.url,
            description: t.description,
            source_name: t.source_name,
            difficulty: t.difficulty,
        });
    };

    const cancelEdit = () => { setEditingId(null); setForm(EMPTY); };

    const remove = async (id) => {
        if (!window.confirm("Delete this tutorial?")) return;

        try {
            await api.delete(`/tutorials/${id}`);
            await loadTutorials();
            onChanged?.();
        } catch (err) {
            alert(err.response?.data?.message ?? "Could not delete.");
        }
    };

    const statusStyle = (s) =>
        s === "approved" ? "border-green-400/30 bg-green-400/10 text-green-300"
        : s === "rejected" ? "border-red-400/30 bg-red-400/10 text-red-300"
        : "border-yellow-400/30 bg-yellow-400/10 text-yellow-300";

    const selectedNode = nodes.find((n) => n.version_node_id === nodeId);
    const canSave = form.title.trim() && form.url.trim()
        && form.description.trim() && form.source_name.trim() && nodeId;

    return (
        <div className="p-8">
            <h1 className="text-5xl font-black text-[#0097a7]">Tutorials</h1>
            <p className="text-gray-400 mt-2 mb-10">
                Add and edit the video tutorials attached to each module.
            </p>

            {loading ? (
                <p className="text-gray-400">Loading...</p>
            ) : (
                <>
                    {/* Roadmap */}
                    <div className="flex flex-wrap gap-3 mb-5">
                        {roadmaps.map((r) => (
                            <button key={r.id} onClick={() => setRoadmapId(r.id)}
                                className={`px-6 py-3 rounded-2xl transition ${
                                    roadmapId === r.id
                                        ? "bg-[#0097a7] text-black font-semibold"
                                        : "bg-white/5 border border-white/10 text-gray-300"
                                }`}>
                                {r.title}
                            </button>
                        ))}
                    </div>

                    {/* Version + module */}
                    <div className="grid md:grid-cols-2 gap-4 mb-10">
                        <div>
                            <label className="block text-sm text-gray-300 mb-2">Version</label>
                            <select
                                value={versionId ?? ""}
                                onChange={(e) => setVersionId(Number(e.target.value))}
                                className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white">
                                {versions.map((v) => (
                                    <option key={v.id} value={v.id}>
                                        v{v.version_number}{v.title ? ` · ${v.title}` : ""} ({v.status})
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-sm text-gray-300 mb-2">Module</label>
                            <select
                                value={nodeId ?? ""}
                                onChange={(e) => setNodeId(Number(e.target.value))}
                                className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white">
                                {nodes.length === 0 && <option value="">No modules in this version</option>}
                                {nodes.map((n) => (
                                    <option key={n.version_node_id} value={n.version_node_id}>
                                        {n.title}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    {/* Add / edit form */}
                    <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6 mb-10">
                        <div className="flex items-center justify-between mb-6">
                            <h2 className="text-2xl font-bold flex items-center gap-2">
                                {editingId ? <Pencil size={20} /> : <Plus size={20} />}
                                {editingId ? "Edit Tutorial" : "New Tutorial"}
                            </h2>

                            {editingId && (
                                <button onClick={cancelEdit}
                                    className="flex items-center gap-2 text-sm text-gray-400 hover:text-white">
                                    <X size={16} /> Cancel
                                </button>
                            )}
                        </div>

                        <div className="grid md:grid-cols-2 gap-4">
                            <input type="text" placeholder="Title" value={form.title}
                                onChange={(e) => setForm({ ...form, title: e.target.value })}
                                className="p-3 rounded-xl bg-black/30 border border-white/10 text-white" />

                            <input type="url" placeholder="https://youtube.com/watch?v=..." value={form.url}
                                onChange={(e) => setForm({ ...form, url: e.target.value })}
                                className="p-3 rounded-xl bg-black/30 border border-white/10 text-white" />

                            <input type="text" placeholder="Source (e.g. Traversy Media)" value={form.source_name}
                                onChange={(e) => setForm({ ...form, source_name: e.target.value })}
                                className="p-3 rounded-xl bg-black/30 border border-white/10 text-white" />

                            <select value={form.difficulty}
                                onChange={(e) => setForm({ ...form, difficulty: e.target.value })}
                                className="p-3 rounded-xl bg-black/30 border border-white/10 text-white">
                                <option value="beginner">Beginner</option>
                                <option value="intermediate">Intermediate</option>
                                <option value="advanced">Advanced</option>
                            </select>
                        </div>

                        <textarea rows="3" placeholder="Description" value={form.description}
                            onChange={(e) => setForm({ ...form, description: e.target.value })}
                            className="w-full mt-4 p-3 rounded-xl bg-black/30 border border-white/10 text-white" />

                        <p className="text-xs text-gray-500 mt-3">
                            {selectedNode
                                ? `Will be attached to: ${selectedNode.title}`
                                : "Select a module first."}
                        </p>

                        <button onClick={save} disabled={busy || !canSave}
                            className={`mt-5 font-bold px-8 py-3 rounded-xl transition ${
                                busy || !canSave
                                    ? "bg-gray-700 text-gray-400 cursor-not-allowed"
                                    : "bg-[#0097a7] text-black hover:scale-105"
                            }`}>
                            {busy ? "Saving..." : editingId ? "Save Changes" : "Add Tutorial"}
                        </button>
                    </div>

                    {/* List */}
                    <div className="flex items-center gap-3 mb-6">
                        <Video className="text-[#0097a7]" />
                        <h2 className="text-3xl font-bold">
                            {selectedNode?.title ?? "Module"} tutorials
                        </h2>
                        <span className="text-xs px-3 py-1 rounded-full bg-white/5 text-gray-400">
                            {tutorials.length}
                        </span>
                    </div>

                    {tutorials.length === 0 ? (
                        <p className="text-gray-500">No tutorials on this module yet.</p>
                    ) : (
                        <div className="space-y-4">
                            {tutorials.map((t) => (
                                <div key={t.id} className="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                                    <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center flex-wrap gap-3 mb-2">
                                                <h3 className="text-xl font-bold">{t.title}</h3>

                                                <span className={`text-xs px-3 py-1 rounded-full border ${statusStyle(t.review_status)}`}>
                                                    {t.review_status}
                                                </span>

                                                <span className="text-xs text-gray-500 capitalize">
                                                    {t.difficulty}
                                                </span>
                                            </div>

                                            <p className="text-sm text-gray-400">{t.description}</p>
                                            <p className="text-xs text-gray-500 mt-2">Source: {t.source_name}</p>

                                            <a href={t.url} target="_blank" rel="noreferrer"
                                               className="mt-2 inline-flex items-center gap-2 text-[#00b0c2] text-sm hover:underline break-all">
                                                {t.url} <ExternalLink size={14} className="shrink-0" />
                                            </a>

                                            {t.review_notes && (
                                                <p className="mt-3 text-xs text-gray-400 border-l-2 border-white/10 pl-3">
                                                    Reviewer said: {t.review_notes}
                                                </p>
                                            )}
                                        </div>

                                        <div className="flex gap-3 shrink-0">
                                            <button onClick={() => startEdit(t)}
                                                className="p-3 rounded-xl border border-[#0097a7]/30 text-[#0097a7] hover:bg-[#0097a7]/10">
                                                <Pencil size={18} />
                                            </button>
                                            <button onClick={() => remove(t.id)}
                                                className="p-3 rounded-xl border border-red-400/30 text-red-400 hover:bg-red-400/10">
                                                <Trash2 size={18} />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

export default AdminTutorials;
