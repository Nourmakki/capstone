import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import api from "../api";
import RoadmapCard from "../components/RoadmapCard";
import { ArrowRight, Sparkles, Code2, Users } from "lucide-react";

const FEATURES = [
    {
        icon: <Sparkles size={22} />,
        title: "AI guided learning",
        text: "The AI proposes the roadmap, picks the tutorials and writes the "
            + "quizzes and projects. A material manager approves every piece "
            + "before a student ever sees it.",
    },
    {
        icon: <Code2 size={22} />,
        title: "Finish the work to move on",
        text: "Watch the tutorial, pass the quiz, hand in the project. A module "
            + "unlocks the next one only once it is genuinely done.",
    },
    {
        icon: <Users size={22} />,
        title: "Graded by AI and by people",
        text: "Submissions are assessed automatically, and a professor can be "
            + "asked for a human review of any one of them.",
    },
];

function HomePage() {
    const navigate = useNavigate();

    const [roadmaps, setRoadmaps] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        // The public list: approved roadmaps with a published version, the same
        // set a signed-in student sees. Hard-coding these is what left the page
        // advertising three domains the platform does not have.
        api.get("/public/roadmaps")
            .then((response) => setRoadmaps(response.data.data ?? []))
            .catch((err) => console.error(err))
            .finally(() => setLoading(false));
    }, []);

    return (
        // No overflow-hidden here: any non-visible overflow on an ancestor
        // stops the nav below from sticking.
        <div className="min-h-screen bg-black text-white">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.15),transparent_55%)] pointer-events-none" />

            <nav className="sticky top-0 z-50 flex items-center justify-between gap-4 px-6 lg:px-8 py-5 border-b border-white/10 bg-black/80 backdrop-blur-md">
                <div className="flex items-center gap-3 min-w-0">
                    <img
                        src="/logo-mark.png"
                        alt=""
                        className="w-10 h-10 shrink-0 object-contain"
                    />

                    <div className="min-w-0">
                        <h1 className="text-2xl font-bold tracking-wide text-[#0097a7]">
                            CS Mastery
                        </h1>
                        <p className="hidden sm:block text-sm text-gray-400">
                            Structured CS Learning Platform
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-3 shrink-0">
                    <button
                        onClick={() => navigate("/login")}
                        className="px-5 py-2 rounded-xl border border-[#0097a7]/30 text-[#00b0c2] hover:bg-[#0097a7]/10 transition"
                    >
                        Login
                    </button>
                    <button
                        onClick={() => navigate("/signup")}
                        className="px-5 py-2 rounded-xl bg-[#0097a7] text-black font-semibold hover:scale-105 transition"
                    >
                        Sign Up
                    </button>
                </div>
            </nav>

            <section className="relative z-10 px-8 lg:px-20 pt-20 pb-16">
                <div className="max-w-4xl">
                    <div className="inline-block px-4 py-1 rounded-full border border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2] text-sm mb-6">
                        AI-guided computer science roadmaps
                    </div>

                    <h1 className="text-4xl sm:text-5xl lg:text-7xl font-black leading-tight">
                        Learn computer science
                        <span className="block text-[#0097a7]">
                            in the right order.
                        </span>
                    </h1>

                    <p className="mt-6 text-lg text-gray-400 max-w-2xl leading-relaxed">
                        Every roadmap is a sequence of modules, each with a tutorial,
                        a quiz and a project. Finish one to unlock the next, and
                        track exactly how far along you are.
                    </p>

                    {/* One call to action. Returning users have Login in the
                        nav, so a second button here only competes with this. */}
                    <div className="mt-10">
                        <button
                            onClick={() => navigate("/signup")}
                            className="flex items-center gap-2 px-7 py-4 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-105 transition"
                        >
                            Start learning <ArrowRight size={18} />
                        </button>
                    </div>
                </div>
            </section>

            <section className="relative z-10 px-8 lg:px-20 pb-24">
                <div className="mb-10">
                    <h2 className="text-3xl lg:text-4xl font-black text-[#0097a7]">
                        Explore Software Engineering Domains
                    </h2>
                    <p className="text-gray-400 mt-2">
                        Structured learning paths for modern tech careers.
                    </p>
                </div>

                {loading && (
                    <div className="text-gray-400 py-20 text-center">
                        Loading roadmaps...
                    </div>
                )}

                {!loading && roadmaps.length === 0 && (
                    <div className="text-gray-400 py-20 text-center">
                        No roadmaps are available yet.
                    </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {roadmaps.map((roadmap) => (
                        <RoadmapCard
                            key={roadmap.id}
                            roadmap={roadmap}
                            // A guest has nothing to open yet - the roadmap is
                            // behind an account, so the card sells the sign-up.
                            onOpen={() => navigate("/signup")}
                            ctaLabel="Start this roadmap"
                        />
                    ))}
                </div>
            </section>

            {/* Deliberately not cards. These were bordered, filled and
                rounded exactly like a roadmap, with the same icon tile - three
                more of them under a grid of roadmaps read as three more
                roadmaps, and nothing about them invited a click that would
                never come. Now they are what they are: a note about how the
                platform works, set as text under a rule. */}
            <section className="relative z-10 px-8 lg:px-20 pb-28">
                <p className="text-xs uppercase tracking-[0.2em] text-gray-500 mb-8">
                    How it works
                </p>

                <div className="grid lg:grid-cols-3 gap-10 lg:gap-14">
                    {FEATURES.map((feature) => (
                        <div key={feature.title} className="border-t border-white/10 pt-6">
                            <div className="flex items-center gap-3 text-[#00b0c2]">
                                {feature.icon}

                                <h3 className="text-lg font-semibold text-white">
                                    {feature.title}
                                </h3>
                            </div>

                            <p className="text-gray-400 leading-relaxed mt-3 text-sm">
                                {feature.text}
                            </p>
                        </div>
                    ))}
                </div>
            </section>

            <footer className="relative z-10 border-t border-white/10 px-8 lg:px-20 py-8 flex items-center text-sm text-gray-500">
                <div className="flex items-center gap-2">
                    <img src="/logo-mark.png" alt="" className="w-6 h-6 object-contain" />
                    CS Mastery
                </div>
            </footer>
        </div>
    );
}

export default HomePage;
