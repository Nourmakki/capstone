import React, { useState } from "react";
import {
    ArrowLeft,
    Clock3,
    Brain,
    CheckCircle2,
    AlertCircle,
    UserRound,
} from "lucide-react";

function QuizPage() {
    const [submitted, setSubmitted] = useState(false);

    const handleSubmit = () => {
        setSubmitted(true);
    };

    // Future API values from roadmap graph version
    const roadmapVersion = "v2.3";
    const moduleId = "react-basics";

    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.08),transparent_55%)] pointer-events-none"></div>

            <header className="relative z-10 px-8 lg:px-20 py-8 border-b border-white/10">
                <button className="flex items-center gap-2 text-gray-400 hover:text-[#0097a7] transition mb-6">
                    <ArrowLeft size={18} />
                    Back To Learning Page
                </button>

                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                    <div>
                        <div className="inline-flex items-center gap-2 px-4 py-1 rounded-full border border-[#0097a7]/20 bg-[#0097a7]/10 text-[#00b0c2] text-sm mb-5">
                            React Basics Quiz
                        </div>

                        <h1 className="text-5xl font-black">
                            Knowledge Check
                        </h1>

                        <p className="mt-4 text-lg text-gray-400 max-w-3xl">
                            Complete the quiz to unlock the project and continue your roadmap.
                        </p>

                        <div className="mt-4 text-sm text-gray-500">
                            Module: {moduleId} • Version: {roadmapVersion}
                        </div>
                    </div>

                    <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/10 p-6 min-w-[250px]">
                        <div className="flex items-center gap-3">
                            <Clock3 size={20} />
                            <span className="font-semibold">Time Remaining</span>
                        </div>

                        <h2 className="text-4xl font-black mt-3">
                            18:24
                        </h2>
                    </div>
                </div>
            </header>

            <main className="relative z-10 px-8 lg:px-20 py-10">
                <div className="max-w-5xl mx-auto">

                    <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8 mb-6">
                        <h2 className="text-xl font-bold mb-6">
                            1. What is React primarily used for?
                        </h2>

                        <div className="space-y-4">
                            <label className="flex items-center gap-3">
                                <input type="radio" name="q1" />
                                Database Management
                            </label>

                            <label className="flex items-center gap-3">
                                <input type="radio" name="q1" />
                                Building User Interfaces
                            </label>

                            <label className="flex items-center gap-3">
                                <input type="radio" name="q1" />
                                Operating Systems
                            </label>

                            <label className="flex items-center gap-3">
                                <input type="radio" name="q1" />
                                Network Security
                            </label>
                        </div>
                    </div>

                    <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8 mb-6">
                        <h2 className="text-xl font-bold mb-6">
                            2. What is the purpose of React State?
                        </h2>

                        <div className="space-y-4">
                            <label className="flex items-center gap-3">
                                <input type="radio" name="q2" />
                                Styling Components
                            </label>

                            <label className="flex items-center gap-3">
                                <input type="radio" name="q2" />
                                Managing Dynamic Data
                            </label>

                            <label className="flex items-center gap-3">
                                <input type="radio" name="q2" />
                                Connecting Databases
                            </label>

                            <label className="flex items-center gap-3">
                                <input type="radio" name="q2" />
                                Deploying Applications
                            </label>
                        </div>
                    </div>

                    <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8 mb-8">
                        <h2 className="text-xl font-bold mb-4">
                            3. Coding Question
                        </h2>

                        <p className="text-gray-400 mb-5">
                            Explain how React Components help organize applications.
                        </p>

                        <textarea
                            rows="8"
                            placeholder="Write your answer here..."
                            className="w-full bg-black/30 border border-white/10 rounded-2xl p-4 text-white outline-none"
                        />
                    </div>

                    {!submitted && (
                        <button
                            onClick={handleSubmit}
                            className="w-full py-4 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-[1.01] transition"
                        >
                            Submit Quiz
                        </button>
                    )}

                    {submitted && (
                        <div className="mt-10 rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-8">
                            <div className="flex items-center gap-3 mb-6">
                                <Brain
                                    size={24}
                                    className="text-[#0097a7]"
                                />

                                <h2 className="text-3xl font-bold">
                                    AI Feedback
                                </h2>
                            </div>

                            <div className="grid md:grid-cols-2 gap-6">
                                <div className="rounded-2xl bg-black/30 border border-white/10 p-6">
                                    <div className="flex items-center gap-2 mb-4">
                                        <CheckCircle2 className="text-green-400" />

                                        <h3 className="font-semibold">
                                            Score
                                        </h3>
                                    </div>

                                    <p className="text-5xl font-black text-[#0097a7]">
                                        85%
                                    </p>
                                </div>

                                <div className="rounded-2xl bg-black/30 border border-white/10 p-6">
                                    <div className="flex items-center gap-2 mb-4">
                                        <AlertCircle className="text-yellow-400" />

                                        <h3 className="font-semibold">
                                            Result
                                        </h3>
                                    </div>

                                    <p className="text-green-400 font-semibold">
                                        Passed
                                    </p>
                                </div>
                            </div>

                            <div className="mt-8 rounded-2xl bg-black/30 border border-white/10 p-6">
                                <h3 className="font-bold text-xl mb-4">
                                    Explanation
                                </h3>

                                <p className="text-gray-300 leading-relaxed">
                                    You demonstrated a strong understanding of React fundamentals and correctly identified the purpose of state management.
                                    Your coding answer explained the role of components, although more detail about reusability and maintainability would strengthen it further.
                                </p>
                            </div>

                            <button className="mt-8 w-full flex items-center justify-center gap-3 py-4 rounded-2xl border border-[#0097a7]/20 bg-[#0097a7]/10 text-[#00b0c2] hover:bg-[#0097a7]/20 transition">
                                <UserRound size={20} />
                                Request Human Correction
                            </button>
                        </div>
                    )}
                </div>
            </main>
        </div>
    );
}

export default QuizPage;