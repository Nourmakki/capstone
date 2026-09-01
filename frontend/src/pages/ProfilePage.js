import React, { useCallback, useEffect, useState } from "react";
import api from "../api";
import {
    User, Mail, MapPin, Trophy, BookOpen, Target, Award, Flame, Save, X,
} from "lucide-react";

function ProfilePage() {
    const [profile, setProfile] = useState(null);
    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState({ name: "", email: "", location: "" });
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    const load = useCallback(async () => {
        try {
            const response = await api.get("/profile");
            setProfile(response.data);
            setForm({
                name: response.data.user.name ?? "",
                email: response.data.user.email ?? "",
                location: response.data.user.location ?? "",
            });
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const save = async () => {
        setSaving(true);

        try {
            await api.put("/profile", form);
            setEditing(false);
            await load();
        } catch (err) {
            const errors = err.response?.data?.errors;
            alert(errors ? Object.values(errors).flat().join("\n") : "Could not save your profile.");
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return (
            <div className="min-h-screen bg-black text-white flex items-center justify-center">
                Loading profile...
            </div>
        );
    }

    const u = profile.user;
    // Only students have learning stats; staff get the account sections only.
    const isStudent = profile.stats !== null;
    const stats = profile.stats ?? { modules_completed: 0, quizzes_passed: 0, projects_submitted: 0 };
    const initial = (u.name ?? "?").charAt(0).toUpperCase();

    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.15),transparent_55%)] pointer-events-none" />

            <header className="relative z-10 px-8 lg:px-20 py-10 border-b border-white/10">
                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-8">
                    <div className="flex items-center gap-5">
                        <div className="w-24 h-24 rounded-full bg-[#0097a7] text-black flex items-center justify-center font-black text-4xl">
                            {initial}
                        </div>
                        <div>
                            <h1 className="text-3xl lg:text-4xl font-black">{u.name}</h1>
                            <p className="text-gray-400 mt-2">
                                {u.role}{u.level ? ` · ${u.level}` : ""}
                            </p>
                        </div>
                    </div>

                    {editing ? (
                        <div className="flex gap-3">
                            <button onClick={save} disabled={saving}
                                className={`flex items-center gap-2 px-6 py-3 rounded-2xl font-bold transition ${
                                    saving ? "bg-gray-700 text-gray-400" : "bg-[#0097a7] text-black hover:scale-105"
                                }`}>
                                <Save size={18} /> {saving ? "Saving..." : "Save"}
                            </button>
                            <button onClick={() => { setEditing(false); load(); }}
                                className="flex items-center gap-2 px-6 py-3 rounded-2xl border border-white/10 text-gray-300">
                                <X size={18} /> Cancel
                            </button>
                        </div>
                    ) : (
                        <button onClick={() => setEditing(true)}
                            className="px-6 py-3 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-105 transition">
                            Edit Profile
                        </button>
                    )}
                </div>
            </header>

            <main className="relative z-10 px-8 lg:px-20 py-12">
                <div className="grid lg:grid-cols-[1fr_350px] gap-8">
                    <div className="space-y-8">
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                            <h2 className="text-2xl font-bold mb-6">User Information</h2>

                            {editing ? (
                                <div className="space-y-4">
                                    <div>
                                        <label className="block text-sm text-gray-300 mb-2">Name</label>
                                        <input type="text" value={form.name}
                                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                                            className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white" />
                                    </div>
                                    <div>
                                        <label className="block text-sm text-gray-300 mb-2">Email</label>
                                        <input type="email" value={form.email}
                                            onChange={(e) => setForm({ ...form, email: e.target.value })}
                                            className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white" />
                                    </div>
                                    {/* Level is shown, not chosen. It describes how far
                                        the student has actually got, and it is what
                                        workshop recommendations and module difficulty
                                        are matched against - a level someone sets for
                                        themselves would be matched against a claim
                                        rather than against their work. */}
                                    {u.level && (
                                        <div>
                                            <label className="block text-sm text-gray-300 mb-2">Level</label>
                                            <div className="w-full p-3 rounded-xl bg-black/20 border border-white/5 text-gray-400">
                                                {u.level}
                                                <span className="block text-xs text-gray-500 mt-1">
                                                    Set by your progress, not by you.
                                                </span>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="space-y-5">
                                    <div className="flex items-center gap-4">
                                        <User className="text-[#0097a7]" /> <span>{u.name}</span>
                                    </div>
                                    <div className="flex items-center gap-4">
                                        <Mail className="text-[#0097a7]" /> <span>{u.email}</span>
                                    </div>
                                    <div className="flex items-center gap-4">
                                        <MapPin className="text-[#0097a7]" />
                                        <span>{u.location || "No location set"}</span>
                                    </div>
                                </div>
                            )}
                        </div>

                        {isStudent && (
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                            <h2 className="text-2xl font-bold mb-6">Selected Roadmaps</h2>

                            {profile.roadmaps.length === 0 ? (
                                <p className="text-gray-500 text-sm">
                                    You have not started a roadmap yet.
                                </p>
                            ) : (
                                <div className="space-y-6">
                                    {profile.roadmaps.map((r) => (
                                        <div key={r.roadmap_id}>
                                            <div className="flex justify-between mb-2">
                                                <span>{r.title}</span>
                                                <span className="text-[#00b0c2]">
                                                    {r.percent}% · {r.completed}/{r.total}
                                                </span>
                                            </div>
                                            <div className="w-full h-3 bg-black/30 rounded-full overflow-hidden">
                                                <div className="h-full bg-gradient-to-r from-blue-400 to-[#0097a7] transition-all"
                                                     style={{ width: `${r.percent}%` }} />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                        )}

                        {isStudent && (
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                            <h2 className="text-2xl font-bold mb-6">Achievements</h2>

                            <div className="grid md:grid-cols-2 gap-5">
                                {stats.quizzes_passed > 0 && (
                                    <div className="rounded-2xl border border-yellow-400/20 bg-yellow-500/5 p-5">
                                        <Award className="text-yellow-400 mb-3" />
                                        <h3 className="font-bold">First Quiz Passed</h3>
                                    </div>
                                )}
                                {stats.modules_completed >= 3 && (
                                    <div className="rounded-2xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-5">
                                        <Flame className="text-[#0097a7] mb-3" />
                                        <h3 className="font-bold">Three Modules Down</h3>
                                    </div>
                                )}
                                {stats.quizzes_passed === 0 && stats.modules_completed < 3 && (
                                    <p className="text-gray-500 text-sm">
                                        Complete modules and pass quizzes to earn achievements.
                                    </p>
                                )}
                            </div>
                        </div>
                        )}
                    </div>

                    <div className="space-y-8">
                        {isStudent && (
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                            <h2 className="text-2xl font-bold mb-6">Learning Stats</h2>

                            {[
                                { icon: <BookOpen className="text-[#0097a7]" />, label: "Modules Completed", value: stats.modules_completed },
                                { icon: <Target className="text-[#0097a7]" />, label: "Quizzes Passed", value: stats.quizzes_passed },
                                { icon: <Trophy className="text-[#0097a7]" />, label: "Projects Submitted", value: stats.projects_submitted },
                            ].map((s) => (
                                <div key={s.label} className="flex justify-between py-2">
                                    <div className="flex items-center gap-3">{s.icon}{s.label}</div>
                                    <span className="font-bold">{s.value}</span>
                                </div>
                            ))}
                        </div>
                        )}

                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                            <h2 className="text-2xl font-bold mb-4">Location</h2>

                            <input type="text"
                                value={editing ? form.location : (u.location ?? "")}
                                disabled={!editing}
                                onChange={(e) => setForm({ ...form, location: e.target.value })}
                                placeholder="City, Country"
                                className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white disabled:opacity-60" />

                            <p className="text-gray-400 text-sm mt-4">
                                Used to recommend nearby workshops. The country is taken from
                                the part after the last comma, so write it as "Beirut, Lebanon".
                            </p>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    );
}

export default ProfilePage;