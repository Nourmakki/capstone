import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import { Check, X, History } from "lucide-react";

const TYPE_LABEL = {
    "roadmaps": "Roadmap",
    "roadmap-versions": "Version",
    "tutorials": "Tutorial",
    "external-resources": "Source",
};

function MaterialHistory() {
    const [history, setHistory] = useState([]);
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        try {
            const response = await api.get("/material/history");
            setHistory(response.data.history);
        } catch (err) {
            console.error(err);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    return (
        <div className="p-8">
            <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">Decision History</h1>
            <p className="text-gray-400 mt-2 mb-10">
                Recent approvals and rejections, and who made them.
            </p>

            {loading ? (
                <p className="text-gray-400">Loading...</p>
            ) : history.length === 0 ? (
                <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-12 text-center">
                    <History size={48} className="mx-auto text-gray-600 mb-4" />
                    <h2 className="text-2xl font-bold">Nothing reviewed yet</h2>
                    <p className="text-gray-400 mt-2">Decisions you make will appear here.</p>
                </div>
            ) : (
                <div className="space-y-4">
                    {history.map((h) => (
                        <div key={`${h.type}-${h.id}`} className="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-3 mb-1">
                                        <span className="text-xs px-3 py-1 rounded-full border border-white/10 bg-black/30 text-gray-400">
                                            {TYPE_LABEL[h.type] ?? h.type}
                                        </span>

                                        <span className={`text-xs px-3 py-1 rounded-full border flex items-center gap-1 ${
                                            h.status === "approved"
                                                ? "border-green-400/30 bg-green-400/10 text-green-300"
                                                : "border-red-400/30 bg-red-400/10 text-red-300"
                                        }`}>
                                            {h.status === "approved" ? <Check size={12} /> : <X size={12} />}
                                            {h.status}
                                        </span>
                                    </div>

                                    <h3 className="text-lg font-bold">{h.title}</h3>

                                    {h.notes && (
                                        <p className="text-sm text-gray-400 mt-2 leading-relaxed">{h.notes}</p>
                                    )}
                                </div>

                                <div className="text-right shrink-0 text-xs text-gray-500">
                                    <p>{h.reviewer}</p>
                                    <p className="mt-1">
                                        {h.reviewed_at ? new Date(h.reviewed_at).toLocaleString() : ""}
                                    </p>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export default MaterialHistory;
