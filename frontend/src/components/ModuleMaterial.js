import React, { useCallback, useEffect, useState } from "react";
import api from "../api";
import {
    Video, Brain, Code2, BookOpen, ExternalLink, Trash2, RefreshCw, Pencil,
} from "lucide-react";

const STATUS_STYLE = {
    approved: "border-green-400/30 bg-green-400/10 text-green-300",
    rejected: "border-red-400/30 bg-red-400/10 text-red-300",
    pending:  "border-yellow-400/30 bg-yellow-400/10 text-yellow-300",
};

/**
 * Everything on one module: its tutorials, quizzes, projects and resources.
 *
 * Tutorials and resources used to have pages of their own, which meant three
 * screens to answer "what is actually on this module" and no screen that
 * answered it. This is that screen, and it sits inside the roadmap rather
 * than beside it.
 *
 * Read and curate, not create - the AI writes this material. A tutorial is
 * somebody else's video so it can only be regenerated or removed; the rest
 * can be corrected.
 */
function ModuleMaterial({ versionNodeId, editable = true, onChanged }) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);

        try {
            const response = await api.get(`/version-nodes/${versionNodeId}/material`);
            setData(response.data);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, [versionNodeId]);

    useEffect(() => { load(); }, [load]);

    const act = async (fn, confirmText) => {
        if (confirmText && !window.confirm(confirmText)) return;

        setBusy(true);

        try {
            await fn();
            await load();
            onChanged?.();
        } catch (err) {
            alert(err.response?.data?.message ?? "Could not do that.");
        } finally {
            setBusy(false);
        }
    };

    const Status = ({ item }) => (
        item.review_status !== "approved" && (
            <span className={`text-xs px-2 py-0.5 rounded-full border shrink-0 ${
                STATUS_STYLE[item.review_status] ?? STATUS_STYLE.pending
            }`}>
                {item.review_status}
            </span>
        )
    );

    const Group = ({ icon, label, items, children }) => (
        <div className="mb-4 last:mb-0">
            <div className="flex items-center gap-2 mb-2">
                {icon}
                <h4 className="text-xs uppercase tracking-wider text-gray-400">
                    {label} ({items.length})
                </h4>
            </div>

            {items.length === 0 ? (
                <p className="text-xs text-gray-600 ml-6">None yet.</p>
            ) : (
                <div className="ml-6 space-y-2">{children}</div>
            )}
        </div>
    );

    if (loading) {
        return <p className="text-xs text-gray-500">Loading material...</p>;
    }

    if (!data) {
        return <p className="text-xs text-gray-600">Could not load this module's material.</p>;
    }

    return (
        <div className={busy ? "opacity-60 pointer-events-none" : ""}>
            <Group icon={<Video size={14} className="text-[#0097a7]" />}
                   label="Tutorials" items={data.tutorials}>
                {data.tutorials.map((t) => (
                    <div key={t.id} className="flex items-start justify-between gap-3 rounded-xl border border-white/10 bg-black/20 p-3">
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <a href={t.url} target="_blank" rel="noreferrer"
                                    className="text-sm font-medium text-[#00b0c2] hover:underline inline-flex items-center gap-1.5">
                                    {t.title}
                                    <ExternalLink size={11} className="shrink-0" />
                                </a>
                                <Status item={t} />
                            </div>
                            <p className="text-xs text-gray-500 mt-1">{t.source_name}</p>
                        </div>

                        {editable && (
                            <div className="flex gap-2 shrink-0">
                                {/* A tutorial is somebody else's video - there is
                                    nothing in it to rewrite, so the AI finds a
                                    different one instead. */}
                                <button title="Ask the AI for a different video"
                                    onClick={() => act(
                                        () => api.post(`/tutorials/${t.id}/regenerate`),
                                        "Ask the AI for a different video for this module?"
                                    )}
                                    className="p-2 rounded-lg border border-[#0097a7]/30 text-[#0097a7] hover:bg-[#0097a7]/10">
                                    <RefreshCw size={14} />
                                </button>

                                <button title="Remove"
                                    onClick={() => act(
                                        () => api.delete(`/tutorials/${t.id}`),
                                        `Remove "${t.title}"?`
                                    )}
                                    className="p-2 rounded-lg border border-red-400/30 text-red-400 hover:bg-red-400/10">
                                    <Trash2 size={14} />
                                </button>
                            </div>
                        )}
                    </div>
                ))}
            </Group>

            <Group icon={<Brain size={14} className="text-purple-400" />}
                   label="Quizzes" items={data.quizzes}>
                {data.quizzes.map((q) => (
                    <div key={q.id} className="rounded-xl border border-white/10 bg-black/20 p-3">
                        <div className="flex items-center gap-2 flex-wrap">
                            <span className="text-sm font-medium">{q.title}</span>
                            <span className="text-xs text-gray-500">
                                {q.questions.length} question{q.questions.length === 1 ? "" : "s"}
                            </span>
                            <Status item={q} />
                        </div>

                        {/* The questions themselves, because a quiz cannot be
                            judged from its title. */}
                        {q.questions.length > 0 && (
                            <ul className="mt-2 space-y-1">
                                {q.questions.map((question, i) => (
                                    <li key={i} className="text-xs text-gray-400">
                                        · {question.question}
                                        <span className="text-gray-600"> → {question.answer}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                ))}
            </Group>

            <Group icon={<Code2 size={14} className="text-yellow-400" />}
                   label="Projects" items={data.projects}>
                {data.projects.map((p) => (
                    <div key={p.id} className="rounded-xl border border-white/10 bg-black/20 p-3">
                        <div className="flex items-center gap-2 flex-wrap">
                            <span className="text-sm font-medium">{p.title}</span>
                            <span className="text-xs text-gray-500 capitalize">{p.difficulty}</span>
                            <Status item={p} />
                        </div>
                        <p className="text-xs text-gray-400 mt-1 line-clamp-2">{p.description}</p>
                    </div>
                ))}
            </Group>

            <Group icon={<BookOpen size={14} className="text-blue-400" />}
                   label="Resources" items={data.external_resources}>
                {data.external_resources.map((r) => (
                    <div key={r.id} className="flex items-start justify-between gap-3 rounded-xl border border-white/10 bg-black/20 p-3">
                        <div className="min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                                <a href={r.url} target="_blank" rel="noreferrer"
                                    className="text-sm font-medium text-[#00b0c2] hover:underline inline-flex items-center gap-1.5 break-all">
                                    {r.title}
                                    <ExternalLink size={11} className="shrink-0" />
                                </a>
                                <Status item={r} />
                            </div>
                            <p className="text-xs text-gray-500 mt-1 capitalize">
                                {r.type.replace(/_/g, " ")}
                            </p>
                        </div>

                        {editable && (
                            <button title="Remove"
                                onClick={() => act(
                                    () => api.delete(`/external-resources/${r.id}`),
                                    `Remove "${r.title}"?`
                                )}
                                className="p-2 rounded-lg border border-red-400/30 text-red-400 hover:bg-red-400/10 shrink-0">
                                <Trash2 size={14} />
                            </button>
                        )}
                    </div>
                ))}
            </Group>

            {/* Correcting a quiz or project is a form of its own, and it lives
                beside the module picker on Learning Material - linked rather
                than duplicated here. */}
            {editable && (data.quizzes.length > 0 || data.projects.length > 0) && (
                <p className="text-xs text-gray-600 mt-4 flex items-center gap-1.5">
                    <Pencil size={11} />
                    Correct a quiz or project from Learning Material.
                </p>
            )}
        </div>
    );
}

export default ModuleMaterial;
