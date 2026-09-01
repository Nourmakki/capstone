import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import api from "../api";
import { BookOpen, ArrowRight, Flame, CheckCircle2 } from "lucide-react";

function ContinueLearningPage() {
    const navigate = useNavigate();
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const load = async () => {
            try {
                const response = await api.get("/progress/continue");
                setData(response.data);
            } catch (error) {
                console.error(error);
            } finally {
                setLoading(false);
            }
        };

        load();
    }, []);

    if (loading) {
        return (
            <div className="min-h-screen bg-black text-gray-400 flex items-center justify-center">
                Loading your progress...
            </div>
        );
    }

    // Most recent first, so the first card is the one to carry on with and the
    // rest are the others still open. A student working through two roadmaps
    // used to see only whichever they opened last.
    const roadmaps = data?.roadmaps ?? [];
    const [current, ...others] = roadmaps;

    /**
     * One roadmap in progress.
     *
     * The first is rendered large - it is the answer to "where was I" - and
     * the others compactly, because they are a list to choose from rather than
     * a thing to read.
     */
    const Card = ({ entry, primary }) => (
        <div className={`rounded-3xl border bg-white/[0.03] ${
            primary
                ? "border-[#0097a7]/20 p-8"
                : "border-white/10 p-6 hover:border-[#0097a7]/30 transition"
        }`}>
            <div className="flex items-center gap-3 mb-2">
                {entry.is_finished
                    ? <CheckCircle2 className="text-green-400 shrink-0" size={primary ? 24 : 20} />
                    : <BookOpen className="text-[#0097a7] shrink-0" size={primary ? 24 : 20} />}

                <h2 className={primary ? "text-3xl font-bold" : "text-xl font-bold"}>
                    {/* Where it sits. "React" on its own does not say which
                        roadmap it came out of, and roadmaps run three deep. */}
                    {entry.roadmap.parent && (
                        <span className={`text-gray-500 font-normal ${primary ? "text-xl" : "text-base"}`}>
                            {entry.roadmap.parent.title}{" "}
                            <span className="text-gray-600">›</span>{" "}
                        </span>
                    )}
                    {entry.roadmap.title}
                </h2>
            </div>

            {/* Progress belongs to the version it was made on, so a student
                stays on theirs - but a roadmap that has moved on since would
                otherwise just look out of date. */}
            {entry.is_latest === false && (
                <p className="mt-3 mb-1 text-sm text-yellow-200 border-l-2 border-yellow-400/40 pl-3">
                    You are following v{entry.version.version_number}.
                    v{entry.newer_version.version_number} has since been published -
                    your progress here is kept as it is.
                </p>
            )}

            {entry.is_finished ? (
                <p className={`text-gray-400 ${primary ? "mt-4" : "mt-2 text-sm"}`}>
                    Every module finished.
                </p>
            ) : (
                <>
                    <p className={primary ? "text-xl font-semibold mt-4" : "text-base font-semibold mt-3"}>
                        {entry.current_node.title}
                    </p>

                    {primary && (
                        <p className="text-gray-400 mt-2">{entry.current_node.description}</p>
                    )}
                </>
            )}

            <div className={primary ? "mt-8" : "mt-4"}>
                <div className="flex justify-between mb-2">
                    <span className="text-gray-400 text-sm">Progress</span>
                    <span className="text-[#00b0c2] text-sm">{entry.percent_complete}%</span>
                </div>

                <div className={`w-full bg-black/30 rounded-full overflow-hidden ${primary ? "h-3" : "h-2"}`}>
                    <div
                        className="h-full bg-gradient-to-r from-blue-400 to-[#0097a7] transition-all"
                        style={{ width: `${entry.percent_complete}%` }}
                    />
                </div>

                <p className="text-gray-400 mt-3 text-sm">
                    {entry.completed_count} of {entry.total_nodes} modules completed
                </p>
            </div>

            {entry.is_finished ? (
                <button
                    onClick={() => navigate(`/roadmap/${entry.roadmap.id}`)}
                    className={`flex items-center gap-3 rounded-2xl border border-white/10 text-gray-300 hover:border-[#0097a7]/40 transition ${
                        primary ? "mt-8 px-7 py-4 font-bold" : "mt-4 px-5 py-2.5 text-sm"
                    }`}
                >
                    Review it
                    <ArrowRight size={primary ? 20 : 16} />
                </button>
            ) : (
                <button
                    onClick={() => navigate(`/learning/${entry.current_node.version_node_id}`)}
                    className={`flex items-center gap-3 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-105 transition ${
                        primary ? "mt-8 px-7 py-4" : "mt-4 px-5 py-2.5 text-sm"
                    }`}
                >
                    {primary ? "Continue Learning" : "Continue"}
                    <ArrowRight size={primary ? 20 : 16} />
                </button>
            )}
        </div>
    );

    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.15),transparent_55%)] pointer-events-none" />

            <div className="relative z-10 px-10 py-10">
                <div className="mb-10">
                    <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]">
                        <Flame size={16} />
                        Continue Learning
                    </div>

                    <h1 className="text-3xl lg:text-4xl font-black mt-5">Welcome Back</h1>

                    <p className="text-gray-400 mt-4 max-w-3xl">
                        {roadmaps.length > 1
                            ? "Every roadmap you have started, most recent first."
                            : "Pick up exactly where you stopped and continue progressing "
                              + "through your personalized roadmap."}
                    </p>
                </div>

                {roadmaps.length === 0 ? (
                    <div className="max-w-3xl rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                        <h2 className="text-2xl font-bold mb-3">
                            You have not started a roadmap yet.
                        </h2>

                        <p className="text-gray-400">
                            Choose a roadmap from your dashboard to begin.
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="max-w-3xl">
                            <Card entry={current} primary />
                        </div>

                        {others.length > 0 && (
                            <>
                                <h2 className="text-sm uppercase tracking-[0.2em] text-gray-500 mt-12 mb-5">
                                    Also in progress
                                </h2>

                                <div className="grid md:grid-cols-2 gap-5 max-w-5xl">
                                    {others.map((entry) => (
                                        <Card key={entry.version.id} entry={entry} />
                                    ))}
                                </div>
                            </>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

export default ContinueLearningPage;
