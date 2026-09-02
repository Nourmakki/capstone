import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import {
    Bot, TrendingUp, Plus, Network, Trash2, Check, X, RefreshCw,
    AlertTriangle, Inbox, ChevronRight, GitBranch, ExternalLink, Clock,
} from "lucide-react";

// Stage slugs the AI service accepts for regenerate/edit.
const STAGES = [
    { id: "trends", label: "trends" },
    { id: "nodes", label: "node suggestions" },
    { id: "placement", label: "placement" },
];

function MaterialAiReview({ onChanged }) {
    const [workflows, setWorkflows] = useState([]);
    const [selected, setSelected] = useState(null);
    const [proposal, setProposal] = useState(null);
    const [feedback, setFeedback] = useState("");
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [serviceDown, setServiceDown] = useState(false);

    // The daily scan's switch, which lives on this screen because this is
    // where the runs it produces arrive.
    const [schedule, setSchedule] = useState(null);
    const [savingSchedule, setSavingSchedule] = useState(false);

    // Unsaved edits, one pair per editable stage. Null while not editing, so
    // each list falls back to what the AI actually sent.
    const [editingNodes, setEditingNodes] = useState(false);
    const [nodeEdits, setNodeEdits] = useState(null);

    const [editingTrends, setEditingTrends] = useState(false);
    const [trendEdits, setTrendEdits] = useState(null);

    const [editingPlacement, setEditingPlacement] = useState(false);
    const [placementEdits, setPlacementEdits] = useState(null);

    // The proposal refers to existing modules by id, which means nothing to
    // a reviewer. The version's own graph is what turns them into titles, and
    // what the placement editor offers as things to link to - so it is still
    // fetched even though the roadmap is no longer listed on this page.
    const [existingNodes, setExistingNodes] = useState([]);

    const loadList = useCallback(async () => {
        try {
            const response = await api.get("/ai-workflows");
            setWorkflows(response.data.workflows);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { loadList(); }, [loadList]);

    useEffect(() => {
        api.get("/ai-schedule")
            .then((r) => setSchedule(r.data))
            .catch((err) => console.error(err));
    }, []);

    /**
     * Turn the daily scan on or off, or move when it runs.
     *
     * Saved immediately rather than behind a Save button: there is one switch
     * and one time, and someone reaching for this before a demo wants it off
     * now, not after confirming a form.
     */
    const saveSchedule = async (change) => {
        setSavingSchedule(true);

        try {
            const response = await api.put("/ai-schedule", change);
            setSchedule(response.data.schedule);
        } catch (err) {
            const errors = err.response?.data?.errors;
            alert(errors
                ? Object.values(errors).flat().join("\n")
                : (err.response?.data?.message ?? "Could not change the schedule."));
        } finally {
            setSavingSchedule(false);
        }
    };

    /** One field of one item, while the edits are unsaved. */
    const updateEdit = (setter) => (index, field, value) =>
        setter((prev) => prev.map((item, i) => (i === index ? { ...item, [field]: value } : item)));

    const updateNodeEdit      = updateEdit(setNodeEdits);
    const updateTrendEdit     = updateEdit(setTrendEdits);
    const updatePlacementEdit = updateEdit(setPlacementEdits);

    /** Add or drop one module from one of a placement's three id lists. */
    const togglePlacementLink = (index, field, nodeId) => {
        setPlacementEdits((prev) => prev.map((p, i) => {
            if (i !== index) return p;

            const ids = p[field] ?? [];

            return {
                ...p,
                [field]: ids.includes(nodeId)
                    ? ids.filter((id) => id !== nodeId)
                    : [...ids, nodeId],
            };
        }));
    };

    /**
     * Send the edited modules back.
     *
     * Her validator is strict about this: an edit carries a stage and a data
     * object holding exactly that stage's key, and no feedback. Editing the
     * modules also invalidates where they were going to sit, so the placement
     * is regenerated rather than left describing the old titles.
     */
    const saveNodeEdits = async () => {
        await decide({
            action: "edit",
            stage: "nodes",
            data: { node_suggestions: nodeEdits },
            regenerate_after_edit: true,
        });

        setEditingNodes(false);
        setNodeEdits(null);
    };

    /**
     * Send the edited trends back.
     *
     * Every payload below is rebuilt field by field rather than spread from
     * what she sent. Her edit models are extra="forbid", and the response
     * carries fields the edit request does not accept - passing the object
     * back whole is how an edit gets refused for a key nobody touched.
     *
     * The trends are what the modules were derived from, so changing one
     * leaves the modules describing the old reading: regenerated, not kept.
     */
    const saveTrendEdits = async () => {
        await decide({
            action: "edit",
            stage: "trends",
            data: {
                // Title and description are the manager's; the score and the
                // sources are the AI's and ride back exactly as they came.
                // Her model requires all four, so they are sent, not dropped.
                trends: trendEdits.map((t) => ({
                    title:            t.title,
                    description:      t.description,
                    confidence_score: t.confidence_score,
                    sources: (t.sources ?? []).map((s) => ({
                        source_name: s.source_name,
                        url:         s.url,
                    })),
                })),
            },
            regenerate_after_edit: true,
        });

        setEditingTrends(false);
        setTrendEdits(null);
    };

    /**
     * Send the edited placement back.
     *
     * Placement is the last stage, so nothing downstream needs regenerating -
     * asking for it would throw away the placement just written by hand.
     *
     * Her stage expects both keys together, so the removals ride along
     * unchanged even when only a placement was touched.
     */
    const savePlacementEdits = async () => {
        // Her PlacementSuggestionEditRequest refuses a module wired to
        // nothing. Caught here so it reads as a sentence rather than as a
        // validation error returned from a service two hops away.
        const orphan = placementEdits.find((p) =>
            !(p.direct_prerequisite_node_ids ?? []).length
            && !(p.direct_successor_node_ids ?? []).length);

        if (orphan) {
            alert(`"${orphan.suggested_node_title}" needs at least one module `
                + "before or after it.");
            return;
        }

        await decide({
            action: "edit",
            stage: "placement",
            data: {
                placement_suggestions: placementEdits.map((p) => ({
                    suggested_node_id:                p.suggested_node_id,
                    suggested_node_title:             p.suggested_node_title,
                    direct_prerequisite_node_ids:     p.direct_prerequisite_node_ids ?? [],
                    direct_successor_node_ids:        p.direct_successor_node_ids ?? [],
                    recommended_predecessor_node_ids: p.recommended_predecessor_node_ids ?? [],
                    explanation:                      p.explanation,
                })),
                existing_edges_to_remove: (data?.existing_edges_to_remove ?? []).map((e) => ({
                    from_node_id: e.from_node_id,
                    to_node_id:   e.to_node_id,
                })),
            },
            regenerate_after_edit: false,
        });

        setEditingPlacement(false);
        setPlacementEdits(null);
    };

    /**
     * Drop every unsaved edit.
     *
     * Opening another run has to clear these: the edits are keyed by position
     * in a list, so an unsaved edit left over from one run would render
     * against the next one's items and save that run's titles onto this one.
     */
    const clearEdits = () => {
        setEditingNodes(false);     setNodeEdits(null);
        setEditingTrends(false);    setTrendEdits(null);
        setEditingPlacement(false); setPlacementEdits(null);
    };

    const openWorkflow = async (workflow) => {
        setSelected(workflow);
        setProposal(null);
        setFeedback("");
        setServiceDown(false);
        setExistingNodes([]);
        clearEdits();
        setBusy(true);

        // Fetched separately and allowed to fail: without titles the proposal
        // is harder to read, but it should still be reviewable.
        api.get(`/roadmap-versions/${workflow.version_id}/graph`)
            .then((r) => setExistingNodes(r.data.nodes ?? []))
            .catch((err) => console.error(err));

        try {
            const response = await api.get(`/ai-workflows/${workflow.id}`);
            setProposal(response.data);
        } catch (err) {
            // 503 means the Python service is not running.
            if (err.response?.status === 503) {
                setServiceDown(true);
            } else {
                alert(err.response?.data?.message ?? "Could not load this proposal.");
            }
        } finally {
            setBusy(false);
        }
    };

    const decide = async (payload) => {
        if (payload.action === "reject" && feedback.trim().length < 1) {
            alert("Tell the AI why you are rejecting this so the next run can do better.");
            return;
        }

        setBusy(true);

        try {
            const response = await api.post(`/ai-workflows/${selected.id}/resume`, payload);
            setProposal(response.data);
            setFeedback("");
            await loadList();
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

    /**
     * Approving only marks the proposal ready. This is the step that writes
     * the approved modules and edges into a new roadmap version.
     */
    const commit = async () => {
        if (!window.confirm(
            "Create a new draft version from this proposal?\n\n"
            + "It starts as a Draft awaiting review, so nothing goes live yet."
        )) return;

        setBusy(true);

        try {
            const response = await api.post(`/ai-workflows/${selected.id}/commit`);
            alert(`${response.data.message}\n\n${response.data.version} created.`);
            await loadList();
            await openWorkflow(selected);
            onChanged?.();
        } catch (err) {
            if (err.response?.status === 503) {
                setServiceDown(true);
            } else {
                alert(err.response?.data?.message ?? "Could not commit this proposal.");
            }
        } finally {
            setBusy(false);
        }
    };

    const data = proposal?.data;
    const awaitingReview = proposal?.status === "awaiting_review";
    const readyToCommit = proposal?.status === "ready_for_commit";

    /**
     * Suggested modules are checked first, exactly as the commit does: the
     * AI numbers its new modules from max(existing id) + 1, which collides
     * with real ids belonging to other roadmaps.
     */
    const titleFor = (id) => {
        const suggested = data?.node_suggestions_with_ids?.find((n) => n.id === id);
        if (suggested) return suggested.title;

        const existing = existingNodes.find((n) => n.node_id === id);
        if (existing) return existing.title;

        return `module ${id}`;
    };

    const NodeChips = ({ label, ids }) => {
        if (!ids?.length) return null;

        return (
            <div className="flex flex-wrap items-center gap-2 mt-2">
                <span className="text-xs text-gray-500">{label}</span>
                {ids.map((id) => (
                    <span key={id}
                          className="text-xs px-3 py-1 rounded-full border border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2]">
                        {titleFor(id)}
                    </span>
                ))}
            </div>
        );
    };

    /**
     * Every module this proposal could link to: the ones already on the
     * roadmap and the ones it is proposing, since a new module may well come
     * after another new one.
     */
    const linkableNodes = [
        ...(data?.node_suggestions_with_ids ?? []).map((n) => ({ id: n.id, title: n.title })),
        ...existingNodes.map((n) => ({ id: n.node_id, title: n.title })),
    ];

    /**
     * The chips again, but as toggles.
     *
     * A text box for a list of numeric ids would be asking a manager to type
     * the thing the ids exist to hide - and one typo writes an edge to a
     * module on another roadmap. Only real modules can be picked here.
     */
    const NodePicker = ({ index, label, field, selected, self }) => (
        <div>
            <span className="text-xs text-gray-500">{label}</span>

            <div className="flex flex-wrap gap-2 mt-1.5">
                {linkableNodes
                    // A module cannot come after itself.
                    .filter((n) => n.id !== self)
                    .map((n) => {
                        const on = (selected ?? []).includes(n.id);

                        return (
                            <button key={n.id} type="button"
                                onClick={() => togglePlacementLink(index, field, n.id)}
                                className={`text-xs px-3 py-1 rounded-full border transition ${
                                    on
                                        ? "border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2]"
                                        : "border-white/10 text-gray-500 hover:border-white/20"
                                }`}>
                                {n.title}
                            </button>
                        );
                    })}

                {linkableNodes.length === 0 && (
                    <span className="text-xs text-gray-600">No modules to link to.</span>
                )}
            </div>
        </div>
    );

    // Read from the list rather than `selected`, so it refreshes after a
    // commit instead of holding the row as it was when it was opened.
    const alreadyCommitted = !!workflows.find((w) => w.id === selected?.id)?.committed_version_id;

    return (
        <div className="p-8">
            <div className="flex items-center gap-3">
                <Bot size={32} className="text-[#0097a7]" />
                <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">AI-detected trends</h1>
            </div>
            <p className="text-gray-400 mt-2 mb-6">
                The AI suggests roadmap changes. Nothing reaches students until you approve it.
            </p>

            {/* The daily scan's switch. It spends API quota and takes minutes,
                so it lives where its results land rather than buried in a
                settings page - the person who reviews the proposals is the one
                who wants it off during a demo. */}
            {schedule && (
                <div className="mb-10 rounded-3xl border border-white/10 bg-white/[0.03] p-5 flex flex-col lg:flex-row lg:items-center gap-5">
                    <div className="flex items-center gap-3 flex-1 min-w-0">
                        <Clock size={20} className={schedule.enabled ? "text-[#0097a7]" : "text-gray-600"} />

                        <div className="min-w-0">
                            <p className="font-semibold">
                                Daily scan{" "}
                                <span className={schedule.enabled ? "text-[#00b0c2]" : "text-gray-500"}>
                                    {schedule.enabled ? `on · ${schedule.run_at}` : "off"}
                                </span>
                            </p>

                            <p className="text-xs text-gray-500 mt-1">
                                {schedule.enabled
                                    ? `Every day at ${schedule.run_at}, all ${schedule.roadmaps_in_scope} published roadmaps go to the AI.`
                                    : `Off. Turning it on sends all ${schedule.roadmaps_in_scope} published roadmaps to the AI each day.`}
                                {schedule.last_run_at && (
                                    <> Last run {new Date(schedule.last_run_at).toLocaleString()} — {schedule.last_run_summary}</>
                                )}
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-3 shrink-0">
                        <input
                            type="time"
                            value={schedule.run_at}
                            disabled={savingSchedule}
                            onChange={(e) => saveSchedule({ run_at: e.target.value })}
                            className="p-2 rounded-xl bg-black/30 border border-white/10 text-white text-sm"
                        />

                        <button
                            onClick={() => saveSchedule({ enabled: !schedule.enabled })}
                            disabled={savingSchedule}
                            className={`px-5 py-2.5 rounded-2xl font-semibold text-sm transition ${
                                savingSchedule
                                    ? "bg-gray-700 text-gray-400 cursor-wait"
                                    : schedule.enabled
                                    ? "bg-white/5 border border-white/10 text-gray-200 hover:border-red-400/40"
                                    : "bg-[#0097a7] text-black hover:scale-[1.02]"
                            }`}
                        >
                            {savingSchedule ? "Saving..." : schedule.enabled ? "Turn off" : "Turn on"}
                        </button>
                    </div>
                </div>
            )}

            <div className="grid lg:grid-cols-[360px_1fr] gap-8">

                {/* Workflow list */}
                <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-5 h-fit">
                    <h2 className="text-xl font-bold mb-5">Runs</h2>

                    {loading ? (
                        <p className="text-gray-400 text-sm">Loading...</p>
                    ) : workflows.length === 0 ? (
                        <div className="text-center py-8">
                            <Inbox size={36} className="mx-auto text-gray-600 mb-3" />
                            <p className="text-gray-400 text-sm">No AI runs yet.</p>
                            <p className="text-gray-600 text-xs mt-2">
                                Runs appear here when the AI proposes roadmap changes.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-2">
                            {workflows.map((w) => (
                                <button key={w.id} onClick={() => openWorkflow(w)}
                                    className={`w-full text-left px-4 py-3 rounded-2xl border transition ${
                                        selected?.id === w.id
                                            ? "border-[#0097a7]/40 bg-[#0097a7]/10"
                                            : "border-white/10 bg-black/20 hover:border-[#0097a7]/30"
                                    }`}>
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-semibold truncate">
                                            {w.roadmap} {w.version_label}
                                        </span>
                                        <ChevronRight size={16} className="text-gray-600 shrink-0" />
                                    </div>

                                    <span className={`inline-block mt-2 text-xs px-2 py-0.5 rounded-full ${
                                        w.awaiting
                                            ? "bg-yellow-400/20 text-yellow-300"
                                            : "bg-white/5 text-gray-500"
                                    }`}>
                                        {w.status.replace(/_/g, " ")}
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {/* Proposal detail */}
                <div>
                    {serviceDown ? (
                        <div className="rounded-3xl border border-yellow-400/30 bg-yellow-400/5 p-8">
                            <div className="flex items-center gap-3 mb-3">
                                <AlertTriangle className="text-yellow-400" />
                                <h2 className="text-2xl font-bold">AI service unavailable</h2>
                            </div>
                            <p className="text-gray-400">
                                The AI service is not running, so proposals cannot be loaded or
                                answered right now. Nothing has been lost - the run resumes when
                                the service is back.
                            </p>
                        </div>
                    ) : !selected ? (
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-12 text-center">
                            <Bot size={48} className="mx-auto text-gray-600 mb-4" />
                            <h2 className="text-2xl font-bold">Select a run</h2>
                            <p className="text-gray-400 mt-2">
                                Pick a run on the left to read what the AI is proposing.
                            </p>
                        </div>
                    ) : busy && !proposal ? (
                        <p className="text-gray-400">Loading proposal...</p>
                    ) : proposal ? (
                        <div className="space-y-6">

                            <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                                <div className="flex items-center justify-between flex-wrap gap-3">
                                    <div>
                                        <h2 className="text-2xl font-bold">
                                            {selected.roadmap} {selected.version_label}
                                        </h2>
                                        <p className="text-xs text-gray-600 mt-1 break-all">
                                            thread {proposal.thread_id}
                                        </p>
                                    </div>

                                    <span className={`text-sm px-4 py-1 rounded-full ${
                                        awaitingReview
                                            ? "bg-yellow-400/20 text-yellow-300"
                                            : "bg-white/5 text-gray-400"
                                    }`}>
                                        {proposal.status?.replace(/_/g, " ")}
                                    </span>
                                </div>

                                {proposal.error && (
                                    <p className="mt-4 text-sm text-red-400">{proposal.error}</p>
                                )}

                                {proposal.rejection_feedback && (
                                    <p className="mt-4 text-sm text-gray-400">
                                        Your feedback: {proposal.rejection_feedback}
                                    </p>
                                )}
                            </div>

                            {data && (
                                <>
                                    <Section icon={<TrendingUp size={18} className="text-[#0097a7]" />}
                                             title="Detected trends" count={data.trends?.length}>
                                        {(trendEdits ?? data.trends ?? []).map((t, i) => (
                                            <div key={i} className="rounded-2xl border border-white/10 bg-black/30 p-4">
                                                {editingTrends ? (
                                                    <div className="space-y-3">
                                                        <input
                                                            value={t.title}
                                                            onChange={(e) => updateTrendEdit(i, "title", e.target.value)}
                                                            className="w-full p-2 rounded-lg bg-black/40 border border-white/10 text-white font-semibold"
                                                        />

                                                        <textarea
                                                            value={t.description}
                                                            rows={3}
                                                            onChange={(e) => updateTrendEdit(i, "description", e.target.value)}
                                                            className="w-full p-2 rounded-lg bg-black/40 border border-white/10 text-white text-sm"
                                                        />

                                                        {/* Shown, not editable. The confidence
                                                            is the AI's own reading of its
                                                            evidence, like the sources under
                                                            it - a manager rewording a trend
                                                            has not re-run that judgement, and
                                                            a hand-set number would read as
                                                            though they had. */}
                                                        {t.confidence_score != null && (
                                                            <p className="text-xs text-gray-500">
                                                                {Math.round(t.confidence_score)}% confidence, and its
                                                                sources, stay as the AI reported them.
                                                            </p>
                                                        )}
                                                    </div>
                                                ) : (
                                                <>
                                                <div className="flex items-start justify-between gap-3">
                                                    <h4 className="font-semibold">{t.title}</h4>

                                                    {/* Her score is already a percentage -
                                                        95 means 95. Multiplying by 100
                                                        rendered "9500% confidence". */}
                                                    {t.confidence_score != null && (
                                                        <span className={`text-xs shrink-0 px-2 py-0.5 rounded-full ${
                                                            t.confidence_score >= 80
                                                                ? "bg-green-400/10 text-green-300"
                                                                : t.confidence_score >= 50
                                                                ? "bg-yellow-400/10 text-yellow-300"
                                                                : "bg-red-400/10 text-red-300"
                                                        }`}>
                                                            {Math.round(t.confidence_score)}% confidence
                                                        </span>
                                                    )}
                                                </div>

                                                <p className="text-sm text-gray-400 mt-2">{t.description}</p>

                                                {/* The evidence. A manager approving a
                                                    module because the AI called something
                                                    a trend is taking its word for it;
                                                    these are the papers and standards it
                                                    read, and they are checkable. */}
                                                {t.sources?.length > 0 && (
                                                    <div className="mt-3 pt-3 border-t border-white/5">
                                                        <p className="text-xs text-gray-500 mb-2">
                                                            {t.sources.length === 1 ? "Source" : `${t.sources.length} sources`}
                                                        </p>

                                                        <ul className="space-y-1.5">
                                                            {t.sources.map((s, j) => (
                                                                <li key={j}>
                                                                    <a href={s.url} target="_blank" rel="noreferrer"
                                                                        className="text-xs text-[#00b0c2] hover:underline inline-flex items-start gap-1.5 break-all">
                                                                        <ExternalLink size={12} className="shrink-0 mt-0.5" />
                                                                        {s.source_name || s.url}
                                                                    </a>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                )}
                                                </>
                                                )}
                                            </div>
                                        ))}

                                        {awaitingReview && data.trends?.length > 0 && (
                                            <div className="flex flex-wrap gap-3 pt-2">
                                                {editingTrends ? (
                                                    <>
                                                        <button onClick={saveTrendEdits} disabled={busy}
                                                            className="px-5 py-2.5 rounded-xl bg-[#0097a7] text-black font-semibold text-sm hover:scale-[1.02] transition">
                                                            Save changes
                                                        </button>

                                                        <button onClick={() => { setEditingTrends(false); setTrendEdits(null); }}
                                                            className="px-5 py-2.5 rounded-xl border border-white/10 text-gray-300 text-sm">
                                                            Cancel
                                                        </button>

                                                        <p className="text-xs text-gray-500 self-center">
                                                            The modules were derived from these, so they are rewritten to match.
                                                        </p>
                                                    </>
                                                ) : (
                                                    <button
                                                        onClick={() => {
                                                            setTrendEdits(data.trends.map((t) => ({ ...t })));
                                                            setEditingTrends(true);
                                                        }}
                                                        className="px-5 py-2.5 rounded-xl border border-[#0097a7]/30 text-[#0097a7] text-sm hover:bg-[#0097a7]/10 transition"
                                                    >
                                                        Edit these trends
                                                    </button>
                                                )}
                                            </div>
                                        )}
                                    </Section>

                                    <Section icon={<Plus size={18} className="text-green-400" />}
                                             title="Proposed new modules" count={data.node_suggestions?.length}>
                                        {/* Editable in place. A manager reading a
                                            proposal usually wants to change a word
                                            in a title or soften a description, and
                                            sending it back to be regenerated for
                                            that is a poor trade. Her workflow takes
                                            an "edit" for exactly this. */}
                                        {(nodeEdits ?? data.node_suggestions ?? []).map((n, i) => (
                                            <div key={i} className="rounded-2xl border border-white/10 bg-black/30 p-4">
                                                {editingNodes ? (
                                                    <div className="space-y-3">
                                                        <div className="flex gap-3">
                                                            <input
                                                                value={n.title}
                                                                onChange={(e) => updateNodeEdit(i, "title", e.target.value)}
                                                                className="flex-1 p-2 rounded-lg bg-black/40 border border-white/10 text-white font-semibold"
                                                            />

                                                            <select
                                                                value={n.difficulty}
                                                                onChange={(e) => updateNodeEdit(i, "difficulty", e.target.value)}
                                                                className="p-2 rounded-lg bg-black/40 border border-white/10 text-white text-sm"
                                                            >
                                                                <option value="beginner">beginner</option>
                                                                <option value="intermediate">intermediate</option>
                                                                <option value="advanced">advanced</option>
                                                            </select>
                                                        </div>

                                                        <textarea
                                                            value={n.description}
                                                            rows={3}
                                                            onChange={(e) => updateNodeEdit(i, "description", e.target.value)}
                                                            className="w-full p-2 rounded-lg bg-black/40 border border-white/10 text-white text-sm"
                                                        />
                                                    </div>
                                                ) : (
                                                    <>
                                                        <div className="flex items-start justify-between gap-3">
                                                            <h4 className="font-semibold">{n.title}</h4>
                                                            <span className="text-xs text-gray-500 capitalize shrink-0">
                                                                {n.difficulty}
                                                            </span>
                                                        </div>
                                                        <p className="text-sm text-gray-400 mt-2">{n.description}</p>
                                                        {n.based_on_trend && (
                                                            <p className="text-xs text-gray-600 mt-2">
                                                                From trend: {n.based_on_trend}
                                                            </p>
                                                        )}
                                                    </>
                                                )}
                                            </div>
                                        ))}

                                        {awaitingReview && data.node_suggestions?.length > 0 && (
                                            <div className="flex flex-wrap gap-3 pt-2">
                                                {editingNodes ? (
                                                    <>
                                                        <button onClick={saveNodeEdits} disabled={busy}
                                                            className="px-5 py-2.5 rounded-xl bg-[#0097a7] text-black font-semibold text-sm hover:scale-[1.02] transition">
                                                            Save changes
                                                        </button>

                                                        <button onClick={() => { setEditingNodes(false); setNodeEdits(null); }}
                                                            className="px-5 py-2.5 rounded-xl border border-white/10 text-gray-300 text-sm">
                                                            Cancel
                                                        </button>

                                                        <p className="text-xs text-gray-500 self-center">
                                                            Saved edits go back to the AI, which re-places the modules.
                                                        </p>
                                                    </>
                                                ) : (
                                                    <button
                                                        onClick={() => {
                                                            setNodeEdits(data.node_suggestions.map((n) => ({ ...n })));
                                                            setEditingNodes(true);
                                                        }}
                                                        className="px-5 py-2.5 rounded-xl border border-[#0097a7]/30 text-[#0097a7] text-sm hover:bg-[#0097a7]/10 transition"
                                                    >
                                                        Edit these modules
                                                    </button>
                                                )}
                                            </div>
                                        )}
                                    </Section>

                                    <Section icon={<Network size={18} className="text-purple-400" />}
                                             title="Where they go" count={data.placement_suggestions?.length}>
                                        {(placementEdits ?? data.placement_suggestions ?? []).map((p, i) => (
                                            <div key={i} className="rounded-2xl border border-white/10 bg-black/30 p-4">
                                                <h4 className="font-semibold">{p.suggested_node_title}</h4>

                                                {editingPlacement ? (
                                                    <div className="space-y-3 mt-3">
                                                        <textarea
                                                            value={p.explanation}
                                                            rows={2}
                                                            onChange={(e) => updatePlacementEdit(i, "explanation", e.target.value)}
                                                            className="w-full p-2 rounded-lg bg-black/40 border border-white/10 text-white text-sm"
                                                        />

                                                        <NodePicker index={i} label="comes after"
                                                            field="direct_prerequisite_node_ids"
                                                            selected={p.direct_prerequisite_node_ids}
                                                            self={p.suggested_node_id} />

                                                        <NodePicker index={i} label="comes before"
                                                            field="direct_successor_node_ids"
                                                            selected={p.direct_successor_node_ids}
                                                            self={p.suggested_node_id} />

                                                        <NodePicker index={i} label="suggested to follow"
                                                            field="recommended_predecessor_node_ids"
                                                            selected={p.recommended_predecessor_node_ids}
                                                            self={p.suggested_node_id} />
                                                    </div>
                                                ) : (
                                                    <>
                                                        <p className="text-sm text-gray-400 mt-2">{p.explanation}</p>

                                                        <NodeChips label="comes after" ids={p.direct_prerequisite_node_ids} />
                                                        <NodeChips label="comes before" ids={p.direct_successor_node_ids} />
                                                        <NodeChips label="suggested to follow" ids={p.recommended_predecessor_node_ids} />

                                                        {!p.direct_prerequisite_node_ids?.length
                                                            && !p.direct_successor_node_ids?.length && (
                                                            <p className="text-xs text-gray-500 mt-2">
                                                                Not linked to anything - it would be a starting module.
                                                            </p>
                                                        )}
                                                    </>
                                                )}
                                            </div>
                                        ))}

                                        {awaitingReview && data.placement_suggestions?.length > 0 && (
                                            <div className="flex flex-wrap gap-3 pt-2">
                                                {editingPlacement ? (
                                                    <>
                                                        <button onClick={savePlacementEdits} disabled={busy}
                                                            className="px-5 py-2.5 rounded-xl bg-[#0097a7] text-black font-semibold text-sm hover:scale-[1.02] transition">
                                                            Save changes
                                                        </button>

                                                        <button onClick={() => { setEditingPlacement(false); setPlacementEdits(null); }}
                                                            className="px-5 py-2.5 rounded-xl border border-white/10 text-gray-300 text-sm">
                                                            Cancel
                                                        </button>

                                                        <p className="text-xs text-gray-500 self-center">
                                                            Placement is the last stage, so nothing is regenerated after this.
                                                        </p>
                                                    </>
                                                ) : (
                                                    <button
                                                        onClick={() => {
                                                            setPlacementEdits(data.placement_suggestions.map((p) => ({ ...p })));
                                                            setEditingPlacement(true);
                                                        }}
                                                        className="px-5 py-2.5 rounded-xl border border-[#0097a7]/30 text-[#0097a7] text-sm hover:bg-[#0097a7]/10 transition"
                                                    >
                                                        Edit this placement
                                                    </button>
                                                )}
                                            </div>
                                        )}
                                    </Section>

                                    {data.existing_edges_to_remove?.length > 0 && (
                                        <Section icon={<Trash2 size={18} className="text-red-400" />}
                                                 title="Connections to remove"
                                                 count={data.existing_edges_to_remove.length}>
                                            {data.existing_edges_to_remove.map((e, i) => (
                                                <div key={i} className="rounded-2xl border border-red-400/20 bg-red-400/5 p-3 text-sm text-gray-300">
                                                    {titleFor(e.from_node_id)} → {titleFor(e.to_node_id)}
                                                </div>
                                            ))}
                                        </Section>
                                    )}
                                </>
                            )}

                            {awaitingReview && (
                                <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-6">
                                    <h3 className="text-xl font-bold mb-4">Your decision</h3>

                                    <textarea
                                        rows="3"
                                        value={feedback}
                                        onChange={(e) => setFeedback(e.target.value)}
                                        placeholder="Feedback (required to reject)"
                                        className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white text-sm mb-4"
                                    />

                                    <div className="flex flex-wrap gap-3">
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
                                            Or ask the AI to try one stage again:
                                        </p>

                                        <div className="flex flex-wrap gap-3">
                                            {STAGES.map((stage) => (
                                                <button key={stage.id}
                                                    onClick={() => decide({ action: "regenerate", stage: stage.id })}
                                                    disabled={busy}
                                                    className="flex items-center gap-2 px-5 py-2 rounded-xl border border-[#0097a7]/30 text-[#00b0c2] hover:bg-[#0097a7]/10 transition text-sm disabled:opacity-50">
                                                    <RefreshCw size={14} /> {stage.label}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            )}

                            {readyToCommit && (
                                <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-6">
                                    <h3 className="text-xl font-bold mb-2">Approved</h3>

                                    <p className="text-sm text-gray-400 mb-5">
                                        Nothing has been written yet. Committing copies this
                                        roadmap's current graph into a new version, adds the
                                        approved modules to it, and puts it in front of
                                        students marked <span className="text-yellow-200">coming soon</span> -
                                        they see what is arriving but cannot enter it. The AI
                                        starts writing the material straight away, and each
                                        module opens once you approve its tutorial, quiz and
                                        project in the Content Queue.
                                    </p>

                                    {alreadyCommitted ? (
                                        <p className="text-sm text-green-300">
                                            Already committed.
                                        </p>
                                    ) : (
                                        <button onClick={commit} disabled={busy}
                                            className="flex items-center gap-2 px-6 py-3 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-105 transition disabled:bg-gray-700 disabled:text-gray-400 disabled:hover:scale-100">
                                            <GitBranch size={18} />
                                            {busy ? "Committing..." : "Commit to a New Version"}
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
    if (!count) {
        return null;
    }

    return (
        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
            <div className="flex items-center gap-3 mb-5">
                {icon}
                <h3 className="text-xl font-bold">{title}</h3>
                <span className="text-xs px-3 py-1 rounded-full bg-white/5 text-gray-400">
                    {count}
                </span>
            </div>

            <div className="space-y-3">{children}</div>
        </div>
    );
}

export default MaterialAiReview;
