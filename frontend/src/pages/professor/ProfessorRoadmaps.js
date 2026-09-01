import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import RoadmapPicker from "../../components/RoadmapPicker";
import flattenModules from "../../components/flattenModules";
import { Network, BookOpen, FileText, Brain, Code2 } from "lucide-react";

function ProfessorRoadmaps() {
    const [roadmaps, setRoadmaps] = useState([]);
    const [selectedId, setSelectedId] = useState(null);
    const [graph, setGraph] = useState(null);
    const [node, setNode] = useState(null);
    const [loading, setLoading] = useState(true);

    const loadRoadmaps = useCallback(async () => {
        try {
            const response = await api.get("/roadmaps");
            const list = response.data.data ?? [];
            setRoadmaps(list);
            setSelectedId((prev) =>
                prev ?? list.find((r) => !r.parent_roadmap_id)?.id ?? list[0]?.id ?? null
            );
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { loadRoadmaps(); }, [loadRoadmaps]);

    // Load the published version's graph whenever the roadmap changes.
    useEffect(() => {
        const run = async () => {
            const roadmap = roadmaps.find((r) => r.id === selectedId);

            if (!roadmap?.latest_version) {
                setGraph(null);
                return;
            }

            try {
                const response = await api.get(`/roadmap-versions/${roadmap.latest_version.id}/graph`);
                setGraph(response.data);
                setNode(null);
            } catch (err) {
                console.error(err);
            }
        };

        if (selectedId) run();
    }, [selectedId, roadmaps]);

    const openNode = async (versionNodeId) => {
        try {
            const response = await api.get(`/version-nodes/${versionNodeId}`);
            setNode(response.data);
        } catch (err) {
            console.error(err);
        }
    };

    return (
        <div className="p-8">
            <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">Roadmaps</h1>
            <p className="text-gray-400 mt-2 mb-10">
                Browse the learning content your students are working through.
            </p>

            {loading ? (
                <p className="text-gray-400">Loading...</p>
            ) : (
                <>
                    <div className="flex flex-wrap gap-3 mb-8">
                        <RoadmapPicker roadmaps={roadmaps} selectedId={selectedId}
                            onSelect={setSelectedId} />
                    </div>

                    {!graph ? (
                        <p className="text-gray-500">
                            {roadmaps.find((r) => r.id === selectedId)?.children?.length
                                ? "Pick one of its paths above to see the modules."
                                : "This roadmap has no published version."}
                        </p>
                    ) : (
                        <div className="grid lg:grid-cols-2 gap-8">
                            <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                                <div className="flex items-center gap-3 mb-6">
                                    <Network className="text-[#0097a7]" />
                                    <h2 className="text-2xl font-bold">
                                        Modules · v{graph.version.version_number}
                                    </h2>
                                </div>

                                <div className="space-y-2">
                                    {flattenModules(graph.nodes).map((n) => (
                                        <button key={n.version_node_id}
                                            onClick={() => openNode(n.version_node_id)}
                                            className={`w-full text-left flex items-center justify-between rounded-xl border px-4 py-3 transition ${
                                                node?.version_node_id === n.version_node_id
                                                    ? "border-[#0097a7]/40 bg-[#0097a7]/10"
                                                    : "border-white/10 bg-black/20 hover:border-[#0097a7]/30"
                                            }`}>
                                            <span className={n.depth ? "pl-5 text-gray-300" : ""}>
                                                {n.depth ? "⤷ " : ""}{n.title}
                                            </span>

                                            <span className="flex items-center gap-2 shrink-0">
                                                {/* A module that hands off has no material
                                                    of its own, and saying so stops it
                                                    reading as an empty one. */}
                                                {n.kind === "roadmap" && (
                                                    <span className="text-xs px-2 py-0.5 rounded-full border border-purple-400/30 text-purple-300">
                                                        opens a roadmap
                                                    </span>
                                                )}
                                                <span className="text-xs text-gray-500 capitalize">{n.difficulty}</span>
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                                {!node ? (
                                    <p className="text-gray-500">Select a module to see its content.</p>
                                ) : (
                                    <>
                                        <h2 className="text-2xl font-bold">{node.node.title}</h2>
                                        <p className="text-gray-400 mt-2 mb-6">{node.node.description}</p>

                                        {[
                                            { icon: <BookOpen size={16} className="text-[#0097a7]" />, label: "Tutorials", items: node.tutorials.map((t) => t.title) },
                                            { icon: <Brain size={16} className="text-purple-400" />, label: "Quizzes", items: node.quizzes.map((q) => q.title) },
                                            { icon: <Code2 size={16} className="text-yellow-400" />, label: "Projects", items: node.projects.map((p) => p.title) },
                                            { icon: <FileText size={16} className="text-blue-400" />, label: "Resources", items: node.external_resources.map((r) => r.title) },
                                        ].map((group) => (
                                            <div key={group.label} className="mb-5">
                                                <div className="flex items-center gap-2 mb-2">
                                                    {group.icon}
                                                    <h3 className="font-semibold text-sm">
                                                        {group.label} ({group.items.length})
                                                    </h3>
                                                </div>

                                                {group.items.length === 0 ? (
                                                    <p className="text-xs text-gray-600 ml-6">None yet.</p>
                                                ) : (
                                                    <ul className="ml-6 space-y-1">
                                                        {group.items.map((item, i) => (
                                                            <li key={i} className="text-sm text-gray-300">· {item}</li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </div>
                                        ))}
                                    </>
                                )}
                            </div>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

export default ProfessorRoadmaps;
