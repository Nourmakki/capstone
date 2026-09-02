import React, { useState } from "react";

/**
 * Choose a roadmap, at either of the two levels that hold one.
 *
 *   Web Development          a subject - a title, not a roadmap
 *     Frontend               a roadmap
 *       React                a roadmap too big to be one module
 *
 * Every manager panel needs this and each had grown its own flat row, so
 * thirteen roadmaps appeared side by side with Frontend next to the Web
 * Development that contains it. Shared rather than copied a fourth time: the
 * hierarchy is a property of the data, so every picker should show it the
 * same way.
 *
 * The top row names subjects, and a subject is a heading: "Web Development"
 * is the shelf that Frontend, Backend and Full Stack sit on, not a fourth
 * thing sitting beside them. So clicking one opens it and nothing more -
 * it can never become the roadmap being worked on. Offering it as one put a
 * title among the roadmaps and invited content onto something with nowhere
 * to put it.
 *
 * Roadmaps below the top row are chosen by clicking them, and stay chosen
 * while the row of what they contain opens underneath. No row repeats the
 * roadmap above it: Frontend is already selected in its own row, so an entry
 * for it among React and Vue would be a second button doing the same thing.
 */
/**
 * What a panel should open on: the first roadmap under the first subject.
 *
 * Never a subject. Panels used to start on whichever roadmap had no parent,
 * which is exactly the one that holds nothing - an empty version list, and a
 * form offering to add a draft to a heading.
 */
export function firstRoadmapIn(roadmaps) {
    const subject = roadmaps.find((r) => !r.parent_roadmap_id);

    return roadmaps.find((r) => r.parent_roadmap_id === subject?.id)?.id
        ?? roadmaps.find((r) => r.parent_roadmap_id)?.id
        ?? null;
}

function RoadmapPicker({ roadmaps, selectedId, onSelect }) {
    const byId = (id) => roadmaps.find((r) => r.id === id);

    const selected = byId(selectedId);

    // Walk up from whatever is selected, so the rows above it stay open.
    const parentOf = (roadmap) =>
        roadmap?.parent_roadmap_id ? byId(roadmap.parent_roadmap_id) : null;

    const subjectOf = (roadmap) => {
        let cursor = roadmap;
        let guard = 0;

        while (cursor?.parent_roadmap_id && guard++ < 5) {
            cursor = parentOf(cursor);
        }

        return cursor ?? null;
    };

    // Which subject is open is the picker's own business now: a subject is
    // not selectable, so the selection cannot answer it on its own.
    const [openSubjectId, setOpenSubjectId] = useState(null);

    const activeL1 = byId(openSubjectId) ?? subjectOf(selected);

    // The path level: either what is selected, or the parent of it - but only
    // while the selection sits inside the subject that is open. Opening Web
    // Development with a roadmap under AI / ML still selected used to leave
    // that roadmap's siblings on screen beneath a heading no longer holding
    // them, and offered "AI / ML itself" beside them - a subject back among
    // the roadmaps by another door.
    const activeL2 =
        selected && subjectOf(selected) === activeL1 && selected !== activeL1
            ? (parentOf(selected) === activeL1 ? selected : parentOf(selected))
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
    const row = (items, activeId, {
        large = false, label = null, onPick = onSelect,
    } = {}) => (
        <div className={`flex flex-wrap items-center gap-3 ${
            large ? "mb-4" : "mb-4 pl-4 border-l-2 border-[#0097a7]/30"
        }`}>
            {label && <span className="text-xs text-gray-500">{label}</span>}

            {items.map((r) => {
                const active = activeId === r.id;
                const count  = roadmaps.filter((c) => c.parent_roadmap_id === r.id).length;

                return (
                    <button key={r.id} onClick={() => onPick(r.id)}
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
            {/* Opening a subject, not choosing one. */}
            {row(subjects, activeL1?.id, {
                large: true,
                onPick: setOpenSubjectId,
            })}

            {paths.length > 0 && row(paths, activeL2?.id ?? selectedId, {
                label: `${activeL1?.title} roadmaps:`,
            })}

            {/* The roadmaps inside the one that is open. That roadmap stays
                chosen in the row above, so it needs no entry of its own down
                here - clicking it there is what opened this row. */}
            {deeper.length > 0 && row(deeper, selectedId, {
                label: `In ${activeL2?.title}:`,
            })}
        </>
    );
}

export default RoadmapPicker;
