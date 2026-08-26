import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import {
    Check, X, ExternalLink, CheckCircle2, Map, GitBranch, Video, FileText,
    Brain, Code2,
} from "lucide-react";

// Slug must match MaterialController::TYPES.
const SECTIONS = [
    { key: "roadmaps", slug: "roadmaps", label: "Roadmaps", icon: <Map size={18} className="text-[#0097a7]" /> },
    { key: "roadmap_versions", slug: "roadmap-versions", label: "Roadmap Versions", icon: <GitBranch size={18} className="text-yellow-400" /> },
    { key: "tutorials", slug: "tutorials", label: "Tutorials", icon: <Video size={18} className="text-purple-400" /> },
    { key: "quizzes", slug: "quizzes", label: "Quizzes", icon: <Brain size={18} className="text-pink-400" /> },
    { key: "projects", slug: "projects", label: "Projects", icon: <Code2 size={18} className="text-orange-400" /> },
    { key: "external_resources", slug: "external-resources", label: "Sources", icon: <FileText size={18} className="text-blue-400" /> },
];

function MaterialQueue({ onChanged }) {
    const [pending, setPending] = useState({});
    const [notes, setNotes] = useState({});
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(null);

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

    const totalPending = SECTIONS.reduce(
        (sum, s) => sum + (pending[s.key]?.length ?? 0), 0
    );

    return (
        <div className="p-8">
            <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">Review Queue</h1>
            <p className="text-gray-400 mt-2 mb-10">
                Approve or reject material before it reaches students.
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
                <div className="space-y-12">
                    {SECTIONS.map((section) => {
                        const items = pending[section.key] ?? [];

                        if (items.length === 0) {
                            return null;
                        }

                        return (
                            <section key={section.key}>
                                <div className="flex items-center gap-3 mb-6">
                                    {section.icon}
                                    <h2 className="text-3xl font-bold">{section.label}</h2>
                                    <span className="text-xs px-3 py-1 rounded-full bg-yellow-400/20 text-yellow-300">
                                        {items.length} pending
                                    </span>
                                </div>

                                <div className="space-y-5">
                                    {items.map((item) => {
                                        const key = `${section.slug}-${item.id}`;

                                        return (
                                            <div key={item.id} className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                                                <div className="flex flex-col lg:flex-row lg:justify-between gap-6">
                                                    <div className="flex-1 min-w-0">
                                                        <h3 className="text-2xl font-bold">{item.title}</h3>

                                                        {item.topic && (
                                                            <p className="text-xs text-gray-500 mt-1">
                                                                Topic: {item.topic}
                                                            </p>
                                                        )}

                                                        {item.description && (
                                                            <p className="text-gray-400 mt-3 text-sm leading-relaxed">
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

                                                    <div className="lg:w-[320px] shrink-0 space-y-3">
                                                        <textarea
                                                            rows="3"
                                                            placeholder="Notes (required to reject)"
                                                            value={notes[key] ?? ""}
                                                            onChange={(e) => setNotes((prev) => ({ ...prev, [key]: e.target.value }))}
                                                            className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white text-sm"
                                                        />

                                                        <div className="flex gap-3">
                                                            <button
                                                                onClick={() => decide(section.slug, item.id, "approve")}
                                                                disabled={busy === key}
                                                                className="flex-1 flex items-center justify-center gap-2 py-3 rounded-xl bg-green-500 hover:bg-green-600 font-semibold transition disabled:opacity-50">
                                                                <Check size={18} /> Approve
                                                            </button>

                                                            <button
                                                                onClick={() => decide(section.slug, item.id, "reject")}
                                                                disabled={busy === key}
                                                                className="flex-1 flex items-center justify-center gap-2 py-3 rounded-xl bg-red-500 hover:bg-red-600 font-semibold transition disabled:opacity-50">
                                                                <X size={18} /> Reject
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </section>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

export default MaterialQueue;
