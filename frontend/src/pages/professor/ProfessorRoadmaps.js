import React, { useCallback, useEffect, useRef, useState } from "react";
import api from "../../api";
import {
    Network, BookOpen, FileText, Brain, Code2, ExternalLink, ChevronRight,
    GitBranch, ArrowLeft, Layers,
} from "lucide-react";

/**
 * One module's material, read only.
 *
 * Deliberately not the manager's ModuleMaterial: that reads
 * /version-nodes/{id}/material, which is behind role:MaterialManager, and it
 * offers regenerate and delete buttons a professor has no business with.
 * This reads the same node through the endpoint every signed-in user may
 * use, and only shows what is there.
 */
function NodeMaterial({ versionNodeId }) {
    const [node, setNode] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let live = true;
        setLoading(true);

        api.get(`/version-nodes/${versionNodeId}`)
            .then((r) => { if (live) setNode(r.data); })
            .catch((err) => console.error(err))
            .finally(() => { if (live) setLoading(false); });

        return () => { live = false; };
    }, [versionNodeId]);

    if (loading) return <p className="text-xs text-gray-500">Loading...</p>;
    if (!node) return <p className="text-xs text-gray-600">Could not load this module.</p>;

    // Tutorials, projects and resources are somewhere else - a video, a repo,
    // a page of documentation - so they carry a url. Listing them as plain
    // titles made a professor copy a name into a search engine to see what
    // they were marking against.
    const groups = [
        { icon: <BookOpen size={16} className="text-[#0097a7]" />, label: "Tutorials",
          items: node.tutorials.map((t) => ({ title: t.title, url: t.url })) },
        { icon: <Brain size={16} className="text-purple-400" />, label: "Quizzes",
          items: node.quizzes.map((q) => ({ title: q.title, note: `${q.questions?.length ?? 0} questions` })) },
        { icon: <Code2 size={16} className="text-yellow-400" />, label: "Projects",
          items: node.projects.map((p) => ({ title: p.title, url: p.github_url })) },
        { icon: <FileText size={16} className="text-blue-400" />, label: "Resources",
          items: node.external_resources.map((r) => ({ title: r.title, url: r.url })) },
    ];

    return (
        <div>
            {node.node.description && (
                <p className="text-sm text-gray-400 mb-4">{node.node.description}</p>
            )}

            {groups.map((group) => (
                <div key={group.label} className="mb-4 last:mb-0">
                    <div className="flex items-center gap-2 mb-2">
                        {group.icon}
                        <h4 className="text-xs uppercase tracking-wider text-gray-400">
                            {group.label} ({group.items.length})
                        </h4>
                    </div>

                    {group.items.length === 0 ? (
                        <p className="text-xs text-gray-600 ml-6">None yet.</p>
                    ) : (
                        <ul className="ml-6 space-y-1">
                            {group.items.map((item, i) => (
                                <li key={i} className="text-sm text-gray-300">
                                    ·{" "}
                                    {item.url ? (
                                        <a href={item.url} target="_blank" rel="noreferrer"
                                            className="text-[#00b0c2] hover:underline inline-flex items-center gap-1.5">
                                            {item.title}
                                            <ExternalLink size={12} className="shrink-0" />
                                        </a>
                                    ) : (
                                        item.title
                                    )}
                                    {item.note && (
                                        <span className="text-xs text-gray-600"> · {item.note}</span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            ))}
        </div>
    );
}

/**
 * The roadmaps, as a professor reads them.
 *
 * Same shape as the material manager's: the roadmaps on the left under the
 * subject holding them, the chosen one's modules on the right, and each
 * module opening to what is on it. A roadmap that lives inside another -
 * React under Frontend - is reached by opening the module that offers it,
 * not by listing every roadmap on the platform in one column.
 *
 * Read only throughout. A professor teaches from this; the material manager
 * is who changes it.
 */
function ProfessorRoadmaps() {
    const [roadmaps, setRoadmaps] = useState([]);
    const [selectedId, setSelectedId] = useState(null);
    const [graph, setGraph] = useState(null);
    const [openModule, setOpenModule] = useState(null);
    const [loading, setLoading] = useState(true);
    const modulesRef = useRef(null);

    const loadRoadmaps = useCallback(async () => {
        try {
            const response = await api.get("/roadmaps");
            setRoadmaps(response.data.data ?? []);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { loadRoadmaps(); }, [loadRoadmaps]);

    // The published version's rail, choices still nested in the module that
    // offers them - that nesting is how the roadmaps below are reached.
    useEffect(() => {
        setOpenModule(null);

        const roadmap = roadmaps.find((r) => r.id === selectedId);

        if (!roadmap?.latest_version) {
            setGraph(null);
            return;
        }

        api.get(`/roadmap-versions/${roadmap.latest_version.id}/graph`)
            .then((r) => setGraph(r.data))
            .catch((err) => console.error(err));
    }, [selectedId, roadmaps]);

    const openRoadmap = (id) => {
        setSelectedId(id);
        setOpenModule(null);
        setTimeout(() => modulesRef.current?.scrollIntoView({ behavior: "smooth", block: "start" }), 120);
    };

    const subjects = roadmaps.filter((r) => !r.parent_roadmap_id);

    // By id: the order they were created is the order they are meant to be
    // taken. A professor gets the list flat and alphabetical, so the grouping
    // here has to restore it.
    const childrenOf = (id) => roadmaps
        .filter((r) => r.parent_roadmap_id === id)
        .sort((a, b) => a.id - b.id);

    const selected = roadmaps.find((r) => r.id === selectedId);

    const parent = selected?.parent_roadmap_id
        ? roadmaps.find((r) => r.id === selected.parent_roadmap_id)
        : null;

    const parentIsSubject = parent && !parent.parent_roadmap_id;

    /** The way from a module into the roadmap it hands off to. */
    const RoadmapDoor = ({ roadmap }) => (
        <button onClick={() => openRoadmap(roadmap.id)}
            className="mt-3 w-full flex items-center justify-between gap-3 rounded-xl border border-[#0097a7]/30 bg-[#0097a7]/5 p-3 text-left hover:bg-[#0097a7]/10 transition">
            <span className="text-sm text-[#00b0c2]">Open {roadmap.title}</span>
            <ChevronRight size={14} className="text-[#0097a7] shrink-0" />
        </button>
    );

    return (
        <div className="p-8">
            <div className="flex items-center gap-3">
                <Layers size={30} className="text-[#0097a7]" />
                <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">Roadmaps</h1>
            </div>
            <p className="text-gray-400 mt-2 mb-8">
                Browse the learning content your students are working through.
            </p>

            {loading ? (
                <p className="text-gray-400">Loading...</p>
            ) : (
                <div className="grid lg:grid-cols-[320px_1fr] gap-8">
                    {/* The roadmaps, under the subject that holds them. */}
                    <div>
                        <h2 className="text-xl font-bold mb-4">Roadmaps</h2>

                        {subjects.length === 0 ? (
                            <p className="text-gray-500 text-sm">Nothing published yet.</p>
                        ) : (
                            <div className="space-y-5">
                                {subjects.map((subject) => (
                                    <div key={subject.id}>
                                        {/* A heading, not a button: a subject holds
                                            roadmaps and has no modules of its own. */}
                                        <p className="text-xs uppercase tracking-wider text-gray-500 mb-2">
                                            {subject.title}
                                        </p>

                                        <div className="space-y-2">
                                            {childrenOf(subject.id).map((r) => (
                                                <button key={r.id} onClick={() => openRoadmap(r.id)}
                                                    className={`text-left rounded-2xl border p-3 transition block w-full ${
                                                        selectedId === r.id
                                                            ? "border-[#0097a7]/40 bg-[#0097a7]/10"
                                                            : "border-white/10 bg-white/[0.03] hover:border-[#0097a7]/30"
                                                    }`}>
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className="font-medium text-sm">{r.title}</span>
                                                        <ChevronRight size={14} className="text-gray-600 shrink-0" />
                                                    </div>

                                                    <span className="text-xs text-gray-500">
                                                        {r.latest_version
                                                            ? `${r.node_count} modules`
                                                            : "nothing published"}
                                                    </span>
                                                </button>
                                            ))}

                                            {childrenOf(subject.id).length === 0 && (
                                                <p className="text-xs text-gray-600">Nothing under this yet.</p>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Its modules. */}
                    <div ref={modulesRef} className="scroll-mt-6">
                        {!selected ? (
                            <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-12 text-center">
                                <Network size={40} className="text-gray-700 mx-auto mb-4" />
                                <h3 className="text-xl font-bold">Pick a roadmap</h3>
                                <p className="text-gray-500 mt-2">
                                    Its modules appear here, and each one opens to show what is on it.
                                </p>
                            </div>
                        ) : (
                            <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                                {/* Only when you have come through a module to get
                                    here, so the left column no longer shows where
                                    you are. */}
                                {parent && !parentIsSubject && (
                                    <button onClick={() => openRoadmap(parent.id)}
                                        className="flex items-center gap-1.5 text-xs text-gray-500 hover:text-[#00b0c2] transition mb-3">
                                        <ArrowLeft size={12} /> Back to {parent.title}
                                    </button>
                                )}

                                <div className="flex items-center gap-3 mb-6">
                                    <GitBranch className="text-[#0097a7]" />
                                    <h2 className="text-2xl font-bold">{selected.title}</h2>

                                    {graph && (
                                        <span className="text-xs px-3 py-1 rounded-full border border-white/10 text-gray-400">
                                            v{graph.version.version_number}
                                        </span>
                                    )}
                                </div>

                                {!graph ? (
                                    <p className="text-gray-500">
                                        This roadmap has no published version, so there is
                                        nothing for students to work through yet.
                                    </p>
                                ) : (
                                    <div className="space-y-2">
                                        {(graph.nodes ?? []).map((m) => (
                                            <div key={m.version_node_id}
                                                 className="rounded-2xl border border-white/10 bg-black/20 p-4">
                                                <button
                                                    onClick={() => setOpenModule(
                                                        openModule === m.version_node_id ? null : m.version_node_id
                                                    )}
                                                    className="w-full flex items-center justify-between gap-3 text-left">
                                                    <div className="min-w-0">
                                                        <span className="font-medium">{m.title}</span>

                                                        {m.coming_soon && (
                                                            <span className="ml-2 text-xs px-2 py-0.5 rounded-full bg-yellow-400/10 text-yellow-300">
                                                                coming soon
                                                            </span>
                                                        )}

                                                        {/* Says before opening it that this
                                                            module is a door rather than a
                                                            page of material. */}
                                                        {m.kind === "roadmap" && (
                                                            <span className="ml-2 text-xs text-gray-500">
                                                                opens a roadmap
                                                            </span>
                                                        )}

                                                        {m.kind === "choice" && (
                                                            <span className="ml-2 text-xs text-gray-500">
                                                                {m.choices?.length ?? 0} to choose from
                                                            </span>
                                                        )}
                                                    </div>

                                                    <span className="flex items-center gap-3 shrink-0">
                                                        <span className="text-xs text-gray-500 capitalize">
                                                            {m.difficulty}
                                                        </span>

                                                        <ChevronRight size={16} className={`text-gray-600 transition-transform ${
                                                            openModule === m.version_node_id ? "rotate-90" : ""
                                                        }`} />
                                                    </span>
                                                </button>

                                                {openModule === m.version_node_id && (
                                                    <div className="mt-4 pt-4 border-t border-white/10">
                                                        {m.links_to_roadmap && (
                                                            <RoadmapDoor roadmap={m.links_to_roadmap} />
                                                        )}

                                                        {m.kind === "choice" && (
                                                            <div className="space-y-2">
                                                                {m.choices?.map((c) => (
                                                                    <div key={c.version_node_id}
                                                                         className="rounded-xl border border-white/10 bg-black/20 p-3">
                                                                        <div className="flex items-center gap-2 flex-wrap">
                                                                            <span className="text-sm font-medium">{c.title}</span>

                                                                            {c.is_recommended && (
                                                                                <span className="text-xs px-2 py-0.5 rounded-full bg-[#0097a7]/10 text-[#00b0c2]">
                                                                                    recommended
                                                                                </span>
                                                                            )}
                                                                        </div>

                                                                        {c.links_to_roadmap
                                                                            ? <RoadmapDoor roadmap={c.links_to_roadmap} />
                                                                            : (
                                                                                <div className="mt-3">
                                                                                    <NodeMaterial versionNodeId={c.version_node_id} />
                                                                                </div>
                                                                            )}
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        )}

                                                        {m.kind === "standard" && (
                                                            <NodeMaterial versionNodeId={m.version_node_id} />
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

export default ProfessorRoadmaps;
