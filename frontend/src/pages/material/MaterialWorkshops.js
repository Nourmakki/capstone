import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import {
    CalendarDays, MapPin, Ticket, User, Building2, ExternalLink,
    Check, X, RefreshCw, Inbox, ChevronRight, Search, AlertTriangle,
} from "lucide-react";

/**
 * The workshop agent, as a manager drives it.
 *
 * Ask it what is on for a roadmap, read the listings it found, then approve
 * all of them, approve some, or reject the lot. Her review takes only those -
 * no editing a listing off the web, and no regenerating one of six - so this
 * screen has fewer controls than the others by design.
 *
 * Approving is not the end of it. She holds the approved list until it is
 * committed here, which is what writes the workshops onto the roadmap and
 * puts them in front of students.
 */
function MaterialWorkshops({ onChanged }) {
    const [runs, setRuns] = useState([]);
    const [selected, setSelected] = useState(null);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [serviceDown, setServiceDown] = useState(false);

    // Starting one.
    const [roadmaps, setRoadmaps] = useState([]);
    const [roadmapId, setRoadmapId] = useState(null);
    const [versions, setVersions] = useState([]);
    const [versionId, setVersionId] = useState(null);
    const [location, setLocation] = useState("");
    const [starting, setStarting] = useState(false);

    // Which listings are ticked, while deciding. Empty means all of them,
    // which is her contract: an approve with no data approves everything.
    const [picked, setPicked] = useState([]);
    const [feedback, setFeedback] = useState("");

    const loadRuns = useCallback(async () => {
        try {
            const response = await api.get("/workshop-runs");
            setRuns(response.data.runs ?? []);
            setServiceDown(false);
        } catch (err) {
            if (err.response?.status === 503) setServiceDown(true);
            else console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { loadRuns(); }, [loadRuns]);

    useEffect(() => {
        api.get("/roadmaps")
            .then((r) => {
                const list = r.data.data ?? [];
                setRoadmaps(list);
                // A roadmap, never a subject: a subject holds no versions.
                setRoadmapId((prev) => prev ?? list.find((x) => x.parent_roadmap_id)?.id ?? null);
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

    const fail = (err) => {
        if (err.response?.status === 503) {
            setServiceDown(true);
            return;
        }

        const errors = err.response?.data?.errors;
        alert(errors
            ? Object.values(errors).flat().join("\n")
            : (err.response?.data?.message ?? "Something went wrong."));
    };

    const start = async () => {
        if (!versionId) return;

        setStarting(true);
        setServiceDown(false);

        try {
            await api.post(`/roadmap-versions/${versionId}/workshop-runs`,
                location.trim() ? { student_location: location.trim() } : {});

            setLocation("");
            await loadRuns();
            onChanged?.();
        } catch (err) {
            fail(err);
        } finally {
            setStarting(false);
        }
    };

    const openRun = async (run) => {
        setSelected(run);
        setResult(null);
        setPicked([]);
        setFeedback("");
        setBusy(true);

        try {
            const response = await api.get(`/workshop-runs/${run.id}`);
            setResult(response.data);
        } catch (err) {
            fail(err);
        } finally {
            setBusy(false);
        }
    };

    const decide = async (payload) => {
        setBusy(true);

        try {
            const response = await api.post(`/workshop-runs/${selected.id}/resume`, payload);
            setResult(response.data);
            setPicked([]);
            setFeedback("");
            await loadRuns();
            onChanged?.();
        } catch (err) {
            fail(err);
        } finally {
            setBusy(false);
        }
    };

    const commit = async () => {
        setBusy(true);

        try {
            const response = await api.post(`/workshop-runs/${selected.id}/commit`);

            alert(`${response.data.message}\n\nStudents can see them on the Workshops page.`);

            await loadRuns();
            await openRun(selected);
            onChanged?.();
        } catch (err) {
            fail(err);
        } finally {
            setBusy(false);
        }
    };

    const remove = async (run) => {
        if (!window.confirm(
            `Remove the workshop search for "${run.topic}"?\n\n`
            + "Any workshops it already added stay on the roadmap."
        )) return;

        try {
            await api.delete(`/workshop-runs/${run.id}`);

            if (selected?.id === run.id) {
                setSelected(null);
                setResult(null);
            }

            await loadRuns();
            onChanged?.();
        } catch (err) {
            fail(err);
        }
    };

    const workshops = result?.data?.workshops ?? [];
    const awaiting = result?.status === "awaiting_review";
    const ready = result?.status === "ready_for_commit";
    const committed = !!runs.find((r) => r.id === selected?.id)?.committed;

    const toggle = (link) => setPicked((prev) => (
        prev.includes(link) ? prev.filter((l) => l !== link) : [...prev, link]
    ));

    return (
        <div className="p-8">
            <div className="flex items-center gap-3">
                <CalendarDays size={32} className="text-[#0097a7]" />
                <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">Workshops</h1>
            </div>

            <p className="text-gray-400 mt-2 mb-10">
                The AI looks for real workshops and conferences on a roadmap's
                subject. Nothing reaches students until you approve it and add it.
            </p>

            {serviceDown && (
                <div className="mb-8 flex items-start gap-3 rounded-2xl border border-red-400/30 bg-red-400/5 p-4">
                    <AlertTriangle size={18} className="text-red-400 shrink-0 mt-0.5" />
                    <p className="text-sm text-gray-300">
                        The AI service is not reachable, so a search cannot start.
                        Anything already found is unaffected.
                    </p>
                </div>
            )}

            {/* Asking for one. */}
            <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6 mb-10">
                <div className="flex items-center gap-3 mb-2">
                    <Search size={20} className="text-[#0097a7]" />
                    <h2 className="text-2xl font-bold">Look for workshops</h2>
                </div>

                <p className="text-sm text-gray-400 mb-5">
                    Searched on the roadmap's own subject. A location narrows it
                    to what is near your students - leave it blank for anywhere.
                </p>

                <div className="grid md:grid-cols-[1fr_1fr_1fr_auto] gap-3">
                    <select value={roadmapId ?? ""}
                        onChange={(e) => setRoadmapId(Number(e.target.value))}
                        className="p-3 rounded-xl bg-black/30 border border-white/10 text-white text-sm">
                        {roadmaps
                            .filter((r) => !r.parent_roadmap_id)
                            .map((subject) => {
                                const paths = roadmaps.filter((r) => r.parent_roadmap_id === subject.id);

                                if (paths.length === 0) return null;

                                return (
                                    <optgroup key={subject.id} label={subject.title}>
                                        {paths.map((path) => (
                                            <option key={path.id} value={path.id}>{path.title}</option>
                                        ))}
                                    </optgroup>
                                );
                            })}
                    </select>

                    <select value={versionId ?? ""}
                        onChange={(e) => setVersionId(Number(e.target.value))}
                        className="p-3 rounded-xl bg-black/30 border border-white/10 text-white text-sm">
                        {versions.length === 0 && <option value="">No versions</option>}
                        {versions.map((v) => (
                            <option key={v.id} value={v.id}>
                                v{v.version_number} ({v.status})
                            </option>
                        ))}
                    </select>

                    <input
                        value={location}
                        onChange={(e) => setLocation(e.target.value)}
                        placeholder="Beirut, Lebanon (optional)"
                        className="p-3 rounded-xl bg-black/30 border border-white/10 text-white text-sm placeholder:text-gray-600"
                    />

                    <button onClick={start} disabled={starting || !versionId}
                        className={`px-6 py-3 rounded-xl font-semibold text-sm transition ${
                            starting || !versionId
                                ? "bg-gray-700 text-gray-400 cursor-not-allowed"
                                : "bg-[#0097a7] text-black hover:scale-[1.02]"
                        }`}>
                        {starting ? "Searching..." : "Search"}
                    </button>
                </div>

                {starting && (
                    <p className="text-xs text-[#00b0c2] mt-3 flex items-center gap-2">
                        <RefreshCw size={13} className="animate-spin" />
                        Reading what is on - this takes a couple of minutes.
                    </p>
                )}
            </div>

            <div className="grid lg:grid-cols-[360px_1fr] gap-8">

                <div>
                    <h2 className="text-xl font-bold mb-4">Searches</h2>

                    {loading ? (
                        <p className="text-gray-400">Loading...</p>
                    ) : runs.length === 0 ? (
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8 text-center">
                            <Inbox size={40} className="mx-auto text-gray-600 mb-3" />
                            <p className="text-gray-400 text-sm">Nothing searched for yet.</p>
                        </div>
                    ) : (
                        <div className="space-y-2">
                            {runs.map((run) => (
                                <div key={run.id}
                                     className={`flex items-start rounded-2xl border transition ${
                                         selected?.id === run.id
                                             ? "border-[#0097a7]/40 bg-[#0097a7]/10"
                                             : "border-white/10 bg-white/[0.03] hover:border-[#0097a7]/30"
                                     }`}>
                                    <button onClick={() => openRun(run)}
                                        className="flex-1 min-w-0 text-left p-4">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-semibold truncate">{run.topic}</span>
                                            <ChevronRight size={16} className="text-gray-500 shrink-0" />
                                        </div>

                                        <p className="text-xs text-gray-500 mt-1 truncate">
                                            {run.roadmap}{run.location ? ` · ${run.location}` : ""}
                                        </p>

                                        <span className={`inline-block mt-2 text-xs px-3 py-1 rounded-full border ${
                                            run.committed
                                                ? "border-green-400/30 bg-green-400/10 text-green-300"
                                                : run.awaiting
                                                ? "border-yellow-400/30 bg-yellow-400/10 text-yellow-300"
                                                : "border-white/10 bg-white/5 text-gray-400"
                                        }`}>
                                            {run.committed ? "added" : run.status.replace(/_/g, " ")}
                                        </span>
                                    </button>

                                    <button
                                        onClick={() => remove(run)}
                                        disabled={busy}
                                        title="Remove this search from the list"
                                        aria-label={`Remove the workshop search for ${run.topic}`}
                                        className="shrink-0 m-3 p-1.5 rounded-lg text-gray-600 hover:text-red-400 hover:bg-red-400/10 transition disabled:opacity-30"
                                    >
                                        <X size={14} />
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <div>
                    {!selected ? (
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-12 text-center">
                            <h3 className="text-xl font-bold">Select a search</h3>
                            <p className="text-gray-400 mt-2">
                                Pick one on the left to read what it found.
                            </p>
                        </div>
                    ) : busy && !result ? (
                        <p className="text-gray-400">Loading...</p>
                    ) : result ? (
                        <div className="space-y-6">
                            {workshops.length === 0 ? (
                                <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8 text-center">
                                    <p className="text-gray-400 text-sm">
                                        This search found nothing suitable.
                                        {result.status === "rejected" && " It was rejected."}
                                    </p>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {workshops.map((w) => {
                                        const chosen = picked.includes(w.link);

                                        return (
                                            <div key={w.link}
                                                 className={`rounded-2xl border p-5 transition ${
                                                     chosen
                                                         ? "border-[#0097a7]/40 bg-[#0097a7]/5"
                                                         : "border-white/10 bg-white/[0.03]"
                                                 }`}>
                                                <div className="flex items-start gap-3">
                                                    {/* Only while a decision is
                                                        open: after it, the list
                                                        is what was approved. */}
                                                    {awaiting && (
                                                        <input
                                                            type="checkbox"
                                                            checked={chosen}
                                                            onChange={() => toggle(w.link)}
                                                            aria-label={`Approve ${w.title}`}
                                                            className="mt-1.5 shrink-0 w-4 h-4 accent-[#0097a7]"
                                                        />
                                                    )}

                                                    <div className="min-w-0 flex-1">
                                                        <h3 className="font-bold">{w.title}</h3>

                                                        <div className="mt-2 flex flex-wrap gap-x-5 gap-y-1.5 text-xs text-gray-400">
                                                            <span className="flex items-center gap-1.5">
                                                                <CalendarDays size={12} />
                                                                {w.date}{w.time ? ` · ${w.time}` : ""}
                                                            </span>

                                                            <span className="flex items-center gap-1.5">
                                                                <MapPin size={12} />
                                                                {w.location}
                                                            </span>

                                                            <span className="flex items-center gap-1.5">
                                                                <Ticket size={12} />
                                                                {w.cost_type === "paid"
                                                                    ? w.price
                                                                    : w.cost_type === "free"
                                                                    ? "Free"
                                                                    : "Price not given"}
                                                            </span>

                                                            {w.organizer && (
                                                                <span className="flex items-center gap-1.5">
                                                                    <Building2 size={12} />
                                                                    {w.organizer}
                                                                </span>
                                                            )}

                                                            {w.speaker && (
                                                                <span className="flex items-center gap-1.5">
                                                                    <User size={12} />
                                                                    {w.speaker}
                                                                </span>
                                                            )}
                                                        </div>

                                                        {/* The listing itself. A
                                                            reviewer approving a
                                                            dead link is the
                                                            easiest mistake here. */}
                                                        <a href={w.link} target="_blank" rel="noreferrer"
                                                           className="mt-3 inline-flex items-start gap-1.5 text-xs text-[#00b0c2] hover:underline break-all">
                                                            <ExternalLink size={12} className="shrink-0 mt-0.5" />
                                                            {w.link}
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}

                            {awaiting && workshops.length > 0 && (
                                <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-6">
                                    <h3 className="text-xl font-bold mb-2">Your decision</h3>

                                    <p className="text-sm text-gray-400 mb-5">
                                        {picked.length === 0
                                            ? "Nothing ticked, so approving takes all of them."
                                            : `Approving ${picked.length} of ${workshops.length}.`}
                                    </p>

                                    <textarea rows="2" value={feedback}
                                        onChange={(e) => setFeedback(e.target.value)}
                                        placeholder="Why you are rejecting (optional)"
                                        className="w-full p-3 rounded-xl bg-black/40 border border-white/10 text-white text-sm mb-4" />

                                    <div className="flex flex-wrap gap-3">
                                        <button
                                            onClick={() => decide({
                                                action: "approve",
                                                ...(picked.length > 0
                                                    ? { approved_workshop_links: picked }
                                                    : {}),
                                            })}
                                            disabled={busy}
                                            className="flex items-center gap-2 px-5 py-2.5 rounded-xl border border-green-400/30 text-green-300 hover:bg-green-400/10 text-sm transition disabled:opacity-40">
                                            <Check size={15} />
                                            {picked.length === 0 ? "Approve all" : `Approve ${picked.length}`}
                                        </button>

                                        <button
                                            onClick={() => decide({
                                                action: "reject",
                                                ...(feedback.trim() ? { feedback: feedback.trim() } : {}),
                                            })}
                                            disabled={busy}
                                            className="flex items-center gap-2 px-5 py-2.5 rounded-xl border border-red-400/30 text-red-400 hover:bg-red-400/10 text-sm transition disabled:opacity-40">
                                            <X size={15} /> Reject
                                        </button>
                                    </div>
                                </div>
                            )}

                            {ready && (
                                <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-6">
                                    <h3 className="text-xl font-bold mb-2">Approved</h3>

                                    <p className="text-sm text-gray-400 mb-5">
                                        Nothing is on the roadmap yet. Adding them puts
                                        these {workshops.length === 1 ? "workshop" : "workshops"} in
                                        front of students on the Workshops page.
                                    </p>

                                    {committed ? (
                                        <p className="text-sm text-green-300">Already added.</p>
                                    ) : (
                                        <button onClick={commit} disabled={busy}
                                            className="flex items-center gap-2 px-6 py-3 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-105 transition disabled:bg-gray-700 disabled:text-gray-400">
                                            <Check size={18} />
                                            {busy ? "Adding..." : "Add to the roadmap"}
                                        </button>
                                    )}
                                </div>
                            )}

                            {result.rejection_feedback && (
                                <p className="text-xs text-gray-400 border-l-2 border-white/10 pl-3">
                                    Rejected: {result.rejection_feedback}
                                </p>
                            )}

                            {result.error && (
                                <div className="flex items-start gap-3 rounded-2xl border border-red-400/30 bg-red-400/5 p-4">
                                    <AlertTriangle size={18} className="text-red-400 shrink-0 mt-0.5" />
                                    <p className="text-sm text-red-200">{result.error}</p>
                                </div>
                            )}
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

export default MaterialWorkshops;
