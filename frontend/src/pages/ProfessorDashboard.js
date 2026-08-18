import React, { useCallback, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import api from "../api";
import { useAuth } from "../context/AuthContext";
import { ShieldCheck, LogOut, UserRound, CheckCircle2, Inbox } from "lucide-react";

function ProfessorDashboard() {
    const navigate = useNavigate();
    const { logout } = useAuth();

    const [requests, setRequests] = useState([]);
    const [stats, setStats] = useState({ pending_unclaimed: 0, claimed_by_me: 0, reviewed_by_me: 0 });
    const [loading, setLoading] = useState(true);
    const [form, setForm] = useState({});

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
        try {
            await api.post(`/professor/review-requests/${id}/claim`);
            await load();
        } catch (err) {
            alert(err.response?.data?.message ?? "Could not claim.");
        }
    };

    const submitReview = async (id) => {
        const entry = form[id] ?? {};

        try {
            await api.post(`/professor/review-requests/${id}/review`, {
                score: Number(entry.score),
                feedback: entry.feedback ?? "",
            });
            setForm((prev) => ({ ...prev, [id]: {} }));
            await load();
        } catch (err) {
            const errors = err.response?.data?.errors;
            alert(errors ? Object.values(errors).flat().join("\n") : (err.response?.data?.message ?? "Could not submit review."));
        }
    };

    const setField = (id, field, value) =>
        setForm((prev) => ({ ...prev, [id]: { ...(prev[id] ?? {}), [field]: value } }));

    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.08),transparent_55%)] pointer-events-none" />

            <header className="relative z-10 px-8 lg:px-20 py-10 border-b border-white/10 flex items-center justify-between">
                <div className="flex items-center gap-4">
                    <div className="w-14 h-14 rounded-2xl bg-[#0097a7] text-black flex items-center justify-center">
                        <ShieldCheck size={28} />
                    </div>
                    <div>
                        <h1 className="text-4xl font-black">Professor Dashboard</h1>
                        <p className="text-gray-400 mt-2">Review student project submissions.</p>
                    </div>
                </div>

                <button
                    onClick={async () => { await logout(); navigate("/login"); }}
                    className="flex items-center gap-2 px-5 py-3 rounded-2xl border border-red-500/20 bg-red-500/5 text-red-400 hover:bg-red-500/10 transition"
                >
                    <LogOut size={18} /> Logout
                </button>
            </header>

            <main className="relative z-10 px-8 lg:px-20 py-10">
                <div className="grid md:grid-cols-3 gap-6 mb-10">
                    {[
                        { label: "Unclaimed requests", value: stats.pending_unclaimed, icon: <Inbox className="text-[#0097a7]" /> },
                        { label: "Claimed by me", value: stats.claimed_by_me, icon: <UserRound className="text-yellow-400" /> },
                        { label: "Reviewed by me", value: stats.reviewed_by_me, icon: <CheckCircle2 className="text-green-400" /> },
                    ].map((card) => (
                        <div key={card.label} className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                            <div className="flex items-center gap-3 mb-3">
                                {card.icon}
                                <span className="text-gray-400">{card.label}</span>
                            </div>
                            <h2 className="text-4xl font-black">{card.value}</h2>
                        </div>
                    ))}
                </div>

                <h2 className="text-3xl font-bold mb-6">Review Queue</h2>

                {loading ? (
                    <p className="text-gray-400">Loading...</p>
                ) : requests.length === 0 ? (
                    <p className="text-gray-500">No pending review requests.</p>
                ) : (
                    <div className="space-y-6">
                        {requests.map((r) => (
                            <div key={r.id} className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                                <div className="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">
                                    <div className="flex-1">
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
                                                    AI estimated {r.submission.ai_score}%
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

                                        {r.submission.has_file && (
                                            <p className="text-xs text-gray-400 mt-2">ZIP file attached</p>
                                        )}

                                        {r.submission.notes && (
                                            <p className="mt-3 text-sm text-gray-300">{r.submission.notes}</p>
                                        )}

                                        {/* The AI's reasoning, so the professor does not start cold. */}
                                        {r.submission.ai_feedback && (
                                            <p className="mt-3 text-xs text-gray-400 leading-relaxed whitespace-pre-line border-l-2 border-[#0097a7]/30 pl-3">
                                                {r.submission.ai_feedback}
                                            </p>
                                        )}
                                    </div>

                                    <div className="lg:w-[340px]">
                                        {!r.mine ? (
                                            <button
                                                onClick={() => claim(r.id)}
                                                className="w-full py-3 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-[1.02] transition"
                                            >
                                                Claim for Review
                                            </button>
                                        ) : (
                                            <div className="space-y-3">
                                                <input
                                                    type="number" min="0" max="100"
                                                    placeholder="Score (0-100)"
                                                    value={form[r.id]?.score ?? ""}
                                                    onChange={(e) => setField(r.id, "score", e.target.value)}
                                                    className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white"
                                                />
                                                <textarea
                                                    rows="4"
                                                    placeholder="Feedback (at least 10 characters)"
                                                    value={form[r.id]?.feedback ?? ""}
                                                    onChange={(e) => setField(r.id, "feedback", e.target.value)}
                                                    className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white"
                                                />
                                                <button
                                                    onClick={() => submitReview(r.id)}
                                                    className="w-full py-3 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-[1.02] transition"
                                                >
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
            </main>
        </div>
    );
}

export default ProfessorDashboard;