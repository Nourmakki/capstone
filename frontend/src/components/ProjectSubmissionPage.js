import React, { useState } from "react";
import {
    ArrowLeft,
    GitBranch,
    Brain,
    CheckCircle2,
    UserRound,
    Trophy,
    Code2,
    GitCommit,
    History,
    Network,
} from "lucide-react";

function ProjectSubmissionPage() {
    const [githubLink, setGithubLink] = useState("");
    const [submitted, setSubmitted] = useState(false);

    const handleSubmit = () => {
        setSubmitted(true);
    };

    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.08),transparent_55%)] pointer-events-none">
            </div>

            <header className="relative z-10 px-8 lg:px-20 py-8 border-b border-white/10">
                <button className="flex items-center gap-2 text-gray-400 hover:text-[#0097a7] transition mb-6">
                    <ArrowLeft size={18} />
                    Back To Learning Page
                </button>

                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-8">
                    <div>
                        <div className="inline-flex items-center gap-2 px-4 py-1 rounded-full border border-[#0097a7]/20 bg-[#0097a7]/10 text-[#00b0c2] text-sm mb-5">
                            React Basics Project
                        </div>

                        <h1 className="text-5xl font-black">
                            Build a React To-Do App
                        </h1>

                        <p className="mt-5 text-lg text-gray-400 max-w-3xl">
                            Apply everything learned in this module by creating
                            a functional To-Do application using React
                            components, state management, and event handling.
                        </p>
                    </div>

                    <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/10 p-6 min-w-[280px]">
                        <div className="flex items-center gap-3 mb-4">
                            <Trophy size={20} />
                            <span className="font-semibold">
                                Project Weight
                            </span>
                        </div>

                        <h2 className="text-4xl font-black">
                            100 pts
                        </h2>
                    </div>
                </div>
            </header>

            <main className="relative z-10 px-8 lg:px-20 py-10">
                <div className="grid lg:grid-cols-[1fr_350px] gap-8">
                    <div>
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8 mb-8">
                            <div className="flex items-center gap-3 mb-6">
                                <Code2 size={22} />
                                <h2 className="text-2xl font-bold">
                                    Project Description
                                </h2>
                            </div>

                            <p className="text-gray-300 leading-relaxed">
                                Create a React To-Do application that allows
                                users to add, delete, and manage tasks. The
                                application should demonstrate proper component
                                structure, state management, and clean UI
                                design.
                            </p>
                        </div>

                        <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-8 mb-8">
                            <div className="flex items-center gap-3 mb-6">
                                <Network className="text-[#0097a7]" />
                                <h2 className="text-2xl font-bold">
                                    Roadmap Graph Tracking
                                </h2>
                            </div>

                            <p className="text-gray-300 leading-relaxed mb-6">
                                This submission will be attached to the roadmap
                                graph version active when the project is
                                submitted, ensuring progress remains traceable
                                even if roadmap nodes or dependencies change
                                later.
                            </p>

                            <div className="grid md:grid-cols-3 gap-4">
                                <div className="rounded-2xl border border-white/10 bg-black/30 p-4">
                                    <GitCommit
                                        size={20}
                                        className="text-[#0097a7] mb-2"
                                    />
                                    <p className="text-sm text-gray-400">
                                        Graph Version
                                    </p>
                                    <p className="font-bold">
                                        v2.4.1
                                    </p>
                                </div>

                                <div className="rounded-2xl border border-white/10 bg-black/30 p-4">
                                    <History
                                        size={20}
                                        className="text-[#0097a7] mb-2"
                                    />
                                    <p className="text-sm text-gray-400">
                                        Snapshot Date
                                    </p>
                                    <p className="font-bold">
                                        2026-06-01
                                    </p>
                                </div>

                                <div className="rounded-2xl border border-white/10 bg-black/30 p-4">
                                    <Network
                                        size={20}
                                        className="text-[#0097a7] mb-2"
                                    />
                                    <p className="text-sm text-gray-400">
                                        Module Node
                                    </p>
                                    <p className="font-bold">
                                        React Basics
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8 mb-8">
                            <h2 className="text-2xl font-bold mb-6">
                                Requirements
                            </h2>

                            <div className="space-y-4">
                                <div className="flex items-center gap-3">
                                    <CheckCircle2 className="text-[#0097a7]" />
                                    React Components
                                </div>

                                <div className="flex items-center gap-3">
                                    <CheckCircle2 className="text-[#0097a7]" />
                                    State Management
                                </div>

                                <div className="flex items-center gap-3">
                                    <CheckCircle2 className="text-[#0097a7]" />
                                    Event Handling
                                </div>

                                <div className="flex items-center gap-3">
                                    <CheckCircle2 className="text-[#0097a7]" />
                                    Add / Delete Tasks
                                </div>

                                <div className="flex items-center gap-3">
                                    <CheckCircle2 className="text-[#0097a7]" />
                                    Responsive Design
                                </div>
                            </div>
                        </div>

                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                            <h2 className="text-2xl font-bold mb-6">
                                Submit Your Project
                            </h2>

                            <label className="block text-gray-300 text-left mb-2">
                                GitHub Repository Link
                            </label>

                            <div className="relative mb-6">
                                <GitBranch
                                    size={18}
                                    className="absolute left-4 top-4 text-gray-400"
                                />

                                <input
                                    type="text"
                                    value={githubLink}
                                    onChange={(e) =>
                                        setGithubLink(e.target.value)
                                    }
                                    placeholder="https://github.com/username/project"
                                    className="w-full pl-11 p-4 rounded-2xl bg-black/30 border border-white/10 text-white"
                                />
                            </div>

                            <label className="block text-gray-300 text-left mb-2">
                                Upload ZIP File
                            </label>

                            <input
                                type="file"
                                className="w-full p-4 rounded-2xl bg-black/30 border border-white/10 mb-6"
                            />

                            <button
                                onClick={handleSubmit}
                                className="w-full py-4 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-[1.01] transition"
                            >
                                Submit Project
                            </button>
                        </div>

                        {submitted && (
                            <div className="mt-8 rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-8">
                                <div className="flex items-center gap-3 mb-6">
                                    <Brain
                                        size={24}
                                        className="text-[#0097a7]"
                                    />
                                    <h2 className="text-3xl font-bold">
                                        AI Evaluation Result
                                    </h2>
                                </div>

                                <div className="rounded-2xl border border-white/10 bg-black/30 p-6 mb-6">
                                    <h3 className="font-semibold mb-3">
                                        Project Score
                                    </h3>

                                    <p className="text-5xl font-black text-[#0097a7]">
                                        91%
                                    </p>
                                </div>

                                <div className="rounded-2xl border border-white/10 bg-black/30 p-6 mb-6">
                                    <h3 className="font-semibold mb-4">
                                        Roadmap Version Recorded
                                    </h3>

                                    <p className="text-gray-300">
                                        This submission has been linked to
                                        roadmap graph version <strong>v2.4.1</strong>
                                        to preserve grading consistency and
                                        future auditability.
                                    </p>
                                </div>

                                <div className="rounded-2xl border border-white/10 bg-black/30 p-6">
                                    <h3 className="font-semibold mb-4">
                                        AI Feedback
                                    </h3>

                                    <p className="text-gray-300 leading-relaxed">
                                        Excellent implementation of React
                                        fundamentals. Components were structured
                                        properly and state management was handled
                                        effectively. The application is
                                        functional and meets all mandatory
                                        requirements. Consider adding task
                                        editing and local storage persistence
                                        for an even stronger solution.
                                    </p>
                                </div>

                                <button className="mt-8 w-full flex items-center justify-center gap-3 py-4 rounded-2xl border border-[#0097a7]/20 bg-[#0097a7]/10 text-[#00b0c2] hover:bg-[#0097a7]/20 transition">
                                    <UserRound size={20} />
                                    Request Professor Review
                                </button>
                            </div>
                        )}
                    </div>

                    <div className="h-fit sticky top-8">
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                            <h2 className="text-2xl font-bold mb-6">
                                Project Checklist
                            </h2>

                            <div className="space-y-5">
                                <div className="flex justify-between">
                                    <span>Components</span>
                                    <CheckCircle2 className="text-green-400" />
                                </div>

                                <div className="flex justify-between">
                                    <span>State Management</span>
                                    <CheckCircle2 className="text-green-400" />
                                </div>

                                <div className="flex justify-between">
                                    <span>Event Handling</span>
                                    <CheckCircle2 className="text-green-400" />
                                </div>

                                <div className="flex justify-between">
                                    <span>Responsive UI</span>
                                    <CheckCircle2 className="text-green-400" />
                                </div>
                            </div>

                            <div className="mt-8">
                                <div className="flex justify-between text-sm mb-2">
                                    <span>Completion</span>
                                    <span>100%</span>
                                </div>

                                <div className="w-full h-3 bg-black/30 rounded-full overflow-hidden">
                                    <div className="w-full h-full bg-gradient-to-r from-blue-400 to-[#0097a7] rounded-full">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    );
}

export default ProjectSubmissionPage;