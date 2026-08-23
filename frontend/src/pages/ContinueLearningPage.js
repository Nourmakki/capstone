import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import api from "../api";
import { BookOpen, ArrowRight, Flame } from "lucide-react";

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

    const started = data?.has_progress && data?.current_node;

    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.15),transparent_55%)] pointer-events-none" />

            <div className="relative z-10 px-10 py-10">
                <div className="mb-10">
                    <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]">
                        <Flame size={16} />
                        Continue Learning
                    </div>

                    <h1 className="text-5xl font-black mt-5">Welcome Back</h1>

                    <p className="text-gray-400 mt-4 max-w-3xl">
                        Pick up exactly where you stopped and continue progressing
                        through your personalized roadmap.
                    </p>
                </div>

                {!started ? (
                    <div className="max-w-3xl rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                        <h2 className="text-2xl font-bold mb-3">
                            {data?.has_progress
                                ? "You have completed every module."
                                : "You have not started a roadmap yet."}
                        </h2>

                        <p className="text-gray-400 mb-6">
                            {data?.has_progress
                                ? "Pick another roadmap to keep learning."
                                : "Choose a roadmap from your dashboard to begin."}
                        </p>
                    </div>
                ) : (
                    <div className="max-w-3xl rounded-3xl border border-[#0097a7]/20 bg-white/[0.03] p-8">
                        <div className="flex items-center gap-3 mb-6">
                            <BookOpen className="text-[#0097a7]" />
                            <h2 className="text-3xl font-bold">{data.roadmap.title}</h2>
                        </div>

                        <p className="text-xl font-semibold">{data.current_node.title}</p>

                        <p className="text-gray-400 mt-2">{data.current_node.description}</p>

                        <div className="mt-8">
                            <div className="flex justify-between mb-2">
                                <span className="text-gray-400">Progress</span>
                                <span className="text-[#00b0c2]">{data.percent_complete}%</span>
                            </div>

                            <div className="w-full h-3 bg-black/30 rounded-full overflow-hidden">
                                <div
                                    className="h-full bg-gradient-to-r from-blue-400 to-[#0097a7] transition-all"
                                    style={{ width: `${data.percent_complete}%` }}
                                />
                            </div>

                            <p className="text-gray-400 mt-3 text-sm">
                                {data.completed_count} of {data.total_nodes} modules completed
                            </p>
                        </div>

                        <button
                            onClick={() =>
                                navigate(`/learning/${data.current_node.version_node_id}`)
                            }
                            className="mt-8 flex items-center gap-3 px-7 py-4 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-105 transition"
                        >
                            Continue Learning
                            <ArrowRight size={20} />
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

export default ContinueLearningPage;