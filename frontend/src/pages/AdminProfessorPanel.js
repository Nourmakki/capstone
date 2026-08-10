import React, { useState } from "react";
import {
    ShieldCheck,
    BookOpen,
    GraduationCap,
    Save,
    GitBranch,
    Upload,
} from "lucide-react";

function AdminProfessorPanel() {
    const [activeTab, setActiveTab] = useState("roadmaps");

    const [roadmapTitle, setRoadmapTitle] = useState(
        "Web Development Roadmap"
    );

    const [roadmapVersion, setRoadmapVersion] = useState("v2.3");

    const [roadmapStatus, setRoadmapStatus] = useState("Draft");

    const [graphJson, setGraphJson] = useState(`{
  "nodes": [],
  "edges": []
}`);

    const [youtubeLink, setYoutubeLink] = useState(
        "https://youtube.com/example"
    );

    const [courseLink, setCourseLink] = useState(
        "https://course-link.com"
    );

    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.08),transparent_55%)] pointer-events-none" />
            <header className="relative z-10 px-8 lg:px-20 py-10 border-b border-white/10">
                <div className="flex items-center gap-4">
                    <div className="w-14 h-14 rounded-2xl bg-[#0097a7] text-black flex items-center justify-center">
                        <ShieldCheck size={28} />
                    </div>

                    <div>
                        <h1 className="text-4xl font-black">
                            Admin / Professor Panel
                        </h1>

                        <p className="text-gray-400 mt-2">
                            Manage roadmaps, versions, tutorials, and learning resources.
                        </p>
                    </div>
                </div>
            </header>

            <main className="relative z-10 px-8 lg:px-20 py-10">
                <div className="flex flex-wrap gap-4 mb-10">
                    <button
                        onClick={() => setActiveTab("roadmaps")}
                        className={`px-6 py-3 rounded-2xl transition ${
                            activeTab === "roadmaps"
                                ? "bg-[#0097a7] text-black"
                                : "bg-white/5 border border-white/10"
                        }`}
                    >
                        Roadmaps
                    </button>

                    <button
                        onClick={() => setActiveTab("resources")}
                        className={`px-6 py-3 rounded-2xl transition ${
                            activeTab === "resources"
                                ? "bg-[#0097a7] text-black"
                                : "bg-white/5 border border-white/10"
                        }`}
                    >
                        Resources
                    </button>
                </div>

                {activeTab === "roadmaps" && (
                    <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                        <div className="flex items-center gap-3 mb-8">
                            <GitBranch className="text-[#0097a7]" />

                            <h2 className="text-3xl font-bold">
                                Versioned Roadmap Editor
                            </h2>
                        </div>

                        <div className="grid md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <label className="block mb-2 text-left text-gray-300">
                                    Roadmap Title
                                </label>

                                <input
                                    type="text"
                                    value={roadmapTitle}
                                    onChange={(e) =>
                                        setRoadmapTitle(e.target.value)
                                    }
                                    className="w-full p-4 rounded-2xl bg-black/30 border border-white/10"
                                />
                            </div>

                            <div>
                                <label className="block mb-2 text-left text-gray-300">
                                    Version
                                </label>

                                <input
                                    type="text"
                                    value={roadmapVersion}
                                    onChange={(e) =>
                                        setRoadmapVersion(e.target.value)
                                    }
                                    className="w-full p-4 rounded-2xl bg-black/30 border border-white/10"
                                />
                            </div>
                        </div>

                        <div className="mb-6">
                            <label className="block mb-2 text-left text-gray-300">
                                Status
                            </label>

                            <select
                                value={roadmapStatus}
                                onChange={(e) =>
                                    setRoadmapStatus(e.target.value)
                                }
                                className="w-full p-4 rounded-2xl bg-black/30 border border-white/10"
                            >
                                <option>Draft</option>
                                <option>Published</option>
                                <option>Archived</option>
                            </select>
                        </div>

                        <div className="grid md:grid-cols-3 gap-4 mb-8">
                            <div className="rounded-2xl bg-black/30 border border-white/10 p-5">
                                <p className="text-gray-400 text-sm">
                                    Nodes
                                </p>
                                <h3 className="text-3xl font-black mt-2">
                                    24
                                </h3>
                            </div>

                            <div className="rounded-2xl bg-black/30 border border-white/10 p-5">
                                <p className="text-gray-400 text-sm">
                                    Edges
                                </p>
                                <h3 className="text-3xl font-black mt-2">
                                    31
                                </h3>
                            </div>

                            <div className="rounded-2xl bg-black/30 border border-white/10 p-5">
                                <p className="text-gray-400 text-sm">
                                    Last Updated
                                </p>
                                <h3 className="text-xl font-bold mt-2">
                                    Today
                                </h3>
                            </div>
                        </div>

                        <label className="block mb-2 text-left text-gray-300">
                            Graph JSON
                        </label>

                        <textarea
                            rows="12"
                            value={graphJson}
                            onChange={(e) => setGraphJson(e.target.value)}
                            className="w-full p-4 rounded-2xl bg-black/30 border border-white/10 font-mono"
                        />

                        <div className="flex flex-wrap gap-4 mt-8">
                            <button className="flex items-center gap-2 px-6 py-3 rounded-2xl bg-[#0097a7] text-black font-bold">
                                <Save size={18} />
                                Save New Version
                            </button>

                            <button className="flex items-center gap-2 px-6 py-3 rounded-2xl border border-green-400/20 bg-green-500/10 text-green-300">
                                <Upload size={18} />
                                Publish Version
                            </button>
                        </div>
                    </div>
                )}

                {activeTab === "resources" && (
                    <div className="space-y-8">
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                            <div className="flex items-center gap-3 mb-6">
                                <div className="text-red-400 font-bold text-xl">
                                    ▶
                                </div>

                                <h2 className="text-2xl font-bold">
                                    YouTube Resource
                                </h2>
                            </div>

                            <input
                                type="text"
                                value={youtubeLink}
                                onChange={(e) =>
                                    setYoutubeLink(e.target.value)
                                }
                                className="w-full p-4 rounded-2xl bg-black/30 border border-white/10"
                            />
                        </div>

                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                            <div className="flex items-center gap-3 mb-6">
                                <GraduationCap className="text-[#0097a7]" />

                                <h2 className="text-2xl font-bold">
                                    External Course
                                </h2>
                            </div>

                            <input
                                type="text"
                                value={courseLink}
                                onChange={(e) =>
                                    setCourseLink(e.target.value)
                                }
                                className="w-full p-4 rounded-2xl bg-black/30 border border-white/10"
                            />
                        </div>

                        <button className="flex items-center gap-2 px-6 py-3 rounded-2xl bg-[#0097a7] text-black font-bold">
                            <Save size={18} />
                            Save Resources
                        </button>
                    </div>
                )}
            </main>
        </div>
    );
}

export default AdminProfessorPanel;