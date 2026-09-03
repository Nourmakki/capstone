import React, { useCallback, useEffect, useMemo, useState } from "react";
import api from "../../api";
import {
    // Aliased: lucide exports an icon called Map, and the tree below is built
    // with real Maps. Imported plainly it shadows the constructor, and `new
    // Map()` fails at runtime with "is not a constructor".
    Check, X, ExternalLink, CheckCircle2, Map as MapIcon, GitBranch, Video,
    FileText, Brain, Code2, PencilLine, ChevronRight, Folder, FolderOpen,
} from "lucide-react";

// Slug must match MaterialController::TYPES. The order is the order items
// appear inside a roadmap: the roadmap itself, then its version, then the
// material on its modules - which is the order they have to be approved in
// anyway, since material on an unapproved roadmap goes nowhere.
const SECTIONS = [
    { key: "roadmaps", slug: "roadmaps", label: "Roadmap", icon: MapIcon, tint: "text-[#0097a7]" },
    { key: "roadmap_versions", slug: "roadmap-versions", label: "Version", icon: GitBranch, tint: "text-yellow-400" },
    { key: "tutorials", slug: "tutorials", label: "Tutorial", icon: Video, tint: "text-purple-400" },
    { key: "quizzes", slug: "quizzes", label: "Quiz", icon: Brain, tint: "text-pink-400" },
    { key: "projects", slug: "projects", label: "Project", icon: Code2, tint: "text-orange-400" },
    { key: "external_resources", slug: "external-resources", label: "Source", icon: FileText, tint: "text-blue-400" },
];

/**
 * The review queue, filed into folders.
 *
 * It used to be six flat lists by type, one huge card per item. Committing a
 * built subject drops seventeen things in here at once - eight roadmaps, nine
 * versions - and as a flat list there was nothing saying they were one tree,
 * or which version belonged to which roadmap. You scrolled past a wall of
 * cards trying to remember what you had already seen.
 *
 * So: subject, then roadmap, then the items on it. The same shape the
 * roadmaps actually have, and the order they have to be approved in.
 */
function MaterialQueue({ onChanged }) {
    const [pending, setPending] = useState({});
    const [notes, setNotes] = useState({});
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(null);

    // Folders are open unless closed, so a queue with one subject in it needs
    // no clicks at all. Held as the closed set for that reason.
    const [closed, setClosed] = useState(new Set());
    const [openItem, setOpenItem] = useState(null);

    const load = useCallback(async () => {
        try {
            const response = await api.get("/material/pending");
            setPending(response.data);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const decide = async (slug, id, action) => {
        const key = `${slug}-${id}`;
        const note = notes[key] ?? "";

        if (action === "reject" && note.trim().length < 5) {
            alert("Say why you are rejecting this - the author needs something to act on.");
            return;
        }

        setBusy(key);

        try {
            await api.post(`/material/${slug}/${id}/${action}`, { notes: note || undefined });
            setNotes((prev) => ({ ...prev, [key]: "" }));
            setOpenItem(null);
            await load();
            onChanged?.();
        } catch (err) {
            const errors = err.response?.data?.errors;
            alert(errors
                ? Object.values(errors).flat().join("\n")
                : (err.response?.data?.message ?? "Could not save that decision."));
        } finally {
            setBusy(null);
        }
    };

    /**
     * The flat per-type lists, rebuilt as subject -> roadmap -> items.
     *
     * Maps rather than plain objects: a roadmap called "constructor" is not a
     * realistic title, but it is a real way for an object keyed by name to
     * hand back something that is not an item list.
     */
    const tree = useMemo(() => {
        const subjects = new Map();

        SECTIONS.forEach((section) => {
            (pending[section.key] ?? []).forEach((item) => {
                // Nulls are possible for material whose version node lost its
                // roadmap. Filed rather than dropped: an item nobody can see
                // is an item nobody approves, and it would sit here forever.
                const subject = item.subject ?? "Unfiled";
                const roadmap = item.roadmap ?? "Unfiled";

                if (!subjects.has(subject)) subjects.set(subject, new Map());

                const roadmaps = subjects.get(subject);

                if (!roadmaps.has(roadmap)) roadmaps.set(roadmap, []);

                roadmaps.get(roadmap).push({ ...item, section });
            });
        });

        return [...subjects.entries()]
            .map(([subject, roadmaps]) => ({
                subject,
                count: [...roadmaps.values()].reduce((n, items) => n + items.length, 0),
                roadmaps: [...roadmaps.entries()]
                    .map(([roadmap, items]) => ({ roadmap, items }))
                    .sort((a, b) => a.roadmap.localeCompare(b.roadmap)),
            }))
            .sort((a, b) => a.subject.localeCompare(b.subject));
    }, [pending]);

    const totalPending = tree.reduce((sum, s) => sum + s.count, 0);

    const toggle = (name) => setClosed((prev) => {
        const next = new Set(prev);

        if (next.has(name)) {
            next.delete(name);
        } else {
            next.add(name);
        }

        return next;
    });

    return (
        <div className="p-8">
            <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">Review Queue</h1>
            <p className="text-gray-400 mt-2 mb-10">
                Approve or reject material before it reaches students.
                {totalPending > 0 && (
                    <span className="ml-2 text-gray-500">
                        {totalPending} waiting, filed under the subject it belongs to.
                    </span>
                )}
            </p>

            {loading ? (
                <p className="text-gray-400">Loading...</p>
            ) : totalPending === 0 ? (
                <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-12 text-center">
                    <CheckCircle2 size={48} className="mx-auto text-gray-600 mb-4" />
                    <h2 className="text-2xl font-bold">Everything is reviewed</h2>
                    <p className="text-gray-400 mt-2">Nothing is waiting for a decision.</p>
                </div>
            ) : (
                <div className="space-y-4">
                    {tree.map((subject) => {
                        const shut = closed.has(subject.subject);

                        return (
                            <div key={subject.subject}
                                 className="rounded-3xl border border-white/10 bg-white/[0.03] overflow-hidden">
                                <button
                                    onClick={() => toggle(subject.subject)}
                                    className="w-full flex items-center gap-3 p-5 text-left hover:bg-white/[0.02] transition">
                                    {shut
                                        ? <Folder size={20} className="text-[#0097a7] shrink-0" />
                                        : <FolderOpen size={20} className="text-[#0097a7] shrink-0" />}

                                    <h2 className="text-xl font-bold min-w-0 truncate">{subject.subject}</h2>

                                    <span className="text-xs px-3 py-1 rounded-full bg-yellow-400/20 text-yellow-300 shrink-0">
                                        {subject.count} pending
                                    </span>

                                    <ChevronRight size={16} className={`text-gray-600 shrink-0 ml-auto transition-transform ${
                                        shut ? "" : "rotate-90"
                                    }`} />
                                </button>

                                {!shut && (
                                    <div className="px-5 pb-5 space-y-5">
                                        {subject.roadmaps.map((group) => (
                                            <div key={group.roadmap}>
                                                {/* A label rather than another
                                                    folder to open: most roadmaps
                                                    have one or two things pending,
                                                    and a third click to reach two
                                                    rows is worse than seeing them. */}
                                                <div className="flex items-center gap-2 mb-2">
                                                    <span className="text-xs uppercase tracking-wider text-gray-500">
                                                        {group.roadmap}
                                                    </span>
                                                    <span className="h-px flex-1 bg-white/5" />
                                                    <span className="text-xs text-gray-600">
                                                        {group.items.length}
                                                    </span>
                                                </div>

                                                <div className="space-y-1.5">
                                                    {group.items.map((item) => (
                                                        <Item
                                                            key={`${item.section.slug}-${item.id}`}
                                                            item={item}
                                                            open={openItem === `${item.section.slug}-${item.id}`}
                                                            onToggle={() => setOpenItem((prev) => (
                                                                prev === `${item.section.slug}-${item.id}`
                                                                    ? null
                                                                    : `${item.section.slug}-${item.id}`
                                                            ))}
                                                            note={notes[`${item.section.slug}-${item.id}`] ?? ""}
                                                            onNote={(k, value) => setNotes((prev) => ({ ...prev, [k]: value }))}
                                                            busy={busy}
                                                            onDecide={decide}
                                                        />
                                                    ))}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}


/**
 * One pending thing: a line, until you open it to decide on it.
 *
 * Defined out here rather than inside MaterialQueue. Nested, it was a new
 * component type on every render, so React threw the old one away and built
 * a fresh one - and typing a single character into the notes box re-rendered
 * the parent and took the focus with it.
 */
function Item({ item, open, onToggle, note, onNote, busy, onDecide }) {
    const { section } = item;
    const key = `${section.slug}-${item.id}`;
    const Icon = section.icon;

    return (
            <div className={`rounded-xl border transition ${
                open
                    ? "border-[#0097a7]/30 bg-[#0097a7]/[0.04]"
                    : "border-white/5 bg-black/20 hover:border-white/10"
            }`}>
                <button
                    onClick={onToggle}
                    className="w-full flex items-center gap-3 p-3 text-left">
                    <Icon size={15} className={`${section.tint} shrink-0`} />

                    <span className="text-sm font-medium min-w-0 truncate">{item.title}</span>

                    <span className="text-xs text-gray-600 shrink-0">{section.label}</span>

                    {/* The module, for material. A roadmap and a version have
                        none - they are the thing modules hang off. */}
                    {item.module && (
                        <span className="text-xs text-gray-600 shrink-0 truncate hidden sm:inline">
                            · {item.module}
                        </span>
                    )}

                    {/* Back in the queue because someone edited it, rather
                        than because it is new. Flagged on the closed row so
                        it does not need opening to be spotted. */}
                    {item.change_note && (
                        <span className="flex items-center gap-1 text-xs text-yellow-300 shrink-0">
                            <PencilLine size={11} /> edited
                        </span>
                    )}

                    <ChevronRight size={14} className={`text-gray-600 shrink-0 ml-auto transition-transform ${
                        open ? "rotate-90" : ""
                    }`} />
                </button>

                {open && (
                    <div className="px-3 pb-3">
                        <div className="rounded-xl border border-white/5 bg-black/20 p-4">
                            <div className="flex flex-col lg:flex-row lg:justify-between gap-5">
                                <div className="flex-1 min-w-0">
                                    {item.change_note && (
                                        <p className="mb-3 flex items-start gap-2 rounded-xl border border-yellow-400/30 bg-yellow-400/5 px-3 py-2 text-xs text-yellow-200">
                                            <PencilLine size={14} className="shrink-0 mt-px" />
                                            <span>
                                                <span className="font-semibold">Edited since it was approved. </span>
                                                {item.change_note}
                                            </span>
                                        </p>
                                    )}

                                    {item.description && (
                                        <p className="text-gray-400 text-sm leading-relaxed">
                                            {item.description}
                                        </p>
                                    )}

                                    {item.source && (
                                        <p className="text-xs text-gray-500 mt-2">
                                            Source: {item.source}
                                        </p>
                                    )}

                                    {item.url && (
                                        <a href={item.url} target="_blank" rel="noreferrer"
                                           className="mt-3 inline-flex items-center gap-2 text-[#00b0c2] text-sm hover:underline break-all">
                                            {item.url} <ExternalLink size={14} className="shrink-0" />
                                        </a>
                                    )}

                                    {/* A quiz cannot be judged without reading it. */}
                                    {item.questions?.length > 0 && (
                                        <div className="mt-4 space-y-3">
                                            {item.questions.map((q, qi) => (
                                                <div key={qi} className="rounded-xl border border-white/10 bg-black/30 p-3">
                                                    <p className="text-sm font-medium">
                                                        {qi + 1}. {q.question}
                                                    </p>

                                                    {q.options?.length > 0 && (
                                                        <ul className="mt-2 ml-4 space-y-1">
                                                            {q.options.map((o, oi) => (
                                                                <li key={oi} className={`text-xs ${
                                                                    o === q.answer ? "text-green-400" : "text-gray-500"
                                                                }`}>
                                                                    · {o}{o === q.answer ? "  ✓" : ""}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}

                                                    {(!q.options || q.options.length === 0) && (
                                                        <p className="mt-2 ml-4 text-xs text-green-400">
                                                            Expected: {q.answer}
                                                        </p>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                <div className="lg:w-[300px] shrink-0 space-y-3">
                                    <textarea
                                        rows="3"
                                        placeholder="Notes (required to reject)"
                                        value={note}
                                        onChange={(e) => onNote(key, e.target.value)}
                                        className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white text-sm"
                                    />

                                    <div className="flex gap-3">
                                        <button
                                            onClick={() => onDecide(section.slug, item.id, "approve")}
                                            disabled={busy === key}
                                            className="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl bg-green-500 hover:bg-green-600 font-semibold text-sm transition disabled:opacity-50">
                                            <Check size={16} /> Approve
                                        </button>

                                        <button
                                            onClick={() => onDecide(section.slug, item.id, "reject")}
                                            disabled={busy === key}
                                            className="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl bg-red-500 hover:bg-red-600 font-semibold text-sm transition disabled:opacity-50">
                                            <X size={16} /> Reject
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        );
}

export default MaterialQueue;
