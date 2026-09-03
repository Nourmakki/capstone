import React, { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import api from "../api";
import { useAuth } from "../context/AuthContext";
import RoadmapCard, { isChooser } from "../components/RoadmapCard";
import {
    ArrowLeft,
    Map,
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

    // Committed but not published: the shape is readable, every module is
    // locked, and none of them can be opened.
    const [announced, setAnnounced] = useState(false);

    const { user } = useAuth();

    useEffect(() => {
        const loadRoadmap = async () => {
            setLoading(true);

            try {
                // The roadmap first, on its own. A parent has no version of its
                // own, so asking for progress against it 404s - and fetching
                // both together meant that 404 sank the whole page instead of
                // showing the chooser.
                const detailResponse = await api.get(`/roadmaps/${roadmapId}`);
                const detail = detailResponse.data.data;

                setRoadmap(detail);

                // A subject is always a chooser: Web Development is a title
                // over Frontend and Backend, so it lists them and never has a
                // rail of its own to show.
                //
                // A path can be a chooser too, when it has children and
                // nothing of its own. Frontend has both - its own modules and
                // React beneath them - and React is reached from the module
                // that links to it, not from a list of paths. Testing children
                // alone hid Frontend's whole rail behind a single card.
                if (isChooser(detail)) {
                    return;
                }

                // A leaf with nothing published is not a failure, it is a
                // roadmap nobody has filled in yet. Asking for progress
                // against it 404s, which would paint a red error over what
                // is really an empty shelf.
                if (! detail.latest_version) {
                    return;
                }

                const progressUrl = selectedVersionId
                    ? `/roadmaps/${roadmapId}/student/${user.id}?version=${selectedVersionId}`
                    : `/roadmaps/${roadmapId}/student/${user.id}`;

                const progressResponse = await api.get(progressUrl);

                const {
                    nodes, edges, completed, current_node, requirements,
                    version: versionInfo, available_versions, is_latest,
                    announced: isAnnounced,
                } = progressResponse.data;

                setVersion(versionInfo);
                setVersions(available_versions ?? []);
                setIsLatest(is_latest);
                setAnnounced(!!isAnnounced);

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

                        // Quizzes and projects that must be finished before
                        // this module can be completed.
                        const required_work = requirements?.[nodeId] ?? { done: 0, total: 0 };

                        return {
                            id: nodeId,
                            versionNodeId: item.id,
                            title: item.node.title,
                            description: item.node.description,
                            difficulty: item.node.difficulty,
                            // Nothing in an announced version opens, whatever
                            // the prerequisites say - it has no material yet.
                            // The server refuses these too; this is what
                            // stops a student being invited to try.
                            status: isAnnounced
                                ? "locked"
                                : isCompleted
                                ? "completed"
                                : isCurrent || isUnlocked
                                ? "current"
                                : "locked",
                            // A finished module is finished regardless; an
                            // unfinished one is as far along as its work.
                            progress: isCompleted
                                ? 100
                                : required_work.total > 0
                                ? Math.round((required_work.done / required_work.total) * 100)
                                : 0,
                            requiredDone: required_work.done,
                            requiredTotal: required_work.total,
                            prerequisites: required,
                            // Null on an ungrouped roadmap, which is every one
                            // until a manager groups it.
                            stage: item.stage ?? null,
                            stageTitle: item.stage_title ?? null,
                            isRecommended: Boolean(item.is_recommended),

                            // Announced, its material still being written.
                            comingSoon: Boolean(item.coming_soon),

                            // What clicking it does. A module either opens a
                            // roadmap of its own, offers a choice of topics, or
                            // has a page - the AI system's three node kinds.
                            linksToRoadmap: item.links_to_roadmap ?? null,
                            choices: (item.choices ?? []).map((choice) => ({
                                versionNodeId: choice.id,
                                title: choice.node?.title ?? "",
                                description: choice.node?.description ?? "",
                                isRecommended: Boolean(choice.is_recommended),
                                recommendationReason: choice.recommendation_reason ?? null,
                                linksToRoadmap: choice.links_to_roadmap ?? null,
                            })),
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

    /**
     * Where a module takes you.
     *
     * Most have a page of their own. One that is too big for a page - React,
     * say - opens a roadmap instead, which is the AI system's
     * separate_roadmap.
     */
    const openModule = (module) => {
        if (module.linksToRoadmap) {
            navigate(`/roadmap/${module.linksToRoadmap.id}`);
            return;
        }

        navigate(`/learning/${module.versionNodeId}`);
    };

    /** A choice is a module in every respect, so it goes the same two ways. */
    const openChoice = (choice) => {
        if (choice.linksToRoadmap) {
            navigate(`/roadmap/${choice.linksToRoadmap.id}`);
            return;
        }

        navigate(`/learning/${choice.versionNodeId}`);
    };

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

    // A parent roadmap has no modules of its own - it is a choice between
    // specializations. Rendering the rail here would draw an empty one.
    const paths = roadmap?.children ?? [];

    // The same rule the fetch used, asked of the same helper so the two
    // cannot drift apart.
    if (roadmap && isChooser(roadmap)) {
        return (
            <div className="min-h-screen bg-[#050B0D] text-white">
                <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.15),transparent_55%)] pointer-events-none" />

                <div className="relative z-10 px-8 lg:px-20 py-10">
                    <button
                        onClick={() => navigate("/dashboard")}
                        className="flex items-center gap-2 text-gray-400 hover:text-[#0097a7] transition mb-8"
                    >
                        <ArrowLeft size={18} />
                        Back to Dashboard
                    </button>

                    <h1 className="text-4xl lg:text-5xl font-black">{roadmap.title}</h1>

                    <p className="text-gray-400 mt-3 max-w-2xl">
                        {roadmap.description} Choose a roadmap to follow — and you
                        can come back for the others.
                    </p>

                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mt-10">
                        {paths.map((child) => (
                            <RoadmapCard
                                key={child.id}
                                roadmap={child}
                                onOpen={(r) => navigate(`/roadmap/${r.id}`)}
                                ctaLabel="Start this path"
                            />
                        ))}
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-[#050B0D] text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.15),transparent_55%)] pointer-events-none"></div>

            <header className="relative z-10 px-8 lg:px-20 py-8 border-b border-white/10">
                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                    <div>
                        {/* A specialization was reached through its parent, so
                            back means the chooser, not the dashboard - going
                            two steps up loses the other paths. */}
                        <button onClick={() => navigate(
                            roadmap?.parent ? `/roadmap/${roadmap.parent.id}` : "/dashboard"
                        )}
                            className="flex items-center gap-2 text-gray-400 hover:text-[#0097a7] transition mb-5"
                        >
                            <ArrowLeft size={18} />
                            {roadmap?.parent ? `Back to ${roadmap.parent.title}` : "Back to Dashboard"}
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
                                            announced
                                                ? "border-yellow-400/40 bg-yellow-400/10 text-yellow-300 hover:bg-yellow-400/20"
                                                : isLatest
                                                ? "border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2] hover:bg-[#0097a7]/20"
                                                : "border-yellow-400/30 bg-yellow-400/10 text-yellow-300 hover:bg-yellow-400/20"
                                        }`}
                                    >
                                        <GitBranch size={14} />
                                        {version.label}
                                        {announced ? " · coming soon" : !isLatest && " (older)"}
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

                                                        {/* Three states, not two: a version can
                                                            also be one that exists but cannot be
                                                            worked through yet. */}
                                                        <span className={`text-xs px-2 py-0.5 rounded-full ${
                                                            v.announced
                                                                ? "bg-yellow-400/10 text-yellow-300"
                                                                : v.status === "Published"
                                                                ? "bg-green-400/10 text-green-300"
                                                                : "bg-white/5 text-gray-500"
                                                        }`}>
                                                            {v.announced
                                                                ? "coming soon"
                                                                : v.status === "Published" ? "current" : "past"}
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

                        {/* Said plainly, and as a panel rather than a line:
                            every module below is locked, and without this the
                            roadmap simply looks broken. */}
                        {announced && (
                            <div className="mb-5 flex items-start gap-3 rounded-2xl border border-yellow-400/30 bg-yellow-400/5 p-4">
                                <AlertCircle size={18} className="text-yellow-400 shrink-0 mt-0.5" />

                                <div>
                                    <p className="font-semibold text-yellow-200">
                                        Coming soon - not published yet
                                    </p>

                                    <p className="text-sm text-gray-300 mt-1">
                                        This is a preview of what is being prepared. You can see
                                        the modules and the order they come in, but none of them
                                        can be opened yet - their tutorials, quizzes and projects
                                        are still being written and reviewed.
                                        {versions.some((v) => !v.announced) && (
                                            <>
                                                {" "}
                                                <button
                                                    onClick={() => setSelectedVersionId(null)}
                                                    className="underline text-yellow-200 hover:text-yellow-100"
                                                >
                                                    Go back to the version you can work through
                                                </button>
                                                .
                                            </>
                                        )}
                                    </p>
                                </div>
                            </div>
                        )}

                        {!announced && !isLatest && (
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

                        <h1 className="text-3xl lg:text-5xl font-black leading-tight">
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
                        {version
                            ? "This roadmap version has no modules yet."
                            : "This roadmap has no published version yet."}
                    </div>
                ) : (
                    /* One vertical path rather than a grid: a roadmap is an
                       order, and a 3-column grid reads left-to-right first,
                       which is the wrong direction. */
                    <div className="max-w-4xl">
                        {modules.map((module, index) => {
                            const statusUI = getStatusUI(module.status);

                            const isLast = index === modules.length - 1;
                            // A coming-soon module is locked whatever the
                            // student has finished: there is nothing in it yet.
                            const isLocked = module.comingSoon || module.status === "locked";
                            const isDone = module.status === "completed";
                            const hasChoices = module.choices.length > 0;

                            // The rail above this stop belongs to the journey
                            // already travelled, so it lights up once the
                            // module before it is finished.
                            const reachedHere = index === 0
                                || modules[index - 1].status === "completed";

                            return (
                                <div key={module.id} className="flex gap-5 sm:gap-6">
                                    {/* The rail: line, marker, line. */}
                                    <div className="flex flex-col items-center w-12 sm:w-14 shrink-0">
                                        <div
                                            className={`w-0.5 h-5 ${
                                                index === 0
                                                    ? "bg-transparent"
                                                    : reachedHere
                                                    ? "bg-[#00b0c2] shadow-[0_0_6px_rgba(0,176,194,0.9),0_0_16px_rgba(0,151,167,0.55)]"
                                                    : "bg-white/10"
                                            }`}
                                        />

                                        <div
                                            /* Only the lit part of the path glows - a locked
                                               marker stays flat so the eye is drawn along
                                               the route already travelled. */
                                            className={`relative flex items-center justify-center w-12 h-12 rounded-full border-2 transition ${
                                                isDone
                                                    ? "bg-[#00b0c2] border-[#00b0c2] text-black shadow-[0_0_10px_rgba(0,176,194,0.81),0_0_26px_rgba(0,151,167,0.5)]"
                                                    : module.status === "current"
                                                    ? "bg-[#00b0c2]/10 border-[#00b0c2] text-[#00b0c2] shadow-[0_0_8px_rgba(0,176,194,0.54),0_0_22px_rgba(0,151,167,0.32)]"
                                                    : "bg-black/40 border-white/10 text-gray-600"
                                            }`}
                                        >
                                            {isDone ? (
                                                <CheckCircle2 size={22} />
                                            ) : isLocked ? (
                                                <Lock size={18} />
                                            ) : (
                                                <PlayCircle size={22} />
                                            )}

                                            {/* Only the module they can act on pulses. */}
                                            {module.status === "current" && (
                                                <span className="absolute inset-0 rounded-full border-2 border-[#00b0c2] animate-ping opacity-40" />
                                            )}
                                        </div>

                                        {/* Fills as the module's quizzes and
                                            projects are finished, so the path
                                            grows with the work rather than
                                            jumping from nothing to done. */}
                                        {!isLast && (
                                            /* No overflow-hidden: it clips the lit
                                               section's glow at the 2px edge, which is
                                               the whole of the effect. */
                                            <div className="w-0.5 flex-1 bg-white/10 relative">
                                                {/* The glow is conditional: a zero-height
                                                    box still casts a shadow, which would
                                                    leave a lit smear on an untouched rail. */}
                                                {/* Two shadows: a tight bright core and a
                                                    wider halo. A single blur on a 2px line
                                                    reads as nothing. */}
                                                <div
                                                    className={`absolute top-0 left-0 w-full bg-[#00b0c2] transition-all duration-500 ${
                                                        module.progress > 0
                                                            ? "shadow-[0_0_6px_rgba(0,176,194,0.9),0_0_16px_rgba(0,151,167,0.55)]"
                                                            : ""
                                                    }`}
                                                    style={{ height: `${module.progress}%` }}
                                                />
                                            </div>
                                        )}
                                    </div>

                                    {/* The stop itself. */}
                                    <div className={`flex-1 min-w-0 ${isLast ? "pb-4" : "pb-10"}`}>
                                        {/* A heading only where a new group starts.
                                            Ungrouped roadmaps fall back to numbering
                                            each module, which is what this did for
                                            every module before stages existed. */}
                                        {module.stage !== null && module.stage !== modules[index - 1]?.stage && (
                                            <div className="mb-4 -mt-1">
                                                <p className="text-[11px] tracking-[0.2em] text-[#0097a7] font-semibold">
                                                    STAGE {module.stage}
                                                </p>
                                                {module.stageTitle && (
                                                    <h3 className="text-lg font-bold mt-0.5">
                                                        {module.stageTitle}
                                                    </h3>
                                                )}
                                            </div>
                                        )}

                                        <div className="flex items-center gap-3 mb-2">
                                            {module.stage === null && (
                                                <span className="text-xs tracking-widest text-gray-500">
                                                    STAGE {index + 1}
                                                </span>
                                            )}

                                            {module.isRecommended && (
                                                <span className="text-[10px] font-bold tracking-widest px-2.5 py-1 rounded-full bg-[#0097a7] text-black">
                                                    RECOMMENDED
                                                </span>
                                            )}

                                            <span
                                                className={`text-xs px-3 py-1 rounded-full border ${
                                                    isDone
                                                        ? "border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2]"
                                                        : module.status === "current"
                                                        ? "border-[#0097a7]/40 bg-[#0097a7]/15 text-[#00b0c2]"
                                                        : "border-white/10 bg-white/[0.03] text-gray-500"
                                                }`}
                                            >
                                                {statusUI.text}
                                            </span>

                                            <span className="text-xs text-gray-500 capitalize">
                                                {module.difficulty}
                                            </span>

                                            {/* The bar shows the shape of the
                                                progress; this says what it is. */}
                                            {!isDone && module.requiredTotal > 0 && (
                                                <span className="text-xs text-gray-500">
                                                    {module.requiredDone} of {module.requiredTotal} done
                                                </span>
                                            )}
                                        </div>

                                        <div
                                            onClick={() => {
                                                // A module offering choices has no
                                                // page of its own - the buttons below
                                                // are the whole point of it, so the
                                                // card itself does nothing.
                                                if (!isLocked && !hasChoices) {
                                                    openModule(module);
                                                }
                                            }}
                                            className={`rounded-3xl border p-6 transition ${
                                                isLocked
                                                    ? "border-white/10 bg-white/[0.02] opacity-60"
                                                    : hasChoices
                                                    ? "border-white/10 bg-white/[0.03]"
                                                    : "border-white/10 bg-white/[0.03] hover:border-[#0097a7]/40 cursor-pointer"
                                            }`}
                                        >
                                            <h3 className="text-xl sm:text-2xl font-bold leading-snug flex items-center gap-3 flex-wrap">
                                                {module.title}

                                                {/* Announced, not yet teachable. Said on
                                                    the module itself so it does not read
                                                    as one the student simply has not
                                                    reached yet. */}
                                                {module.comingSoon && (
                                                    <span className="text-[10px] font-bold tracking-widest px-2.5 py-1 rounded-full bg-yellow-400/15 border border-yellow-400/30 text-yellow-200">
                                                        COMING SOON
                                                    </span>
                                                )}
                                            </h3>

                                            <p className="mt-3 text-gray-400 leading-relaxed text-sm">
                                                {module.description}
                                            </p>

                                            <div className="mt-4 flex flex-wrap gap-2">
                                                {module.prerequisites.length > 0 ? (
                                                    module.prerequisites.map((prereq) => (
                                                        <span
                                                            key={prereq}
                                                            className="px-3 py-1 rounded-full text-xs bg-white/5 border border-white/10 text-gray-400"
                                                        >
                                                            after {titleById[prereq] ?? prereq}
                                                        </span>
                                                    ))
                                                ) : (
                                                    <span className="px-3 py-1 rounded-full text-xs bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]">
                                                        Starting module
                                                    </span>
                                                )}
                                            </div>

                                            {/* One button per choice, rather than one
                                                for the module. Each opens its own page
                                                with its own tutorials, quizzes and
                                                projects - HTML, CSS and JavaScript
                                                under Fundamentals. */}
                                            {hasChoices ? (
                                                <div className="mt-6 flex flex-wrap gap-3">
                                                    {module.choices.map((choice) => (
                                                        <button
                                                            key={choice.versionNodeId}
                                                            disabled={isLocked}
                                                            title={choice.recommendationReason ?? choice.description}
                                                            onClick={(event) => {
                                                                event.stopPropagation();
                                                                if (!isLocked) openChoice(choice);
                                                            }}
                                                            className={`px-5 py-2.5 rounded-2xl font-semibold text-sm transition flex items-center gap-2 ${
                                                                isLocked
                                                                    ? "bg-gray-800 text-gray-500 cursor-not-allowed"
                                                                    : choice.isRecommended
                                                                    ? "bg-[#0097a7] text-black hover:scale-[1.02]"
                                                                    : "bg-white/5 border border-white/10 text-gray-200 hover:border-[#0097a7]/40"
                                                            }`}
                                                        >
                                                            {choice.title}

                                                            {choice.linksToRoadmap && (
                                                                <Map size={14} className="shrink-0 opacity-70" />
                                                            )}

                                                            {choice.isRecommended && !isLocked && (
                                                                <span className="text-[10px] uppercase tracking-wide px-2 py-0.5 rounded-full bg-black/20">
                                                                    Recommended
                                                                </span>
                                                            )}
                                                        </button>
                                                    ))}
                                                </div>
                                            ) : (
                                                <button
                                                    disabled={isLocked}
                                                    onClick={(event) => {
                                                        event.stopPropagation();

                                                        if (!isLocked) {
                                                            openModule(module);
                                                        }
                                                    }}
                                                    className={`mt-6 px-6 py-3 rounded-2xl font-semibold transition ${
                                                        isLocked
                                                            ? "bg-gray-800 text-gray-500 cursor-not-allowed"
                                                            : "bg-[#0097a7] text-black hover:scale-[1.02]"
                                                    }`}
                                                >
                                                    {module.comingSoon
                                                        // Locked would suggest the
                                                        // student has to earn it. This
                                                        // one is waiting on us.
                                                        ? "Material on its way"
                                                        : isLocked
                                                        ? "Locked"
                                                        : module.linksToRoadmap
                                                        // It hands off to a roadmap, so
                                                        // "Continue Learning" would
                                                        // promise the wrong thing.
                                                        ? `Open ${module.linksToRoadmap.title}`
                                                        : isDone
                                                        ? "Review Module"
                                                        : module.status === "current"
                                                        ? "Continue Learning"
                                                        : "Locked"}
                                                </button>
                                            )}
                                        </div>
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