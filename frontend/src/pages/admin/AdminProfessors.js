import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import { UserCheck, UserX } from "lucide-react";

function AdminProfessors({ onChanged }) {
    const [requests, setRequests] = useState([]);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(null);

    const load = useCallback(async () => {
        try {
            const response = await api.get("/admin/professor-requests");
            setRequests(response.data);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    const act = async (id, action) => {
        setBusy(id);

        try {
            if (action === "approve") {
                await api.put(`/admin/approve-professor/${id}`);
            } else {
                await api.delete(`/admin/reject-professor/${id}`);
            }

            await load();
            onChanged?.();
        } catch (err) {
            alert(err.response?.data?.message ?? "Action failed.");
        } finally {
            setBusy(null);
        }
    };

    return (
        <div className="p-8">
            <h1 className="text-5xl font-black text-[#0097a7]">Professor Requests</h1>
            <p className="text-gray-400 mt-2 mb-10">
                Review applications from users requesting professor access.
            </p>

            {loading ? (
                <p className="text-gray-400">Loading...</p>
            ) : requests.length === 0 ? (
                <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-12 text-center">
                    <UserCheck size={48} className="mx-auto text-gray-600 mb-4" />
                    <h2 className="text-2xl font-bold">Nothing pending</h2>
                    <p className="text-gray-400 mt-2">All professor requests have been handled.</p>
                </div>
            ) : (
                <div className="space-y-6">
                    {requests.map((p) => (
                        <div key={p.id} className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                            <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                                <div>
                                    <h2 className="text-2xl font-bold">{p.name}</h2>
                                    <p className="text-gray-400 mt-2">{p.email}</p>
                                    {p.location && (
                                        <p className="text-gray-500 text-sm mt-1">{p.location}</p>
                                    )}
                                </div>

                                <div className="flex gap-3">
                                    <button onClick={() => act(p.id, "approve")} disabled={busy === p.id}
                                        className="flex items-center gap-2 px-6 py-3 rounded-2xl bg-green-500 hover:bg-green-600 transition font-semibold disabled:opacity-50">
                                        <UserCheck size={18} /> Approve
                                    </button>
                                    <button onClick={() => act(p.id, "reject")} disabled={busy === p.id}
                                        className="flex items-center gap-2 px-6 py-3 rounded-2xl bg-red-500 hover:bg-red-600 transition font-semibold disabled:opacity-50">
                                        <UserX size={18} /> Reject
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export default AdminProfessors;
