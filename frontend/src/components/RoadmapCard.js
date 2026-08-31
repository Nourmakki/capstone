import React from "react";
import {
    Code2, Brain, Smartphone, Database, Cloud, Cpu, ShieldCheck,
    Gamepad2, Monitor, Layout, Server, Layers, Network, Sparkles, Terminal,
    Activity, ArrowRight,
} from "lucide-react";

// Presentation only - the database stores content, not styling. Keyed by
// title, so the roadmaps we know about keep a deliberate look.
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
    "Frontend":           { icon: <Layout size={28} />,      color: "from-sky-400 to-cyan-500" },
    "Backend":            { icon: <Server size={28} />,      color: "from-violet-500 to-indigo-600" },
    "Full Stack":         { icon: <Layers size={28} />,      color: "from-emerald-400 to-teal-500" },

    // Specializations under AI / ML
    "Machine Learning":     { icon: <Brain size={28} />,      color: "from-[#0097a7] to-green-400" },
    "Deep Learning":        { icon: <Network size={28} />,    color: "from-purple-500 to-indigo-600" },
    "Generative AI":        { icon: <Sparkles size={28} />,   color: "from-fuchsia-500 to-pink-600" },

    // Specializations under DevOps / Cloud
    "Platform Foundations": { icon: <Terminal size={28} />,   color: "from-slate-400 to-slate-600" },
    "Cloud Infrastructure": { icon: <Cloud size={28} />,      color: "from-sky-400 to-blue-600" },
    "Site Reliability":     { icon: <Activity size={28} />,   color: "from-amber-400 to-orange-500" },
};

/**
 * Every roadmap gets a colour, including ones nobody has styled.
 *
 * The map above cannot keep up: specializations are created by managers and
 * roadmaps by the AI, so a hard-coded list means anything new lands grey and
 * looks broken next to the rest. Picking from a palette by title keeps that
 * from happening, and keying on the title rather than the id means a roadmap
 * looks the same wherever it appears and after any reseed.
 */
const PALETTE = [
    "from-cyan-500 to-blue-500",
    "from-[#0097a7] to-green-400",
    "from-violet-500 to-indigo-600",
    "from-amber-400 to-orange-500",
    "from-emerald-400 to-teal-500",
    "from-rose-500 to-pink-600",
    "from-sky-400 to-cyan-500",
    "from-lime-500 to-emerald-500",
];

const paletteFor = (title = "") => {
    let hash = 0;

    for (let i = 0; i < title.length; i++) {
        hash = (hash * 31 + title.charCodeAt(i)) | 0;
    }

    return PALETTE[Math.abs(hash) % PALETTE.length];
};

const styleFor = (roadmap) =>
    CARD_STYLES[roadmap.title] ?? {
        icon: <Code2 size={28} />,
        color: paletteFor(roadmap.title),
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
    const style = styleFor(roadmap);

    // A parent has no modules of its own - it is a choice between
    // specializations, so it is available on the strength of its children.
    const children = roadmap.children ?? [];
    const isChooser = children.length > 0;

    // But only as available as its paths actually are. Having children is not
    // the same as having somewhere to go: a parent whose topics are all
    // unpublished opened onto a page of "Unavailable" cards, which is worse
    // than the greyed-out card it replaced, because the student has to click
    // to find out there is nothing there.
    const openablePaths = children.filter((child) => child.latest_version);

    const available = isChooser
        ? openablePaths.length > 0
        : Boolean(roadmap.latest_version);

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
                {isChooser
                    ? available
                        // Every path is named, including the ones not open yet:
                        // this line describes the page behind the card, and that
                        // page shows them all, greying out what is not ready.
                        ? `${children.length} paths · ${children.map((c) => c.title).join(", ")}`
                        : "No published paths yet"
                    : available
                    ? `${roadmap.node_count} modules · v${roadmap.latest_version.version_number}`
                    : "No published version yet"}
            </p>

            {/* Visual affordance only - the card itself handles the click. */}
            <span
                className={`mt-6 text-sm flex items-center gap-2 transition-all ${
                    available ? "text-[#0097a7] group-hover:gap-3" : "text-gray-600"
                }`}
            >
                {isChooser ? "Choose a path" : available ? ctaLabel : "Unavailable"}
                <ArrowRight size={16} />
            </span>
        </div>
    );
}

export default RoadmapCard;
