import React from "react";
import {
    Code2, Brain, Smartphone, Database, Cloud, Cpu, ShieldCheck,
    Gamepad2, Monitor, ArrowRight,
} from "lucide-react";

// Presentation only - the database stores content, not styling. Keyed by
// title, so a roadmap nobody styled still renders with the default.
export const CARD_STYLES = {
    "Web Development":    { icon: <Code2 size={28} />,       color: "from-cyan-500 to-blue-500" },
    "AI / ML":            { icon: <Brain size={28} />,       color: "from-[#0097a7] to-green-400" },
    "Cybersecurity":      { icon: <ShieldCheck size={28} />, color: "from-red-500 to-rose-600" },
    "Mobile Development": { icon: <Smartphone size={28} />,  color: "from-yellow-400 to-orange-500" },
    "DevOps / Cloud":     { icon: <Cloud size={28} />,       color: "from-pink-500 to-rose-500" },
    "Embedded / IOT":     { icon: <Cpu size={28} />,         color: "from-orange-500 to-red-500" },
    "Desktop Apps":       { icon: <Monitor size={28} />,     color: "from-fuchsia-500 to-purple-600" },
    "Data Engineering":   { icon: <Database size={28} />,    color: "from-indigo-500 to-blue-600" },
    "Game Development":   { icon: <Gamepad2 size={28} />,    color: "from-lime-500 to-emerald-500" },
};

const DEFAULT_STYLE = {
    icon: <Code2 size={28} />,
    color: "from-gray-500 to-gray-700",
};

/**
 * One roadmap, as it appears on both the public landing page and the student
 * home page.
 *
 * Shared rather than duplicated because the two pages showing the same
 * roadmaps differently is exactly the drift that left the landing page
 * advertising three domains the platform does not have. Only the destination
 * differs: a guest is sent to sign up, a student to the roadmap itself.
 */
function RoadmapCard({ roadmap, onOpen, ctaLabel = "View Roadmap" }) {
    const style = CARD_STYLES[roadmap.title] ?? DEFAULT_STYLE;
    const available = Boolean(roadmap.latest_version);

    const open = () => {
        if (available) onOpen(roadmap);
    };

    return (
        // The whole card is the click target, not just the link.
        <div
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

            <h3 className="mt-6 text-xl font-bold">{roadmap.title}</h3>

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
                    available ? "text-[#0097a7] group-hover:gap-3" : "text-gray-600"
                }`}
            >
                {available ? ctaLabel : "Unavailable"}
                <ArrowRight size={16} />
            </span>
        </div>
    );
}

export default RoadmapCard;
