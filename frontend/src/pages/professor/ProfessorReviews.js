import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import { UserRound, CheckCircle2, Inbox, CheckCircle, XCircle, HelpCircle, Download } from "lucide-react";

function ProfessorReviews({ onChanged }) {
    const [requests, setRequests] = useState([]);
    const [stats, setStats] = useState({ pending_unclaimed: 0, claimed_by_me: 0, reviewed_by_me: 0 });
    const [loading, setLoading] = useState(true);
    const [form, setForm] = useState({});
    const [busy, setBusy] = useState(null);

    /**
     * The download route needs the bearer token, so a plain link will not
     * work. Fetch it as a blob and hand the browser an object URL instead -
     * same approach as the admin CV download.
     */
    const downloadZip = async (submissionId, student) => {
        try {
            const response = await api.get(`/submissions/${submissionId}/download`, {
                responseType: "blob",
            });

            const url = URL.createObjectURL(
                new Blob([response.data], { type: "application/zip" })
            );

            const link = document.createElement("a");
            link.href = url;
            link.download = `submission-${submissionId}-${student.replace(/\s+/g, "-").toLowerCase()}.zip`;
            document.body.appendChild(link);
            link.click();
            link.remove();

            setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch (err) {
            console.error(err);
            alert(err.response?.data?.message ?? "Could not download this submission.");
        }
    };

    const load = useCallback(async () => {
        try {
            const [r, s] = await Promise.all([
                api.get("/professor/review-requests"),
                api.get("/professor/stats"),
            ]);
            setRequests(r.data.requests);
            setStats(s.data);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const claim = async (id) => {
        setBusy(id);

        try {
            await api.post(`/professor/review-requests/${id}/claim`);
            await load();
            onChanged?.();
        } catch (err) {
            alert(err.response?.data?.message ?? "Could not claim.");
        } finally {
            setBusy(null);
        }
    };

    const submitReview = async (id) => {
        const entry = form[id] ?? {};
        setBusy(id);

        try {
            await api.post(`/professor/review-requests/${id}/review`, {
                score: Number(entry.score),
                feedback: entry.feedback ?? "",
            });
            setForm((prev) => ({ ...prev, [id]: {} }));
            await load();
            onChanged?.();
        } catch (err) {
            const errors = err.response?.data?.errors;
            alert(errors
                ? Object.values(errors).flat().join("\n")
                : (err.response?.data?.message ?? "Could not submit review."));
        } finally {
            setBusy(null);
        }
    };

    const setField = (id, field, value) =>
        setForm((prev) => ({ ...prev, [id]: { ...(prev[id] ?? {}), [field]: value } }));

    return (
        <div className="p-8">
            <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">Review Queue</h1>
            <p className="text-gray-400 mt-2 mb-10">
                Grade student quizzes and project submissions.
            </p>

            <div className="grid md:grid-cols-3 gap-6 mb-10">
                {[
                    { label: "Unclaimed requests", value: stats.pending_unclaimed, icon: <Inbox className="text-[#0097a7]" /> },
                    { label: "Claimed by me", value: stats.claimed_by_me, icon: <UserRound className="text-yellow-400" /> },
                    { label: "Reviewed by me", value: stats.reviewed_by_me, icon: <CheckCircle2 className="text-green-400" /> },
                ].map((card) => (
                    <div key={card.label} className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                        <div className="flex items-center gap-3 mb-3">
                            {card.icon}
                            <span className="text-gray-400 text-sm">{card.label}</span>
                        </div>
                        <h2 className="text-4xl font-black">{card.value}</h2>
                    </div>
                ))}
            </div>

            {loading ? (
                <p className="text-gray-400">Loading...</p>
            ) : requests.length === 0 ? (
                <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-12 text-center">
                    <CheckCircle2 size={48} className="mx-auto text-gray-600 mb-4" />
                    <h2 className="text-2xl font-bold">Queue is empty</h2>
                    <p className="text-gray-400 mt-2">No students are waiting for a review.</p>
                </div>
            ) : (
                <div className="space-y-6">
                    {requests.map((r) => (
                        <div key={r.id} className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                            <div className="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center flex-wrap gap-3 mb-1">
                                        <span className={`text-xs px-3 py-1 rounded-full border capitalize ${
                                            r.kind === "quiz"
                                                ? "border-purple-400/30 bg-purple-500/10 text-purple-300"
                                                : "border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2]"
                                        }`}>
                                            {r.kind}
                                        </span>

                                        {r.submission.ai_score !== null && (
                                            <span className="text-xs text-gray-400">
                                                AI estimated {r.submission.ai_score} / 100
                                            </span>
                                        )}

                                        {r.kind === "quiz" && r.submission.score !== null && (
                                            <span className="text-xs text-gray-400">
                                                Auto-scored {r.submission.score}%
                                            </span>
                                        )}
                                    </div>

                                    <h3 className="text-2xl font-bold">{r.title}</h3>
                                    <p className="text-gray-400 mt-1">by {r.student}</p>

                                    {r.submission.github_url && (
                                        <a href={r.submission.github_url} target="_blank" rel="noreferrer"
                                           className="block mt-3 text-[#00b0c2] text-sm break-all hover:underline">
                                            {r.submission.github_url}
                                        </a>
                                    )}

                                    {/* The archive is the fixed snapshot of what was
                                        handed in, so grading needs to reach it. */}
                                    {r.submission.has_file && (
                                        <button
                                            onClick={() => downloadZip(r.submission.id, r.student)}
                                            className="mt-3 inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-[#0097a7]/30 text-[#00b0c2] text-sm hover:bg-[#0097a7]/10 transition"
                                        >
                                            <Download size={14} />
                                            Download ZIP
                                        </button>
                                    )}

                                    {r.submission.notes && (
                                        <p className="mt-3 text-sm text-gray-300">{r.submission.notes}</p>
                                    )}

                                    {r.submission.ai_feedback && (
                                        <p className="mt-3 text-xs text-gray-400 leading-relaxed whitespace-pre-line border-l-2 border-[#0097a7]/30 pl-3">
                                            {r.submission.ai_feedback}
                                        </p>
                                    )}

                                    {/* Quiz answers, so grading does not need a second screen. */}
                                    {r.submission.answers && (
                                        <div className="mt-5 space-y-3">
                                            {r.submission.answers.map((a, i) => (
                                                <div key={a.question_id} className="rounded-2xl border border-white/10 bg-black/30 p-4">
                                                    <div className="flex items-start gap-2">
                                                        {a.is_correct === true && <CheckCircle size={16} className="text-green-400 mt-1 shrink-0" />}
                                                        {a.is_correct === false && <XCircle size={16} className="text-red-400 mt-1 shrink-0" />}
                                                        {a.is_correct === null && <HelpCircle size={16} className="text-yellow-400 mt-1 shrink-0" />}
                                                        <p className="font-semibold text-sm">{i + 1}. {a.question}</p>
                                                    </div>

                                                    <div className="mt-3 ml-6 space-y-1 text-sm">
                                                        <p className="text-gray-300">
                                                            <span className="text-gray-500">Answered: </span>
                                                            {a.given || <span className="italic text-gray-600">blank</span>}
                                                        </p>

                                                        {a.auto_gradable && (
                                                            <p className="text-green-400">
                                                                <span className="text-gray-500">Correct: </span>
                                                                {a.correct_answer}
                                                            </p>
                                                        )}

                                                        {!a.auto_gradable && (
                                                            <p className="text-yellow-400 text-xs">
                                                                Written answer - needs your judgement
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                <div className="lg:w-[340px] shrink-0">
                                    {!r.mine ? (
                                        <button onClick={() => claim(r.id)} disabled={busy === r.id}
                                            className="w-full py-3 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-[1.02] transition disabled:opacity-50">
                                            Claim for Review
                                        </button>
                                    ) : (
                                        <div className="space-y-3">
                                            <input type="number" min="0" max="100" placeholder="Score (0-100)"
                                                value={form[r.id]?.score ?? ""}
                                                onChange={(e) => setField(r.id, "score", e.target.value)}
                                                className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white" />

                                            <textarea rows="4" placeholder="Feedback (at least 10 characters)"
                                                value={form[r.id]?.feedback ?? ""}
                                                onChange={(e) => setField(r.id, "feedback", e.target.value)}
                                                className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white" />

                                            <button onClick={() => submitReview(r.id)} disabled={busy === r.id}
                                                className="w-full py-3 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-[1.02] transition disabled:opacity-50">
                                                Submit Review
                                            </button>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export default ProfessorReviews;
