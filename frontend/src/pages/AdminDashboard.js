import React from "react";
import { useState, useEffect } from "react";
import api from "../api";
import {
    ShieldCheck,
    UserCheck,
    UserX,
    Users,
} from "lucide-react";

function AdminDashboard() {
    const [professorRequests, setProfessorRequests] = useState([]);

    const [stats, setStats] = useState({
        pendingProfessors: 0,
        approvedProfessors: 0,
        admins: 0,
    });

    useEffect(() => {
        loadStats();
    }, []);
    const loadStats = async () => {
        try {
            const response = await api.get("/admin/dashboard-stats");
            setStats(response.data);
        } catch (error) {
            console.error(error);
        }
    };
    
    useEffect(() => {
        loadProfessorRequests();
    }, []);
    const loadProfessorRequests = async () => {
        try {
            const response = await api.get(
                "/admin/professor-requests"
            );
            setProfessorRequests(response.data);
        } catch (error) {
            console.error(error);
        }
    };

    const handleApprove = async (id) => {
        try {
            await api.put(
                `/admin/approve-professor/${id}`
            );
            alert("Professor approved.");
            loadProfessorRequests();
        } catch (error) {
            console.error(error);
            alert("Unable to approve professor.");
        }
    };

    const handleReject = async (id) => {
        try {
            await api.delete(
                `/admin/reject-professor/${id}`
            );
            alert("Professor rejected.");
            loadProfessorRequests();
        } catch (error) {
            console.error(error);
            alert("Unable to reject professor.");
        }
    };

    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.08),transparent_55%)] pointer-events-none" />
            <header className="relative z-10 px-8 lg:px-20 py-10 border-b border-white/10">
                <div className="flex items-center gap-4">
                    <div className="w-14 h-14 rounded-2xl bg-[#0097a7] text-black flex items-center justify-center">
                        <ShieldCheck size={28} />
                    </div>

                    <div>
                        <h1 className="text-4xl font-black">
                            Admin Dashboard
                        </h1>

                        <p className="text-gray-400 mt-2">
                            Manage professor requests and user permissions.
                        </p>
                    </div>
                </div>
            </header>

            <main className="relative z-10 px-8 lg:px-20 py-10">
                <div className="grid md:grid-cols-3 gap-6 mb-10">
                    <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                        <div className="flex items-center gap-3 mb-3">
                            <Users className="text-[#0097a7]" />
                            <span className="text-gray-400">
                                {stats.pendingProfessors}
                            </span>
                        </div>
                        <h2 className="text-4xl font-black">
                            {professorRequests.length}
                        </h2>
                    </div>
                    <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                        <div className="flex items-center gap-3 mb-3">
                            <UserCheck className="text-green-400" />
                            <span className="text-gray-400">
                                Approved Professors
                            </span>
                        </div>
                        <h2 className="text-4xl font-black">
                            {stats.approvedProfessors}
                        </h2>
                    </div>
                    <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                        <div className="flex items-center gap-3 mb-3">
                            <ShieldCheck className="text-[#0097a7]" />
                            <span className="text-gray-400">
                                Administrators
                            </span>
                        </div>
                        <h2 className="text-4xl font-black">
                            {stats.admins}
                        </h2>
                    </div>
                </div>
                <div className="mb-8">
                    <h2 className="text-3xl font-bold mb-2">
                        Pending Professor Requests
                    </h2>
                    <p className="text-gray-400">
                        Review applications submitted by users requesting professor access.
                    </p>
                </div>
                <div className="space-y-6">
                    {professorRequests.map((professor) => (
                        <div
                            key={professor.id}
                            className="rounded-3xl border border-white/10 bg-white/[0.03] p-6"
                        >
                            <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                                <div>
                                    <h2 className="text-2xl font-bold">
                                        {professor.name}
                                    </h2>
                                    <p className="text-gray-400 mt-2">
                                        {professor.email}
                                    </p>
                                    <p className="text-[#00b0c2] mt-2">
                                        {professor.specialization}
                                    </p>
                                    <p className="text-gray-500 text-sm mt-1">
                                        {professor.location}
                                    </p>
                                </div>
                                <div className="flex gap-3">
                                    <button onClick={() => handleApprove(professor.id)}
                                        className="flex items-center gap-2 px-6 py-3 rounded-2xl bg-green-500 hover:bg-green-600 transition font-semibold"
                                    >
                                        <UserCheck size={18} />
                                        Approve
                                    </button>
                                    <button onClick={() => handleReject(professor.id)}
                                        className="flex items-center gap-2 px-6 py-3 rounded-2xl bg-red-500 hover:bg-red-600 transition font-semibold"
                                    >
                                        <UserX size={18} />
                                        Reject
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </main>
        </div>
    );
}

export default AdminDashboard;