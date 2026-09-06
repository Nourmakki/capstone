import React from "react";

const BULLET = /^[-*•]\s+/;

/**
 * The headings AiLearningCommitService::projectBrief writes. Recognised by
 * name so the generated shape always reads correctly, whatever a heading
 * happens to look like.
 */
const KNOWN_HEADINGS = [
    "what you will learn",
    "tasks",
    "requirements",
    "constraints",
    "deliverables",
    "done when",
];

/**
 * A heading, or the first line of a paragraph?
 *
 * Named headings are certain. Beyond those, a line is only a heading if
 * something bulleted sits under it - a manager who writes their own section
 * above a list gets the same treatment, and a two-line paragraph does not
 * lose its first line to a heading it never meant.
 */
const isHeading = (line, rest) => {
    if (!line || BULLET.test(line) || rest.length === 0) {
        return false;
    }

    if (KNOWN_HEADINGS.includes(line.toLowerCase())) {
        return true;
    }

    return BULLET.test(rest[0]) && line.length <= 40 && !/[.?!,;:]$/.test(line);
};

/**
 * A project brief, as the sections it was written in.
 *
 * The AI returns eight structured fields - overview, tasks, requirements,
 * constraints, deliverables, acceptance criteria - which the commit flattens
 * into the one text column a project has, keeping the structure as blank
 * lines, headings and "- " bullets. Reading it back turns that into sections
 * again.
 *
 * @returns {Array<{heading: ?string, parts: Array<object>}>}
 */
export function parseBrief(text) {
    return String(text ?? "")
        // A blank line ends a section, which is how the brief is written.
        .split(/\n\s*\n/)
        .map((chunk) => chunk.split("\n").map((line) => line.trim()).filter(Boolean))
        .filter((lines) => lines.length > 0)
        .map((lines) => {
            const heading = isHeading(lines[0], lines.slice(1)) ? lines[0] : null;
            const body = heading ? lines.slice(1) : lines;

            // A run of bullets is one list, and a run of plain lines is one
            // paragraph - a brief that wraps its overview over three lines
            // means one paragraph, not three.
            const parts = [];

            for (const line of body) {
                const last = parts[parts.length - 1];

                if (BULLET.test(line)) {
                    const item = line.replace(BULLET, "");

                    if (last?.type === "list") last.items.push(item);
                    else parts.push({ type: "list", items: [item] });
                } else if (last?.type === "text") {
                    last.text += ` ${line}`;
                } else {
                    parts.push({ type: "text", text: line });
                }
            }

            return { heading, parts };
        });
}

/**
 * What the student has to build.
 *
 * This used to be printed into a single <p>, which collapses every newline it
 * is given: eight sections and every bullet in them arrived as one unbroken
 * paragraph. The structure was in the text the whole time - nothing was
 * rendering it.
 */
function ProjectBrief({ text, className = "" }) {
    const sections = parseBrief(text);

    if (sections.length === 0) {
        return null;
    }

    return (
        <div className={`space-y-6 ${className}`}>
            {sections.map((section, index) => (
                <section key={index}>
                    {section.heading && (
                        <h3 className="text-xs font-bold uppercase tracking-wider text-[#00b0c2] mb-2.5">
                            {section.heading}
                        </h3>
                    )}

                    <div className="space-y-3">
                        {section.parts.map((part, partIndex) => (
                            part.type === "list" ? (
                                <ul key={partIndex} className="space-y-2">
                                    {part.items.map((item, itemIndex) => (
                                        <li key={itemIndex} className="flex gap-3 text-gray-300">
                                            <span className="mt-[0.55rem] h-1.5 w-1.5 rounded-full bg-[#0097a7] shrink-0" />
                                            <span className="leading-relaxed">{item}</span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p key={partIndex} className="text-gray-400 leading-relaxed">
                                    {part.text}
                                </p>
                            )
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}

export default ProjectBrief;
