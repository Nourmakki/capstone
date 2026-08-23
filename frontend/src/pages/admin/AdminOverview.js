import React from "react";
import {
    Users, UserCheck, ShieldCheck, GraduationCap, Layers,
    Map, Upload, ClipboardCheck, XCircle, Bot, Inbox,
} from "lucide-react";

function AdminOverview({ stats, onGo }) {
    // The admin manages people. Roadmaps, tutorials, quizzes and projects
    // belong to the material manager and are deliberately absent here.
    const cards = [
        { label: "Pending professors", value: stats.pendingProfessors, icon: <Users className="text-yellow-400" />, go: "professors" },
        { label: "Approved professors", value: stats.approvedProfessors, icon: <UserCheck className="text-green-400" /> },
        { label: "Students", value: stats.students, icon: <GraduationCap className="text-blue-400" /> },
        { label: "Material managers", value: stats.materialManagers, icon: <Layers className="text-[#0097a7]" /> },
        { label: "Administrators", value: stats.admins, icon: <ShieldCheck className="text-[#0097a7]" /> },
    ];

    // Visibility only - none of these are clickable, because acting on
    // material is the material manager's job, not the admin's.
    const platformCards = [
        { label: "Roadmaps", value: stats.roadmaps, icon: <Map className="text-[#0097a7]" /> },
        { label: "Published versions", value: stats.publishedVersions, icon: <Upload className="text-green-400" /> },
        { label: "Material awaiting approval", value: stats.materialPending, icon: <ClipboardCheck className="text-yellow-400" /> },
        { label: "Rejected material", value: stats.materialRejected, icon: <XCircle className="text-red-400" /> },
        { label: "AI runs awaiting review", value: stats.aiWorkflowsAwaiting, icon: <Bot className="text-purple-400" /> },
        { label: "Student work awaiting a professor", value: stats.pendingReviews, icon: <Inbox className="text-blue-400" /> },
    ];

    return (
        <div className="p-8">
            <h1 className="text-5xl font-black text-[#0097a7]">Overview</h1>
            <p className="text-gray-400 mt-2 mb-10">
                Accounts and access across the platform.
            </p>

            <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                {cards.map((c) => (
                    <button
                        key={c.label}
                        onClick={() => c.go && onGo(c.go)}
                        disabled={!c.go}
                        className={`text-left rounded-3xl border border-white/10 bg-white/[0.03] p-6 transition ${
                            c.go ? "hover:border-[#0097a7]/40 cursor-pointer" : "cursor-default"
                        }`}
                    >
                        <div className="flex items-center gap-3 mb-3">
                            {c.icon}
                            <span className="text-gray-400 text-sm">{c.label}</span>
                        </div>
                        <h2 className="text-4xl font-black">{c.value ?? 0}</h2>
                    </button>
                ))}
            </div>

            <h2 className="text-3xl font-bold mt-12 mb-2">Platform</h2>
            <p className="text-gray-400 mb-6">
                Read-only. Material is managed by the material managers.
            </p>

            <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                {platformCards.map((c) => (
                    <div key={c.label}
                         className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                        <div className="flex items-center gap-3 mb-3">
                            {c.icon}
                            <span className="text-gray-400 text-sm">{c.label}</span>
                        </div>
                        <h2 className="text-4xl font-black">{c.value ?? 0}</h2>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default AdminOverview;
