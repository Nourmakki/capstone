import React, { useCallback, useEffect, useRef, useState } from "react";
import api from "../../api";
import ModuleMaterial from "../../components/ModuleMaterial";
import {
    Upload, Network, AlertTriangle, Sparkles, Check, X, ChevronRight,
    Inbox, GitBranch, Layers, ArrowLeft,
} from "lucide-react";

const STATUS_STYLE = {
    Published: "border-green-400/30 bg-green-400/10 text-green-300",
    Draft:     "border-yellow-400/30 bg-yellow-400/10 text-yellow-300",
};

/**
 * Roadmaps: ask for a new one, then browse what exists.
 *
 * The top half is the only way a roadmap is created - a subject name goes to
 * the AI builder, which proposes the paths under it and the modules in each.
 * Nothing here creates a roadmap by hand.
 *
 * The bottom half is a reader. Pick a roadmap, pick one of its modules, see
 * what is on it. Version handling is down to publishing: drafts come from the
 * AI, and approving one in the Content Queue is the other way to make it live.
 */
function AdminRoadmaps({ onChanged }) {
    // --- the builder -------------------------------------------------
    const [topic, setTopic] = useState("");
    const [runs, setRuns] = useState([]);
    const [starting, setStarting] = useState(false);
    const [serviceDown, setServiceDown] = useState(false);

    // --- browsing ----------------------------------------------------
    const [roadmaps, setRoadmaps] = useState([]);
    const [selectedId, setSelectedId] = useState(null);
    const [versions, setVersions] = useState([]);
    const [versionId, setVersionId] = useState(null);
    const [modules, setModules] = useState([]);
    const [openModule, setOpenModule] = useState(null);

    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const modulesRef = useRef(null);

    const fail = (err) => {
        const errors = err.response?.data?.errors;
        alert(errors
            ? Object.values(errors).flat().join("\n")
            : (err.response?.data?.message ?? "Something went wrong."));
    };

    // -----------------------------------------------------------------
    // The builder
    // -----------------------------------------------------------------

    const loadRuns = useCallback(async () => {
        try {
            const response = await api.get("/roadmap-builder-runs");
            setRuns(response.data.runs ?? []);
            setServiceDown(false);
        } catch (err) {
            // 503 is the Python service being off, which is not an error
            // worth shouting about - the rest of the page still works.
            if (err.response?.status === 503) setServiceDown(true);
            else console.error(err);
        }
    }, []);

    const startBuild = async () => {
        if (!topic.trim()) return;

        setStarting(true);

        try {
            await api.post("/roadmap-builder-runs", { topic: topic.trim() });
            setTopic("");
            await loadRuns();
            onChanged?.();
        } catch (err) {
            fail(err);
        } finally {
            setStarting(false);
        }
    };

    const resumeRun = async (run, action) => {
        setBusy(true);

        try {
            await api.post(`/roadmap-builder-runs/${run.id}/resume`, { action });
            await loadRuns();
        } catch (err) {
            fail(err);
        } finally {
            setBusy(false);
        }
    };

    const commitRun = async (run) => {
        if (!window.confirm(
            `Commit "${run.topic}"?\n\n`
            + "This writes the subject and its roadmaps into the platform as "
            + "drafts awaiting review. Students see none of it until it is "
            + "approved and published."
        )) return;

        setBusy(true);

        try {
            await api.post(`/roadmap-builder-runs/${run.id}/commit`);
            await Promise.all([loadRuns(), loadRoadmaps()]);
            onChanged?.();
        } catch (err) {
            fail(err);
        } finally {
            setBusy(false);
        }
    };

    // -----------------------------------------------------------------
    // Browsing what exists
    // -----------------------------------------------------------------

    const loadRoadmaps = useCallback(async () => {
        try {
            const response = await api.get("/roadmaps");
            setRoadmaps(response.data.data ?? []);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { loadRoadmaps(); loadRuns(); }, [loadRoadmaps, loadRuns]);

    // Versions of whichever roadmap is open, newest first.
    useEffect(() => {
        if (!selectedId) {
            setVersions([]);
            setVersionId(null);
            return;
        }

        api.get(`/roadmaps/${selectedId}/versions`)
            .then((r) => {
                const list = r.data.data ?? [];
                setVersions(list);

                // The live one, not the newest: what students are on is the
                // version somebody opening this usually means.
                const live = list.find((v) => v.status === "Published");
                setVersionId((live ?? list[0])?.id ?? null);
            })
            .catch((err) => console.error(err));
    }, [selectedId]);

    // The chosen version's modules.
    useEffect(() => {
        setOpenModule(null);

        if (!versionId) {
            setModules([]);
            return;
        }

        // The rail as it comes, choices still nested inside the module that
        // offers them. Flattening would put "React" in the list as though it
        // were a step of its own, when it is a way out of this roadmap.
        api.get(`/roadmap-versions/${versionId}/graph`)
            .then((r) => setModules(r.data.nodes ?? []))
            .catch((err) => console.error(err));
    }, [versionId]);

    const publish = async (id) => {
        setBusy(true);

        try {
            await api.post(`/roadmap-versions/${id}/publish`);
            const r = await api.get(`/roadmaps/${selectedId}/versions`);
            setVersions(r.data.data ?? []);
            onChanged?.();
        } catch (err) {
            fail(err);
        } finally {
            setBusy(false);
        }
    };

    const openRoadmap = (id) => {
        setSelectedId(id);
        setOpenModule(null);
        // The modules are below the list on a phone, so say so by moving.
        setTimeout(() => modulesRef.current?.scrollIntoView({ behavior: "smooth", block: "start" }), 120);
    };

    const subjects  = roadmaps.filter((r) => !r.parent_roadmap_id);
    // By id, which is the order they were created and the order they are
    // meant to be taken - Frontend before Backend. A manager gets the
    // roadmaps as one flat alphabetical list, so the grouping done here has
    // to restore that order; a student gets it from the API already sorted.
    const childrenOf = (id) => roadmaps
        .filter((r) => r.parent_roadmap_id === id)
        .sort((a, b) => a.id - b.id);
    const selected  = roadmaps.find((r) => r.id === selectedId);
    const livePublished = versions.some((v) => v.status === "Published" && v.review_status === "approved");

    // The roadmap this one sits inside, when it is not one of the listed
    // ones. React is opened through Frontend, so it never appears on the
    // left, and without this there is nothing saying where you are.
    const parent = selected?.parent_roadmap_id
        ? roadmaps.find((r) => r.id === selected.parent_roadmap_id)
        : null;

    const parentIsSubject = parent && !parent.parent_roadmap_id;

    /** The way from a module into the roadmap it hands off to. */
    const RoadmapDoor = ({ roadmap }) => (
        <button onClick={() => openRoadmap(roadmap.id)}
            className="mt-3 w-full flex items-center justify-between gap-3 rounded-xl border border-[#0097a7]/30 bg-[#0097a7]/5 p-3 text-left hover:bg-[#0097a7]/10 transition">
            <span className="text-sm text-[#00b0c2]">Open {roadmap.title}</span>
            <ChevronRight size={14} className="text-[#0097a7] shrink-0" />
        </button>
    );

    return (
        <div className="p-8">
            <div className="flex items-center gap-3">
                <Layers size={30} className="text-[#0097a7]" />
                <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">Roadmaps</h1>
            </div>
            <p className="text-gray-400 mt-2 mb-6">
                Name a subject and the AI builds a roadmap for it. Everything
                below already exists.
            </p>

            {/* ---------------------------------------------------------- */}
            {/* Ask for a new one                                          */}
            {/* ---------------------------------------------------------- */}
            <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-8 mb-8">
                <div className="flex items-center gap-3 mb-2">
                    <Sparkles size={20} className="text-[#0097a7]" />
                    <h2 className="text-2xl font-bold">Build a new roadmap</h2>
                </div>

                <p className="text-sm text-gray-400 mb-5">
                    A whole subject from its name - "Mobile Development", "Data
                    Engineering". The AI proposes the roadmaps under it and the
                    modules in each, and you review before any of it is kept.
                </p>

                <div className="flex flex-wrap gap-3">
                    <input
                        type="text"
                        value={topic}
                        onChange={(e) => setTopic(e.target.value)}
                        onKeyDown={(e) => { if (e.key === "Enter") startBuild(); }}
                        placeholder="A subject, e.g. Mobile Development"
                        className="flex-1 min-w-[260px] p-3 rounded-xl bg-black/40 border border-white/10 text-white"
                    />

                    <button onClick={startBuild} disabled={starting || !topic.trim()}
                        className={`flex items-center gap-2 font-bold px-8 py-3 rounded-xl transition ${
                            starting || !topic.trim()
                                ? "bg-gray-700 text-gray-400 cursor-not-allowed"
                                : "bg-[#0097a7] text-black hover:scale-105"
                        }`}>
                        <Sparkles size={18} />
                        {starting ? "Starting..." : "Build it"}
                    </button>
                </div>

                {serviceDown && (
                    <div className="mt-5 flex items-start gap-3 rounded-2xl border border-yellow-400/30 bg-yellow-400/5 p-4">
                        <AlertTriangle size={18} className="text-yellow-400 shrink-0 mt-0.5" />
                        <p className="text-sm text-gray-300">
                            The AI service is not running, so a build cannot start.
                            Nothing already under way has been lost.
                        </p>
                    </div>
                )}

                {/* What has been asked for, and what it is waiting on. */}
                {runs.length > 0 && (
                    <div className="mt-6 space-y-3">
                        {runs.map((run) => (
                            <div key={run.id}
                                 className="rounded-2xl border border-white/10 bg-black/30 p-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="font-semibold">{run.topic}</span>

                                        <span className="text-xs px-2 py-0.5 rounded-full border border-white/10 text-gray-400">
                                            {run.status.replace(/_/g, " ")}
                                        </span>

                                        {run.review_stage && (
                                            <span className="text-xs text-gray-500">
                                                reviewing {run.review_stage}
                                            </span>
                                        )}
                                    </div>

                                    {run.committed && run.roadmap && (
                                        <p className="text-xs text-gray-500 mt-1">
                                            Committed as {run.roadmap.title}.
                                        </p>
                                    )}
                                </div>

                                {!run.committed && (
                                    <div className="flex flex-wrap gap-2 shrink-0">
                                        {run.status === "awaiting_review" && (
                                            <>
                                                <button onClick={() => resumeRun(run, "approve")} disabled={busy}
                                                    className="flex items-center gap-1.5 px-4 py-2 rounded-xl border border-green-400/30 text-green-300 hover:bg-green-400/10 text-sm transition">
                                                    <Check size={14} /> Approve
                                                </button>

                                                <button onClick={() => resumeRun(run, "reject")} disabled={busy}
                                                    className="flex items-center gap-1.5 px-4 py-2 rounded-xl border border-red-400/30 text-red-400 hover:bg-red-400/10 text-sm transition">
                                                    <X size={14} /> Reject
                                                </button>
                                            </>
                                        )}

                                        {run.status === "ready_for_commit" && (
                                            <button onClick={() => commitRun(run)} disabled={busy}
                                                className="flex items-center gap-1.5 px-5 py-2 rounded-xl bg-[#0097a7] text-black font-semibold text-sm hover:scale-[1.02] transition">
                                                <Check size={14} /> Keep it
                                            </button>
                                        )}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* ---------------------------------------------------------- */}
            {/* What already exists                                        */}
            {/* ---------------------------------------------------------- */}
            {loading ? (
                <p className="text-gray-400">Loading...</p>
            ) : (
                <div className="grid lg:grid-cols-[320px_1fr] gap-8">
                    {/* The roadmaps, under the subject that holds them. */}
                    <div>
                        <h2 className="text-xl font-bold mb-4">Roadmaps</h2>

                        {subjects.length === 0 ? (
                            <p className="text-gray-500 text-sm">Nothing built yet.</p>
                        ) : (
                            <div className="space-y-5">
                                {subjects.map((subject) => (
                                    <div key={subject.id}>
                                        {/* A heading, not a button: a subject holds
                                            roadmaps and has no modules of its own. */}
                                        <p className="text-xs uppercase tracking-wider text-gray-500 mb-2">
                                            {subject.title}
                                        </p>

                                        <div className="space-y-2">
                                            {/* The subject's own roadmaps and no deeper.
                                                React and Vue live under Frontend, and they
                                                are reached by opening the module that
                                                offers them - not by listing every roadmap
                                                on the platform in one column. */}
                                            {childrenOf(subject.id).map((r) => (
                                                <button key={r.id} onClick={() => openRoadmap(r.id)}
                                                    className={`text-left rounded-2xl border p-3 transition block w-full ${
                                                        selectedId === r.id
                                                            ? "border-[#0097a7]/40 bg-[#0097a7]/10"
                                                            : "border-white/10 bg-white/[0.03] hover:border-[#0097a7]/30"
                                                    }`}>
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className="font-medium text-sm">{r.title}</span>
                                                        <ChevronRight size={14} className="text-gray-600 shrink-0" />
                                                    </div>

                                                    <div className="flex items-center gap-2 mt-1 flex-wrap">
                                                        {r.review_status !== "approved" && (
                                                            <span className="text-xs px-2 py-0.5 rounded-full bg-yellow-400/10 text-yellow-300">
                                                                {r.review_status}
                                                            </span>
                                                        )}

                                                        <span className="text-xs text-gray-500">
                                                            {r.latest_version
                                                                ? `${r.node_count} modules`
                                                                : "no published version"}
                                                        </span>
                                                    </div>
                                                </button>
                                            ))}

                                            {childrenOf(subject.id).length === 0 && (
                                                <p className="text-xs text-gray-600">Nothing under this yet.</p>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Its modules. */}
                    <div ref={modulesRef} className="scroll-mt-6">
                        {!selected ? (
                            <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-12 text-center">
                                <Network size={40} className="text-gray-700 mx-auto mb-4" />
                                <h3 className="text-xl font-bold">Pick a roadmap</h3>
                                <p className="text-gray-500 mt-2">
                                    Its modules appear here, and each one opens to show what is on it.
                                </p>
                            </div>
                        ) : (
                            <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                                {/* Only when you have gone through a module to get
                                    here, so the left column no longer shows where
                                    you are. */}
                                {parent && !parentIsSubject && (
                                    <button onClick={() => openRoadmap(parent.id)}
                                        className="flex items-center gap-1.5 text-xs text-gray-500 hover:text-[#00b0c2] transition mb-3">
                                        <ArrowLeft size={12} /> Back to {parent.title}
                                    </button>
                                )}

                                <div className="flex items-center gap-3 mb-2">
                                    <GitBranch className="text-[#0097a7]" />
                                    <h2 className="text-2xl font-bold">{selected.title}</h2>
                                </div>

                                {selected.review_notes && (
                                    <p className="text-xs text-gray-400 mb-4">
                                        Reviewer said: {selected.review_notes}
                                    </p>
                                )}

                                {/* Which version is being read, and the one
                                    control left here: making a draft live. */}
                                {versions.length > 0 && (
                                    <div className="flex flex-wrap items-center gap-2 mb-6">
                                        {versions.map((v) => (
                                            <div key={v.id} className="flex items-center gap-1">
                                                <button onClick={() => setVersionId(v.id)}
                                                    className={`px-4 py-2 rounded-xl text-sm border transition ${
                                                        versionId === v.id
                                                            ? "border-[#0097a7]/40 bg-[#0097a7]/10 text-[#00b0c2]"
                                                            : "border-white/10 text-gray-400 hover:border-white/20"
                                                    }`}>
                                                    v{v.version_number}
                                                    <span className={`ml-2 text-xs px-2 py-0.5 rounded-full border ${
                                                        STATUS_STYLE[v.status] ?? "border-white/10 text-gray-500"
                                                    }`}>
                                                        {v.status}
                                                    </span>
                                                </button>

                                                {v.status !== "Published" && (
                                                    <button onClick={() => publish(v.id)} disabled={busy}
                                                        title={`Publish v${v.version_number}`}
                                                        className="p-2 rounded-xl border border-green-400/20 bg-green-500/10 text-green-300 hover:bg-green-500/20 transition">
                                                        <Upload size={14} />
                                                    </button>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}

                                {!livePublished && (
                                    <div className="mb-6 flex items-start gap-3 rounded-2xl border border-yellow-400/30 bg-yellow-400/5 p-4">
                                        <AlertTriangle size={18} className="text-yellow-400 shrink-0 mt-0.5" />
                                        <p className="text-sm text-yellow-200">
                                            No published version, so students cannot open this roadmap.
                                            {versions.length === 0
                                                ? " The AI builder is what fills it."
                                                : " Publish a draft, or approve one in the Content Queue."}
                                        </p>
                                    </div>
                                )}

                                {modules.length === 0 ? (
                                    <p className="text-gray-500">This version has no modules.</p>
                                ) : (
                                    <div className="space-y-2">
                                        {modules.map((m) => (
                                            <div key={m.version_node_id}
                                                 className="rounded-2xl border border-white/10 bg-black/20 p-4">
                                                <button
                                                    onClick={() => setOpenModule(
                                                        openModule === m.version_node_id ? null : m.version_node_id
                                                    )}
                                                    className="w-full flex items-center justify-between gap-3 text-left">
                                                    <div className="min-w-0">
                                                        <span className="font-medium">{m.title}</span>

                                                        {m.coming_soon && (
                                                            <span className="ml-2 text-xs px-2 py-0.5 rounded-full bg-yellow-400/10 text-yellow-300">
                                                                coming soon
                                                            </span>
                                                        )}

                                                        {/* Says before opening it that this
                                                            module is a door rather than a
                                                            page of material. */}
                                                        {m.kind === "roadmap" && (
                                                            <span className="ml-2 text-xs text-gray-500">
                                                                opens a roadmap
                                                            </span>
                                                        )}

                                                        {m.kind === "choice" && (
                                                            <span className="ml-2 text-xs text-gray-500">
                                                                {m.choices?.length ?? 0} to choose from
                                                            </span>
                                                        )}
                                                    </div>

                                                    <ChevronRight size={16} className={`text-gray-600 shrink-0 transition-transform ${
                                                        openModule === m.version_node_id ? "rotate-90" : ""
                                                    }`} />
                                                </button>

                                                {openModule === m.version_node_id && (
                                                    <div className="mt-4 pt-4 border-t border-white/10">
                                                        {/* Where this module leads. A roadmap
                                                            node hands off to one roadmap; a
                                                            choice offers several, and either
                                                            way that is how the roadmaps below
                                                            this one are reached. */}
                                                        {m.links_to_roadmap && (
                                                            <RoadmapDoor roadmap={m.links_to_roadmap} />
                                                        )}

                                                        {m.kind === "choice" && (
                                                            <div className="space-y-2">
                                                                {m.choices?.map((c) => (
                                                                    <div key={c.version_node_id}
                                                                         className="rounded-xl border border-white/10 bg-black/20 p-3">
                                                                        <div className="flex items-center gap-2 flex-wrap">
                                                                            <span className="text-sm font-medium">{c.title}</span>

                                                                            {c.is_recommended && (
                                                                                <span className="text-xs px-2 py-0.5 rounded-full bg-[#0097a7]/10 text-[#00b0c2]">
                                                                                    recommended
                                                                                </span>
                                                                            )}
                                                                        </div>

                                                                        {c.links_to_roadmap
                                                                            ? <RoadmapDoor roadmap={c.links_to_roadmap} />
                                                                            : (
                                                                                <div className="mt-3">
                                                                                    <ModuleMaterial
                                                                                        versionNodeId={c.version_node_id}
                                                                                        onChanged={onChanged}
                                                                                    />
                                                                                </div>
                                                                            )}
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        )}

                                                        {/* A module that leads nowhere is a
                                                            module you read. */}
                                                        {m.kind === "standard" && (
                                                            <ModuleMaterial
                                                                versionNodeId={m.version_node_id}
                                                                onChanged={onChanged}
                                                            />
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {runs.length === 0 && !serviceDown && subjects.length === 0 && (
                <div className="mt-8 flex items-center gap-3 text-gray-600">
                    <Inbox size={18} />
                    <p className="text-sm">Nothing here yet - name a subject above to start.</p>
                </div>
            )}
        </div>
    );
}

export default AdminRoadmaps;
