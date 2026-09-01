import React, { useCallback, useEffect, useRef, useState } from "react";
import api from "../../api";
import { GitBranch, Upload, Plus, Network, AlertTriangle, Trash2 } from "lucide-react";
import VersionModulesEditor from "./VersionModulesEditor";
import RoadmapPicker from "../../components/RoadmapPicker";

function AdminRoadmaps({ onChanged }) {
    const [roadmaps, setRoadmaps] = useState([]);
    const [selectedId, setSelectedId] = useState(null);
    const [versions, setVersions] = useState([]);
    const [graph, setGraph] = useState(null);
    const [versionForm, setVersionForm] = useState({ version_number: "", title: "" });

    // The editor renders below the fold, so clicking Modules looks
    // like nothing happened unless we scroll to it.
    const graphRef = useRef(null);
    const [graphOpenCount, setGraphOpenCount] = useState(0);

    useEffect(() => {
        if (graphOpenCount === 0) {
            return;
        }

        const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

        graphRef.current?.scrollIntoView({
            behavior: reduceMotion ? "auto" : "smooth",
            block: "start",
        });
    }, [graphOpenCount]);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);

    const loadRoadmaps = useCallback(async () => {
        try {
            const response = await api.get("/roadmaps");
            const list = response.data.data ?? [];
            setRoadmaps(list);

            // Open on a roadmap proper, not whichever topic happens to sort
            // first - the top row is where the panel starts.
            setSelectedId((prev) =>
                prev ?? list.find((r) => !r.parent_roadmap_id)?.id ?? list[0]?.id ?? null
            );
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { loadRoadmaps(); }, [loadRoadmaps]);

    const loadVersions = useCallback(async () => {
        if (!selectedId) return;

        try {
            const response = await api.get(`/roadmaps/${selectedId}/versions`);
            setVersions(response.data.data ?? []);
        } catch (err) {
            console.error(err);
        }
    }, [selectedId]);

    useEffect(() => { loadVersions(); }, [loadVersions]);

    const createDraft = async () => {
        setBusy(true);

        try {
            // Blank fields let the API pick the next number and no title.
            await api.post(`/roadmaps/${selectedId}/versions`, {
                version_number: versionForm.version_number.trim() || undefined,
                title: versionForm.title.trim() || undefined,
            });

            setVersionForm({ version_number: "", title: "" });
            await loadVersions();
            onChanged?.();
        } catch (err) {
            const errors = err.response?.data?.errors;
            alert(errors
                ? Object.values(errors).flat().join("\n")
                : (err.response?.data?.message ?? "Could not create a draft."));
        } finally {
            setBusy(false);
        }
    };

    const publish = async (versionId) => {
        setBusy(true);

        try {
            await api.put(`/roadmap-versions/${versionId}/publish`);
            await loadVersions();
            await loadRoadmaps();
            onChanged?.();
        } catch (err) {
            alert(err.response?.data?.message ?? "Could not publish.");
        } finally {
            setBusy(false);
        }
    };

    const viewGraph = (versionId) => {
        setGraph(versionId);
        setGraphOpenCount((n) => n + 1);
    };

    const deleteVersion = async (version) => {
        const label = `v${version.version_number}${version.title ? ` · ${version.title}` : ""}`;

        if (!window.confirm(
            `Delete ${label}?\n\n` +
            "Its modules go with it, along with any tutorials, quizzes, projects " +
            "and resources attached to them. This cannot be undone."
        )) return;

        setBusy(true);

        try {
            const response = await api.delete(`/roadmap-versions/${version.id}`);
            const r = response.data.removed;

            // Close the editor if it was showing the version we just removed.
            if (graph === version.id) setGraph(null);

            await loadVersions();
            await loadRoadmaps();
            onChanged?.();

            alert(
                `${label} deleted.\n\n` +
                `${r.modules} modules, ${r.tutorials} tutorials, ${r.quizzes} quizzes, ` +
                `${r.projects} projects, ${r.resources} resources removed.`
            );
        } catch (err) {
            alert(err.response?.data?.message ?? "Could not delete this version.");
        } finally {
            setBusy(false);
        }
    };

    const statusStyle = (s) =>
        s === "Published" ? "border-green-400/30 bg-green-400/10 text-green-300"
        : s === "Draft" ? "border-yellow-400/30 bg-yellow-400/10 text-yellow-300"
        : "border-white/10 bg-white/[0.03] text-gray-400";

    return (
        <div className="p-8">
            <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">Roadmaps</h1>
            <p className="text-gray-400 mt-2 mb-10">
                Review what the AI proposed, and manage each roadmap's versions.
                Roadmaps themselves are created by the AI builder.
            </p>

            {loading ? (
                <p className="text-gray-400">Loading...</p>
            ) : (
                <>
                    <RoadmapPicker roadmaps={roadmaps} selectedId={selectedId}
                        onSelect={(id) => { setSelectedId(id); setGraph(null); }} />

                    <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                        <div className="flex items-center justify-between mb-8">
                            <div className="flex items-center gap-3">
                                <GitBranch className="text-[#0097a7]" />
                                <div>
                                    <h2 className="text-3xl font-bold">Versions</h2>
                                    {/* Which roadmap these belong to - the tab above is easy to miss. */}
                                    <p className="text-sm text-[#00b0c2] mt-1">
                                        {roadmaps.find((r) => r.id === selectedId)?.title ?? "No roadmap selected"}
                                    </p>

                                    {roadmaps.find((r) => r.id === selectedId)?.review_notes && (
                                        <p className="text-xs text-gray-400 mt-2 max-w-md">
                                            Reviewer said: {roadmaps.find((r) => r.id === selectedId).review_notes}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div className="flex flex-wrap items-center gap-3">
                                <input
                                    type="text"
                                    value={versionForm.version_number}
                                    onChange={(e) => setVersionForm({ ...versionForm, version_number: e.target.value })}
                                    placeholder="Number (auto)"
                                    className="w-36 p-3 rounded-xl bg-black/30 border border-white/10 text-white text-sm"
                                />

                                <input
                                    type="text"
                                    value={versionForm.title}
                                    onChange={(e) => setVersionForm({ ...versionForm, title: e.target.value })}
                                    placeholder="Title (optional)"
                                    className="w-56 p-3 rounded-xl bg-black/30 border border-white/10 text-white text-sm"
                                />

                                <button onClick={createDraft} disabled={busy || !selectedId}
                                    className={`flex items-center gap-2 px-6 py-3 rounded-2xl font-bold transition ${
                                        busy ? "bg-gray-700 text-gray-400" : "bg-[#0097a7] text-black hover:scale-105"
                                    }`}>
                                    <Plus size={18} /> New Draft Version
                                </button>
                            </div>
                        </div>

                        {/* Without a live version the roadmap is greyed out for
                            students, which is otherwise invisible from here. */}
                        {!versions.some((v) => v.status === "Published" && v.review_status === "approved") && (
                            <div className="mb-6 flex items-start gap-3 rounded-2xl border border-yellow-400/30 bg-yellow-400/5 p-4">
                                <AlertTriangle size={18} className="text-yellow-400 shrink-0 mt-0.5" />
                                <p className="text-sm text-yellow-200">
                                    No published version, so students cannot open this roadmap.
                                    {versions.length === 0
                                        ? " Create a draft version, then have it approved."
                                        : " Get a draft approved to make one live."}
                                </p>
                            </div>
                        )}

                        {versions.length === 0 ? (
                            <p className="text-gray-500">No versions yet.</p>
                        ) : (
                            <div className="space-y-4">
                                {versions.map((v) => (
                                    <div key={v.id} className="rounded-2xl border border-white/10 bg-black/30 p-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                                        <div className="flex items-center gap-4 min-w-0">
                                            <span className="text-2xl font-black shrink-0">v{v.version_number}</span>

                                            <div className="min-w-0">
                                                <div className="flex items-center gap-2 flex-wrap">
                                                    <span className={`text-xs px-3 py-1 rounded-full border ${statusStyle(v.status)}`}>
                                                        {v.status}
                                                    </span>

                                                    {v.review_status !== "approved" && (
                                                        <span className="text-xs px-3 py-1 rounded-full border border-yellow-400/30 bg-yellow-400/10 text-yellow-300">
                                                            {v.review_status === "pending" ? "awaiting review" : "rejected"}
                                                        </span>
                                                    )}
                                                </div>

                                                {v.title && (
                                                    <p className="text-sm text-gray-400 mt-1 truncate">{v.title}</p>
                                                )}
                                            </div>
                                        </div>

                                        <div className="flex gap-3">
                                            <button onClick={() => viewGraph(v.id)}
                                                className="flex items-center gap-2 px-5 py-2 rounded-xl border border-white/10 text-gray-300 hover:border-[#0097a7]/40 transition">
                                                <Network size={16} /> Modules
                                            </button>

                                            {v.status !== "Published" && (
                                                <button onClick={() => publish(v.id)} disabled={busy}
                                                    className="flex items-center gap-2 px-5 py-2 rounded-xl border border-green-400/20 bg-green-500/10 text-green-300 hover:bg-green-500/20 transition">
                                                    <Upload size={16} /> Publish
                                                </button>
                                            )}

                                            {/* Drafts only - a published or archived version is
                                                what students followed and has to stay. */}
                                            {v.status === "Draft" && (
                                                <button onClick={() => deleteVersion(v)} disabled={busy}
                                                    title="Delete this draft version"
                                                    className="flex items-center gap-2 px-5 py-2 rounded-xl border border-red-400/30 text-red-400 hover:bg-red-400/10 transition">
                                                    <Trash2 size={16} />
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {graph && (
                        <div ref={graphRef} className="mt-8 scroll-mt-6">
                            <VersionModulesEditor
                                key={graph}
                                versionId={graph}
                                onChanged={() => { loadVersions(); onChanged?.(); }}
                            />
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

export default AdminRoadmaps;
