import {
    LayoutDashboard,
    BookOpen,
    CheckSquare,
    Users,
    Trophy,
    LogOut,
    Menu,
    UserRound,
} from "lucide-react";
import React, { useState } from "react";
import { useNavigate } from "react-router-dom";

import StudentHomePage from "./StudentHomePage";
import TodoPage from "./TodoPage";
import TeamsPage from "./TeamsPage";
import ContinueLearningPage from "./ContinueLearningPage";

function Dashboard() {
    const [activePage, setActivePage] = useState("dashboard");
    const [collapsed, setCollapsed] = useState(false);
    const navigate = useNavigate();

    const handleLogout = () => {
        localStorage.removeItem("user");
        navigate("/login");
    };

    return (
        <div className="min-h-screen bg-black text-white flex overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.08),transparent_55%)] pointer-events-none" />

            <aside
                className={`fixed top-0 left-0 h-screen z-20 border-r border-white/10 bg-black/40 backdrop-blur-xl flex flex-col px-6 pt-6 pb-6 transition-all duration-300 ${
                    collapsed ? "w-24" : "w-72"
                }`}
            >
                {collapsed ? (
                    <div className="flex flex-col items-center mb-6">
                        <button
                            onClick={() => setCollapsed(!collapsed)}
                            className="self-end p-2 rounded-lg hover:bg-white/10 transition"
                        >
                            <Menu size={22} />
                        </button>

                        <h1 className="mt-4 text-3xl font-black text-[#0097a7]">
                            CS
                        </h1>
                    </div>
                ) : (
                    <div className="mb-6">
                        <div className="flex justify-end">
                            <button
                                onClick={() => setCollapsed(!collapsed)}
                                className="p-2 rounded-lg hover:bg-white/10 transition"
                            >
                                <Menu size={22} />
                            </button>
                        </div>

                        <div className="mt-2">
                            <h1 className="text-3xl font-black text-[#0097a7]">
                                CS Mastery
                            </h1>

                            <p className="text-sm text-gray-500 mt-1">
                                Student Dashboard
                            </p>
                        </div>
                    </div>
                )}

                <div className="flex-1 flex flex-col justify-between overflow-y-auto">

                    <nav className="space-y-2">

                        <button
                            title="Dashboard"
                            onClick={() => setActivePage("dashboard")}
                            className={`w-full flex items-center ${
                                collapsed ? "justify-center" : "gap-3"
                            } px-4 py-2.5 rounded-2xl transition ${
                                activePage === "dashboard"
                                    ? "bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]"
                                    : "hover:bg-white/5 text-gray-300"
                            }`}
                        >
                            <LayoutDashboard size={20} />
                            {!collapsed && "Dashboard"}
                        </button>

                        <button
                            title="My Learning"
                            onClick={() => setActivePage("learning")}
                            className={`w-full flex items-center ${
                                collapsed ? "justify-center" : "gap-3"
                            } px-4 py-2.5 rounded-2xl transition ${
                                activePage === "learning"
                                    ? "bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]"
                                    : "hover:bg-white/5 text-gray-300"
                            }`}
                        >
                            <BookOpen size={20} />
                            {!collapsed && "My Learning"}
                        </button>

                        <button
                            title="To-Do List"
                            onClick={() => setActivePage("todo")}
                            className={`w-full flex items-center ${
                                collapsed ? "justify-center" : "gap-3"
                            } px-4 py-2.5 rounded-2xl transition ${
                                activePage === "todo"
                                    ? "bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]"
                                    : "hover:bg-white/5 text-gray-300"
                            }`}
                        >
                            <CheckSquare size={20} />
                            {!collapsed && "To-Do List"}
                        </button>

                        <button
                            title="Teams"
                            onClick={() => setActivePage("teams")}
                            className={`w-full flex items-center ${
                                collapsed ? "justify-center" : "gap-3"
                            } px-4 py-2.5 rounded-2xl transition ${
                                activePage === "teams"
                                    ? "bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]"
                                    : "hover:bg-white/5 text-gray-300"
                            }`}
                        >
                            <Users size={20} />
                            {!collapsed && "Teams"}
                        </button>

                        <button
                            title="My Profile"
                            onClick={() => navigate("/profile")}
                            className={`w-full flex items-center ${
                                collapsed ? "justify-center" : "gap-3"
                            } px-4 py-2.5 rounded-2xl transition hover:bg-white/5 text-gray-300`}
                        >
                            <UserRound size={20} />
                            {!collapsed && "My Profile"}
                        </button>

                    </nav>

                    <div className="mt-6 space-y-4">
                                                {!collapsed && (
                            <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-5">
                                <div className="flex items-center gap-2 mb-3">
                                    <Trophy
                                        size={18}
                                        className="text-[#0097a7]"
                                    />
                                    <h3 className="font-semibold">
                                        Overall Progress
                                    </h3>
                                </div>

                                <div className="w-full h-3 bg-gray-800 rounded-full overflow-hidden">
                                    <div className="w-[48%] h-full bg-gradient-to-r from-blue-400 to-[#0097a7] rounded-full" />
                                </div>

                                <p className="mt-3 text-gray-400">
                                    48% Completed
                                </p>
                            </div>
                        )}

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
                className={`relative z-10 flex-1 overflow-y-auto transition-all duration-300 ${
                    collapsed ? "ml-24" : "ml-72"
                }`}
            >
                {activePage === "dashboard" && <StudentHomePage />}
                {activePage === "learning" && <ContinueLearningPage />}
                {activePage === "todo" && <TodoPage />}
                {activePage === "teams" && <TeamsPage />}
            </main>
        </div>
    );
}

export default Dashboard;