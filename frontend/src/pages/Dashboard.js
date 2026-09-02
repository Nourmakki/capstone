import {
    LayoutDashboard,
    BookOpen,
    CheckSquare,
    Users,
    LogOut,
    Menu,
    X,
    UserRound,
    CalendarDays,
} from "lucide-react";
import React, { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";

import StudentHomePage from "./StudentHomePage";
import TodoPage from "./TodoPage";
import TeamsPage from "./TeamsPage";
import ContinueLearningPage from "./ContinueLearningPage";
import ProfilePage from "./ProfilePage";
import WorkshopsPage from "./WorkshopsPage";

function Dashboard() {
    const [activePage, setActivePage] = useState("dashboard");
    const [collapsed, setCollapsed] = useState(false);

    // Below lg the sidebar is an off-canvas drawer: at 375px a fixed 288px
    // sidebar leaves the page 87px to live in.
    const [drawerOpen, setDrawerOpen] = useState(false);

    const navigate = useNavigate();

    // Picking a destination should also get the drawer out of the way.
    const go = (page) => {
        setActivePage(page);
        setDrawerOpen(false);
    };

    const { logout } = useAuth();
    const handleLogout = async () => {
        await logout();
        navigate("/login");
    };

    return (
        <div className="min-h-screen bg-black text-white flex overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.15),transparent_55%)] pointer-events-none" />

            {/* Phone header. The sidebar is off screen at this width, so this
                is the only way back to it. */}
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

            {/* Tapping beside the drawer closes it - the usual way out. */}
            {drawerOpen && (
                <div
                    onClick={() => setDrawerOpen(false)}
                    className="lg:hidden fixed inset-0 z-30 bg-black/60 backdrop-blur-sm"
                />
            )}

            <aside
                className={`fixed top-0 left-0 h-screen z-40 border-r border-white/10 bg-black/40 backdrop-blur-xl flex flex-col px-6 pt-6 pb-6 transition-all duration-300 w-72 ${
                    collapsed ? "lg:w-24" : "lg:w-72"
                } ${drawerOpen ? "translate-x-0" : "-translate-x-full"} lg:translate-x-0`}
            >
                {collapsed ? (
                    <div className="shrink-0 flex flex-col items-center mb-6">
                        <button
                            onClick={() => setCollapsed(!collapsed)}
                            className="hidden lg:block self-end p-2 rounded-lg hover:bg-white/10 transition"
                        >
                            <Menu size={22} />
                        </button>

                        <h1 className="mt-4 text-3xl font-black text-[#0097a7]">
                            CS
                        </h1>
                    </div>
                ) : (
                    <div className="shrink-0 mb-6">
                        <div className="flex justify-end">
                            {/* Collapsing is a desktop idea; on a phone the
                                drawer is either open or gone. */}
                            <button
                                onClick={() => setCollapsed(!collapsed)}
                                className="hidden lg:block p-2 rounded-lg hover:bg-white/10 transition"
                            >
                                <Menu size={22} />
                            </button>

                            <button
                                onClick={() => setDrawerOpen(false)}
                                aria-label="Close menu"
                                className="lg:hidden p-2 rounded-lg hover:bg-white/10 transition"
                            >
                                <X size={22} />
                            </button>
                        </div>

                        <div className="mt-2">
                            <div className="flex items-center gap-2.5">
                                <img src="/logo-mark.png" alt="" className="w-9 h-9 shrink-0 object-contain" />

                                <h1 className="text-3xl font-black text-[#0097a7]">
                                    CS Mastery
                                </h1>
                            </div>

                            <p className="text-sm text-gray-500 mt-1">
                                Student Dashboard
                            </p>
                        </div>
                    </div>
                )}

                <div className="flex-1 min-h-0 flex flex-col">

                    <nav className="flex-1 min-h-0 overflow-y-auto overscroll-contain space-y-2 pr-1">

                        <button
                            title="Dashboard"
                            onClick={() => go("dashboard")}
                            className={`w-full flex items-center ${
                                collapsed ? "justify-center px-0" : "gap-3 px-4"
                            } py-2.5 rounded-2xl transition ${
                                activePage === "dashboard"
                                    ? "bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]"
                                    : "hover:bg-white/5 text-gray-300"
                            }`}
                        >
                            <LayoutDashboard size={20} className="shrink-0" />
                            {!collapsed && "Dashboard"}
                        </button>

                        <button
                            title="My Learning"
                            onClick={() => go("learning")}
                            className={`w-full flex items-center ${
                                collapsed ? "justify-center px-0" : "gap-3 px-4"
                            } py-2.5 rounded-2xl transition ${
                                activePage === "learning"
                                    ? "bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]"
                                    : "hover:bg-white/5 text-gray-300"
                            }`}
                        >
                            <BookOpen size={20} className="shrink-0" />
                            {!collapsed && "My Learning"}
                        </button>

                        <button
                            title="To-Do List"
                            onClick={() => go("todo")}
                            className={`w-full flex items-center ${
                                collapsed ? "justify-center px-0" : "gap-3 px-4"
                            } py-2.5 rounded-2xl transition ${
                                activePage === "todo"
                                    ? "bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]"
                                    : "hover:bg-white/5 text-gray-300"
                            }`}
                        >
                            <CheckSquare size={20} className="shrink-0" />
                            {!collapsed && "To-Do List"}
                        </button>

                        <button
                            title="Teams"
                            onClick={() => go("teams")}
                            className={`w-full flex items-center ${
                                collapsed ? "justify-center px-0" : "gap-3 px-4"
                            } py-2.5 rounded-2xl transition ${
                                activePage === "teams"
                                    ? "bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]"
                                    : "hover:bg-white/5 text-gray-300"
                            }`}
                        >
                            <Users size={20} className="shrink-0" />
                            {!collapsed && "Teams"}
                        </button>

                        <button
                            title="Workshops"
                            onClick={() => go("workshops")}
                            className={`w-full flex items-center ${
                                collapsed ? "justify-center px-0" : "gap-3 px-4"
                            } py-2.5 rounded-2xl transition ${
                                activePage === "workshops"
                                    ? "bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]"
                                    : "hover:bg-white/5 text-gray-300"
                            }`}
                        >
                            <CalendarDays size={20} className="shrink-0" />
                            {!collapsed && "Workshops"}
                        </button>

                        <button
                            title="My Profile"
                            onClick={() => go("profile")}
                            className={`w-full flex items-center ${
                                collapsed ? "justify-center px-0" : "gap-3 px-4"
                            } py-2.5 rounded-2xl transition ${
                                activePage === "profile"
                                    ? "bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]"
                                    : "hover:bg-white/5 text-gray-300"
                            }`}
                        >
                            <UserRound size={20} className="shrink-0" />
                            {!collapsed && "My Profile"}
                        </button>

                    </nav>
                    <div className="shrink-0 mt-6 space-y-4">
                        <button
                            title="Logout"
                            onClick={handleLogout}
                            className={`w-full flex items-center ${
                                collapsed
                                    ? "justify-center"
                                    : "justify-center gap-3"
                            } py-3 rounded-2xl border border-red-500/20 bg-red-500/5 text-red-400 hover:bg-red-500/10 hover:border-red-500/40 transition`}
                        >
                            <LogOut size={18} />
                            {!collapsed && "Logout"}
                        </button>
                    </div>
                </div>
            </aside>

            <main
                /* No left margin below lg - the sidebar is off screen there -
                   but room for the fixed phone header. */
                className={`relative z-10 flex-1 overflow-y-auto transition-all duration-300 ml-0 pt-16 lg:pt-0 ${
                    collapsed ? "lg:ml-24" : "lg:ml-72"
                }`}
            >
                {activePage === "dashboard" && <StudentHomePage />}
                {activePage === "learning" && <ContinueLearningPage />}
                {activePage === "todo" && <TodoPage />}
                {activePage === "teams" && <TeamsPage />}
                {activePage === "workshops" && <WorkshopsPage />}
                {activePage === "profile" && <ProfilePage />}
            </main>
        </div>
    );
}

export default Dashboard;