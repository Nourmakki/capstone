import React, { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import api from "../api";
import { useAuth } from "../context/AuthContext";
import {
    ArrowLeft,
    CheckCircle2,
    Lock,
    PlayCircle,
    Trophy,
    Flame,
    Clock3,
} from "lucide-react";

function RoadmapOverview() {
    const navigate = useNavigate();
    const { roadmapId } = useParams();

    const [roadmap, setRoadmap] = useState(null);
    const [modules, setModules] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const { user } = useAuth();

    useEffect(() => {
        const loadRoadmap = async () => {
            setLoading(true);

            try {
                const [detailResponse, progressResponse] = await Promise.all([
                    api.get(`/roadmaps/${roadmapId}`),
                    api.get(`/roadmaps/${roadmapId}/student/${user.id}`),
                ]);

                setRoadmap(detailResponse.data.data);

                const { nodes, edges, completed, current_node } = progressResponse.data;

                // Map node id -> the node ids that must be completed first.
                const prerequisites = {};
                edges.forEach((edge) => {
                    prerequisites[edge.to_node_id] =
                        prerequisites[edge.to_node_id] ?? [];
                    prerequisites[edge.to_node_id].push(edge.from_node_id);
                });

                setModules(
                    nodes.map((item) => {
                        const nodeId = item.node.id;
                        const required = prerequisites[nodeId] ?? [];

                        const isCompleted = completed.includes(nodeId);
                        const isCurrent = current_node === nodeId;
                        const isUnlocked = required.every((id) =>
                            completed.includes(id)
                        );

                        return {
                            id: nodeId,
                            versionNodeId: item.id,
                            title: item.node.title,
                            description: item.node.description,
                            difficulty: item.node.difficulty,
                            status: isCompleted
                                ? "completed"
                                : isCurrent || isUnlocked
                                ? "current"
                                : "locked",
                            progress: isCompleted ? 100 : 0,
                            prerequisites: required,
                        };
                    })
                );
            } catch (err) {
                console.error(err);
                setError("Could not load this roadmap.");
            } finally {
                setLoading(false);
            }
        };

        if (user?.id) {
            loadRoadmap();
        }
    }, [roadmapId, user?.id]);

    // Derived from the loaded modules, so the header reflects real progress.
    const completedCount = modules.filter((m) => m.status === "completed").length;
    const percentComplete = modules.length
        ? Math.round((completedCount / modules.length) * 100)
        : 0;

    const titleById = Object.fromEntries(modules.map((m) => [m.id, m.title]));

    const getStatusUI = (status) => {
        if (status === "completed") {
            return {
                icon: <CheckCircle2 size={20} className="text-green-400" />,
                border: "border-green-400/20",
                bg: "bg-green-400/5",
                text: "Completed",
            };
        }

        if (status === "current") {
            return {
                icon: <PlayCircle size={20} className="text-[#0097a7]" />,
                border: "border-[#0097a7]/30",
                bg: "bg-[#0097a7]/10",
                text: "In Progress",
            };
        }

        return {
            icon: <Lock size={20} className="text-gray-500" />,
            border: "border-white/10",
            bg: "bg-white/[0.03]",
            text: "Locked",
        };
    };

    return (
        <div className="min-h-screen bg-[#050B0D] text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.08),transparent_55%)] pointer-events-none"></div>

            <header className="relative z-10 px-8 lg:px-20 py-8 border-b border-white/10">
                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                    <div>
                        <button onClick={() => navigate("/dashboard")}
                            className="flex items-center gap-2 text-gray-400 hover:text-[#0097a7] transition mb-5"
                        >
                            <ArrowLeft size={18} />
                            Back to Dashboard
                        </button>

                        <div className="inline-flex items-center gap-2 px-4 py-1 rounded-full border border-[#0097a7]/20 bg-[#0097a7]/10 text-[#0097a7] text-sm mb-5">
                            <Flame size={15} />
                            Structured Learning Path
                        </div>

                        <h1 className="text-5xl font-black leading-tight">
                            {roadmap?.title ?? "Roadmap"}
                            <span className="block text-[#0097a7]">
                                Roadmap
                            </span>
                        </h1>

                        <p className="mt-6 text-lg text-gray-400 max-w-3xl leading-relaxed">
                            {roadmap?.description}
                        </p>
                    </div>

                    <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/10 p-6 min-w-[280px]">
                        <div className="flex items-center gap-3 mb-5">
                            <Trophy size={22} className="text-[#0097a7]" />
                            <h3 className="text-xl font-bold">
                                Your Progress
                            </h3>
                        </div>

                        <div className="w-full h-3 bg-black/30 rounded-full overflow-hidden">
                            <div
                                className="h-full bg-gradient-to-r from-blue-400 to-[#0097a7] rounded-full transition-all"
                                style={{ width: `${percentComplete}%` }}
                            ></div>
                        </div>

                        <div className="flex items-center justify-between mt-4">
                            <p className="text-gray-300">
                                {percentComplete}% Completed
                            </p>

                            <div className="flex items-center gap-2 text-[#0097a7] text-sm">
                                <Clock3 size={15} />
                                {completedCount} / {modules.length} modules
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main className="relative z-10 px-8 lg:px-20 py-16">
                {loading ? (
                    <div className="text-center text-gray-400 py-20">
                        Loading roadmap...
                    </div>
                ) : error ? (
                    <div className="text-center text-red-400 py-20">
                        {error}
                    </div>
                ) : modules.length === 0 ? (
                    <div className="text-center text-gray-400 py-20">
                        This roadmap version has no modules yet.
                    </div>
                ) : (
                    <div className="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
                        {modules.map((module) => {
                            const statusUI = getStatusUI(module.status);

                            return (
                                <div
                                    key={module.id}
                                    className={`group relative rounded-3xl border ${statusUI.border} ${statusUI.bg} backdrop-blur-md p-6 hover:border-[#0097a7]/40 transition duration-300 overflow-hidden`}
                                >
                                    <div className="absolute inset-0 opacity-0 group-hover:opacity-10 transition bg-gradient-to-br from-blue-400 to-[#0097a7]"></div>

                                    <div className="relative z-10">
                                        <div className="flex items-center justify-between mb-6">
                                            <div className="flex items-center gap-3">
                                                {statusUI.icon}
                                                <span className="text-sm text-gray-300">
                                                    {statusUI.text}
                                                </span>
                                            </div>

                                            <span className="text-xs px-3 py-1 rounded-full bg-white/5 border border-white/10 text-gray-400 capitalize">
                                                {module.difficulty}
                                            </span>
                                        </div>

                                        <h3 className="text-2xl font-bold leading-snug">
                                            {module.title}
                                        </h3>

                                        <p className="mt-4 text-gray-400 leading-relaxed text-sm">
                                            {module.description}
                                        </p>

                                        <div className="mt-4 flex flex-wrap gap-2">
                                            {module.prerequisites.length > 0 ? (
                                                module.prerequisites.map((prereq) => (
                                                    <span
                                                        key={prereq}
                                                        className="px-3 py-1 rounded-full text-xs bg-white/5 border border-white/10 text-gray-400"
                                                    >
                                                        Requires: {titleById[prereq] ?? prereq}
                                                    </span>
                                                ))
                                            ) : (
                                                <span className="px-3 py-1 rounded-full text-xs bg-green-500/10 border border-green-500/20 text-green-300">
                                                    Entry Module
                                                </span>
                                            )}
                                        </div>

                                        <div className="mt-6">
                                            <div className="flex items-center justify-between text-sm mb-2">
                                                <span className="text-gray-400">
                                                    Progress
                                                </span>
                                                <span className="text-[#0097a7]">
                                                    {module.progress}%
                                                </span>
                                            </div>

                                            <div className="w-full h-2 bg-black/30 rounded-full overflow-hidden">
                                                <div
                                                    className="h-full bg-gradient-to-r from-blue-400 to-[#0097a7] rounded-full"
                                                    style={{ width: `${module.progress}%` }}
                                                ></div>
                                            </div>
                                        </div>

                                        <button
                                            disabled={module.status === "locked"}
                                            onClick={() => {
                                                if (module.status !== "locked") {
                                                    navigate(`/learning/${module.versionNodeId}`);
                                                }
                                            }}
                                            className={`mt-7 w-full py-3 rounded-2xl font-semibold transition ${
                                                module.status === "locked"
                                                    ? "bg-gray-800 text-gray-500 cursor-not-allowed"
                                                    : "bg-[#0097a7] text-black hover:scale-[1.02]"
                                            }`}
                                        >
                                            {module.status === "completed"
                                                ? "Review Module"
                                                : module.status === "current"
                                                ? "Continue Learning"
                                                : "Locked"}
                                        </button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </main>
        </div>
    );
}

export default RoadmapOverview;