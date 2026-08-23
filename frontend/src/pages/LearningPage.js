import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import api from "../api";
import {
    ArrowLeft, PlayCircle, CheckCircle2, Lock,
    BookOpen, Code2, FileText, GitBranch,
} from "lucide-react";

const RESOURCE_ICONS = {
    documentation: <BookOpen size={20} />,
    article: <FileText size={20} />,
    course: <Code2 size={20} />,
    social_account: <Code2 size={20} />,
};

function LearningPage() {
    const navigate = useNavigate();
    const { versionNodeId } = useParams();

    const [activeTab, setActiveTab] = useState("tutorials");
    const [detail, setDetail] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const load = async () => {
            setLoading(true);

            try {
                const response = await api.get(`/version-nodes/${versionNodeId}`);
                setDetail(response.data);
            } catch (err) {
                console.error(err);
                setError("Could not load this module.");
            } finally {
                setLoading(false);
            }
        };

        load();
    }, [versionNodeId]);

    const handleCompleteModule = async () => {
        setSaving(true);

        try {
            await api.post("/progress/complete-node", {
                version_node_id: versionNodeId,
            });

            navigate(`/roadmap/${detail.roadmap.id}`);
        } catch (err) {
            console.error(err);
            alert("Unable to complete this module.");
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return (
            <div className="min-h-screen bg-black text-white flex items-center justify-center">
                Loading module...
            </div>
        );
    }

    if (error || !detail) {
        return (
            <div className="min-h-screen bg-black text-red-400 flex items-center justify-center">
                {error ?? "Module not found."}
            </div>
        );
    }

    // A locked module stays closed even when reached by URL - showing its
    // tutorials would make the roadmap order meaningless.
    if (!detail.is_unlocked) {
        return (
            <div className="min-h-screen bg-black text-white flex items-center justify-center p-8">
                <div className="max-w-lg w-full rounded-3xl border border-white/10 bg-white/[0.03] p-10 text-center">
                    <Lock size={48} className="mx-auto text-gray-500 mb-5" />

                    <h1 className="text-3xl font-black">{detail.node.title}</h1>
                    <p className="text-gray-400 mt-3">
                        This module is locked. Finish what comes before it first.
                    </p>

                    {detail.prerequisites.length > 0 && (
                        <div className="mt-6 space-y-2 text-left">
                            {detail.prerequisites.map((p) => (
                                <div key={p.version_node_id}
                                     className="flex items-center justify-between rounded-xl border border-white/10 bg-black/30 px-4 py-3">
                                    <span className="text-sm">{p.title}</span>
                                    <span className={`text-xs ${p.completed ? "text-green-400" : "text-yellow-400"}`}>
                                        {p.completed ? "done" : "not finished"}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}

                    <button
                        onClick={() => navigate(`/roadmap/${detail.roadmap.id}`)}
                        className="mt-8 w-full py-3 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-[1.02] transition"
                    >
                        Back to Roadmap
                    </button>
                </div>
            </div>
        );
    }

    const tabs = [
        { id: "tutorials", label: `🎥 Tutorials (${detail.tutorials.length})` },
        { id: "quiz", label: `🧠 Quiz (${detail.quizzes.length})` },
        { id: "project", label: `💻 Project (${detail.projects.length})` },
        { id: "resources", label: `📚 Resources (${detail.external_resources.length})` },
    ];

    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.15),transparent_55%)] pointer-events-none" />

            <header className="relative z-10 px-8 lg:px-20 py-8 border-b border-white/10">
                <button
                    onClick={() => navigate(`/roadmap/${detail.roadmap.id}`)}
                    className="flex items-center gap-2 text-gray-400 hover:text-[#0097a7] transition mb-6"
                >
                    <ArrowLeft size={18} />
                    Back To Roadmap
                </button>

                <div className="flex flex-wrap gap-3 mb-5">
                    <div className="inline-flex items-center gap-2 px-4 py-1 rounded-full border border-[#0097a7]/20 bg-[#0097a7]/10 text-[#00b0c2] text-sm">
                        {detail.roadmap.title}
                    </div>

                    <div className="inline-flex items-center gap-2 px-4 py-1 rounded-full border border-purple-400/20 bg-purple-500/10 text-purple-300 text-sm">
                        <GitBranch size={14} />
                        v{detail.version.version_number}
                    </div>

                    <div className="inline-flex items-center px-4 py-1 rounded-full border border-white/10 bg-white/5 text-gray-300 text-sm capitalize">
                        {detail.node.difficulty}
                    </div>
                </div>

                <h1 className="text-5xl font-black">{detail.node.title}</h1>

                <p className="mt-4 text-lg text-gray-400 max-w-3xl">
                    {detail.node.description}
                </p>

                {detail.prerequisites.length > 0 && (
                    <div className="mt-6 flex flex-wrap gap-3 text-sm">
                        {detail.prerequisites.map((prereq) => (
                            <span
                                key={prereq.node_id}
                                className={`px-3 py-1 rounded-full border ${
                                    prereq.completed
                                        ? "border-green-500/20 bg-green-500/10 text-green-300"
                                        : "border-yellow-500/20 bg-yellow-500/10 text-yellow-300"
                                }`}
                            >
                                {prereq.completed ? "✓" : "!"} {prereq.title}
                            </span>
                        ))}
                    </div>
                )}
            </header>

            <main className="relative z-10 px-8 lg:px-20 py-10">
                <div className="grid lg:grid-cols-[1fr_320px] gap-8">
                    <div>
                        <div className="flex flex-wrap gap-3 mb-8">
                            {tabs.map((tab) => (
                                <button
                                    key={tab.id}
                                    onClick={() => setActiveTab(tab.id)}
                                    className={`px-5 py-3 rounded-2xl transition ${
                                        activeTab === tab.id
                                            ? "bg-[#0097a7] text-black font-semibold"
                                            : "bg-white/[0.03] border border-white/10 text-gray-300"
                                    }`}
                                >
                                    {tab.label}
                                </button>
                            ))}
                        </div>

                        {activeTab === "tutorials" && (
                            <div className="space-y-5">
                                {detail.tutorials.length === 0 ? (
                                    <EmptyState text="No tutorials for this module yet." />
                                ) : (
                                    detail.tutorials.map((tutorial) => (
                                        <div key={tutorial.id} className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                                            <div className="flex items-center justify-between gap-4">
                                                <div>
                                                    <h3 className="text-xl font-bold">{tutorial.title}</h3>
                                                    <p className="text-gray-400 mt-2">{tutorial.description}</p>
                                                    <div className="mt-4 flex gap-3 text-sm">
                                                        <span className="text-[#00b0c2]">{tutorial.source_name}</span>
                                                    </div>
                                                </div>

                                                <a
                                                    href={tutorial.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="flex items-center gap-2 px-5 py-3 rounded-2xl bg-[#0097a7] text-black font-semibold shrink-0"
                                                >
                                                    <PlayCircle size={18} />
                                                    Watch
                                                </a>
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        )}

                        {activeTab === "quiz" && (
                            <div className="space-y-5">
                                {detail.quizzes.length === 0 ? (
                                    <EmptyState text="No quiz for this module yet." />
                                ) : (
                                    detail.quizzes.map((quiz) => (
                                        <div key={quiz.id} className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                                            <h2 className="text-3xl font-bold mb-3">{quiz.title}</h2>
                                            <p className="text-gray-400 mb-6">
                                                {quiz.questions?.length ?? 0} questions
                                            </p>
                                            <button
                                                onClick={() => navigate(`/quiz/${quiz.id}`)}
                                                className="px-6 py-3 rounded-2xl bg-[#0097a7] text-black font-semibold"
                                            >
                                                Start Quiz
                                            </button>
                                        </div>
                                    ))
                                )}
                            </div>
                        )}

                        {activeTab === "project" && (
                            <div className="space-y-5">
                                {detail.projects.length === 0 ? (
                                    <EmptyState text="No project for this module yet." />
                                ) : (
                                    detail.projects.map((project) => (
                                        <div key={project.id} className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                                            <h2 className="text-3xl font-bold mb-2">{project.title}</h2>
                                            <p className="text-[#00b0c2] mb-4 capitalize">{project.difficulty}</p>
                                            <p className="text-gray-400 mb-6">{project.description}</p>
                                            <button
                                                onClick={() => navigate(`/project/${project.id}`)}
                                                className="px-6 py-3 rounded-2xl bg-[#0097a7] text-black font-semibold"
                                            >
                                                Open Project
                                            </button>
                                        </div>
                                    ))
                                )}
                            </div>
                        )}

                        {activeTab === "resources" && (
                            <div className="space-y-5">
                                {detail.external_resources.length === 0 ? (
                                    <EmptyState text="No extra resources for this module yet." />
                                ) : (
                                    detail.external_resources.map((resource) => (
                                        <a
                                            key={resource.id}
                                            href={resource.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="block rounded-3xl border border-white/10 bg-white/[0.03] p-6 hover:border-[#0097a7]/40 transition"
                                        >
                                            <div className="flex items-center gap-3 mb-3">
                                                {RESOURCE_ICONS[resource.type] ?? <FileText size={20} />}
                                                <h3 className="font-bold">{resource.title}</h3>
                                            </div>
                                            <p className="text-gray-400 text-sm capitalize">
                                                {resource.type.replace("_", " ")}
                                            </p>
                                        </a>
                                    ))
                                )}
                            </div>
                        )}
                    </div>

                    <div className="h-fit sticky top-8">
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                            <h2 className="text-2xl font-bold mb-6">Module Status</h2>

                            <div className="space-y-5">
                                <StatusRow label="Tutorials" done={detail.tutorials.length > 0} />
                                <StatusRow label="Quiz" done={detail.quizzes.length > 0} />
                                <StatusRow label="Project" done={detail.projects.length > 0} />
                                <StatusRow label="Prerequisites" done={detail.is_unlocked} />
                            </div>

                            <div className="mt-8">
                                {detail.is_completed ? (
                                    <div className="w-full py-4 rounded-2xl bg-green-500/10 border border-green-500/20 text-green-300 text-center font-bold">
                                        ✓ Module Completed
                                    </div>
                                ) : (
                                    <>
                                        {/* Say what is still outstanding rather than
                                            leaving a dead button with no explanation. */}
                                        {detail.completion_blockers?.length > 0 && (
                                            <div className="mb-4 rounded-2xl border border-yellow-400/20 bg-yellow-400/5 p-4">
                                                <p className="text-sm font-semibold text-yellow-300 mb-2">
                                                    Before you can complete this module:
                                                </p>
                                                <ul className="space-y-1">
                                                    {detail.completion_blockers.map((b, i) => (
                                                        <li key={i} className="text-sm text-gray-300">· {b}</li>
                                                    ))}
                                                </ul>
                                            </div>
                                        )}

                                        <button
                                            disabled={detail.completion_blockers?.length > 0 || saving}
                                            onClick={handleCompleteModule}
                                            className={`w-full py-4 rounded-2xl font-bold transition ${
                                                !detail.completion_blockers?.length && !saving
                                                    ? "bg-[#0097a7] text-black hover:scale-[1.02]"
                                                    : "bg-gray-800 text-gray-500 cursor-not-allowed"
                                            }`}
                                        >
                                            {saving
                                                ? "Saving..."
                                                : detail.completion_blockers?.length > 0
                                                ? "Not Finished Yet"
                                                : "Mark as Complete →"}
                                        </button>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    );
}

function EmptyState({ text }) {
    return (
        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8 text-center text-gray-400">
            {text}
        </div>
    );
}

function StatusRow({ label, done }) {
    return (
        <div className="flex justify-between items-center">
            <span>{label}</span>
            {done ? (
                <CheckCircle2 className="text-green-400" size={20} />
            ) : (
                <Lock className="text-gray-500" size={20} />
            )}
        </div>
    );
}

export default LearningPage;