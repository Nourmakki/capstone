import React, { useCallback, useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import api from "../api";
import { useAuth } from "../context/AuthContext";
import {
    LayoutDashboard, Users, LogOut, Menu, X, ChartNoAxesColumnIncreasing,
} from "lucide-react";

import AdminOverview from "./admin/AdminOverview";
import AdminProfessors from "./admin/AdminProfessors";
import AdminStatistics from "./admin/AdminStatistics";

function AdminDashboard() {
    const navigate = useNavigate();
    const { logout } = useAuth();

    const [activePage, setActivePage] = useState("overview");
    const [collapsed, setCollapsed] = useState(false);

    // Below lg the sidebar is an off-canvas drawer: a fixed 288px sidebar
    // leaves a 375px viewport 87px to live in.
    const [drawerOpen, setDrawerOpen] = useState(false);

    const go = (page) => {
        setActivePage(page);
        setDrawerOpen(false);
    };
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
        { id: "statistics", label: "Statistics", icon: <ChartNoAxesColumnIncreasing size={20} /> },
        { id: "professors", label: "Professors", icon: <Users size={20} />, badge: stats.pendingProfessors },
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

            <aside className={`fixed top-0 left-0 h-screen z-40 border-r border-white/10 bg-black/40 backdrop-blur-xl flex flex-col px-6 pt-6 pb-6 transition-all duration-300 w-72 ${
                collapsed ? "lg:w-24" : "lg:w-72"
            } ${drawerOpen ? "translate-x-0" : "-translate-x-full"} lg:translate-x-0`}>
                <div className="shrink-0 mb-6">
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
                        <div className="mt-2">
                            <div className="flex items-center gap-2.5">
                                <img src="/logo-mark.png" alt="" className="w-9 h-9 shrink-0 object-contain" />
                                <h1 className="text-3xl font-black text-[#0097a7]">CS Mastery</h1>
                            </div>
                            <p className="text-sm text-gray-500 mt-1">Admin Panel</p>
                        </div>
                    )}
                </div>

                <div className="flex-1 min-h-0 flex flex-col">
                    <nav className="flex-1 min-h-0 overflow-y-auto overscroll-contain space-y-2 pr-1">
                        {navItems.map((item) => (
                            <button key={item.id} title={item.label}
                                onClick={() => go(item.id)}
                                className={`w-full flex items-center ${
                                    collapsed ? "justify-center px-0" : "gap-3 px-4"
                                } py-2.5 rounded-2xl transition ${
                                    activePage === item.id
                                        ? "bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]"
                                        : "hover:bg-white/5 text-gray-300"
                                }`}>
                                <span className="shrink-0">{item.icon}</span>
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

            <main className={`relative z-10 flex-1 overflow-y-auto transition-all duration-300 ml-0 pt-16 lg:pt-0 ${
                collapsed ? "lg:ml-24" : "lg:ml-72"
            }`}>
                {activePage === "overview" && <AdminOverview stats={stats} onGo={setActivePage} />}
                {activePage === "statistics" && <AdminStatistics />}
                {activePage === "professors" && <AdminProfessors onChanged={loadStats} />}
            </main>
        </div>
    );
}

export default AdminDashboard;
