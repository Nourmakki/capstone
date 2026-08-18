import React, { useCallback, useEffect, useState } from "react";
import api from "../api";
import { MapPin, Clock3, Globe, CalendarDays, ExternalLink } from "lucide-react";

function WorkshopsPage() {
    const [recommended, setRecommended] = useState([]);
    const [all, setAll] = useState([]);
    const [country, setCountry] = useState(null);
    const [hasProgress, setHasProgress] = useState(false);
    const [tab, setTab] = useState("recommended");
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        try {
            const [rec, everything] = await Promise.all([
                api.get("/workshops/recommended"),
                api.get("/workshops"),
            ]);
            setRecommended(rec.data.workshops);
            setCountry(rec.data.country);
            setHasProgress(rec.data.has_progress);
            setAll(everything.data.workshops);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const list = tab === "recommended" ? recommended : all;

    const formatDate = (iso) =>
        new Date(iso).toLocaleString(undefined, {
            weekday: "short", day: "numeric", month: "short",
            hour: "2-digit", minute: "2-digit",
        });

    return (
        <div className="min-h-screen bg-black text-white p-8">
            <div className="mb-8">
                <h1 className="text-5xl font-black text-[#0097a7]">Workshops</h1>
                <p className="text-gray-400 mt-2">
                    {!country
                        ? "Set a location on your profile to see nearby events."
                        : hasProgress
                        ? `Matched to your roadmaps, in ${country} and online.`
                        : `Events in ${country} and online. Start a roadmap and these will narrow to your topics.`}
                </p>
            </div>

            <div className="flex gap-3 mb-8">
                {[
                    { id: "recommended", label: `For You (${recommended.length})` },
                    { id: "all", label: `All Upcoming (${all.length})` },
                ].map((t) => (
                    <button key={t.id} onClick={() => setTab(t.id)}
                        className={`px-5 py-3 rounded-2xl transition ${
                            tab === t.id
                                ? "bg-[#0097a7] text-black font-semibold"
                                : "bg-white/[0.03] border border-white/10 text-gray-300"
                        }`}>
                        {t.label}
                    </button>
                ))}
            </div>

            {loading ? (
                <p className="text-gray-400">Loading workshops...</p>
            ) : list.length === 0 ? (
                <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-12 text-center">
                    <CalendarDays size={48} className="mx-auto text-gray-600 mb-4" />
                    <h2 className="text-2xl font-bold">Nothing scheduled</h2>
                    <p className="text-gray-400 mt-2">
                        {tab === "recommended"
                            ? "No workshops match your roadmaps and location yet."
                            : "There are no upcoming workshops."}
                    </p>
                </div>
            ) : (
                <div className="grid gap-5 md:grid-cols-2">
                    {list.map((w) => (
                        <div key={w.id} className="rounded-3xl border border-white/10 bg-white/[0.03] p-6 hover:border-[#0097a7]/40 transition">
                            <div className="flex items-start justify-between gap-3 mb-3">
                                <h3 className="text-xl font-bold">{w.title}</h3>
                                {w.is_online ? (
                                    <span className="shrink-0 text-xs px-3 py-1 rounded-full border border-purple-400/30 bg-purple-500/10 text-purple-300 flex items-center gap-1">
                                        <Globe size={12} /> Online
                                    </span>
                                ) : (
                                    <span className="shrink-0 text-xs px-3 py-1 rounded-full border border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2]">
                                        {w.country}
                                    </span>
                                )}
                            </div>

                            {w.topic && (
                                <p className="text-xs text-gray-500 mb-3">Topic: {w.topic}</p>
                            )}

                            {w.description && (
                                <p className="text-sm text-gray-400 leading-relaxed">{w.description}</p>
                            )}

                            <div className="mt-5 space-y-2 text-sm text-gray-400">
                                <div className="flex items-center gap-2">
                                    <CalendarDays size={15} className="text-[#0097a7]" />
                                    {formatDate(w.scheduled_at)}
                                </div>
                                <div className="flex items-center gap-2">
                                    <Clock3 size={15} className="text-[#0097a7]" />
                                    {w.duration_minutes} minutes
                                </div>
                                {w.location && (
                                    <div className="flex items-center gap-2">
                                        <MapPin size={15} className="text-[#0097a7]" />
                                        {w.location}
                                    </div>
                                )}
                            </div>

                            {w.url && (
                                <a href={w.url} target="_blank" rel="noreferrer"
                                   className="mt-5 inline-flex items-center gap-2 text-[#00b0c2] text-sm hover:underline">
                                    More details <ExternalLink size={14} />
                                </a>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export default WorkshopsPage;