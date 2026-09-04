import React, { useCallback, useEffect, useRef, useState } from "react";
import api from "../../api";
import ModuleMaterial from "../../components/ModuleMaterial";
import {
    Upload, Network, AlertTriangle, Sparkles, Check, X, ChevronRight,
    Inbox, GitBranch, Layers, ArrowLeft, RefreshCw, Trash2, Plus, Pencil,
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

    // What the AI is proposing, for the run currently opened. Approving
    // without this was approving blind: the buttons said yes or no to
    // something the page never showed.
    const [openRun, setOpenRun] = useState(null);
    const [proposal, setProposal] = useState(null);
    const [loadingProposal, setLoadingProposal] = useState(false);

    // Which run is mid-decision, and which way. Per run rather than a single
    // flag, so one long approval does not blank the buttons on every row.
    const [working, setWorking] = useState(null);

    // The roadmap package being hand-edited, or null. Holds the whole
    // GeneratedPathRoadmapModel - her edit contract wants the complete
    // package back, not a patch, so add/delete build a new copy of it
    // rather than sending the one node that changed.
    const [packageEdits, setPackageEdits] = useState(null);
    const [savingPackage, setSavingPackage] = useState(false);

    // The scope being hand-edited - which roadmaps belong under the subject.
    // Held apart from packageEdits because the two are different stages and
    // are never open at the same time.
    const [scopeEdits, setScopeEdits] = useState(null);

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

    /**
     * Read what a run is proposing.
     *
     * The list only carries the run's status. The proposal itself lives in her
     * service, keyed by thread, and has to be fetched per run - which is why
     * it loads on opening one rather than with the list.
     */
    const openProposal = async (run) => {
        if (openRun === run.id) {
            setOpenRun(null);
            setProposal(null);
            setPackageEdits(null);
            setScopeEdits(null);
            return;
        }

        setOpenRun(run.id);
        setProposal(null);
        setPackageEdits(null);
        setScopeEdits(null);
        setLoadingProposal(true);

        try {
            const response = await api.get(`/roadmap-builder-runs/${run.id}`);
            setProposal(response.data);
        } catch (err) {
            fail(err);
            setOpenRun(null);
        } finally {
            setLoadingProposal(false);
        }
    };

    /**
     * Approve or reject the stage a run is waiting on.
     *
     * This is a long call - her service writes the next roadmap before it
     * answers, which is minutes, not seconds. `working` is per run and drives
     * visible text on the button: disabled alone looked identical to doing
     * nothing, so the first approval read as a dead button and was pressed
     * again while it was still running.
     */
    const resumeRun = async (run, action) => {
        setWorking({ id: run.id, action });
        setPackageEdits(null);
        setScopeEdits(null);

        try {
            await api.post(`/roadmap-builder-runs/${run.id}/resume`, { action });
            await loadRuns();

            // The stage has moved on, so the proposal on screen answers the
            // previous question. Re-read it rather than close it: what comes
            // back is the next thing to decide about.
            if (openRun === run.id) {
                const response = await api.get(`/roadmap-builder-runs/${run.id}`);
                setProposal(response.data);
            }
        } catch (err) {
            fail(err);
        } finally {
            setWorking(null);
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
    // Hand-editing the roadmap the run is deciding on
    //
    // Her edit contract is extra="forbid" on every model in the package, so
    // these rebuild each object field by field rather than spreading what
    // she sent - the same rule the trends editor follows, for the same
    // reason: a field she added to the response but not to the request model
    // would get the whole edit refused.
    // -----------------------------------------------------------------

    const sanitizeNode = (n) => {
        const node = {
            node_id: n.node_id,
            title: n.title,
            description: n.description,
            order: n.order,
            prerequisite_node_ids: n.prerequisite_node_ids ?? [],
            learning_outcomes: n.learning_outcomes ?? [],
            type: n.type,
        };

        if (n.type === "choice") {
            node.choices = (n.choices ?? []).map((c) => ({
                choice_id: c.choice_id,
                title: c.title,
                description: c.description,
                separate_roadmap: c.separate_roadmap,
                recommended: c.recommended,
                recommendation_reason: c.recommendation_reason ?? null,
            }));
        }

        return node;
    };

    const sanitizeResult = (r) => ({
        roadmap_topic: r.roadmap_topic,
        title: r.title,
        description: r.description,
        nodes: (r.nodes ?? []).map(sanitizeNode),
        freshness_summary: r.freshness_summary,
    });

    const sanitizePackage = (pkg) => ({
        path_id: pkg.path_id,
        path_title: pkg.path_title,
        roadmap_result: sanitizeResult(pkg.roadmap_result),
        child_roadmaps: (pkg.child_roadmaps ?? []).map((c) => ({
            parent_node_id: c.parent_node_id,
            choice_id: c.choice_id,
            choice_title: c.choice_title,
            roadmap_result: sanitizeResult(c.roadmap_result),
        })),
    });

    /**
     * Remove one node from a node list, renumbering what is left.
     *
     * Her validator wants node_id and order running 1..N with no gaps, so a
     * deletion in the middle has to shift everything after it down by one -
     * and any prerequisite that pointed at the deleted node has to go too,
     * or it references an id that no longer exists.
     */
    const withoutNode = (nodes, nodeId) => {
        const kept = nodes.filter((n) => n.node_id !== nodeId);
        const idMap = new Map(kept.map((n, i) => [n.node_id, i + 1]));

        return {
            idMap,
            nodes: kept.map((n, i) => ({
                ...n,
                node_id: i + 1,
                order: i + 1,
                prerequisite_node_ids: n.prerequisite_node_ids
                    .filter((pid) => idMap.has(pid))
                    .map((pid) => idMap.get(pid)),
            })),
        };
    };

    const startEditingPackage = (pkg) => setPackageEdits(sanitizePackage(pkg));
    const cancelEditingPackage = () => setPackageEdits(null);

    /**
     * Delete a node from the main roadmap.
     *
     * A choice node with a "becomes its own roadmap" choice carries a whole
     * child roadmap keyed to it - deleting the node has to take that child
     * with it, and warns first since it is losing more than one card's
     * worth of work.
     */
    const deleteTopLevelNode = (nodeId) => {
        setPackageEdits((pkg) => {
            const node = pkg.roadmap_result.nodes.find((n) => n.node_id === nodeId);
            const branches = node?.type === "choice"
                ? (node.choices ?? []).filter((c) => c.separate_roadmap)
                : [];

            if (branches.length > 0 && !window.confirm(
                `"${node.title}" branches into its own roadmap for `
                + `${branches.map((c) => c.title).join(", ")}.\n\n`
                + "Deleting it removes that roadmap from this proposal too. Continue?"
            )) return pkg;

            const { idMap, nodes } = withoutNode(pkg.roadmap_result.nodes, nodeId);

            return {
                ...pkg,
                roadmap_result: { ...pkg.roadmap_result, nodes },
                child_roadmaps: pkg.child_roadmaps
                    .filter((c) => c.parent_node_id !== nodeId)
                    .map((c) => ({ ...c, parent_node_id: idMap.get(c.parent_node_id) })),
            };
        });
    };

    /** Delete a node from one child roadmap. Self-contained: a choice inside
     *  a child cannot itself branch into a further child roadmap, so there
     *  is nothing downstream to clean up. */
    const deleteChildNode = (childIndex, nodeId) => {
        setPackageEdits((pkg) => ({
            ...pkg,
            child_roadmaps: pkg.child_roadmaps.map((c, i) => {
                if (i !== childIndex) return c;
                const { nodes } = withoutNode(c.roadmap_result.nodes, nodeId);
                return { ...c, roadmap_result: { ...c.roadmap_result, nodes } };
            }),
        }));
    };

    /**
     * Append a new module to the main roadmap or a child.
     *
     * Always added last, as a standard module: its order is the new
     * highest, so every existing node is a valid prerequisite for it with no
     * ordering check needed. A choice node needs at least two choices, one
     * of them marked recommended, and a reason for that choice - enough of
     * its own form to be a separate feature, so this covers the module type
     * a manager actually asked for here.
     */
    const addNode = (draft, childIndex = null) => {
        setPackageEdits((pkg) => {
            const applyTo = (result) => {
                const n = result.nodes.length + 1;
                return {
                    ...result,
                    nodes: [...result.nodes, {
                        node_id: n,
                        order: n,
                        type: "standard",
                        title: draft.title,
                        description: draft.description,
                        prerequisite_node_ids: draft.prerequisites,
                        learning_outcomes: draft.outcomes,
                    }],
                };
            };

            if (childIndex === null) {
                return { ...pkg, roadmap_result: applyTo(pkg.roadmap_result) };
            }

            return {
                ...pkg,
                child_roadmaps: pkg.child_roadmaps.map((c, i) =>
                    i !== childIndex ? c : { ...c, roadmap_result: applyTo(c.roadmap_result) }),
            };
        });
    };

    /**
     * Reword one module, in the main roadmap or in a child.
     *
     * Only the three fields the form offers are replaced. Everything else on
     * the node - its id, its order, its prerequisites, its choices - is the
     * shape of the graph, and carrying it through untouched is what keeps
     * this edit from needing the renumbering that a delete does.
     */
    const editNode = (nodeId, draft, childIndex = null) => {
        const apply = (result) => ({
            ...result,
            nodes: result.nodes.map((n) => (n.node_id !== nodeId ? n : {
                ...n,
                title: draft.title,
                description: draft.description,
                learning_outcomes: draft.outcomes,
            })),
        });

        setPackageEdits((pkg) => (childIndex === null
            ? { ...pkg, roadmap_result: apply(pkg.roadmap_result) }
            : {
                ...pkg,
                child_roadmaps: pkg.child_roadmaps.map((c, i) =>
                    i !== childIndex ? c : { ...c, roadmap_result: apply(c.roadmap_result) }),
            }));
    };

    const savePackageEdits = async (run) => {
        setSavingPackage(true);

        try {
            await api.post(`/roadmap-builder-runs/${run.id}/resume`, {
                action: "edit",
                stage: "roadmap",
                data: sanitizePackage(packageEdits),
            });

            setPackageEdits(null);
            await loadRuns();

            const response = await api.get(`/roadmap-builder-runs/${run.id}`);
            setProposal(response.data);
        } catch (err) {
            fail(err);
        } finally {
            setSavingPackage(false);
        }
    };

    // -----------------------------------------------------------------
    // Hand-editing the scope: which roadmaps belong under the subject
    // -----------------------------------------------------------------

    /**
     * Her scope model, rebuilt field by field like the package one.
     *
     * is_broad_topic is derived rather than carried over. Her validator ties
     * it to the count - broad demands two paths or more, not broad demands
     * exactly one - so keeping the flag she sent would make deleting down to
     * one path, or adding a second, produce a payload she refuses. Deriving
     * it also matches what the flag means: a subject with one roadmap under
     * it is a roadmap, not a heading.
     */
    const sanitizeScope = (scope) => ({
        input_topic: scope.input_topic,
        is_broad_topic: (scope.paths ?? []).length > 1,
        scope_summary: scope.scope_summary,
        // Renumbered on the way out: her ids have to run 1..N with no gaps,
        // which a delete anywhere but the end would otherwise break.
        paths: (scope.paths ?? []).map((p, i) => ({
            path_id: i + 1,
            title: p.title,
            description: p.description,
        })),
    });

    const startEditingScope = (scope) => setScopeEdits(sanitizeScope(scope));
    const cancelEditingScope = () => setScopeEdits(null);

    const addPath = (draft) => setScopeEdits((scope) => ({
        ...scope,
        paths: [...scope.paths, {
            path_id: scope.paths.length + 1,
            title: draft.title,
            description: draft.description,
        }],
    }));

    const editPath = (pathId, draft) => setScopeEdits((scope) => ({
        ...scope,
        paths: scope.paths.map((p) => (p.path_id !== pathId ? p : {
            ...p,
            title: draft.title,
            description: draft.description,
        })),
    }));

    /** Renumbered on removal, since her ids must stay 1..N. */
    const deletePath = (pathId) => setScopeEdits((scope) => {
        if (scope.paths.length <= 1) {
            alert("A subject needs at least one roadmap under it.");
            return scope;
        }

        return {
            ...scope,
            paths: scope.paths
                .filter((p) => p.path_id !== pathId)
                .map((p, i) => ({ ...p, path_id: i + 1 })),
        };
    });

    const saveScopeEdits = async (run) => {
        setSavingPackage(true);

        try {
            await api.post(`/roadmap-builder-runs/${run.id}/resume`, {
                action: "edit",
                stage: "scope",
                data: sanitizeScope(scopeEdits),
            });

            setScopeEdits(null);
            await loadRuns();

            const response = await api.get(`/roadmap-builder-runs/${run.id}`);
            setProposal(response.data);
        } catch (err) {
            fail(err);
        } finally {
            setSavingPackage(false);
        }
    };

    /**
     * Ask the AI for another go at one stage.
     *
     * Her validator requires feedback on a regenerate and refuses data, so
     * this is the one decision that cannot be sent from a button alone -
     * without a reason the next attempt has no more to go on than the last.
     *
     * Long, like an approve: she rewrites the whole stage before answering.
     */
    const regenerate = async (run, stage) => {
        const reason = window.prompt(
            stage === "scope"
                ? "What should be different about the roadmaps proposed?"
                : "What should be different about this roadmap?"
        );

        if (reason === null) return;

        if (!reason.trim()) {
            alert("Tell the AI what to change - a regenerate without a reason repeats itself.");
            return;
        }

        setWorking({ id: run.id, action: "regenerate" });
        setScopeEdits(null);
        setPackageEdits(null);

        try {
            await api.post(`/roadmap-builder-runs/${run.id}/resume`, {
                action: "regenerate",
                stage,
                feedback: reason.trim(),
            });

            await loadRuns();

            const response = await api.get(`/roadmap-builder-runs/${run.id}`);
            setProposal(response.data);
        } catch (err) {
            fail(err);
        } finally {
            setWorking(null);
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

    /**
     * A roadmap already accepted, in one line.
     *
     * Summarised rather than shown in full: it is context for the decision in
     * hand, and three roadmaps of six modules each rendered whole would bury
     * the one actually being asked about.
     */
    const Accepted = ({ pkg }) => {
        const modules = pkg.roadmap_result?.nodes ?? [];
        const children = pkg.child_roadmaps ?? [];

        return (
            <div className="rounded-xl border border-green-400/20 bg-green-400/5 p-3">
                <div className="flex items-center gap-2 flex-wrap">
                    <Check size={12} className="text-green-400 shrink-0" />
                    <span className="text-sm font-medium">{pkg.path_title}</span>
                    <span className="text-xs text-gray-500">
                        {modules.length} modules
                        {children.length > 0 && `, ${children.length} branching off`}
                    </span>
                </div>

                <p className="text-xs text-gray-500 mt-1 ml-5">
                    {modules.map((n) => n.title).join(" · ")}
                </p>
            </div>
        );
    };

    /** Whatever the run is currently asking a manager to decide about. */
    const Proposal = ({ detail, run }) => {
        const data = detail?.data;

        if (!data) {
            return <p className="text-xs text-gray-600">Nothing to show for this run.</p>;
        }

        // Stage one: which roadmaps belong under the subject.
        if (detail.review_stage === "scope_review" && data.scope_result) {
            const scope = data.scope_result;
            const editing = scopeEdits;
            const shown = editing ?? scope;
            const open = run?.status === "awaiting_review";

            return (
                <div>
                    <div className="flex items-center gap-2 mb-1 flex-wrap">
                        <h4 className="text-sm font-semibold">
                            Proposed roadmaps under {scope.input_topic}
                        </h4>

                        {open && !editing && (
                            <span className="ml-auto flex items-center gap-2">
                                <button type="button" onClick={() => startEditingScope(scope)}
                                    className="flex items-center gap-1.5 text-xs px-3 py-1 rounded-full border border-[#0097a7]/30 text-[#0097a7] hover:bg-[#0097a7]/10 transition">
                                    <Pencil size={11} /> Edit these roadmaps
                                </button>

                                <button type="button" onClick={() => regenerate(run, "scope")}
                                    disabled={!!working}
                                    className="flex items-center gap-1.5 text-xs px-3 py-1 rounded-full border border-white/10 text-gray-400 hover:border-white/20 transition disabled:opacity-40">
                                    <RefreshCw size={11} /> Ask for different ones
                                </button>
                            </span>
                        )}
                    </div>

                    <p className="text-xs text-gray-500 mb-3">{scope.scope_summary}</p>

                    <div className="space-y-2">
                        {(shown.paths ?? []).map((p) => (
                            <div key={p.path_id}
                                 className="rounded-xl border border-white/10 bg-black/20 p-3">
                                {editing ? (
                                    <EditPathForm
                                        path={p}
                                        canDelete={shown.paths.length > 1}
                                        onSave={(draft) => editPath(p.path_id, draft)}
                                        onDelete={() => deletePath(p.path_id)}
                                    />
                                ) : (
                                    <>
                                        <span className="text-sm font-medium">{p.title}</span>
                                        <p className="text-xs text-gray-500 mt-1">{p.description}</p>
                                    </>
                                )}
                            </div>
                        ))}
                    </div>

                    {editing && (
                        <>
                            <AddPathForm onAdd={addPath} />

                            {/* Her scope model ties this to the count, so it
                                follows the list rather than being a choice. */}
                            <p className="text-xs text-gray-600 mt-3">
                                {shown.paths.length === 1
                                    ? "One roadmap, so this is a subject with a single path under it."
                                    : `${shown.paths.length} roadmaps under this subject.`}
                            </p>

                            <div className="flex flex-wrap gap-3 mt-3">
                                <button type="button" onClick={() => saveScopeEdits(run)} disabled={savingPackage}
                                    className="px-5 py-2.5 rounded-xl bg-[#0097a7] text-black font-semibold text-sm hover:scale-[1.02] transition disabled:opacity-50">
                                    {savingPackage ? "Saving..." : "Save changes"}
                                </button>

                                <button type="button" onClick={cancelEditingScope} disabled={savingPackage}
                                    className="px-5 py-2.5 rounded-xl border border-white/10 text-gray-300 text-sm disabled:opacity-50">
                                    Cancel
                                </button>
                            </div>
                        </>
                    )}
                </div>
            );
        }

        const done = data.approved_roadmap_packages ?? [];
        const pkg  = data.roadmap_package;

        if (!pkg && done.length === 0) {
            return <p className="text-xs text-gray-600">Nothing to review at this stage.</p>;
        }

        // Stage two: the roadmap being decided on, and everything already
        // accepted above it. Judging roadmap three on its own says nothing
        // about whether the set covers the subject without repeating itself.
        return (
            <div className="space-y-4">
                {done.length > 0 && (
                    <div>
                        <h4 className="text-sm font-semibold mb-2">
                            Approved so far
                            <span className="ml-2 text-xs font-normal text-gray-500">
                                {done.length} of {data.total_roadmaps ?? done.length}
                            </span>
                        </h4>

                        <div className="space-y-2">
                            {done.map((p, i) => (
                                <Accepted key={p.path_id ?? i} pkg={p} />
                            ))}
                        </div>
                    </div>
                )}

                {pkg && (
                    <div>
                        <div className="flex items-center gap-2 mb-2 flex-wrap">
                            <h4 className="text-sm font-semibold">{pkg.path_title}</h4>

                            <span className="text-xs px-2 py-0.5 rounded-full bg-yellow-400/10 text-yellow-300">
                                deciding on this one
                            </span>

                            {data.total_roadmaps > 0 && (
                                <span className="text-xs text-gray-500">
                                    roadmap {data.current_roadmap_number} of {data.total_roadmaps}
                                </span>
                            )}

                            {/* Editing only makes sense while a decision is
                                still open - once the run has moved on, this
                                package is history, not the one waiting. */}
                            {run?.status === "awaiting_review" && !packageEdits && (
                                <span className="ml-auto flex items-center gap-2">
                                    <button type="button" onClick={() => startEditingPackage(pkg)}
                                        className="flex items-center gap-1.5 text-xs px-3 py-1 rounded-full border border-[#0097a7]/30 text-[#0097a7] hover:bg-[#0097a7]/10 transition">
                                        <Pencil size={11} /> Edit these modules
                                    </button>

                                    {/* When the modules are wrong enough that
                                        rewording them is not the fix. Costs a
                                        full rewrite of this roadmap, so it sits
                                        beside editing rather than instead. */}
                                    <button type="button" onClick={() => regenerate(run, "roadmap")}
                                        disabled={!!working}
                                        className="flex items-center gap-1.5 text-xs px-3 py-1 rounded-full border border-white/10 text-gray-400 hover:border-white/20 transition disabled:opacity-40">
                                        <RefreshCw size={11} /> Write it again
                                    </button>
                                </span>
                            )}
                        </div>

                        {packageEdits ? (
                            <div className="space-y-3">
                                <ProposedRoadmap
                                    result={packageEdits.roadmap_result}
                                    onDeleteNode={deleteTopLevelNode}
                                    onAddNode={(draft) => addNode(draft)}
                                    onEditNode={(nodeId, draft) => editNode(nodeId, draft)}
                                />

                                {(packageEdits.child_roadmaps ?? []).map((child, i) => (
                                    <ProposedRoadmap
                                        key={`${child.parent_node_id}-${child.choice_id}-${i}`}
                                        result={child.roadmap_result}
                                        heading={`${child.choice_title} — its own roadmap`}
                                        onDeleteNode={(nodeId) => deleteChildNode(i, nodeId)}
                                        onAddNode={(draft) => addNode(draft, i)}
                                        onEditNode={(nodeId, draft) => editNode(nodeId, draft, i)}
                                    />
                                ))}

                                <div className="flex flex-wrap gap-3 pt-1">
                                    <button type="button" onClick={() => savePackageEdits(run)} disabled={savingPackage}
                                        className="px-5 py-2.5 rounded-xl bg-[#0097a7] text-black font-semibold text-sm hover:scale-[1.02] transition disabled:opacity-50">
                                        {savingPackage ? "Saving..." : "Save changes"}
                                    </button>

                                    <button type="button" onClick={cancelEditingPackage} disabled={savingPackage}
                                        className="px-5 py-2.5 rounded-xl border border-white/10 text-gray-300 text-sm disabled:opacity-50">
                                        Cancel
                                    </button>
                                </div>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                <ProposedRoadmap result={pkg.roadmap_result} />

                                {/* A choice that becomes its own roadmap arrives
                                    with that roadmap already written. */}
                                {(pkg.child_roadmaps ?? []).map((child) => (
                                    <ProposedRoadmap
                                        key={`${child.parent_node_id}-${child.choice_id}`}
                                        result={child.roadmap_result}
                                        heading={`${child.choice_title} — its own roadmap`}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>
        );
    };

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
                                 className="rounded-2xl border border-white/10 bg-black/30 p-4">
                            <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="font-semibold">{run.topic}</span>

                                        <span className="text-xs px-2 py-0.5 rounded-full border border-white/10 text-gray-400">
                                            {run.status.replace(/_/g, " ")}
                                        </span>

                                        {run.review_stage && (
                                            <span className="text-xs text-gray-500">
                                                reviewing {run.review_stage.replace(/_/g, " ")}
                                            </span>
                                        )}
                                    </div>

                                    {run.committed && run.roadmap && (
                                        <p className="text-xs text-gray-500 mt-1">
                                            Committed as {run.roadmap.title}.
                                        </p>
                                    )}

                                    {/* Read it before deciding on it. */}
                                    {!run.committed && (
                                        <button onClick={() => openProposal(run)}
                                            className="text-xs text-gray-500 mt-2 flex items-center gap-1.5 hover:text-[#00b0c2] transition">
                                            <ChevronRight size={11} className={`transition-transform ${
                                                openRun === run.id ? "rotate-90" : ""
                                            }`} />
                                            {openRun === run.id ? "hide what it proposes" : "see what it proposes"}
                                        </button>
                                    )}
                                </div>

                                {!run.committed && (
                                    <div className="flex flex-wrap gap-2 shrink-0">
                                        {run.status === "awaiting_review" && (() => {
                                            const mine = working?.id === run.id;

                                            // Approving writes the next roadmap before it
                                            // answers, so this sits here for minutes. The
                                            // button has to say so.
                                            if (mine) {
                                                return (
                                                    <span className="flex items-center gap-2 px-4 py-2 text-sm text-[#00b0c2]">
                                                        <RefreshCw size={14} className="animate-spin" />
                                                        {working.action === "approve"
                                                            ? "Approving - the AI is writing the next roadmap, this takes a few minutes"
                                                            : "Rejecting..."}
                                                    </span>
                                                );
                                            }

                                            return (
                                                <>
                                                    <button onClick={() => resumeRun(run, "approve")} disabled={!!working}
                                                        className="flex items-center gap-1.5 px-4 py-2 rounded-xl border border-green-400/30 text-green-300 hover:bg-green-400/10 text-sm transition disabled:opacity-40">
                                                        <Check size={14} /> Approve
                                                    </button>

                                                    <button onClick={() => resumeRun(run, "reject")} disabled={!!working}
                                                        className="flex items-center gap-1.5 px-4 py-2 rounded-xl border border-red-400/30 text-red-400 hover:bg-red-400/10 text-sm transition disabled:opacity-40">
                                                        <X size={14} /> Reject
                                                    </button>
                                                </>
                                            );
                                        })()}

                                        {run.status === "ready_for_commit" && (
                                            <button onClick={() => commitRun(run)} disabled={busy}
                                                className="flex items-center gap-1.5 px-5 py-2 rounded-xl bg-[#0097a7] text-black font-semibold text-sm hover:scale-[1.02] transition">
                                                <Check size={14} /> Keep it
                                            </button>
                                        )}
                                    </div>
                                )}
                            </div>

                            {openRun === run.id && (
                                <div className="mt-4 pt-4 border-t border-white/10">
                                    {loadingProposal
                                        ? <p className="text-xs text-gray-500">Reading the proposal...</p>
                                        : <Proposal detail={proposal} run={run} />}
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

/**
 * A new module, added while hand-editing a proposed roadmap.
 *
 * Standard only - a choice node needs at least two choices, one marked
 * recommended with a reason, which is a form of its own. Always appended
 * last, so every existing module is a valid thing to put it after: nothing
 * here can come before a node that has not been added yet.
 */
/**
 * One roadmap the builder wrote: its modules, in order.
 *
 * The edit callbacks are only passed while this package is being hand-edited
 * - their presence is what turns the per-module controls on, rather than a
 * separate flag they could drift out of sync with.
 *
 * Defined out here rather than inside AdminRoadmaps. Nested, it was a new
 * component type on every render, so React threw it away and rebuilt it -
 * and once editing a module writes to the page's state, a single keystroke
 * would re-render the parent and take the focus out of the field.
 */
/**
 * One roadmap in the scope, open for rewording or removal.
 *
 * Always open rather than behind a pencil: the scope is a handful of lines,
 * and a manager who has pressed "edit these roadmaps" is there to change
 * them. The nodes editor hides behind a pencil because a roadmap can hold
 * twenty modules and twenty open forms is a wall.
 */
function EditPathForm({ path, canDelete, onSave, onDelete }) {
    const [title, setTitle] = useState(path.title);
    const [description, setDescription] = useState(path.description);

    // Written straight through on change. The scope is small enough that a
    // save button per row would be more ceremony than the edit is worth, and
    // the whole scope is only sent when Save changes is pressed anyway.
    const push = (next) => onSave({
        title: next.title ?? title,
        description: next.description ?? description,
    });

    return (
        <div className="space-y-2">
            <div className="flex items-center gap-2">
                <span className="text-xs text-gray-600">{path.path_id}</span>

                <input
                    value={title}
                    onChange={(e) => { setTitle(e.target.value); push({ title: e.target.value }); }}
                    placeholder="Roadmap title"
                    className="flex-1 p-2 rounded-lg bg-black/40 border border-white/10 text-white text-sm font-medium"
                />

                <button type="button"
                    onClick={onDelete}
                    disabled={!canDelete}
                    title={canDelete
                        ? "Remove this roadmap"
                        : "A subject needs at least one roadmap under it"}
                    className="p-2 rounded-lg border border-red-400/20 text-red-400 hover:bg-red-400/10 transition shrink-0 disabled:opacity-30 disabled:hover:bg-transparent">
                    <Trash2 size={14} />
                </button>
            </div>

            <textarea
                value={description}
                onChange={(e) => { setDescription(e.target.value); push({ description: e.target.value }); }}
                rows={2}
                placeholder="What this roadmap covers"
                className="w-full p-2 rounded-lg bg-black/40 border border-white/10 text-white text-xs"
            />
        </div>
    );
}


/** A roadmap the AI did not think of, added to the scope. */
function AddPathForm({ onAdd }) {
    const [adding, setAdding] = useState(false);
    const [title, setTitle] = useState("");
    const [description, setDescription] = useState("");

    const canAdd = title.trim().length > 0 && description.trim().length > 0;

    if (!adding) {
        return (
            <button type="button" onClick={() => setAdding(true)}
                className="mt-3 flex items-center gap-1.5 text-xs text-[#00b0c2] hover:text-[#0097a7] transition">
                <Plus size={12} /> Add a roadmap
            </button>
        );
    }

    return (
        <div className="mt-3 rounded-xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-3 space-y-2">
            <input
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="Roadmap title"
                className="w-full p-2 rounded-lg bg-black/40 border border-white/10 text-white text-sm"
            />

            <textarea
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                rows={2}
                placeholder="What this roadmap covers"
                className="w-full p-2 rounded-lg bg-black/40 border border-white/10 text-white text-xs"
            />

            <div className="flex gap-2 pt-1">
                <button type="button" disabled={!canAdd}
                    onClick={() => {
                        onAdd({ title: title.trim(), description: description.trim() });
                        setTitle("");
                        setDescription("");
                        setAdding(false);
                    }}
                    className="px-4 py-2 rounded-lg bg-[#0097a7] text-black text-xs font-semibold disabled:bg-gray-700 disabled:text-gray-400 transition">
                    Add roadmap
                </button>

                <button type="button" onClick={() => setAdding(false)}
                    className="px-4 py-2 rounded-lg border border-white/10 text-gray-300 text-xs">
                    Cancel
                </button>
            </div>
        </div>
    );
}


function ProposedRoadmap({ result, heading, onDeleteNode, onAddNode, onEditNode }) {
    const editable = !!onDeleteNode;

    const [adding, setAdding] = useState(false);
    const [editingId, setEditingId] = useState(null);

    return (
        <div className="rounded-2xl border border-white/10 bg-black/20 p-4">
            <h5 className="font-semibold text-sm">{heading ?? result.title}</h5>
            <p className="text-xs text-gray-400 mt-1">{result.description}</p>

            <div className="mt-3 space-y-2">
                {(result.nodes ?? []).map((n) => (
                    <div key={n.node_id} className="rounded-xl border border-white/5 bg-black/20 p-3">
                        {editingId === n.node_id ? (
                            <EditNodeForm
                                node={n}
                                onCancel={() => setEditingId(null)}
                                onSave={(draft) => { onEditNode(n.node_id, draft); setEditingId(null); }}
                            />
                        ) : (
                        <>
                        <div className="flex items-baseline gap-2 flex-wrap">
                            <span className="text-xs text-gray-600">{n.order}</span>
                            <span className="text-sm font-medium">{n.title}</span>

                            {n.type === "choice" && (
                                <span className="text-xs px-2 py-0.5 rounded-full bg-[#0097a7]/10 text-[#00b0c2]">
                                    {n.choices?.length ?? 0} to choose from
                                </span>
                            )}

                            {editable && (
                                <span className="ml-auto flex items-center gap-2 shrink-0">
                                    <button type="button" onClick={() => setEditingId(n.node_id)}
                                        title="Edit this module"
                                        className="text-gray-500 hover:text-[#00b0c2] transition">
                                        <Pencil size={13} />
                                    </button>

                                    <button type="button" onClick={() => onDeleteNode(n.node_id)}
                                        title="Delete this module"
                                        className="text-red-400/70 hover:text-red-400 transition">
                                        <Trash2 size={14} />
                                    </button>
                                </span>
                            )}
                        </div>

                        <p className="text-xs text-gray-500 mt-1">{n.description}</p>

                        {/* The choices, because a choice node is the shape of
                            the roadmap and not a detail. */}
                        {n.type === "choice" && (
                            <div className="mt-2 space-y-1.5 pl-3 border-l border-white/10">
                                {n.choices.map((c) => (
                                    <div key={c.choice_id}>
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <span className="text-xs font-medium">{c.title}</span>

                                            {c.recommended && (
                                                <span className="text-xs px-2 py-0.5 rounded-full bg-[#0097a7]/10 text-[#00b0c2]">
                                                    recommended
                                                </span>
                                            )}

                                            {c.separate_roadmap && (
                                                <span className="text-xs text-gray-600">
                                                    becomes its own roadmap
                                                </span>
                                            )}
                                        </div>

                                        <p className="text-xs text-gray-600">{c.description}</p>

                                        {c.recommendation_reason && (
                                            <p className="text-xs text-gray-600 italic">
                                                {c.recommendation_reason}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                        </>
                        )}
                    </div>
                ))}
            </div>

            {result.freshness_summary && (
                <p className="text-xs text-gray-600 mt-3 pt-3 border-t border-white/5">
                    {result.freshness_summary}
                </p>
            )}

            {editable && (adding ? (
                <AddNodeForm
                    existingNodes={result.nodes}
                    onCancel={() => setAdding(false)}
                    onAdd={(draft) => { onAddNode(draft); setAdding(false); }}
                />
            ) : (
                <button type="button" onClick={() => setAdding(true)}
                    className="mt-3 flex items-center gap-1.5 text-xs text-[#00b0c2] hover:text-[#0097a7] transition">
                    <Plus size={12} /> Add a module
                </button>
            ))}
        </div>
    );
}


/**
 * Rewording a module the AI wrote.
 *
 * Title, description and outcomes only. Where a module sits, what it depends
 * on and the choices hanging off it are the shape of the roadmap rather than
 * its wording - changing those from here would mean renumbering and
 * re-checking the graph, which is what the add and delete controls already
 * do deliberately.
 */
function EditNodeForm({ node, onSave, onCancel }) {
    const [title, setTitle] = useState(node.title);
    const [description, setDescription] = useState(node.description);
    const [outcomes, setOutcomes] = useState((node.learning_outcomes ?? []).join("\n"));

    const outcomeList = outcomes.split("\n").map((s) => s.trim()).filter(Boolean);
    const canSave = title.trim().length > 0 && description.trim().length > 0 && outcomeList.length > 0;

    return (
        <div className="space-y-2">
            <div className="flex items-center gap-2">
                <span className="text-xs text-gray-600">{node.order}</span>

                <input
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    placeholder="Module title"
                    className="flex-1 p-2 rounded-lg bg-black/40 border border-white/10 text-white text-sm font-medium"
                />
            </div>

            <textarea
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                rows={2}
                placeholder="Description"
                className="w-full p-2 rounded-lg bg-black/40 border border-white/10 text-white text-xs"
            />

            <textarea
                value={outcomes}
                onChange={(e) => setOutcomes(e.target.value)}
                rows={2}
                placeholder="Learning outcomes, one per line"
                className="w-full p-2 rounded-lg bg-black/40 border border-white/10 text-white text-xs"
            />

            {node.type === "choice" && (
                <p className="text-xs text-gray-600">
                    The {node.choices?.length ?? 0} choices on this module are left as they are.
                </p>
            )}

            <div className="flex gap-2 pt-1">
                <button type="button" disabled={!canSave}
                    onClick={() => onSave({
                        title: title.trim(),
                        description: description.trim(),
                        outcomes: outcomeList,
                    })}
                    className="px-4 py-2 rounded-lg bg-[#0097a7] text-black text-xs font-semibold disabled:bg-gray-700 disabled:text-gray-400 transition">
                    Save module
                </button>

                <button type="button" onClick={onCancel}
                    className="px-4 py-2 rounded-lg border border-white/10 text-gray-300 text-xs">
                    Cancel
                </button>
            </div>
        </div>
    );
}


function AddNodeForm({ existingNodes, onAdd, onCancel }) {
    const [title, setTitle] = useState("");
    const [description, setDescription] = useState("");
    const [outcomes, setOutcomes] = useState("");
    const [prerequisites, setPrerequisites] = useState([]);

    const togglePrerequisite = (id) => setPrerequisites((prev) =>
        prev.includes(id) ? prev.filter((p) => p !== id) : [...prev, id]);

    const outcomeList = outcomes.split("\n").map((s) => s.trim()).filter(Boolean);
    const canAdd = title.trim().length > 0 && description.trim().length > 0 && outcomeList.length > 0;

    return (
        <div className="mt-3 rounded-xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-3 space-y-2">
            <input
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="Module title"
                className="w-full p-2 rounded-lg bg-black/40 border border-white/10 text-white text-sm"
            />

            <textarea
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                rows={2}
                placeholder="Description"
                className="w-full p-2 rounded-lg bg-black/40 border border-white/10 text-white text-sm"
            />

            <textarea
                value={outcomes}
                onChange={(e) => setOutcomes(e.target.value)}
                rows={2}
                placeholder="Learning outcomes, one per line"
                className="w-full p-2 rounded-lg bg-black/40 border border-white/10 text-white text-sm"
            />

            {existingNodes.length > 0 && (
                <div>
                    <span className="text-xs text-gray-500">Comes after</span>

                    <div className="flex flex-wrap gap-2 mt-1.5">
                        {existingNodes.map((n) => (
                            <button key={n.node_id} type="button"
                                onClick={() => togglePrerequisite(n.node_id)}
                                className={`text-xs px-3 py-1 rounded-full border transition ${
                                    prerequisites.includes(n.node_id)
                                        ? "border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2]"
                                        : "border-white/10 text-gray-500 hover:border-white/20"
                                }`}>
                                {n.title}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <div className="flex gap-2 pt-1">
                <button type="button" disabled={!canAdd}
                    onClick={() => onAdd({
                        title: title.trim(),
                        description: description.trim(),
                        outcomes: outcomeList,
                        prerequisites,
                    })}
                    className="px-4 py-2 rounded-lg bg-[#0097a7] text-black text-xs font-semibold disabled:bg-gray-700 disabled:text-gray-400 transition">
                    Add module
                </button>

                <button type="button" onClick={onCancel}
                    className="px-4 py-2 rounded-lg border border-white/10 text-gray-300 text-xs">
                    Cancel
                </button>
            </div>
        </div>
    );
}

export default AdminRoadmaps;
