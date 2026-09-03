import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import { firstRoadmapIn } from "../../components/RoadmapPicker";
import ModuleMaterialEditor from "../../components/ModuleMaterialEditor";
import {
    Sparkles, Video, Brain, Code2, BookOpen, Check, X, Pencil,
    RefreshCw, AlertTriangle, Inbox, ChevronRight, ExternalLink,
    Layers, Wand2,
} from "lucide-react";

// Stages her service accepts for regenerate.
const STAGES = [
    { id: "tutorial", label: "tutorial" },
    { id: "quiz", label: "quiz" },
    { id: "project", label: "project" },
    { id: "external_resources", label: "resources" },
];

function MaterialLearningReview({ onChanged }) {
    const [runs, setRuns] = useState([]);
    const [selected, setSelected] = useState(null);
    const [result, setResult] = useState(null);
    const [feedback, setFeedback] = useState("");
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [serviceDown, setServiceDown] = useState(false);

    // Starting a run needs a module to run against.
    const [roadmaps, setRoadmaps] = useState([]);
    const [roadmapId, setRoadmapId] = useState(null);
    const [versions, setVersions] = useState([]);
    const [versionId, setVersionId] = useState(null);
    const [modules, setModules] = useState([]);
    const [moduleId, setModuleId] = useState(null);

    // Modules the roadmap builder left empty. Which roadmap is expanded, and
    // which single module is generating - one at a time is the point of this
    // whole screen, so it is one id rather than a set.
    const [pending, setPending] = useState([]);
    const [pendingTotal, setPendingTotal] = useState(0);
    const [openGroup, setOpenGroup] = useState(null);
    const [generating, setGenerating] = useState(null);

    // Folded away until asked for. The header carries the count, so the page
    // still says how much is waiting without unrolling all of it.
    const [awaitingOpen, setAwaitingOpen] = useState(false);

    const loadRuns = useCallback(async () => {
        try {
            const response = await api.get("/learning-runs");
            setRuns(response.data.runs ?? []);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    const loadPending = useCallback(async () => {
        try {
            const response = await api.get("/modules-awaiting-material");
            setPending(response.data.groups ?? []);
            setPendingTotal(response.data.total ?? 0);
        } catch (err) {
            console.error(err);
        }
    }, []);

    useEffect(() => { loadRuns(); loadPending(); }, [loadRuns, loadPending]);

    /**
     * Generate for one module off the awaiting list.
     *
     * Deliberately one at a time: committing a built subject used to queue a
     * run for every module at once, which took the site down. Every other
     * button on the list is disabled while one is running, so the same thing
     * cannot be done by hand from here.
     */
    const generateFor = async (module) => {
        setGenerating(module.version_node_id);
        setServiceDown(false);

        try {
            await api.post(`/version-nodes/${module.version_node_id}/learning-runs`);
            await Promise.all([loadRuns(), loadPending()]);
            onChanged?.();
        } catch (err) {
            if (err.response?.status === 503) {
                setServiceDown(true);
            } else {
                alert(err.response?.data?.message ?? "Could not start generation.");
            }
        } finally {
            setGenerating(null);
        }
    };

    useEffect(() => {
        api.get("/roadmaps")
            .then((r) => {
                const list = r.data.data ?? [];
                setRoadmaps(list);
                // A roadmap, never a subject: a subject holds no modules, so
                // opening here offered nothing to generate material for.
                setRoadmapId((prev) => prev ?? firstRoadmapIn(list));
            })
            .catch((err) => console.error(err));
    }, []);

    useEffect(() => {
        if (!roadmapId) return;

        api.get(`/roadmaps/${roadmapId}/versions`)
            .then((r) => {
                const list = r.data.data ?? [];
                setVersions(list);
                setVersionId(list[0]?.id ?? null);
            })
            .catch((err) => console.error(err));
    }, [roadmapId]);

    useEffect(() => {
        if (!versionId) return;

        api.get(`/roadmap-versions/${versionId}/graph`)
            .then((r) => {
                const list = r.data.nodes ?? [];
                setModules(list);
                setModuleId(list[0]?.version_node_id ?? null);
            })
            .catch((err) => console.error(err));
    }, [versionId]);

    const openRun = async (run) => {
        setSelected(run);
        setResult(null);
        setFeedback("");
        setServiceDown(false);
        setBusy(true);

        try {
            const response = await api.get(`/learning-runs/${run.id}`);
            setResult(response.data);
        } catch (err) {
            if (err.response?.status === 503) {
                setServiceDown(true);
            } else {
                alert(err.response?.data?.message ?? "Could not load this run.");
            }
        } finally {
            setBusy(false);
        }
    };

    /**
     * Her schema forbids extra keys, and each action allows a different set:
     * approve takes nothing, reject takes feedback, regenerate takes a stage
     * and optional feedback.
     */
    const decide = async (payload) => {
        if (payload.action === "reject" && !feedback.trim()) {
            alert("Tell the AI why you are rejecting this so the next run can do better.");
            return;
        }

        setBusy(true);

        try {
            const response = await api.post(`/learning-runs/${selected.id}/resume`, payload);
            setResult(response.data);
            setFeedback("");
            await loadRuns();
            onChanged?.();
        } catch (err) {
            if (err.response?.status === 503) {
                setServiceDown(true);
            } else {
                const errors = err.response?.data?.errors;
                alert(errors
                    ? Object.values(errors).flat().join("\n")
                    : (err.response?.data?.message ?? "Could not send that decision."));
            }
        } finally {
            setBusy(false);
        }
    };

    const commit = async () => {
        if (!window.confirm(
            "Add this material to the module?\n\n"
            + "It arrives as pending, so students will not see it until you "
            + "approve it in the Content Queue."
        )) return;

        setBusy(true);

        try {
            const response = await api.post(`/learning-runs/${selected.id}/commit`);
            const c = response.data.created;

            alert(
                `${response.data.message}\n\n`
                + `${c.tutorial} tutorial, ${c.quiz} quiz questions, `
                + `${c.project} project, ${c.resources} resources.`
            );

            await Promise.all([loadRuns(), loadPending()]);
            await openRun(selected);
            onChanged?.();
        } catch (err) {
            if (err.response?.status === 503) {
                setServiceDown(true);
            } else {
                alert(err.response?.data?.message ?? "Could not commit this material.");
            }
        } finally {
            setBusy(false);
        }
    };

    const data = result?.data;
    const awaitingReview = result?.status === "awaiting_review";
    // The envelope, which now ends at "ready_for_commit" like the roadmap
    // workflows do - it said "ready" until she unified the wording. The
    // tutorial_result inside data still carries a "ready" of its own.
    const ready = result?.status === "ready_for_commit";

    const committed = !!runs.find((r) => r.id === selected?.id)?.committed;

    return (
        <div className="p-8">
            <div className="flex items-center gap-3">
                <Sparkles size={32} className="text-[#0097a7]" />
                <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">
                    Learning Material
                </h1>
            </div>

            <p className="text-gray-400 mt-2 mb-10">
                The AI writes a tutorial, quiz, project and resources for one module.
                Nothing reaches students until you approve it.
            </p>

            {/* Fixing what a module already has, by hand. First on the
                page: it is the one part always worth showing, since it works
                whatever else is or is not waiting.

                The generate form that used to stand here is gone - generation
                is started from the awaiting list below, one module at a time,
                which is the same call with the module already named. Its
                dropdowns chose the module for this editor too, so the editor
                keeps a picker of its own rather than losing the only way to
                author a quiz question by hand. */}
            <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6 mb-10">
                <div className="flex items-center gap-3 mb-2">
                    <Pencil size={20} className="text-[#0097a7]" />
                    <h2 className="text-2xl font-bold">Edit a module's material</h2>
                </div>

                <p className="text-sm text-gray-400 mb-5">
                    Correct what the AI wrote, or add a tutorial, quiz question
                    or project by hand.
                </p>

                <div className="grid md:grid-cols-3 gap-4">
                    <select value={roadmapId ?? ""}
                        onChange={(e) => setRoadmapId(Number(e.target.value))}
                        className="p-3 rounded-xl bg-black/30 border border-white/10 text-white">
                        {/* A dropdown nests with optgroup rather than a second
                            row, so a roadmap reads as belonging to its subject
                            instead of sitting beside it as a peer.

                            The subject is the group label and nothing more: it
                            is a heading over Frontend and Backend with no
                            modules of its own. optgroup cannot nest, so the
                            third level is indented instead. */}
                        {roadmaps
                            .filter((r) => !r.parent_roadmap_id)
                            .map((subject) => {
                                const paths = roadmaps.filter(
                                    (r) => r.parent_roadmap_id === subject.id
                                );

                                if (paths.length === 0) return null;

                                return (
                                    <optgroup key={subject.id} label={subject.title}>
                                        {paths.flatMap((path) => [
                                            <option key={path.id} value={path.id}>
                                                {path.title}
                                            </option>,

                                            ...roadmaps
                                                .filter((r) => r.parent_roadmap_id === path.id)
                                                .map((child) => (
                                                    <option key={child.id} value={child.id}>
                                                        {"  — "}{child.title}
                                                    </option>
                                                )),
                                        ])}
                                    </optgroup>
                                );
                            })}
                    </select>

                    <select value={versionId ?? ""}
                        onChange={(e) => setVersionId(Number(e.target.value))}
                        className="p-3 rounded-xl bg-black/30 border border-white/10 text-white">
                        {versions.map((v) => (
                            <option key={v.id} value={v.id}>
                                v{v.version_number}{v.title ? ` · ${v.title}` : ""} ({v.status})
                            </option>
                        ))}
                    </select>

                    <select value={moduleId ?? ""}
                        onChange={(e) => setModuleId(Number(e.target.value))}
                        className="p-3 rounded-xl bg-black/30 border border-white/10 text-white">
                        {modules.length === 0 && <option value="">No modules</option>}
                        {modules.map((m) => (
                            <option key={m.version_node_id} value={m.version_node_id}>
                                {m.title}
                            </option>
                        ))}
                    </select>
                </div>

                {moduleId && (
                    <div className="mt-6 pt-6 border-t border-white/10">
                        <ModuleMaterialEditor versionNodeId={moduleId} onChanged={onChanged} />
                    </div>
                )}
            </div>

            {/* Awaiting material: the worklist the roadmap builder leaves
                behind, since it writes a subject of empty modules and starts
                nothing. Folded away by default - seventy-odd modules is a
                wall of a thing to scroll past, and the count in the header
                says how many are waiting without opening any of it. */}
            {pendingTotal > 0 && (
                <div className="rounded-3xl border border-yellow-400/20 bg-yellow-400/[0.03] mb-10 overflow-hidden">
                    <button
                        onClick={() => setAwaitingOpen((was) => !was)}
                        className="w-full flex items-center gap-3 p-6 text-left hover:bg-white/[0.02] transition flex-wrap">
                        <Layers size={20} className="text-yellow-400 shrink-0" />
                        <h2 className="text-2xl font-bold">Awaiting material</h2>
                        <span className="text-xs px-3 py-1 rounded-full bg-yellow-400/10 text-yellow-300">
                            {pendingTotal} {pendingTotal === 1 ? "module" : "modules"}
                        </span>

                        <ChevronRight size={18} className={`text-gray-600 shrink-0 ml-auto transition-transform ${
                            awaitingOpen ? "rotate-90" : ""
                        }`} />
                    </button>

                    {awaitingOpen && (
                        <div className="px-6 pb-6">
                    <p className="text-sm text-gray-400 mb-6">
                        Modules with nothing on them yet. Generate them one at a
                        time - each takes a few minutes, and starting many at once
                        is what the AI service cannot take.
                    </p>

                    <div className="space-y-2">
                        {pending.map((group) => {
                            const open = openGroup === group.roadmap;

                            return (
                                <div key={group.roadmap}
                                     className="rounded-2xl border border-white/10 bg-black/20 overflow-hidden">
                                    <button
                                        onClick={() => setOpenGroup(open ? null : group.roadmap)}
                                        className="w-full flex items-center justify-between gap-3 p-4 text-left hover:bg-white/[0.02] transition">
                                        <span className="font-semibold">{group.roadmap}</span>

                                        <span className="flex items-center gap-3 shrink-0">
                                            <span className="text-xs text-gray-500">
                                                {group.modules.length} to write
                                            </span>
                                            <ChevronRight size={16} className={`text-gray-600 transition-transform ${
                                                open ? "rotate-90" : ""
                                            }`} />
                                        </span>
                                    </button>

                                    {open && (
                                        <div className="px-4 pb-4 space-y-2">
                                            {group.modules.map((m) => {
                                                const mine = generating === m.version_node_id;

                                                return (
                                                    <div key={m.version_node_id}
                                                         className="flex items-center justify-between gap-3 rounded-xl border border-white/5 bg-black/20 p-3">
                                                        <div className="min-w-0">
                                                            <span className="text-sm font-medium">{m.title}</span>

                                                            {m.difficulty && (
                                                                <span className="ml-2 text-xs text-gray-600 capitalize">
                                                                    {m.difficulty}
                                                                </span>
                                                            )}
                                                        </div>

                                                        {/* Generation takes minutes and says so on
                                                            the button, because a spinner alone read
                                                            as a dead control and invited a second
                                                            press. */}
                                                        {mine ? (
                                                            <span className="flex items-center gap-2 text-xs text-[#00b0c2] shrink-0">
                                                                <RefreshCw size={13} className="animate-spin" />
                                                                Starting - this takes a few minutes
                                                            </span>
                                                        ) : (
                                                            <button
                                                                onClick={() => generateFor(m)}
                                                                disabled={!!generating || busy}
                                                                className="flex items-center gap-1.5 px-4 py-2 rounded-xl border border-[#0097a7]/30 text-[#00b0c2] text-xs hover:bg-[#0097a7]/10 transition disabled:opacity-30 shrink-0">
                                                                <Wand2 size={13} /> Generate
                                                            </button>
                                                        )}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>

                    {generating && (
                        <p className="text-xs text-gray-500 mt-4">
                            One at a time - the other modules become available again
                            when this run has started.
                        </p>
                    )}
                        </div>
                    )}
                </div>
            )}

            {serviceDown && (
                <div className="mb-8 flex items-start gap-3 rounded-2xl border border-yellow-400/30 bg-yellow-400/5 p-4">
                    <AlertTriangle size={18} className="text-yellow-400 shrink-0 mt-0.5" />
                    <div>
                        <p className="font-semibold text-yellow-300">AI service unavailable</p>
                        <p className="text-sm text-gray-300 mt-1">
                            The generation service is not running. Nothing has been
                            lost - a run in progress resumes when it is back.
                        </p>
                    </div>
                </div>
            )}

            <div className="grid lg:grid-cols-[320px_1fr] gap-8">
                {/* Runs */}
                <div>
                    <h2 className="text-xl font-bold mb-4">Runs</h2>

                    {loading ? (
                        <p className="text-gray-400">Loading...</p>
                    ) : runs.length === 0 ? (
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8 text-center">
                            <Inbox size={40} className="mx-auto text-gray-600 mb-3" />
                            <p className="text-gray-400 text-sm">
                                No material generated yet.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {runs.map((run) => (
                                <button key={run.id} onClick={() => openRun(run)}
                                    className={`w-full text-left rounded-2xl border p-4 transition ${
                                        selected?.id === run.id
                                            ? "border-[#0097a7]/40 bg-[#0097a7]/10"
                                            : "border-white/10 bg-white/[0.03] hover:border-[#0097a7]/30"
                                    }`}>
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-semibold truncate">{run.module}</span>
                                        <ChevronRight size={16} className="text-gray-500 shrink-0" />
                                    </div>

                                    <p className="text-xs text-gray-500 mt-1">{run.roadmap}</p>

                                    <span className={`inline-block mt-2 text-xs px-3 py-1 rounded-full border ${
                                        run.committed
                                            ? "border-green-400/30 bg-green-400/10 text-green-300"
                                            : run.awaiting
                                            ? "border-yellow-400/30 bg-yellow-400/10 text-yellow-300"
                                            : "border-white/10 bg-white/5 text-gray-400"
                                    }`}>
                                        {run.committed ? "committed" : run.status.replace(/_/g, " ")}
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {/* The material */}
                <div>
                    {!selected ? (
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-12 text-center">
                            <h3 className="text-xl font-bold">Select a run</h3>
                            <p className="text-gray-400 mt-2">
                                Pick a run on the left to read what the AI wrote.
                            </p>
                        </div>
                    ) : busy && !result ? (
                        <p className="text-gray-400">Loading...</p>
                    ) : data ? (
                        <div className="space-y-6">
                            <Section icon={<Video size={18} className="text-[#0097a7]" />} title="Tutorial">
                                {data.tutorial_result?.tutorial ? (
                                    <div className="rounded-2xl border border-white/10 bg-black/30 p-4">
                                        <h4 className="font-semibold">{data.tutorial_result.tutorial.title}</h4>
                                        <p className="text-xs text-gray-500 mt-1">
                                            {data.tutorial_result.tutorial.channel_name}
                                        </p>
                                        <a href={data.tutorial_result.tutorial.url} target="_blank" rel="noreferrer"
                                           className="mt-2 inline-block text-[#00b0c2] text-sm hover:underline break-all">
                                            {data.tutorial_result.tutorial.url}
                                        </a>
                                        <p className="text-sm text-gray-400 mt-3">
                                            {data.tutorial_result.tutorial.selection_reason}
                                        </p>
                                        {data.tutorial_result.coverage?.summary && (
                                            <p className="text-xs text-gray-500 mt-3 border-l-2 border-white/10 pl-3">
                                                {data.tutorial_result.coverage.summary}
                                            </p>
                                        )}
                                    </div>
                                ) : (
                                    <p className="text-sm text-gray-500">
                                        No suitable tutorial was found.
                                    </p>
                                )}
                            </Section>

                            <Section icon={<Brain size={18} className="text-purple-400" />}
                                     title="Quiz" count={data.quiz_result?.questions?.length}>
                                {data.quiz_result?.questions?.map((q, i) => (
                                    <div key={i} className="rounded-2xl border border-white/10 bg-black/30 p-4">
                                        <p className="font-semibold">{i + 1}. {q.question}</p>

                                        <div className="mt-3 space-y-1">
                                            {q.options?.map((o) => (
                                                <p key={o.key} className={`text-sm ${
                                                    o.key === q.correct_option
                                                        ? "text-[#00b0c2]"
                                                        : "text-gray-400"
                                                }`}>
                                                    {o.key}. {o.text}
                                                    {o.key === q.correct_option && " ✓"}
                                                </p>
                                            ))}
                                        </div>

                                        <p className="text-xs text-gray-500 mt-3">
                                            {q.correct_answer_explanation}
                                        </p>
                                    </div>
                                ))}
                            </Section>

                            <Section icon={<Code2 size={18} className="text-green-400" />} title="Project">
                                {data.project_result?.title && (
                                    <div className="rounded-2xl border border-white/10 bg-black/30 p-4">
                                        <h4 className="font-semibold">{data.project_result.title}</h4>
                                        <p className="text-sm text-gray-400 mt-2">
                                            {data.project_result.overview}
                                        </p>

                                        {/* What the project is for. A reviewer is
                                            judging whether it teaches the module,
                                            and this is the AI saying what it thinks
                                            the module teaches. */}
                                        {data.project_result.learning_objective && (
                                            <p className="mt-3 text-xs text-gray-400 border-l-2 border-[#0097a7]/30 pl-3">
                                                {data.project_result.learning_objective}
                                            </p>
                                        )}

                                        {[
                                            ["Tasks", data.project_result.tasks],
                                            ["Requirements", data.project_result.technical_requirements],
                                            // What a student may not do, and what they
                                            // hand in - both were being dropped, and
                                            // both are graded against later.
                                            ["Constraints", data.project_result.constraints],
                                            ["Deliverables", data.project_result.deliverables],
                                            ["Done when", data.project_result.acceptance_criteria],
                                        ].map(([heading, items]) => (
                                            items?.length ? (
                                                <div key={heading} className="mt-3">
                                                    <p className="text-xs text-gray-500 mb-1">{heading}</p>
                                                    <ul className="space-y-1">
                                                        {items.map((item, i) => (
                                                            <li key={i} className="text-sm text-gray-300">· {item}</li>
                                                        ))}
                                                    </ul>
                                                </div>
                                            ) : null
                                        ))}
                                    </div>
                                )}
                            </Section>

                            <Section icon={<BookOpen size={18} className="text-blue-400" />}
                                     title="Resources"
                                     count={data.external_resources_result?.resources?.length}>
                                {data.external_resources_result?.resources?.map((r, i) => (
                                    <div key={i} className="rounded-2xl border border-white/10 bg-black/30 p-4">
                                        <h4 className="font-semibold">{r.title}</h4>
                                        <p className="text-xs text-gray-500 mt-1">
                                            {r.source_name} · {r.resource_type.replace(/_/g, " ")}
                                        </p>
                                        <p className="text-sm text-gray-400 mt-2">{r.description}</p>

                                        {/* The link itself, so a reviewer can check
                                            the resource rather than judge it by its
                                            title - approving a dead or wrong link is
                                            the easiest mistake to make here. */}
                                        {r.url && (
                                            <a href={r.url} target="_blank" rel="noreferrer"
                                                className="mt-2 inline-flex items-start gap-1.5 text-xs text-[#00b0c2] hover:underline break-all">
                                                <ExternalLink size={12} className="shrink-0 mt-0.5" />
                                                {r.url}
                                            </a>
                                        )}

                                        {/* Why the AI picked it. This is the argument
                                            a reviewer is actually accepting or
                                            refusing, and it was being dropped. */}
                                        {r.why_recommended && (
                                            <p className="mt-3 text-xs text-gray-400 border-l-2 border-[#0097a7]/30 pl-3">
                                                {r.why_recommended}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </Section>

                            {awaitingReview && (
                                <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-6">
                                    <h3 className="text-xl font-bold mb-4">Your decision</h3>

                                    <textarea rows="3" value={feedback}
                                        onChange={(e) => setFeedback(e.target.value)}
                                        placeholder="Feedback (required to reject)"
                                        className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white text-sm mb-4" />

                                    <div className="flex flex-wrap gap-3">
                                        {/* Her schema forbids a stage or data on approve. */}
                                        <button onClick={() => decide({ action: "approve" })} disabled={busy}
                                            className="flex items-center gap-2 px-6 py-3 rounded-2xl bg-green-500 hover:bg-green-600 font-semibold transition disabled:opacity-50">
                                            <Check size={18} /> Approve
                                        </button>

                                        <button onClick={() => decide({ action: "reject", feedback })} disabled={busy}
                                            className="flex items-center gap-2 px-6 py-3 rounded-2xl bg-red-500 hover:bg-red-600 font-semibold transition disabled:opacity-50">
                                            <X size={18} /> Reject
                                        </button>
                                    </div>

                                    <div className="mt-5 pt-5 border-t border-white/10">
                                        <p className="text-sm text-gray-400 mb-3">
                                            Or ask it to write one part again:
                                        </p>

                                        <div className="flex flex-wrap gap-3">
                                            {STAGES.map((stage) => (
                                                <button key={stage.id}
                                                    onClick={() => decide({
                                                        action: "regenerate",
                                                        stage: stage.id,
                                                        ...(feedback.trim() ? { feedback } : {}),
                                                    })}
                                                    disabled={busy}
                                                    className="flex items-center gap-2 px-5 py-2 rounded-xl border border-[#0097a7]/30 text-[#00b0c2] hover:bg-[#0097a7]/10 transition text-sm disabled:opacity-50">
                                                    <RefreshCw size={14} /> {stage.label}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            )}

                            {ready && (
                                <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-6">
                                    <h3 className="text-xl font-bold mb-2">Approved</h3>

                                    <p className="text-sm text-gray-400 mb-5">
                                        Nothing has been written yet. Adding it puts the
                                        tutorial, quiz, project and resources on the module
                                        as pending, for you to approve in the Content Queue.
                                    </p>

                                    {committed ? (
                                        <p className="text-sm text-green-300">
                                            Already added to the module.
                                        </p>
                                    ) : (
                                        <button onClick={commit} disabled={busy}
                                            className="flex items-center gap-2 px-6 py-3 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-105 transition disabled:bg-gray-700 disabled:text-gray-400">
                                            <Check size={18} />
                                            {busy ? "Adding..." : "Add to the Module"}
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

function Section({ icon, title, count, children }) {
    return (
        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
            <div className="flex items-center gap-3 mb-4">
                {icon}
                <h3 className="text-lg font-bold">{title}</h3>
                {count !== undefined && (
                    <span className="text-xs px-3 py-1 rounded-full bg-white/5 text-gray-400">
                        {count ?? 0}
                    </span>
                )}
            </div>

            <div className="space-y-3">{children}</div>
        </div>
    );
}

export default MaterialLearningReview;