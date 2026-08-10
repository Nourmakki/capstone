import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { useNavigate } from "react-router-dom";
import api from "../api";

import {
    ArrowLeft,
    PlayCircle,
    CheckCircle2,
    Lock,
    BookOpen,
    Code2,
    FileText,
    Clock3,
    GitBranch,
} from "lucide-react";

function LearningPage() {
    const navigate = useNavigate();
    const [activeTab, setActiveTab] = useState("tutorials");
    const [loading, setLoading] = useState(true);
    const [tutorials, setTutorials] = useState([]);
    const { versionNodeId } = useParams();
    const [user, setUser] = useState(null);

    useEffect(() => {

        const savedUser = JSON.parse(localStorage.getItem("user"));
            if (savedUser) {
            setUser(savedUser);
        }

    const fetchTutorials = async () => {
    try {
        console.log("Fetching tutorials...");

        const response = await api.get(
            `/version-nodes/${versionNodeId}/tutorials`
        );

        console.log("Response:", response.data);

        setTutorials(response.data);
    } catch (error) {
        console.error(error);
    } finally {
        setLoading(false);
    }
};
        fetchTutorials();
    }, []);

    const handleCompleteModule = async () => {
    try {
        await api.post("/progress/complete-node", {
            user_id: user.id,
            version_node_id: versionNodeId,
        });
        alert("Module completed!");
        navigate("/roadmap");
    } catch (error) {
        console.error(error);
        alert("Unable to complete module.");
    }
};

    const tutorialCompleted = true;
    const quizCompleted = true;
    const projectCompleted = true;

    const canProceed =
        tutorialCompleted &&
        quizCompleted &&
        projectCompleted;

    const roadmapVersion = "v2.3";
    const nodeId = "react-basics";

    console.log("tutorials =", tutorials);
    
    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.08),transparent_55%)] pointer-events-none" />

            <header className="relative z-10 px-8 lg:px-20 py-8 border-b border-white/10">
                <button onClick={() => navigate("/roadmap")}
                    className="flex items-center gap-2 text-gray-400 hover:text-[#0097a7] transition mb-6"
                >
                    <ArrowLeft size={18} />
                    Back To Roadmap
                </button>

                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-8">
                    <div>
                        <div className="flex flex-wrap gap-3 mb-5">
                            <div className="inline-flex items-center gap-2 px-4 py-1 rounded-full border border-[#0097a7]/20 bg-[#0097a7]/10 text-[#00b0c2] text-sm">
                                Web Development
                            </div>

                            <div className="inline-flex items-center gap-2 px-4 py-1 rounded-full border border-purple-400/20 bg-purple-500/10 text-purple-300 text-sm">
                                <GitBranch size={14} />
                                {roadmapVersion}
                            </div>
                        </div>

                        <h1 className="text-5xl font-black">
                            React Basics
                        </h1>

                        <p className="mt-4 text-lg text-gray-400 max-w-3xl">
                            Learn components, props, state management,
                            event handling, and build your first React
                            applications.
                        </p>

                        <div className="mt-6 flex flex-wrap gap-6 text-sm text-gray-400">
                            <span>
                                Node ID:
                                <span className="text-[#00b0c2] ml-2">
                                    {nodeId}
                                </span>
                            </span>

                            <span>
                                Prerequisite:
                                <span className="text-green-400 ml-2">
                                    JavaScript Basics ✓
                                </span>
                            </span>

                            <span>
                                Updated:
                                <span className="text-[#00b0c2] ml-2">
                                    May 2026
                                </span>
                            </span>
                        </div>
                    </div>

                    <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/10 p-6 min-w-[280px]">
                        <div className="flex items-center gap-3 mb-4">
                            <Clock3 size={18} />
                            <span>Estimated Time</span>
                        </div>

                        <h3 className="text-3xl font-bold">
                            4 Hours
                        </h3>

                        <div className="mt-5">
                            <div className="flex justify-between text-sm mb-2">
                                <span>Progress</span>
                                <span>65%</span>
                            </div>

                            <div className="w-full h-3 bg-black/30 rounded-full overflow-hidden">
                                <div className="w-[65%] h-full bg-gradient-to-r from-[#0097a7] to-blue-500 rounded-full" />
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main className="relative z-10 px-8 lg:px-20 py-10">
                <div className="grid lg:grid-cols-[1fr_320px] gap-8">
                    <div>
                        <div className="flex flex-wrap gap-3 mb-8">
                            {[
                                {
                                    id: "tutorials",
                                    label: "🎥 Tutorials",
                                },
                                {
                                    id: "quiz",
                                    label: "🧠 Quiz",
                                },
                                {
                                    id: "project",
                                    label: "💻 Project",
                                },
                                {
                                    id: "resources",
                                    label: "📚 Resources",
                                },
                            ].map((tab) => (
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
                                {loading ? (
                                <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8 text-center text-gray-400">
                                    Loading tutorials...
                                </div>
                                ) : tutorials.length === 0 ? (
                                <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8 text-center text-gray-400">
                                    No tutorials available yet.
                                </div>
                                ) : (
                                tutorials.map((tutorial) => (
                                <div key={tutorial.id}
                                    className="rounded-3xl border border-white/10 bg-white/[0.03] p-6"
                                >
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <h3 className="text-xl font-bold">
                                                {tutorial.title}
                                            </h3>
                                            <p className="text-gray-400 mt-2">
                                                {tutorial.description}
                                            </p>
                                            <div className="mt-4 flex gap-3 text-sm">
                                                <span className="text-[#00b0c2]">
                                                    {tutorial.source_name}
                                                </span>
                                                <span className="text-yellow-400 capitalize">
                                                    {tutorial.difficulty}
                                                </span>
                                            </div>
                                        </div>
                                        <a href={tutorial.url}
                                            target="_blank" rel="noreferrer"
                                            className="flex items-center gap-2 px-5 py-3 rounded-2xl bg-[#0097a7] text-black font-semibold"
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
                            <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                                <h2 className="text-3xl font-bold mb-6">
                                    React Basics Quiz
                                </h2>

                                <p className="text-gray-400 mb-2">
                                    Quiz Version: 3
                                </p>

                                <p className="text-gray-400 mb-6">
                                    Test your understanding of components,
                                    props, state, and events.
                                </p>

                                <div className="flex items-center gap-3 mb-8">
                                    <CheckCircle2 className="text-green-400" />
                                    <span>
                                        Passed - Score: 85%
                                    </span>
                                </div>

                                <button onClick={() => navigate("/quiz")}
                                    className="px-6 py-3 rounded-2xl bg-[#0097a7] text-black font-semibold"
                                >
                                    Review Quiz
                                </button>
                            </div>
                        )}

                        {activeTab === "project" && (
                            <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                                <h2 className="text-3xl font-bold mb-2">
                                    Build a React To-Do App
                                </h2>

                                <p className="text-[#00b0c2] mb-4">
                                    Project Version: 2
                                </p>

                                <p className="text-gray-400 mb-6">
                                    Create a functional To-Do application
                                    using React fundamentals.
                                </p>

                                <ul className="space-y-3 mb-8">
                                    <li>✓ Components</li>
                                    <li>✓ State Management</li>
                                    <li>✓ Event Handling</li>
                                    <li>✓ Add / Delete Tasks</li>
                                </ul>

                                <input
                                    type="file"
                                    className="w-full p-4 rounded-2xl bg-black/30 border border-white/10 mb-5"
                                />

                                <button onClick={() => navigate("/project")}
                                    className="px-6 py-3 rounded-2xl bg-[#0097a7] text-black font-semibold"
                                >
                                    Submit Project
                                </button>
                            </div>
                        )}

                        {activeTab === "resources" && (
                            <div className="space-y-5">
                                <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                                    <div className="flex items-center gap-3 mb-3">
                                        <BookOpen size={20} />
                                        <h3 className="font-bold">
                                            React Documentation
                                        </h3>
                                    </div>

                                    <p className="text-gray-400">
                                        Official React documentation and guides.
                                    </p>
                                </div>

                                <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                                    <div className="flex items-center gap-3 mb-3">
                                        <Code2 size={20} />
                                        <h3 className="font-bold">
                                            GitHub Examples
                                        </h3>
                                    </div>

                                    <p className="text-gray-400">
                                        Explore open-source React projects.
                                    </p>
                                </div>

                                <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                                    <div className="flex items-center gap-3 mb-3">
                                        <FileText size={20} />
                                        <h3 className="font-bold">
                                            Extra Course
                                        </h3>
                                    </div>

                                    <p className="text-gray-400">
                                        Advanced React learning materials.
                                    </p>
                                </div>
                            </div>
                        )}
                    </div>

                    <div className="h-fit sticky top-8">
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                            <h2 className="text-2xl font-bold mb-6">
                                Module Progress
                            </h2>

                            <div className="space-y-5">
                                <div className="flex justify-between">
                                    <span>Tutorials</span>
                                    <CheckCircle2 className="text-green-400" />
                                </div>

                                <div className="flex justify-between">
                                    <span>Quiz</span>
                                    <CheckCircle2 className="text-green-400" />
                                </div>

                                <div className="flex justify-between">
                                    <span>Project</span>

                                    {projectCompleted ? (
                                        <CheckCircle2 className="text-green-400" />
                                    ) : (
                                        <Lock className="text-gray-500" />
                                    )}
                                </div>
                            </div>

                            <div className="mt-8">
                                <button disabled={!canProceed}
                                    onClick={handleCompleteModule}
                                    className={`w-full py-4 rounded-2xl font-bold transition ${
                                        canProceed
                                            ? "bg-[#0097a7] text-black"
                                            : "bg-gray-800 text-gray-500 cursor-not-allowed"
                                    }`}
                                >
                                    {canProceed
                                        ? "Next Step →"
                                        : "Complete All Requirements"}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    );
}

export default LearningPage;