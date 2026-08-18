import React, { useCallback, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import api from "../api";
import { useAuth } from "../context/AuthContext";
import {
    LayoutDashboard, Users, Map, LogOut, Menu, ShieldCheck,
} from "lucide-react";

import AdminOverview from "./admin/AdminOverview";
import AdminProfessors from "./admin/AdminProfessors";
import AdminRoadmaps from "./admin/AdminRoadmaps";

function AdminDashboard() {
    const navigate = useNavigate();
    const { logout } = useAuth();

    const [activePage, setActivePage] = useState("overview");
    const [collapsed, setCollapsed] = useState(false);
    const [stats, setStats] = useState({});

    const loadStats = useCallback(async () => {
        try {
            const response = await api.get("/admin/dashboard-stats");
            setStats(response.data);
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
        { id: "overview", label: "Overview", icon: <LayoutDashboard size={20} /> },
        { id: "professors", label: "Professors", icon: <Users size={20} />, badge: stats.pendingProfessors },
        { id: "roadmaps", label: "Roadmaps", icon: <Map size={20} /> },
    ];

    return (
        <div className="min-h-screen bg-black text-white flex overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.08),transparent_55%)] pointer-events-none" />

            <aside className={`fixed top-0 left-0 h-screen z-20 border-r border-white/10 bg-black/40 backdrop-blur-xl flex flex-col px-6 pt-6 pb-6 transition-all duration-300 ${
                collapsed ? "w-24" : "w-72"
            }`}>
                <div className="shrink-0 mb-6">
                    <div className="flex justify-end">
                        <button onClick={() => setCollapsed(!collapsed)}
                            className="p-2 rounded-lg hover:bg-white/10 transition">
                            <Menu size={22} />
                        </button>
                    </div>

                    {collapsed ? (
                        <div className="flex flex-col items-center mt-2">
                            <ShieldCheck size={28} className="text-[#0097a7]" />
                        </div>
                    ) : (
                        <div className="mt-2">
                            <h1 className="text-3xl font-black text-[#0097a7]">CS Mastery</h1>
                            <p className="text-sm text-gray-500 mt-1">Admin Panel</p>
                        </div>
                    )}
                </div>

                <div className="flex-1 min-h-0 flex flex-col">
                    <nav className="flex-1 min-h-0 overflow-y-auto overscroll-contain space-y-2 pr-1">
                        {navItems.map((item) => (
                            <button key={item.id} title={item.label}
                                onClick={() => setActivePage(item.id)}
                                className={`w-full flex items-center ${
                                    collapsed ? "justify-center" : "gap-3"
                                } px-4 py-2.5 rounded-2xl transition ${
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

                    <div className="shrink-0 mt-6">
                        <button title="Logout" onClick={handleLogout}
                            className={`w-full flex items-center ${
                                collapsed ? "justify-center" : "justify-center gap-3"
                            } py-3 rounded-2xl border border-red-500/20 bg-red-500/5 text-red-400 hover:bg-red-500/10 hover:border-red-500/40 transition`}>
                            <LogOut size={18} />
                            {!collapsed && "Logout"}
                        </button>
                    </div>
                </div>
            </aside>

            <main className={`relative z-10 flex-1 overflow-y-auto transition-all duration-300 ${
                collapsed ? "ml-24" : "ml-72"
            }`}>
                {activePage === "overview" && <AdminOverview stats={stats} onGo={setActivePage} />}
                {activePage === "professors" && <AdminProfessors onChanged={loadStats} />}
                {activePage === "roadmaps" && <AdminRoadmaps onChanged={loadStats} />}
            </main>
        </div>
    );
}

export default AdminDashboard;
