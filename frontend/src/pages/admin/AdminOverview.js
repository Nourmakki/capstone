import React from "react";
import {
    Users, UserCheck, ShieldCheck, GraduationCap, Map, Upload, FileEdit, Inbox,
    Layers, ClipboardCheck, XCircle, Bot,
} from "lucide-react";

function AdminOverview({ stats, onGo }) {
    const cards = [
        { label: "Pending professors", value: stats.pendingProfessors, icon: <Users className="text-yellow-400" />, go: "professors" },
        { label: "Approved professors", value: stats.approvedProfessors, icon: <UserCheck className="text-green-400" /> },
        { label: "Administrators", value: stats.admins, icon: <ShieldCheck className="text-[#0097a7]" /> },
        { label: "Students", value: stats.students, icon: <GraduationCap className="text-blue-400" /> },
        { label: "Roadmaps", value: stats.roadmaps, icon: <Map className="text-[#0097a7]" />, go: "roadmaps" },
        { label: "Published versions", value: stats.publishedVersions, icon: <Upload className="text-green-400" />, go: "roadmaps" },
        { label: "Draft versions", value: stats.draftVersions, icon: <FileEdit className="text-yellow-400" />, go: "roadmaps" },
        { label: "Pending reviews", value: stats.pendingReviews, icon: <Inbox className="text-purple-400" /> },
    ];

    // What the material managers are carrying.
    const materialCards = [
        { label: "Material managers", value: stats.materialManagers, icon: <Layers className="text-[#0097a7]" /> },
        { label: "Awaiting approval", value: stats.materialPending, icon: <ClipboardCheck className="text-yellow-400" />, go: "roadmaps" },
        { label: "Rejected material", value: stats.materialRejected, icon: <XCircle className="text-red-400" /> },
        { label: "AI runs awaiting review", value: stats.aiWorkflowsAwaiting, icon: <Bot className="text-purple-400" /> },
    ];

    return (
        <div className="p-8">
            <h1 className="text-5xl font-black text-[#0097a7]">Overview</h1>
            <p className="text-gray-400 mt-2 mb-10">
                Everything happening across the platform.
            </p>

            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
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

            <h2 className="text-3xl font-bold mt-12 mb-2">Material</h2>
            <p className="text-gray-400 mb-6">
                What the material managers are reviewing.
            </p>

            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
                {materialCards.map((c) => (
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
        </div>
    );
}

export default AdminOverview;
