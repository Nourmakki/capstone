import React, { useCallback, useEffect, useRef, useState } from "react";
import api from "../api";
import { Users, Plus, UserPlus, UserMinus, Send, MessageSquare } from "lucide-react";

function TeamsPage() {
    const [teams, setTeams] = useState([]);
    const [form, setForm] = useState({ name: "", description: "", project_focus: "" });
    const [selected, setSelected] = useState(null);
    const [messages, setMessages] = useState([]);
    const [chatBody, setChatBody] = useState("");
    const [loading, setLoading] = useState(true);

    // Bumped on every "Open" click so the scroll fires even when the same
    // team is reopened - keying the effect on the team id would not.
    const [openCount, setOpenCount] = useState(0);
    const chatRef = useRef(null);

    useEffect(() => {
        if (openCount === 0) {
            return;
        }

        // Smooth scrolling is ignored entirely when the user asks for reduced
        // motion, so fall back to jumping rather than not moving at all.
        const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

        chatRef.current?.scrollIntoView({
            behavior: reduceMotion ? "auto" : "smooth",
            block: "start",
        });
    }, [openCount]);

    const load = useCallback(async () => {
        try {
            const response = await api.get("/teams");
            setTeams(response.data.teams);

            // Keep the open team in sync after joining or leaving.
            setSelected((prev) => prev ? response.data.teams.find((t) => t.id === prev.id) ?? null : null);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const openTeam = async (team) => {
        setSelected(team);
        setMessages([]);
        setOpenCount((n) => n + 1);

        if (!team.is_member) return;

        try {
            const response = await api.get(`/teams/${team.id}/messages`);
            setMessages(response.data.messages);
        } catch (err) {
            console.error(err);
        }
    };

    const createTeam = async () => {
        if (!form.name.trim()) return;

        try {
            await api.post("/teams", {
                name: form.name,
                description: form.description || null,
                project_focus: form.project_focus || null,
            });
            setForm({ name: "", description: "", project_focus: "" });
            await load();
        } catch (err) {
            const errors = err.response?.data?.errors;
            alert(errors ? Object.values(errors).flat().join("\n") : "Could not create the team.");
        }
    };

    const join = async (team) => {
        try {
            await api.post(`/teams/${team.id}/join`);
            await load();
            await openTeam({ ...team, is_member: true });
        } catch (err) {
            alert(err.response?.data?.message ?? "Could not join.");
        }
    };

    const leave = async (team) => {
        try {
            await api.delete(`/teams/${team.id}/leave`);
            setSelected(null);
            setMessages([]);
            await load();
        } catch (err) {
            alert(err.response?.data?.message ?? "Could not leave.");
        }
    };

    const sendMessage = async () => {
        if (!chatBody.trim() || !selected) return;

        try {
            const response = await api.post(`/teams/${selected.id}/messages`, { body: chatBody });
            setMessages((prev) => [...prev, response.data.message]);
            setChatBody("");
        } catch (err) {
            alert(err.response?.data?.message ?? "Could not send.");
        }
    };

    return (
        <div className="min-h-screen bg-black text-white p-20 z-10 px-8">
            <div className="mb-10">
                <h1 className="text-3xl lg:text-4xl font-black text-[#0097a7]">Teams & Collaboration</h1>
                <p className="text-gray-400 mt-2">Work together, chat, and build projects with other students.</p>
            </div>

            <div className="bg-white/[0.03] border border-white/10 rounded-3xl p-6 mb-10">
                <h2 className="text-2xl font-bold mb-6 flex items-center gap-2">
                    <Plus /> Create Team
                </h2>

                <div className="grid md:grid-cols-3 gap-4">
                    <input
                        type="text" placeholder="Team Name" value={form.name}
                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                        className="bg-black border border-white/10 rounded-xl p-3 focus:outline-none focus:border-[#0097a7]"
                    />
                    <input
                        type="text" placeholder="Description" value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                        className="bg-black border border-white/10 rounded-xl p-3 focus:outline-none focus:border-[#0097a7]"
                    />
                    <input
                        type="text" placeholder="Current Project" value={form.project_focus}
                        onChange={(e) => setForm({ ...form, project_focus: e.target.value })}
                        className="bg-black border border-white/10 rounded-xl p-3 focus:outline-none focus:border-[#0097a7]"
                    />
                </div>

                <button
                    onClick={createTeam}
                    disabled={!form.name.trim()}
                    className={`mt-6 font-bold px-8 py-3 rounded-xl transition ${
                        form.name.trim() ? "bg-[#0097a7] text-black hover:scale-105" : "bg-gray-700 text-gray-400 cursor-not-allowed"
                    }`}
                >
                    Create Team
                </button>
            </div>

            <h2 className="text-3xl font-bold mb-6">Available Teams</h2>

            {loading ? (
                <p className="text-gray-400">Loading...</p>
            ) : teams.length === 0 ? (
                <p className="text-gray-500">No teams yet. Create the first one.</p>
            ) : (
                <div className="space-y-5">
                    {teams.map((team) => (
                        <div key={team.id} className="bg-white/[0.03] border border-white/10 rounded-3xl p-6 hover:border-[#0097a7]/40 transition">
                            <div className="flex flex-col lg:flex-row justify-between lg:items-center gap-5">
                                <div>
                                    <div className="flex items-center gap-3">
                                        <h3 className="text-2xl font-bold">{team.name}</h3>
                                        {team.is_creator && (
                                            <span className="text-xs px-3 py-1 rounded-full border border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2]">
                                                creator
                                            </span>
                                        )}
                                    </div>

                                    {team.description && <p className="text-gray-400 mt-1">{team.description}</p>}
                                    {team.project_focus && <p className="text-gray-400">Project: {team.project_focus}</p>}

                                    <div className="flex items-center gap-2 mt-4 text-[#0097a7]">
                                        <Users size={18} />
                                        {team.member_count} {team.member_count === 1 ? "member" : "members"}
                                    </div>
                                </div>

                                <div className="flex gap-3">
                                    {team.is_member ? (
                                        <button onClick={() => leave(team)}
                                            className="flex items-center gap-2 border border-red-400/30 text-red-400 px-5 py-3 rounded-xl hover:bg-red-400/10 transition">
                                            <UserMinus size={18} /> Leave
                                        </button>
                                    ) : (
                                        <button onClick={() => join(team)}
                                            className="flex items-center gap-2 border border-[#0097a7]/30 px-5 py-3 rounded-xl hover:bg-[#0097a7]/10 transition">
                                            <UserPlus size={18} /> Join
                                        </button>
                                    )}

                                    <button onClick={() => openTeam(team)}
                                        className="flex items-center gap-2 bg-[#0097a7] text-black px-5 py-3 rounded-xl font-bold hover:scale-105 transition">
                                        <MessageSquare size={18} /> Open
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {selected && (
                <div ref={chatRef} className="mt-12 scroll-mt-6 grid lg:grid-cols-2 gap-8">
                    <div className="bg-white/[0.03] border border-white/10 rounded-3xl p-6">
                        <h2 className="text-2xl font-bold flex items-center gap-2 mb-6">
                            <MessageSquare /> {selected.name} Chat
                        </h2>

                        {!selected.is_member ? (
                            <p className="text-gray-500">Join this team to see its chat.</p>
                        ) : (
                            <>
                                <div className="bg-black rounded-2xl border border-white/10 h-80 overflow-y-auto p-4 space-y-4">
                                    {messages.length === 0 ? (
                                        <p className="text-gray-500 text-sm">No messages yet. Say hello.</p>
                                    ) : (
                                        messages.map((m) => (
                                            <div key={m.id} className="border border-white/10 rounded-xl p-3">
                                                <p className="text-[#0097a7] font-semibold text-sm">{m.sender}</p>
                                                <p className="text-gray-300 mt-1">{m.body}</p>
                                            </div>
                                        ))
                                    )}
                                </div>

                                <div className="flex gap-3 mt-5">
                                    <input
                                        type="text" placeholder="Write a message..."
                                        value={chatBody}
                                        onChange={(e) => setChatBody(e.target.value)}
                                        onKeyDown={(e) => e.key === "Enter" && sendMessage()}
                                        className="flex-1 bg-black border border-white/10 rounded-xl p-3 focus:outline-none focus:border-[#0097a7]"
                                    />
                                    <button onClick={sendMessage} className="bg-[#0097a7] text-black px-5 rounded-xl hover:scale-105 transition">
                                        <Send />
                                    </button>
                                </div>
                            </>
                        )}
                    </div>

                    <div className="bg-white/[0.03] border border-white/10 rounded-3xl p-6">
                        <h2 className="text-2xl font-bold flex items-center gap-2 mb-6">
                            <Users /> Team Members
                        </h2>

                        <div className="flex flex-wrap gap-3">
                            {selected.members.map((member, i) => (
                                <div key={i} className="px-4 py-2 rounded-full bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]">
                                    {member}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

export default TeamsPage;