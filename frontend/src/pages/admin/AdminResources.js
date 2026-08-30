import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import {
    BookOpen, Plus, Pencil, Trash2, ExternalLink, X, FileText, GraduationCap, AtSign,
} from "lucide-react";

const EMPTY = {
    title: "",
    url: "",
    type: "documentation",
};

// Matches the external_resources.type enum.
const TYPES = [
    { id: "documentation",  label: "Documentation", icon: <BookOpen size={16} /> },
    { id: "article",        label: "Article",       icon: <FileText size={16} /> },
    { id: "course",         label: "Course",        icon: <GraduationCap size={16} /> },
    { id: "social_account", label: "Social",        icon: <AtSign size={16} /> },
];

/**
 * The reading list on a module.
 *
 * These used to arrive only through the AI commit path, so a roadmap built by
 * hand had an empty Resources tab and no way to fill it. Unlike a tutorial
 * these are editable - a link and a title are things a human can sensibly
 * correct, which is the same line Nour's EditableLearningStage draws.
 */
function AdminResources({ onChanged }) {
    const [roadmaps, setRoadmaps] = useState([]);
    const [roadmapId, setRoadmapId] = useState(null);
    const [versions, setVersions] = useState([]);
    const [versionId, setVersionId] = useState(null);
    const [nodes, setNodes] = useState([]);
    const [nodeId, setNodeId] = useState(null);

    const [resources, setResources] = useState([]);
    const [form, setForm] = useState(EMPTY);
    const [editingId, setEditingId] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        api.get("/roadmaps")
            .then((r) => {
                const list = r.data.data ?? [];
                setRoadmaps(list);
                setRoadmapId((prev) => prev ?? list[0]?.id ?? null);
            })
            .catch((err) => console.error(err))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => {
        if (!roadmapId) return;

        api.get(`/roadmaps/${roadmapId}/versions`)
            .then((r) => {
                const list = r.data.data ?? [];
                setVersions(list);

                // The published version, not the newest: fixing what a student
                // reported is the common reason to be here.
                const live = list.find((v) => v.status === "Published");
                setVersionId((live ?? list[0])?.id ?? null);

                setNodes([]);
                setNodeId(null);
                setResources([]);
            })
            .catch((err) => console.error(err));
    }, [roadmapId]);

    useEffect(() => {
        if (!versionId) return;

        api.get(`/roadmap-versions/${versionId}/graph`)
            .then((r) => {
                const list = r.data.nodes ?? [];
                setNodes(list);
                setNodeId(list[0]?.version_node_id ?? null);
            })
            .catch((err) => console.error(err));
    }, [versionId]);

    const loadResources = useCallback(async () => {
        if (!nodeId) {
            setResources([]);
            return;
        }

        try {
            const response = await api.get(`/version-nodes/${nodeId}/external-resources`);
            setResources(response.data);
        } catch (err) {
            console.error(err);
        }
    }, [nodeId]);

    useEffect(() => {
        loadResources();
        setForm(EMPTY);
        setEditingId(null);
    }, [loadResources]);

    // Laravel's url rule needs a scheme, and refusing a pasted link over a
    // missing https:// is a pointless thing to make someone retype.
    const withScheme = (url) => {
        const trimmed = url.trim();
        return /^https?:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`;
    };

    const save = async () => {
        if (!nodeId) return;

        setBusy(true);

        try {
            const payload = {
                ...form,
                url: withScheme(form.url),
                version_node_id: nodeId,
            };

            if (editingId) {
                await api.put(`/external-resources/${editingId}`, payload);
            } else {
                await api.post("/external-resources", payload);
            }

            setForm(EMPTY);
            setEditingId(null);
            await loadResources();
            onChanged?.();
        } catch (err) {
            const errors = err.response?.data?.errors;
            alert(errors
                ? Object.values(errors).flat().join("\n")
                : (err.response?.data?.message ?? "Could not save the resource."));
        } finally {
            setBusy(false);
        }
    };

    const startEdit = (r) => {
        setEditingId(r.id);
        setForm({ title: r.title, url: r.url, type: r.type });
    };

    const cancelEdit = () => { setEditingId(null); setForm(EMPTY); };

    const remove = async (id) => {
        if (!window.confirm("Delete this resource?")) return;

        try {
            await api.delete(`/external-resources/${id}`);
            if (editingId === id) cancelEdit();
            await loadResources();
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
    const canSave = form.title.trim() && form.url.trim() && nodeId;

    return (
        <div className="p-8">
            <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">Resources</h1>
            <p className="text-gray-400 mt-2 mb-10">
                The documentation, articles and courses attached to each module.
                Nothing reaches students until you approve it in the Content Queue.
            </p>

            {loading ? (
                <p className="text-gray-400">Loading...</p>
            ) : (
                <>
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

                    <div className="grid md:grid-cols-2 gap-4 mb-10">
                        <div>
                            <label className="block text-sm text-gray-300 mb-2">Version</label>
                            <select value={versionId ?? ""}
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
                            <select value={nodeId ?? ""}
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

                    <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6 mb-10">
                        <div className="flex items-center justify-between mb-6">
                            <h2 className="text-2xl font-bold flex items-center gap-2">
                                {editingId ? <Pencil size={20} /> : <Plus size={20} />}
                                {editingId ? "Edit Resource" : "New Resource"}
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
                                className="p-3 rounded-xl bg-black/40 border border-white/10 text-white" />

                            <input type="text" placeholder="https://react.dev/learn" value={form.url}
                                onChange={(e) => setForm({ ...form, url: e.target.value })}
                                className="p-3 rounded-xl bg-black/40 border border-white/10 text-white" />
                        </div>

                        <div className="flex flex-wrap gap-3 mt-4">
                            {TYPES.map((t) => (
                                <button key={t.id} type="button"
                                    onClick={() => setForm({ ...form, type: t.id })}
                                    className={`flex items-center gap-2 px-5 py-2.5 rounded-xl border text-sm transition ${
                                        form.type === t.id
                                            ? "bg-[#0097a7] border-[#0097a7] text-black font-semibold"
                                            : "bg-black/40 border-white/10 text-gray-300 hover:border-[#0097a7]/40"
                                    }`}>
                                    {t.icon} {t.label}
                                </button>
                            ))}
                        </div>

                        <p className="text-xs text-gray-500 mt-4">
                            {selectedNode
                                ? `Will be attached to: ${selectedNode.title}`
                                : "Pick a module first."}
                        </p>

                        <button onClick={save} disabled={busy || !canSave}
                            className={`mt-4 flex items-center gap-2 font-bold px-8 py-3 rounded-xl transition ${
                                busy || !canSave
                                    ? "bg-gray-700 text-gray-400 cursor-not-allowed"
                                    : "bg-[#0097a7] text-black hover:scale-105"
                            }`}>
                            {busy ? "Saving..." : editingId ? "Save Changes" : "Add Resource"}
                        </button>
                    </div>

                    <h2 className="text-2xl font-bold flex items-center gap-2 mb-5">
                        <BookOpen className="text-[#0097a7]" />
                        {selectedNode ? `${selectedNode.title} resources` : "Resources"}
                        <span className="text-xs px-3 py-1 rounded-full bg-white/5 text-gray-400">
                            {resources.length}
                        </span>
                    </h2>

                    {resources.length === 0 ? (
                        <p className="text-gray-500">This module has no resources yet.</p>
                    ) : (
                        <div className="space-y-4">
                            {resources.map((r) => (
                                <div key={r.id} className="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                                    <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-3 flex-wrap mb-2">
                                                <h3 className="text-lg font-bold">{r.title}</h3>

                                                <span className={`text-xs px-3 py-1 rounded-full border ${statusStyle(r.review_status)}`}>
                                                    {r.review_status}
                                                </span>

                                                <span className="text-xs text-gray-500 capitalize">
                                                    {r.type.replace("_", " ")}
                                                </span>
                                            </div>

                                            <a href={r.url} target="_blank" rel="noreferrer"
                                                className="inline-flex items-center gap-2 text-[#00b0c2] text-sm hover:underline break-all">
                                                {r.url} <ExternalLink size={14} className="shrink-0" />
                                            </a>

                                            {r.review_notes && (
                                                <p className="mt-2 text-xs text-gray-400 border-l-2 border-white/10 pl-3">
                                                    Reviewer said: {r.review_notes}
                                                </p>
                                            )}
                                        </div>

                                        <div className="flex gap-3 shrink-0">
                                            <button onClick={() => startEdit(r)}
                                                className="p-3 rounded-xl border border-[#0097a7]/30 text-[#0097a7] hover:bg-[#0097a7]/10">
                                                <Pencil size={18} />
                                            </button>
                                            <button onClick={() => remove(r.id)}
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

export default AdminResources;
