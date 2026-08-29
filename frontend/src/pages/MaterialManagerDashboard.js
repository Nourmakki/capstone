import React, { useCallback, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import api from "../api";
import { useAuth } from "../context/AuthContext";
import {
    Bot, ClipboardCheck, History, UserRound, LogOut, Menu, X, Map, Video,
    Sparkles,
    Pencil,
} from "lucide-react";

import MaterialAiReview from "./material/MaterialAiReview";
import MaterialQueue from "./material/MaterialQueue";
import MaterialHistory from "./material/MaterialHistory";
import AdminRoadmaps from "./admin/AdminRoadmaps";
import AdminTutorials from "./admin/AdminTutorials";
import ProfilePage from "./ProfilePage";
import MaterialLearningReview from "./material/MaterialLearningReview";
import MaterialEditor from "./material/MaterialEditor";

function MaterialManagerDashboard() {
    const navigate = useNavigate();
    const { logout } = useAuth();

    const [activePage, setActivePage] = useState("ai");
    const [collapsed, setCollapsed] = useState(false);

    // Below lg the sidebar is an off-canvas drawer: a fixed 288px sidebar
    // leaves a 375px viewport 87px to live in.
    const [drawerOpen, setDrawerOpen] = useState(false);

    const go = (page) => {
        setActivePage(page);
        setDrawerOpen(false);
    };
    const [pending, setPending] = useState(0);
    const [awaitingAi, setAwaitingAi] = useState(0);

    const loadStats = useCallback(async () => {
        try {
            const [material, ai] = await Promise.allSettled([
                api.get("/material/stats"),
                api.get("/ai-workflows"),
            ]);

            if (material.status === "fulfilled") {
                setPending(material.value.data.total_pending ?? 0);
            }

            // The AI service may be offline; the badge just stays at zero.
            if (ai.status === "fulfilled") {
                setAwaitingAi(ai.value.data.awaiting_review ?? 0);
            }
        } catch (err) {
            console.error(err);
        }
    }, []);

    useEffect(() => { loadStats(); }, [loadStats]);

    const handleLogout = async () => {
        await logout();
        navigate("/login");
    };

    const navItems = [
        { id: "ai", label: "AI Proposals", icon: <Bot size={20} />, badge: awaitingAi },
        // Roadmap shape comes from AI Proposals; what fills each module comes
        // from here.
        { id: "learning", label: "Learning Material", icon: <Sparkles size={20} /> },
        // Fixing what is already on a module, AI-written or not.
        { id: "edit", label: "Edit Material", icon: <Pencil size={20} /> },
        { id: "queue", label: "Content Queue", icon: <ClipboardCheck size={20} />, badge: pending },
        { id: "roadmaps", label: "Roadmaps", icon: <Map size={20} /> },
        { id: "tutorials", label: "Tutorials", icon: <Video size={20} /> },
        { id: "history", label: "History", icon: <History size={20} /> },
        { id: "profile", label: "My Profile", icon: <UserRound size={20} /> },
    ];

    return (
        <div className="min-h-screen bg-black text-white flex overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.15),transparent_55%)] pointer-events-none" />

            {/* Phone header. The sidebar is off screen at this width. */}
            <header className="lg:hidden fixed top-0 left-0 right-0 z-30 flex items-center gap-3 px-4 h-16 border-b border-white/10 bg-black/80 backdrop-blur-xl">
                <button
                    onClick={() => setDrawerOpen(true)}
                    aria-label="Open menu"
                    className="p-2 rounded-lg hover:bg-white/10 transition"
                >
                    <Menu size={22} />
                </button>

                <img src="/logo-mark.png" alt="" className="w-8 h-8 object-contain" />
                <h1 className="text-xl font-black text-[#0097a7]">CS Mastery</h1>
            </header>

            {drawerOpen && (
                <div
                    onClick={() => setDrawerOpen(false)}
                    className="lg:hidden fixed inset-0 z-30 bg-black/60 backdrop-blur-sm"
                />
            )}

            <aside className={`fixed top-0 left-0 h-screen z-40 border-r border-white/10 bg-black/40 backdrop-blur-xl flex flex-col px-6 pt-6 pb-6 short:pt-4 short:pb-4 shorter:pt-3 shorter:pb-3 transition-all duration-300 w-72 ${
                collapsed ? "lg:w-24" : "lg:w-72"
            } ${drawerOpen ? "translate-x-0" : "-translate-x-full"} lg:translate-x-0`}>
                <div className="shrink-0 mb-6 short:mb-4 shorter:mb-2">
                    <div className="flex justify-end">
                        <button onClick={() => setCollapsed(!collapsed)}
                            className="hidden lg:block p-2 rounded-lg hover:bg-white/10 transition">
                            <Menu size={22} />
                        </button>

                        <button onClick={() => setDrawerOpen(false)}
                            aria-label="Close menu"
                            className="lg:hidden p-2 rounded-lg hover:bg-white/10 transition">
                            <X size={22} />
                        </button>
                    </div>

                    {collapsed ? (
                        <div className="flex flex-col items-center mt-2">
                            <img src="/logo-mark.png" alt="CS Mastery" className="w-8 h-8 object-contain" />
                        </div>
                    ) : (
                        <div className="mt-2 shorter:mt-0">
                            {/* Beside the title, not above it: stacking would add
                                ~48 px back to a sidebar that was just trimmed to
                                fit short screens. */}
                            <div className="flex items-center gap-2.5">
                                <img src="/logo-mark.png" alt="" className="w-9 h-9 shrink-0 object-contain" />
                                <h1 className="text-3xl short:text-2xl font-black text-[#0097a7]">CS Mastery</h1>
                            </div>

                            {/* The role is a nicety, not a control. On a short
                                screen the eight nav items need the space more. */}
                            <p className="text-sm text-gray-500 mt-1 shorter:hidden">Material Manager</p>
                        </div>
                    )}
                </div>

                <div className="flex-1 min-h-0 flex flex-col">
                    {/* The items share out whatever height is left rather than
                        each claiming a fixed 46px. On a tall screen they settle
                        at their max and nothing moves; on a short one they give
                        up padding down to 36px before the nav has to scroll. */}
                    <nav className="flex-1 min-h-0 overflow-y-auto overscroll-contain flex flex-col gap-2 shorter:gap-1 pr-1">
                        {navItems.map((item) => (
                            <button key={item.id} title={item.label}
                                onClick={() => go(item.id)}
                                className={`w-full flex-1 min-h-[36px] max-h-[46px] flex items-center ${
                                    collapsed ? "justify-center" : "gap-3"
                                } px-4 rounded-2xl transition ${
                                    activePage === item.id
                                        ? "bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]"
                                        : "hover:bg-white/5 text-gray-300"
                                }`}>
                                {item.icon}
                                {!collapsed && (
                                    <span className="flex-1 flex items-center justify-between">
                                        {item.label}
                                        {item.badge > 0 && (
                                            <span className="text-xs px-2 py-0.5 rounded-full bg-yellow-400/20 text-yellow-300">
                                                {item.badge}
                                            </span>
                                        )}
                                    </span>
                                )}
                            </button>
                        ))}
                    </nav>

                    <div className="shrink-0 mt-6 short:mt-4 shorter:mt-2">
                        <button title="Logout" onClick={handleLogout}
                            className={`w-full flex items-center ${
                                collapsed ? "justify-center" : "justify-center gap-3"
                            } py-3 short:py-2.5 rounded-2xl border border-red-500/20 bg-red-500/5 text-red-400 hover:bg-red-500/10 hover:border-red-500/40 transition`}>
                            <LogOut size={18} />
                            {!collapsed && "Logout"}
                        </button>
                    </div>
                </div>
            </aside>

            <main className={`relative z-10 flex-1 overflow-y-auto transition-all duration-300 ml-0 pt-16 lg:pt-0 ${
                collapsed ? "lg:ml-24" : "lg:ml-72"
            }`}>
                {activePage === "ai" && <MaterialAiReview onChanged={loadStats} />}
                {activePage === "learning" && <MaterialLearningReview onChanged={loadStats} />}
                {activePage === "edit" && <MaterialEditor onChanged={loadStats} />}
                {activePage === "queue" && <MaterialQueue onChanged={loadStats} />}
                {activePage === "roadmaps" && <AdminRoadmaps onChanged={loadStats} />}
                {activePage === "tutorials" && <AdminTutorials onChanged={loadStats} />}
                {activePage === "history" && <MaterialHistory />}
                {activePage === "profile" && <ProfilePage />}
            </main>
        </div>
    );
}

export default MaterialManagerDashboard;
