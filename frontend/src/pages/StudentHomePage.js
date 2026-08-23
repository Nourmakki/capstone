import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import api from "../api";
import {
    Code2, Brain, Smartphone, Database, Cloud, Cpu,
    Gamepad2, Monitor, ArrowRight,
} from "lucide-react";

// Presentation only - the database stores content, not styling.
const CARD_STYLES = {
    "Web Development":   { icon: <Code2 size={28} />,      color: "from-cyan-500 to-blue-500" },
    "AI / ML":           { icon: <Brain size={28} />,      color: "from-[#0097a7] to-green-400" },
    "Mobile Development":{ icon: <Smartphone size={28} />, color: "from-yellow-400 to-orange-500" },
    "DevOps / Cloud":    { icon: <Cloud size={28} />,      color: "from-pink-500 to-rose-500" },
    "Embedded / IOT":    { icon: <Cpu size={28} />,        color: "from-orange-500 to-red-500" },
    "Desktop Apps":      { icon: <Monitor size={28} />,    color: "from-fuchsia-500 to-purple-600" },
    "Data Engineering":  { icon: <Database size={28} />,   color: "from-indigo-500 to-blue-600" },
    "Game Development":  { icon: <Gamepad2 size={28} />,   color: "from-lime-500 to-emerald-500" },
};

const DEFAULT_STYLE = {
    icon: <Code2 size={28} />,
    color: "from-gray-500 to-gray-700",
};

function StudentHomePage() {
    const navigate = useNavigate();
    const [roadmaps, setRoadmaps] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        const loadRoadmaps = async () => {
            try {
                const response = await api.get("/roadmaps");
                setRoadmaps(response.data.data ?? []);
            } catch (err) {
                console.error(err);
                setError("Could not load roadmaps.");
            } finally {
                setLoading(false);
            }
        };

        loadRoadmaps();
    }, []);

    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.15),transparent_55%)] pointer-events-none" />

            <section className="pt-20 relative z-10 px-8 lg:px-20 pb-24">
                <div className="flex items-center justify-between mb-10">
                    <div>
                        <h2 className="text-4xl font-bold text-[#0097a7]">
                            Explore Software Engineering Domains
                        </h2>
                        <p className="text-gray-400 mt-2">
                            Structured learning paths for modern tech careers.
                        </p>
                    </div>
                </div>

                {loading && (
                    <div className="text-gray-400 py-20 text-center">
                        Loading roadmaps...
                    </div>
                )}

                {error && (
                    <div className="text-red-400 py-20 text-center">{error}</div>
                )}

                {!loading && !error && roadmaps.length === 0 && (
                    <div className="text-gray-400 py-20 text-center">
                        No roadmaps are available yet.
                    </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {roadmaps.map((roadmap) => {
                        const style = CARD_STYLES[roadmap.title] ?? DEFAULT_STYLE;
                        const available = Boolean(roadmap.latest_version);

                        const open = () => {
                            if (available) {
                                navigate(`/roadmap/${roadmap.id}`);
                            }
                        };

                        return (
                            // The whole card is the click target, not just the link.
                            <div
                                key={roadmap.id}
                                role="button"
                                tabIndex={available ? 0 : -1}
                                aria-disabled={!available}
                                onClick={open}
                                onKeyDown={(e) => {
                                    if (e.key === "Enter" || e.key === " ") {
                                        e.preventDefault();
                                        open();
                                    }
                                }}
                                className={`group relative rounded-3xl border border-white/10 bg-white/[0.03] backdrop-blur-md p-6 transition duration-300 overflow-hidden focus:outline-none focus:ring-2 focus:ring-[#0097a7]/50 ${
                                    available
                                        ? "cursor-pointer hover:border-[#0097a7]/40"
                                        : "cursor-not-allowed opacity-60"
                                }`}
                            >
                                <div className={`absolute inset-0 opacity-0 group-hover:opacity-20 transition bg-gradient-to-br ${style.color}`} />

                                <div className={`w-14 h-14 rounded-2xl flex items-center justify-center bg-gradient-to-br ${style.color}`}>
                                    {style.icon}
                                </div>

                                <h3 className="mt-6 text-xl font-bold">
                                    {roadmap.title}
                                </h3>

                                <p className="mt-3 text-sm text-gray-400 leading-relaxed">
                                    {roadmap.description}
                                </p>

                                <p className="mt-4 text-xs text-gray-500">
                                    {available
                                        ? `${roadmap.node_count} modules · v${roadmap.latest_version.version_number}`
                                        : "No published version yet"}
                                </p>

                                {/* Visual affordance only - the card itself handles the click. */}
                                <span
                                    className={`mt-6 text-sm flex items-center gap-2 transition-all ${
                                        available
                                            ? "text-[#0097a7] group-hover:gap-3"
                                            : "text-gray-600"
                                    }`}
                                >
                                    {available ? "View Roadmap" : "Unavailable"}
                                    <ArrowRight size={16} />
                                </span>
                            </div>
                        );
                    })}
                </div>
            </section>
        </div>
    );
}

export default StudentHomePage;