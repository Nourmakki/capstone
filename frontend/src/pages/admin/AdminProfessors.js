import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import { UserCheck, UserX, FileText, AlertTriangle } from "lucide-react";

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

    /**
     * The CV route needs the bearer token, so a plain link will not work.
     * Fetch it as a blob and hand the browser an object URL instead.
     */
    const openCv = async (id) => {
        try {
            const response = await api.get(`/admin/professor-requests/${id}/cv`, {
                responseType: "blob",
            });

            const url = URL.createObjectURL(
                new Blob([response.data], { type: "application/pdf" })
            );

            window.open(url, "_blank", "noopener");

            // Release the handle once the new tab has taken it.
            setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch (err) {
            console.error(err);
            alert("Could not open this CV.");
        }
    };

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
            <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">Professor Requests</h1>
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
                                <div className="flex-1 min-w-0">
                                    <h2 className="text-2xl font-bold">{p.name}</h2>
                                    <p className="text-gray-400 mt-2">{p.email}</p>
                                    {p.location && (
                                        <p className="text-gray-500 text-sm mt-1">{p.location}</p>
                                    )}

                                    {p.qualification && (
                                        <p className="text-[#00b0c2] mt-3">{p.qualification}</p>
                                    )}

                                    {p.has_cv ? (
                                        <button onClick={() => openCv(p.id)}
                                            className="mt-4 flex items-center gap-2 px-5 py-2 rounded-xl border border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2] text-sm hover:bg-[#0097a7]/20 transition">
                                            <FileText size={16} /> Open CV
                                        </button>
                                    ) : (
                                        <p className="mt-4 flex items-center gap-2 text-yellow-400 text-sm">
                                            <AlertTriangle size={16} /> No CV on file
                                        </p>
                                    )}
                                </div>

                                <div className="flex gap-3 shrink-0">
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
