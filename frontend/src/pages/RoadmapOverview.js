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
    GitBranch,
    ChevronDown,
    AlertCircle,
} from "lucide-react";

function RoadmapOverview() {
    const navigate = useNavigate();
    const { roadmapId } = useParams();

    const [roadmap, setRoadmap] = useState(null);
    const [modules, setModules] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    // null means "whatever is currently published".
    const [selectedVersionId, setSelectedVersionId] = useState(null);
    const [version, setVersion] = useState(null);
    const [versions, setVersions] = useState([]);
    const [isLatest, setIsLatest] = useState(true);
    const [pickerOpen, setPickerOpen] = useState(false);

    const { user } = useAuth();

    useEffect(() => {
        const loadRoadmap = async () => {
            setLoading(true);

            try {
                const progressUrl = selectedVersionId
                    ? `/roadmaps/${roadmapId}/student/${user.id}?version=${selectedVersionId}`
                    : `/roadmaps/${roadmapId}/student/${user.id}`;

                const [detailResponse, progressResponse] = await Promise.all([
                    api.get(`/roadmaps/${roadmapId}`),
                    api.get(progressUrl),
                ]);

                setRoadmap(detailResponse.data.data);

                const {
                    nodes, edges, completed, current_node,
                    version: versionInfo, available_versions, is_latest,
                } = progressResponse.data;

                setVersion(versionInfo);
                setVersions(available_versions ?? []);
                setIsLatest(is_latest);

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
    }, [roadmapId, user?.id, selectedVersionId]);

    const chooseVersion = (id) => {
        setPickerOpen(false);

        // Passing null re-requests the live version rather than pinning one.
        setSelectedVersionId(id === version?.id ? selectedVersionId : id);
    };

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
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.15),transparent_55%)] pointer-events-none"></div>

            <header className="relative z-10 px-8 lg:px-20 py-8 border-b border-white/10">
                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                    <div>
                        <button onClick={() => navigate("/dashboard")}
                            className="flex items-center gap-2 text-gray-400 hover:text-[#0097a7] transition mb-5"
                        >
                            <ArrowLeft size={18} />
                            Back to Dashboard
                        </button>

                        <div className="flex items-center flex-wrap gap-3 mb-5">
                            <div className="inline-flex items-center gap-2 px-4 py-1 rounded-full border border-[#0097a7]/20 bg-[#0097a7]/10 text-[#0097a7] text-sm">
                                <Flame size={15} />
                                Structured Learning Path
                            </div>

                            {/* Version badge - click to read an earlier version. */}
                            {version && (
                                <div className="relative">
                                    <button
                                        onClick={() => setPickerOpen((open) => !open)}
                                        className={`inline-flex items-center gap-2 px-4 py-1 rounded-full border text-sm transition ${
                                            isLatest
                                                ? "border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2] hover:bg-[#0097a7]/20"
                                                : "border-yellow-400/30 bg-yellow-400/10 text-yellow-300 hover:bg-yellow-400/20"
                                        }`}
                                    >
                                        <GitBranch size={14} />
                                        {version.label}
                                        {!isLatest && " (older)"}
                                        <ChevronDown size={14} />
                                    </button>

                                    {pickerOpen && (
                                        <div className="absolute left-0 top-full mt-2 z-30 w-80 rounded-2xl border border-white/10 bg-[#0b1416] shadow-2xl overflow-hidden">
                                            <p className="px-4 py-3 text-xs text-gray-500 border-b border-white/10">
                                                Choose a version to read
                                            </p>

                                            {versions.map((v) => (
                                                <button
                                                    key={v.id}
                                                    onClick={() => chooseVersion(v.id)}
                                                    className={`w-full text-left px-4 py-3 transition border-b border-white/5 last:border-b-0 ${
                                                        v.id === version.id
                                                            ? "bg-[#0097a7]/10 text-[#00b0c2]"
                                                            : "hover:bg-white/5 text-gray-300"
                                                    }`}
                                                >
                                                    <div className="flex items-center justify-between gap-3">
                                                        <span className="font-semibold">v{v.version_number}</span>

                                                        <span className={`text-xs px-2 py-0.5 rounded-full ${
                                                            v.status === "Published"
                                                                ? "bg-green-400/10 text-green-300"
                                                                : "bg-white/5 text-gray-500"
                                                        }`}>
                                                            {v.status === "Published" ? "current" : "past"}
                                                        </span>
                                                    </div>

                                                    {v.title && (
                                                        <p className="text-xs text-gray-500 mt-1">{v.title}</p>
                                                    )}
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>

                        {!isLatest && (
                            <div className="flex items-center gap-2 mb-5 text-sm text-yellow-300">
                                <AlertCircle size={15} />
                                You are reading an older version.
                                <button
                                    onClick={() => setSelectedVersionId(null)}
                                    className="underline hover:text-yellow-200"
                                >
                                    Switch to the current one
                                </button>
                            </div>
                        )}

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