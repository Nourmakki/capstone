import React from "react";

/**
 * Choose a roadmap, with its topics nested underneath.
 *
 * Every manager panel needs this and each had grown its own flat row, so
 * thirteen roadmaps appeared side by side with Frontend sitting next to the
 * Web Development that contains it, as though they were peers. Shared rather
 * than copied a fourth time: the hierarchy is a property of the data, so
 * every picker should show it the same way.
 *
 * The top row is the roadmaps proper. A parent's topics appear beneath it once
 * it is chosen, and opening a topic keeps its siblings on screen so moving
 * between them does not mean going back up first.
 */
function RoadmapPicker({ roadmaps, selectedId, onSelect }) {
    const parents = roadmaps.filter((r) => !r.parent_roadmap_id);

    const selected = roadmaps.find((r) => r.id === selectedId);

    const activeParentId = selected?.parent_roadmap_id ?? selected?.id ?? null;
    const activeParent = roadmaps.find((r) => r.id === activeParentId);
    const topics = roadmaps.filter((r) => r.parent_roadmap_id === activeParentId);

    const status = (r, active) =>
        r.review_status !== "approved" && (
            <span className={`text-xs px-2 py-0.5 rounded-full ${
                active
                    ? "bg-black/20 text-black"
                    : r.review_status === "rejected"
                    ? "bg-red-400/20 text-red-300"
                    : "bg-yellow-400/20 text-yellow-300"
            }`}>
                {r.review_status}
            </span>
        );

    return (
        <>
            <div className="flex flex-wrap gap-3 mb-4">
                {parents.map((r) => {
                    const active = activeParentId === r.id;

                    return (
                        <button key={r.id} onClick={() => onSelect(r.id)}
                            className={`px-6 py-3 rounded-2xl transition flex items-center gap-2 ${
                                active
                                    ? "bg-[#0097a7] text-black font-semibold"
                                    : "bg-white/5 border border-white/10 text-gray-300"
                            }`}>
                            {r.title}

                            {/* So a parent does not look empty before it is opened. */}
                            {(r.children?.length ?? 0) > 0 && (
                                <span className={`text-xs px-2 py-0.5 rounded-full ${
                                    active ? "bg-black/20 text-black" : "bg-white/10 text-gray-400"
                                }`}>
                                    {r.children.length}
                                </span>
                            )}

                            {status(r, active)}
                        </button>
                    );
                })}
            </div>

            {topics.length > 0 && (
                <div className="flex flex-wrap items-center gap-3 mb-5 pl-4 border-l-2 border-[#0097a7]/30">
                    <span className="text-xs text-gray-500">
                        Topics in {activeParent?.title}:
                    </span>

                    {/* A parent can hold content of its own, so it stays
                        selectable once a topic has been opened. */}
                    <button onClick={() => onSelect(activeParentId)}
                        className={`px-4 py-2 rounded-xl text-sm transition ${
                            selectedId === activeParentId
                                ? "bg-[#0097a7] text-black font-semibold"
                                : "bg-white/5 border border-white/10 text-gray-400"
                        }`}>
                        {activeParent?.title} itself
                    </button>

                    {topics.map((r) => {
                        const active = selectedId === r.id;

                        return (
                            <button key={r.id} onClick={() => onSelect(r.id)}
                                className={`px-4 py-2 rounded-xl text-sm transition flex items-center gap-2 ${
                                    active
                                        ? "bg-[#0097a7] text-black font-semibold"
                                        : "bg-white/5 border border-white/10 text-gray-300"
                                }`}>
                                {r.title}
                                {status(r, active)}
                            </button>
                        );
                    })}
                </div>
            )}
        </>
    );
}

export default RoadmapPicker;
