import React from "react";

/**
 * Choose a roadmap, at any of the three levels the tree now has.
 *
 *   Web Development          a subject
 *     Frontend               a path
 *       React                a module too big to be one module
 *
 * Every manager panel needs this and each had grown its own flat row, so
 * thirteen roadmaps appeared side by side with Frontend next to the Web
 * Development that contains it. Shared rather than copied a fourth time: the
 * hierarchy is a property of the data, so every picker should show it the
 * same way.
 *
 * A row appears only when the level above it has been chosen, and choosing
 * something keeps its siblings on screen - moving between Vue and Angular
 * does not mean going back up first.
 *
 * The level-3 row matters more than it looks: eleven roadmaps live down there
 * now, and a picker that stopped at two levels put all of their content out
 * of reach - the same fault as scoping this list to top-level roadmaps did.
 */
function RoadmapPicker({ roadmaps, selectedId, onSelect }) {
    const byId = (id) => roadmaps.find((r) => r.id === id);

    const selected = byId(selectedId);

    // Walk up from whatever is selected, so the rows above it stay open.
    const parentOf = (roadmap) =>
        roadmap?.parent_roadmap_id ? byId(roadmap.parent_roadmap_id) : null;

    const depth = (roadmap) => {
        let level = 1;
        let cursor = parentOf(roadmap);

        while (cursor && level < 5) {
            level += 1;
            cursor = parentOf(cursor);
        }

        return level;
    };

    const selectedDepth = selected ? depth(selected) : 1;

    const activeL1 =
        selectedDepth === 3 ? parentOf(parentOf(selected))
        : selectedDepth === 2 ? parentOf(selected)
        : selected;

    const activeL2 =
        selectedDepth === 3 ? parentOf(selected)
        : selectedDepth === 2 ? selected
        : null;

    const childrenOf = (roadmap) =>
        roadmap ? roadmaps.filter((r) => r.parent_roadmap_id === roadmap.id) : [];

    const subjects = roadmaps.filter((r) => !r.parent_roadmap_id);
    const paths    = childrenOf(activeL1);
    const deeper   = childrenOf(activeL2);

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

    /** One row of buttons. `activeId` is what counts as chosen at this level. */
    const row = (items, activeId, { large = false, label = null, itself = null } = {}) => (
        <div className={`flex flex-wrap items-center gap-3 ${
            large ? "mb-4" : "mb-4 pl-4 border-l-2 border-[#0097a7]/30"
        }`}>
            {label && <span className="text-xs text-gray-500">{label}</span>}

            {/* The level above stays selectable: it can hold content of its
                own, and a row offering only its children would hide that. */}
            {itself && (
                <button onClick={() => onSelect(itself.id)}
                    className={`px-4 py-2 rounded-xl text-sm transition ${
                        selectedId === itself.id
                            ? "bg-[#0097a7] text-black font-semibold"
                            : "bg-white/5 border border-white/10 text-gray-400"
                    }`}>
                    {itself.title} itself
                </button>
            )}

            {items.map((r) => {
                const active = activeId === r.id;
                const count  = roadmaps.filter((c) => c.parent_roadmap_id === r.id).length;

                return (
                    <button key={r.id} onClick={() => onSelect(r.id)}
                        className={`rounded-2xl transition flex items-center gap-2 ${
                            large ? "px-6 py-3" : "px-4 py-2 text-sm rounded-xl"
                        } ${
                            active
                                ? "bg-[#0097a7] text-black font-semibold"
                                : "bg-white/5 border border-white/10 text-gray-300"
                        }`}>
                        {r.title}

                        {/* So a roadmap does not look empty before it is opened. */}
                        {count > 0 && (
                            <span className={`text-xs px-2 py-0.5 rounded-full ${
                                active ? "bg-black/20 text-black" : "bg-white/10 text-gray-400"
                            }`}>
                                {count}
                            </span>
                        )}

                        {status(r, active)}
                    </button>
                );
            })}
        </div>
    );

    return (
        <>
            {row(subjects, activeL1?.id, { large: true })}

            {paths.length > 0 && row(paths, activeL2?.id ?? selectedId, {
                label: `In ${activeL1?.title}:`,
                itself: activeL1,
            })}

            {deeper.length > 0 && row(deeper, selectedId, {
                label: `In ${activeL2?.title}:`,
                itself: activeL2,
            })}
        </>
    );
}

export default RoadmapPicker;
