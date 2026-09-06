import React, { useState } from "react";
import { Plus, Trash2, X, Save, AlertTriangle } from "lucide-react";

/*
| Editing a learning proposal before it is approved.
|
| Her review stage takes an edit one stage at a time -
|
|   { action: "edit", stage: "quiz", data: { quiz_result: {...} } }
|
| - with no feedback, and with the data validated against QuizEditRequest,
| ProjectEditRequest or ExternalResourcesEditRequest. Those models set
| extra="forbid", so a payload carrying one key she does not know is refused
| whole. Every form here edits the object the run returned rather than
| building a new one, which is what keeps the keys exactly hers: her response
| models and her edit models have the same fields, so a proposal round-trips.
|
| Tutorial is deliberately not one of these. She refuses it - "Tutorial cannot
| be edited. Use regenerate instead." - because a tutorial is a video somebody
| else made, and the only choices about it are which one and whether it stays.
*/

// Her QuizQuestionType.
const QUESTION_TYPES = [
    { id: "conceptual", label: "Conceptual" },
    { id: "code_analysis", label: "Code analysis" },
    { id: "calculation", label: "Calculation" },
    { id: "applied_reasoning", label: "Applied reasoning" },
];

const OPTION_KEYS = ["A", "B", "C", "D"];

// Her PracticalActivityType.
const ACTIVITY_TYPES = [
    { id: "project", label: "Project" },
    { id: "exercises", label: "Exercises" },
];

// Her ExternalResourceType - not the platform's own list of resource kinds.
// This payload is going to her, so it is hers that applies.
const RESOURCE_TYPES = [
    { id: "official_documentation", label: "Official documentation" },
    { id: "github_repository", label: "GitHub repository" },
    { id: "technical_reference", label: "Technical reference" },
];

const filled = (value) => typeof value === "string" && value.trim() !== "";

/** Her lists refuse duplicates, compared trimmed and case-insensitively. */
const hasDuplicates = (items) => {
    const normalized = items.map((item) => String(item).trim().toLowerCase());

    return new Set(normalized).size !== normalized.length;
};

/**
 * What is wrong with this edit, in her terms.
 *
 * Checked here rather than left to the round trip. Her validator answers the
 * first thing it finds and answers it as a Pydantic error, so a manager who
 * emptied one option of one question of ten would get back a sentence about
 * a list length with nothing saying which question it meant.
 *
 * @returns {string[]} empty when the edit is sendable
 */
export function problemsWith(stage, value) {
    const problems = [];

    if (stage === "quiz") {
        const questions = value.questions ?? [];

        if (questions.length !== 10) {
            problems.push("A quiz is exactly 10 questions - this has " + questions.length + ".");
        }

        if (hasDuplicates(questions.map((question) => question.question_id))) {
            problems.push("Two questions share an id.");
        }

        questions.forEach((question, index) => {
            const where = "Question " + (index + 1);

            if (!filled(question.question)) {
                problems.push(where + " has no text.");
            }

            if (!filled(question.correct_answer_explanation)) {
                problems.push(where + " does not say why its answer is right.");
            }

            const options = question.options ?? [];

            if (options.some((option) => !filled(option.text))) {
                problems.push(where + " has an empty option.");
            } else if (hasDuplicates(options.map((option) => option.text))) {
                problems.push(where + " has two options that read the same.");
            }
        });

        return problems;
    }

    if (stage === "project") {
        [
            ["title", "a title"],
            ["overview", "an overview"],
            ["activity_context", "a context"],
            ["learning_objective", "a learning objective"],
        ].forEach(([field, what]) => {
            if (!filled(value[field])) {
                problems.push("The project needs " + what + ".");
            }
        });

        [
            ["tasks", "Tasks", 3],
            ["technical_requirements", "Requirements", 1],
            ["constraints", "Constraints", 1],
            ["deliverables", "Deliverables", 1],
            ["acceptance_criteria", "Done when", 3],
        ].forEach(([field, label, minimum]) => {
            const items = value[field] ?? [];

            if (items.some((item) => !filled(item))) {
                problems.push(label + " has an empty line.");
            } else if (items.length < minimum) {
                problems.push(label + " needs at least " + minimum
                    + (minimum === 1 ? " line." : " lines."));
            } else if (hasDuplicates(items)) {
                problems.push(label + " repeats itself.");
            }
        });

        return problems;
    }

    const resources = value.resources ?? [];

    if (resources.length > 3) {
        problems.push("At most 3 resources.");
    }

    resources.forEach((resource, index) => {
        const where = "Resource " + (index + 1);

        [
            ["title", "a title"],
            ["url", "a link"],
            ["source_name", "a source"],
            ["description", "a description"],
            ["why_recommended", "a reason it was picked"],
        ].forEach(([field, what]) => {
            if (!filled(resource[field])) {
                problems.push(where + " needs " + what + ".");
            }
        });
    });

    if (hasDuplicates(resources.map((resource) => resource.url ?? ""))) {
        problems.push("Two resources point at the same link.");
    }

    return problems;
}

const inputClass = "w-full p-3 rounded-xl bg-black/40 border border-white/10 text-white text-sm";

function Field({ label, children }) {
    return (
        <label className="block">
            <span className="block text-xs uppercase tracking-wider text-gray-500 mb-1.5">
                {label}
            </span>
            {children}
        </label>
    );
}

/**
 * One of the project's five lists of sentences.
 *
 * A textarea of newline-separated lines would be less code, but her validator
 * counts the list and refuses duplicates within it, so the thing being edited
 * had better be the list itself.
 */
function StringList({ label, items, minimum, onChange }) {
    return (
        <div>
            <div className="flex items-center gap-2 mb-2">
                <span className="text-xs uppercase tracking-wider text-gray-500">{label}</span>

                <span className="text-[11px] text-gray-600">
                    {items.length}{minimum > 1 ? " of " + minimum + "+" : ""}
                </span>

                <button
                    type="button"
                    onClick={() => onChange([...items, ""])}
                    className="ml-auto flex items-center gap-1.5 text-xs text-[#00b0c2] hover:underline"
                >
                    <Plus size={13} /> Add
                </button>
            </div>

            <div className="space-y-2">
                {items.map((item, index) => (
                    <div key={index} className="flex gap-2">
                        <textarea
                            rows="2"
                            value={item}
                            onChange={(e) => onChange(items.map((v, i) => (i === index ? e.target.value : v)))}
                            className={inputClass}
                        />

                        <button
                            type="button"
                            onClick={() => onChange(items.filter((_, i) => i !== index))}
                            aria-label={`Remove ${label} line ${index + 1}`}
                            className="p-2.5 h-fit rounded-xl border border-red-400/30 text-red-400 hover:bg-red-400/10"
                        >
                            <Trash2 size={15} />
                        </button>
                    </div>
                ))}
            </div>
        </div>
    );
}

function QuizForm({ value, onChange }) {
    const questions = value.questions ?? [];

    const patch = (index, changes) => onChange({
        ...value,
        questions: questions.map((question, i) => (i === index ? { ...question, ...changes } : question)),
    });

    return (
        <div className="space-y-5">
            {questions.map((question, index) => (
                <div key={question.question_id ?? index}
                     className="rounded-2xl border border-white/10 bg-black/20 p-5 space-y-4">

                    <div className="flex items-center gap-3">
                        <span className="text-xs font-bold text-[#00b0c2]">
                            Question {index + 1}
                        </span>

                        <select
                            value={question.question_type}
                            onChange={(e) => patch(index, { question_type: e.target.value })}
                            className="ml-auto p-2 rounded-lg bg-black/40 border border-white/10 text-white text-xs"
                        >
                            {QUESTION_TYPES.map((type) => (
                                <option key={type.id} value={type.id}>{type.label}</option>
                            ))}
                        </select>
                    </div>

                    <textarea
                        rows="2"
                        value={question.question}
                        onChange={(e) => patch(index, { question: e.target.value })}
                        className={inputClass}
                    />

                    {/* Four options, keyed A to D. The keys are fixed - her
                        validator wants exactly that set - so they are labels
                        rather than anything anyone can type into. */}
                    <div className="grid sm:grid-cols-2 gap-2">
                        {OPTION_KEYS.map((key) => {
                            const option = (question.options ?? []).find((o) => o.key === key)
                                ?? { key, text: "" };

                            return (
                                <div key={key} className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => patch(index, { correct_option: key })}
                                        title={"Mark " + key + " correct"}
                                        className={`w-8 h-8 shrink-0 rounded-lg text-xs font-bold transition ${
                                            question.correct_option === key
                                                ? "bg-green-400/20 border border-green-400/40 text-green-300"
                                                : "bg-white/5 border border-white/10 text-gray-400 hover:border-[#0097a7]/40"
                                        }`}
                                    >
                                        {key}
                                    </button>

                                    <input
                                        type="text"
                                        value={option.text}
                                        onChange={(e) => patch(index, {
                                            options: OPTION_KEYS.map((k) => {
                                                const existing = (question.options ?? []).find((o) => o.key === k)
                                                    ?? { key: k, text: "" };

                                                return k === key ? { key: k, text: e.target.value } : existing;
                                            }),
                                        })}
                                        className={inputClass}
                                    />
                                </div>
                            );
                        })}
                    </div>

                    <p className="text-[11px] text-gray-600">
                        The lit letter is the correct answer - click another to move it.
                    </p>

                    <Field label="Why that answer is right">
                        <textarea
                            rows="2"
                            value={question.correct_answer_explanation}
                            onChange={(e) => patch(index, { correct_answer_explanation: e.target.value })}
                            className={inputClass}
                        />
                    </Field>
                </div>
            ))}
        </div>
    );
}

function ProjectForm({ value, onChange }) {
    const setList = (field) => (items) => onChange({ ...value, [field]: items });
    const setInput = (field) => (e) => onChange({ ...value, [field]: e.target.value });

    return (
        <div className="space-y-5">
            <div className="grid md:grid-cols-[1fr_200px] gap-4">
                <Field label="Title">
                    <input type="text" value={value.title} onChange={setInput("title")} className={inputClass} />
                </Field>

                <Field label="Kind">
                    <select value={value.activity_type} onChange={setInput("activity_type")} className={inputClass}>
                        {ACTIVITY_TYPES.map((type) => (
                            <option key={type.id} value={type.id}>{type.label}</option>
                        ))}
                    </select>
                </Field>
            </div>

            <Field label="Overview">
                <textarea rows="3" value={value.overview} onChange={setInput("overview")} className={inputClass} />
            </Field>

            <Field label="Context">
                <textarea rows="2" value={value.activity_context} onChange={setInput("activity_context")} className={inputClass} />
            </Field>

            <Field label="What you will learn">
                <textarea rows="2" value={value.learning_objective} onChange={setInput("learning_objective")} className={inputClass} />
            </Field>

            <StringList label="Tasks" items={value.tasks ?? []} minimum={3} onChange={setList("tasks")} />
            <StringList label="Requirements" items={value.technical_requirements ?? []} minimum={1} onChange={setList("technical_requirements")} />
            <StringList label="Constraints" items={value.constraints ?? []} minimum={1} onChange={setList("constraints")} />
            <StringList label="Deliverables" items={value.deliverables ?? []} minimum={1} onChange={setList("deliverables")} />
            <StringList label="Done when" items={value.acceptance_criteria ?? []} minimum={3} onChange={setList("acceptance_criteria")} />
        </div>
    );
}

function ResourcesForm({ value, onChange }) {
    const resources = value.resources ?? [];

    const patch = (index, changes) => onChange({
        ...value,
        resources: resources.map((resource, i) => (i === index ? { ...resource, ...changes } : resource)),
    });

    return (
        <div className="space-y-5">
            {resources.map((resource, index) => (
                <div key={index} className="rounded-2xl border border-white/10 bg-black/20 p-5 space-y-4">
                    <div className="flex items-center gap-3">
                        <span className="text-xs font-bold text-[#00b0c2]">
                            Resource {index + 1}
                        </span>

                        <button
                            type="button"
                            onClick={() => onChange({
                                ...value,
                                resources: resources.filter((_, i) => i !== index),
                            })}
                            aria-label={`Remove resource ${index + 1}`}
                            className="ml-auto p-2 rounded-lg border border-red-400/30 text-red-400 hover:bg-red-400/10"
                        >
                            <Trash2 size={15} />
                        </button>
                    </div>

                    <div className="grid md:grid-cols-[1fr_220px] gap-4">
                        <Field label="Title">
                            <input type="text" value={resource.title}
                                onChange={(e) => patch(index, { title: e.target.value })}
                                className={inputClass} />
                        </Field>

                        <Field label="Kind">
                            <select value={resource.resource_type}
                                onChange={(e) => patch(index, { resource_type: e.target.value })}
                                className={inputClass}>
                                {RESOURCE_TYPES.map((type) => (
                                    <option key={type.id} value={type.id}>{type.label}</option>
                                ))}
                            </select>
                        </Field>
                    </div>

                    <Field label="Link">
                        <input type="url" value={resource.url}
                            onChange={(e) => patch(index, { url: e.target.value })}
                            className={inputClass} />
                    </Field>

                    <Field label="Source">
                        <input type="text" value={resource.source_name}
                            onChange={(e) => patch(index, { source_name: e.target.value })}
                            className={inputClass} />
                    </Field>

                    <Field label="Description">
                        <textarea rows="2" value={resource.description}
                            onChange={(e) => patch(index, { description: e.target.value })}
                            className={inputClass} />
                    </Field>

                    <Field label="Why it was picked">
                        <textarea rows="2" value={resource.why_recommended}
                            onChange={(e) => patch(index, { why_recommended: e.target.value })}
                            className={inputClass} />
                    </Field>
                </div>
            ))}

            {resources.length < 3 && (
                <button
                    type="button"
                    onClick={() => onChange({
                        ...value,
                        resources: [...resources, {
                            title: "",
                            url: "",
                            resource_type: "official_documentation",
                            source_name: "",
                            description: "",
                            why_recommended: "",
                        }],
                    })}
                    className="flex items-center gap-2 px-5 py-2 rounded-xl border border-[#0097a7]/30 text-[#00b0c2] hover:bg-[#0097a7]/10 transition text-sm"
                >
                    <Plus size={16} /> Add a resource
                </button>
            )}
        </div>
    );
}

const TITLES = {
    quiz: "Edit the quiz",
    project: "Edit the project",
    external_resources: "Edit the resources",
};

/**
 * One stage of a proposal, in a form of its own.
 *
 * A stage at a time because that is how she takes it: an edit names one stage
 * and carries only that stage's result, so three stages are three forms and
 * three saves rather than one form that could half-succeed.
 */
function LearningProposalEditor({ stage, value, busy, onCancel, onSave }) {
    // Edited in a copy. Abandoning halfway should leave the proposal on screen
    // as the AI wrote it, not as it was part-way through being rewritten.
    const [draft, setDraft] = useState(value);

    const problems = problemsWith(stage, draft);

    return (
        <div className="rounded-2xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-5">
            <div className="flex items-center justify-between mb-5">
                <h4 className="font-bold">{TITLES[stage]}</h4>

                <button onClick={onCancel} disabled={busy}
                    className="flex items-center gap-2 text-sm text-gray-400 hover:text-white disabled:opacity-50">
                    <X size={16} /> Cancel
                </button>
            </div>

            {stage === "quiz" && <QuizForm value={draft} onChange={setDraft} />}
            {stage === "project" && <ProjectForm value={draft} onChange={setDraft} />}
            {stage === "external_resources" && <ResourcesForm value={draft} onChange={setDraft} />}

            {problems.length > 0 && (
                <div className="mt-5 flex items-start gap-2 rounded-xl border border-yellow-400/30 bg-yellow-400/5 p-3">
                    <AlertTriangle size={15} className="text-yellow-400 shrink-0 mt-0.5" />

                    <ul className="text-xs text-yellow-100 space-y-1">
                        {problems.slice(0, 6).map((problem) => (
                            <li key={problem}>{problem}</li>
                        ))}

                        {problems.length > 6 && (
                            <li className="text-yellow-200/70">
                                and {problems.length - 6} more.
                            </li>
                        )}
                    </ul>
                </div>
            )}

            <button
                onClick={() => onSave(draft)}
                disabled={busy || problems.length > 0}
                className={`mt-5 flex items-center gap-2 font-bold px-6 py-2.5 rounded-xl transition ${
                    busy || problems.length > 0
                        ? "bg-gray-700 text-gray-400 cursor-not-allowed"
                        : "bg-[#0097a7] text-black hover:scale-105"
                }`}
            >
                <Save size={16} />
                {busy ? "Saving..." : "Save this stage"}
            </button>
        </div>
    );
}

export default LearningProposalEditor;
