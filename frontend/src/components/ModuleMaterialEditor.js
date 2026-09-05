import React, { useCallback, useEffect, useState } from "react";
import api from "../api";
import {
    Brain, Code2, Plus, Pencil, Trash2, X, Save, Sparkles, AlertTriangle,
    BookOpen, Video,
} from "lucide-react";

const QUESTION_TYPES = [
    { id: "multiple_choice", label: "Multiple choice" },
    { id: "true_false", label: "True / false" },
    { id: "short_answer", label: "Short answer" },
];

// Mirrors ExternalResourceController::TYPES. The server refuses anything else.
const RESOURCE_TYPES = [
    { id: "documentation", label: "Documentation" },
    { id: "article", label: "Article" },
    { id: "course", label: "Course" },
    { id: "social_account", label: "Social account" },
];

// Mirrors MaterialAuthoringController::OPTION_COUNT. Short answer has none.
const OPTION_COUNT = { multiple_choice: 4, true_false: 2 };

/**
 * The slots a question of this type gets. Switching type resets them because
 * the two sets never carry over sensibly - True and False are not the start of
 * a multiple choice question.
 */
const optionsForType = (type) =>
    type === "true_false" ? ["True", "False"]
    : type === "short_answer" ? []
    : Array(OPTION_COUNT.multiple_choice).fill("");

const blankQuestion = () => ({
    id: null,
    question: "",
    type: "multiple_choice",
    options: optionsForType("multiple_choice"),
    answer: "",
    explanation: "",
});

/**
 * Correcting the quizzes, projects and reading list on one module.
 *
 * All of this arrives from the AI, so a wrong answer or a clumsy sentence is
 * the repair being made. Editing only: the learning workflow writes the
 * material, and nothing here creates it from nothing. There were New Quiz and
 * New Project buttons, but no route behind them - both saved with PUT to an
 * id that did not exist yet, so the only thing either could do was 404.
 *
 * Tutorials are absent on purpose. A tutorial is a video somebody else made,
 * so the only things that can be changed about it are which video it is and
 * whether it stays - regenerate and delete, both on the run. The server draws
 * the same line by giving tutorials no update route at all, and so does
 * Nour's service: her review stage allows an edit of quiz, project and
 * external_resources, and answers a tutorial edit with "Tutorial cannot be
 * edited. Use regenerate instead."
 *
 * It takes the module it is given rather than picking one, so it sits under
 * whatever already names a module - today, the selected run.
 */
function ModuleMaterialEditor({ versionNodeId, onChanged }) {
    const [material, setMaterial] = useState(null);
    const [busy, setBusy] = useState(false);

    // Only one thing is edited at a time - a half-finished quiz and a
    // half-finished project on screen together invites saving the wrong one.
    const [quizDraft, setQuizDraft] = useState(null);
    const [projectDraft, setProjectDraft] = useState(null);
    const [resourceDraft, setResourceDraft] = useState(null);

    const loadMaterial = useCallback(async () => {
        if (!versionNodeId) {
            setMaterial(null);
            return;
        }

        try {
            const response = await api.get(`/version-nodes/${versionNodeId}/material`);
            setMaterial(response.data);
        } catch (err) {
            console.error(err);
        }
    }, [versionNodeId]);

    // Drafts are dropped when the module changes: they are keyed to the
    // material that was on screen, and saving one against another module's
    // quiz would write the wrong questions.
    useEffect(() => {
        loadMaterial();
        setQuizDraft(null);
        setProjectDraft(null);
        setResourceDraft(null);
    }, [loadMaterial]);

    const fail = (err) => {
        const errors = err.response?.data?.errors;
        alert(errors
            ? Object.values(errors).flat().join("\n")
            : (err.response?.data?.message ?? "Something went wrong."));
    };

    // -----------------------------------------------------------------
    // Quizzes
    // -----------------------------------------------------------------

    const editQuiz = (quiz) => {
        setProjectDraft(null);
        setQuizDraft({
            id: quiz.id,
            title: quiz.title,
            questions: quiz.questions.map((q) => ({
                // Carried back on save so the row survives the edit - past
                // submissions are keyed to it.
                id: q.id,
                question: q.question,
                type: q.type,
                // Multiple choice gets four slots to fill; true/false has
                // exactly two, and padding it would offer blanks that can
                // never be used.
                options: q.type === "multiple_choice"
                    ? [...q.options, "", "", "", ""].slice(0, Math.max(4, q.options.length))
                    : [...q.options],
                answer: q.answer,
                // Why the answer is right. Sent back on save because the
                // server refuses an edit that changes the question and
                // leaves this describing the old one.
                explanation: q.explanation ?? "",
            })),
        });
    };

    const patchQuestion = (index, changes) => {
        setQuizDraft((draft) => ({
            ...draft,
            questions: draft.questions.map((q, i) => (i === index ? { ...q, ...changes } : q)),
        }));
    };

    const patchOption = (questionIndex, optionIndex, value) => {
        setQuizDraft((draft) => ({
            ...draft,
            questions: draft.questions.map((q, i) => (
                i === questionIndex
                    ? { ...q, options: q.options.map((o, oi) => (oi === optionIndex ? value : o)) }
                    : q
            )),
        }));
    };

    const saveQuiz = async () => {
        setBusy(true);

        try {
            const payload = {
                title: quizDraft.title,
                questions: quizDraft.questions.map((q) => ({
                    ...(q.id ? { id: q.id } : {}),
                    question: q.question,
                    type: q.type,
                    options: q.type === "short_answer"
                        ? []
                        : q.options.map((o) => o.trim()).filter(Boolean),
                    answer: q.answer,
                    explanation: q.explanation?.trim() || null,
                })),
            };

            // Edit only - the learning workflow writes quizzes, and a
            // manager fixes what it wrote.
            await api.put(`/quizzes/${quizDraft.id}`, payload);

            setQuizDraft(null);
            await loadMaterial();
            onChanged?.();
        } catch (err) {
            fail(err);
        } finally {
            setBusy(false);
        }
    };

    const deleteQuiz = async (quiz) => {
        if (!window.confirm(
            `Delete "${quiz.title}"?\n\n`
            + "Students who already took it keep their score, but the "
            + "questions go. This cannot be undone."
        )) return;

        try {
            await api.delete(`/quizzes/${quiz.id}`);
            if (quizDraft?.id === quiz.id) setQuizDraft(null);
            await loadMaterial();
            onChanged?.();
        } catch (err) {
            fail(err);
        }
    };

    // -----------------------------------------------------------------
    // Projects
    // -----------------------------------------------------------------

    const editProject = (project) => {
        setQuizDraft(null);
        setProjectDraft({
            id: project.id,
            title: project.title,
            description: project.description,
            difficulty: project.difficulty,
            github_url: project.github_url ?? "",
        });
    };

    const saveProject = async () => {
        setBusy(true);

        try {
            const payload = {
                title: projectDraft.title,
                description: projectDraft.description,
                difficulty: projectDraft.difficulty,
                github_url: projectDraft.github_url.trim() || null,
            };

            await api.put(`/projects/${projectDraft.id}`, payload);

            setProjectDraft(null);
            await loadMaterial();
            onChanged?.();
        } catch (err) {
            fail(err);
        } finally {
            setBusy(false);
        }
    };

    const deleteProject = async (project) => {
        if (!window.confirm(`Delete "${project.title}"?\n\nThis cannot be undone.`)) return;

        try {
            await api.delete(`/projects/${project.id}`);
            if (projectDraft?.id === project.id) setProjectDraft(null);
            await loadMaterial();
            onChanged?.();
        } catch (err) {
            fail(err);
        }
    };

    // -----------------------------------------------------------------
    // External resources
    //
    // A title, a link and what kind of thing it is - all of them things a
    // person can sensibly correct, which is why these are editable where a
    // tutorial is not.
    // -----------------------------------------------------------------

    const editResource = (resource) => {
        setQuizDraft(null);
        setProjectDraft(null);
        setResourceDraft({
            id: resource.id,
            title: resource.title,
            url: resource.url,
            type: resource.type,
        });
    };

    const saveResource = async () => {
        setBusy(true);

        try {
            await api.put(`/external-resources/${resourceDraft.id}`, {
                // The server validates this against the module it belongs to,
                // so it travels with the edit even though it cannot change.
                version_node_id: versionNodeId,
                title: resourceDraft.title.trim(),
                url: resourceDraft.url.trim(),
                type: resourceDraft.type,
            });

            setResourceDraft(null);
            await loadMaterial();
            onChanged?.();
        } catch (err) {
            fail(err);
        } finally {
            setBusy(false);
        }
    };

    const deleteResource = async (resource) => {
        if (!window.confirm(`Remove "${resource.title}" from the reading list?\n\nThis cannot be undone.`)) return;

        try {
            await api.delete(`/external-resources/${resource.id}`);
            if (resourceDraft?.id === resource.id) setResourceDraft(null);
            await loadMaterial();
            onChanged?.();
        } catch (err) {
            fail(err);
        }
    };

    // -----------------------------------------------------------------

    const statusStyle = (s) =>
        s === "approved" ? "border-green-400/30 bg-green-400/10 text-green-300"
        : s === "rejected" ? "border-red-400/30 bg-red-400/10 text-red-300"
        : "border-yellow-400/30 bg-yellow-400/10 text-yellow-300";

    // Every option filled, not just some - the server refuses a short set, and
    // finding that out on save is worse than a disabled button.
    const filledOptions = (q) => q.options.filter((o) => o.trim()).length;

    // Two options that read the same make a question unanswerable - the answer
    // is matched on text, so a duplicate is both right and wrong.
    const duplicateOptions = (q) => {
        const filled = q.options.map((o) => o.trim().toLowerCase()).filter(Boolean);

        return filled.length - new Set(filled).size;
    };

    /**
     * An explanation that was written for a different question is worse than
     * none, so the server refuses an edit that changes a question and leaves
     * its explanation untouched. Same rule as validate_edited_quiz() in the AI
     * system. Caught here so Save explains instead of the server erroring.
     */
    const explanationOutOfDate = (q) => {
        const original = material?.quizzes
            ?.find((quiz) => quiz.id === quizDraft?.id)
            ?.questions.find((existing) => existing.id === q.id);

        if (!original || !original.explanation) return false;

        const contentChanged =
            original.question !== q.question
            || original.answer !== q.answer
            || original.options.join(" ")
                !== q.options.map((o) => o.trim()).filter(Boolean).join(" ");

        return contentChanged && (q.explanation ?? "").trim() === original.explanation.trim();
    };

    const questionValid = (q) =>
        q.question.trim()
        && q.answer.trim()
        && !explanationOutOfDate(q)
        && (q.type === "short_answer"
            || (filledOptions(q) === OPTION_COUNT[q.type] && duplicateOptions(q) === 0));

    const quizValid = quizDraft
        && quizDraft.title.trim()
        && quizDraft.questions.length > 0
        && quizDraft.questions.every(questionValid);

    const projectValid = projectDraft
        && projectDraft.title.trim()
        && projectDraft.description.trim();

    // The server wants a real URL, and finding that out on save is worse than
    // a button that stays out until the link is one.
    const resourceValid = resourceDraft
        && resourceDraft.title.trim()
        && /^https?:\/\/\S+$/i.test(resourceDraft.url.trim());

    if (!versionNodeId) {
        return <p className="text-sm text-gray-500">Pick a module to see its material.</p>;
    }

    return (
        <div>
            {/* ------------------------------------------------ */}
            {/* Quizzes                                          */}
            {/* ------------------------------------------------ */}
            <div className="flex items-center gap-3 mb-5">
                <Brain className="text-purple-400" />
                <h2 className="text-2xl font-bold">Quizzes</h2>
                <span className="text-xs px-3 py-1 rounded-full bg-white/5 text-gray-400">
                    {material?.quizzes?.length ?? 0}
                </span>
            </div>

            {quizDraft && (
                <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-6 mb-8">
                    <div className="flex items-center justify-between mb-5">
                        <h3 className="text-xl font-bold">Edit Quiz</h3>

                        <button onClick={() => setQuizDraft(null)}
                            className="flex items-center gap-2 text-sm text-gray-400 hover:text-white">
                            <X size={16} /> Cancel
                        </button>
                    </div>

                    <input type="text" placeholder="Quiz title"
                        value={quizDraft.title}
                        onChange={(e) => setQuizDraft({ ...quizDraft, title: e.target.value })}
                        className="w-full p-3 rounded-xl bg-black/40 border border-white/10 text-white mb-6" />

                    <div className="space-y-5">
                        {quizDraft.questions.map((q, qi) => (
                            <div key={qi} className="rounded-2xl border border-white/10 bg-black/30 p-5">
                                <div className="flex items-center justify-between mb-3">
                                    <span className="text-xs text-gray-500">
                                        QUESTION {qi + 1}
                                        {q.id ? "" : " · new"}
                                    </span>

                                    {quizDraft.questions.length > 1 && (
                                        <button
                                            onClick={() => setQuizDraft({
                                                ...quizDraft,
                                                questions: quizDraft.questions.filter((_, i) => i !== qi),
                                            })}
                                            className="p-2 rounded-lg border border-red-400/30 text-red-400 hover:bg-red-400/10">
                                            <Trash2 size={14} />
                                        </button>
                                    )}
                                </div>

                                <textarea rows="2" placeholder="The question"
                                    value={q.question}
                                    onChange={(e) => patchQuestion(qi, { question: e.target.value })}
                                    className="w-full p-3 rounded-xl bg-black/40 border border-white/10 text-white mb-3" />

                                <select value={q.type}
                                    onChange={(e) => patchQuestion(qi, {
                                        type: e.target.value,
                                        options: optionsForType(e.target.value),
                                        answer: "",
                                    })}
                                    className="p-3 rounded-xl bg-black/40 border border-white/10 text-white mb-4">
                                    {QUESTION_TYPES.map((t) => (
                                        <option key={t.id} value={t.id}>{t.label}</option>
                                    ))}
                                </select>

                                {q.type === "short_answer" ? (
                                    <div>
                                        <label className="block text-xs text-gray-500 mb-2">
                                            Expected answer — a professor grades these by hand
                                        </label>
                                        <input type="text" value={q.answer}
                                            onChange={(e) => patchQuestion(qi, { answer: e.target.value })}
                                            className="w-full p-3 rounded-xl bg-black/40 border border-white/10 text-white" />
                                    </div>
                                ) : (
                                    <div>
                                        <label className="block text-xs text-gray-500 mb-2">
                                            {q.type === "multiple_choice"
                                                ? "All four options — click one to mark it correct"
                                                : "Options — click one to mark it correct"}
                                        </label>

                                        <div className="space-y-2">
                                            {q.options.map((option, oi) => {
                                                const isCorrect = option.trim()
                                                    && option.trim().toLowerCase() === q.answer.trim().toLowerCase();

                                                return (
                                                    <div key={oi} className="flex items-center gap-2">
                                                        <button
                                                            onClick={() => patchQuestion(qi, { answer: option })}
                                                            disabled={!option.trim()}
                                                            title="Mark correct"
                                                            className={`w-9 h-9 shrink-0 rounded-lg border text-xs font-bold transition ${
                                                                isCorrect
                                                                    ? "bg-[#0097a7] border-[#0097a7] text-black"
                                                                    : "border-white/10 text-gray-500 hover:border-[#0097a7]/40"
                                                            }`}>
                                                            {String.fromCharCode(65 + oi)}
                                                        </button>

                                                        <input type="text" value={option}
                                                            placeholder={`Option ${String.fromCharCode(65 + oi)}`}
                                                            onChange={(e) => patchOption(qi, oi, e.target.value)}
                                                            className={`flex-1 p-3 rounded-xl bg-black/40 border text-white ${
                                                                isCorrect ? "border-[#0097a7]/40" : "border-white/10"
                                                            }`} />
                                                    </div>
                                                );
                                            })}
                                        </div>

                                        {/* Both directions, because older material can
                                            arrive with more than four and a disabled
                                            Save button with no reason is a dead end. */}
                                        {filledOptions(q) < OPTION_COUNT[q.type] && (
                                            <p className="text-xs text-yellow-300 mt-2">
                                                {OPTION_COUNT[q.type] - filledOptions(q)} more
                                                {" "}option{OPTION_COUNT[q.type] - filledOptions(q) === 1 ? "" : "s"}
                                                {" "}to fill in.
                                            </p>
                                        )}

                                        {filledOptions(q) > OPTION_COUNT[q.type] && (
                                            <p className="text-xs text-yellow-300 mt-2">
                                                Only {OPTION_COUNT[q.type]} options are allowed.
                                                Clear {filledOptions(q) - OPTION_COUNT[q.type]} of them.
                                            </p>
                                        )}

                                        {duplicateOptions(q) > 0 && (
                                            <p className="text-xs text-yellow-300 mt-2">
                                                Two options say the same thing.
                                            </p>
                                        )}

                                        {!q.answer.trim() && (
                                            <p className="text-xs text-yellow-300 mt-2">
                                                Nothing marked correct yet.
                                            </p>
                                        )}
                                    </div>
                                )}

                                {/* Why the answer is right. Optional on a
                                    hand-written question; where one already
                                    exists it has to be rewritten alongside
                                    the question it explains. */}
                                <div className="mt-4">
                                    <label className="block text-xs text-gray-500 mb-2">
                                        Why the answer is right
                                        <span className="text-gray-600"> (optional)</span>
                                    </label>

                                    <textarea
                                        rows="2"
                                        value={q.explanation ?? ""}
                                        onChange={(e) => patchQuestion(qi, { explanation: e.target.value })}
                                        placeholder="Explain the correct answer"
                                        className={`w-full p-3 rounded-xl bg-black/40 border text-white text-sm ${
                                            explanationOutOfDate(q)
                                                ? "border-yellow-400/50"
                                                : "border-white/10"
                                        }`}
                                    />

                                    {explanationOutOfDate(q) && (
                                        <p className="text-xs text-yellow-300 mt-2">
                                            You changed this question, so its explanation
                                            needs updating too.
                                        </p>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="flex flex-wrap gap-3 mt-6">
                        <button
                            onClick={() => setQuizDraft({
                                ...quizDraft,
                                questions: [...quizDraft.questions, blankQuestion()],
                            })}
                            className="flex items-center gap-2 px-5 py-2 rounded-xl border border-white/10 text-gray-300 hover:border-[#0097a7]/40 transition text-sm">
                            <Plus size={16} /> Add question
                        </button>

                        <button onClick={saveQuiz} disabled={busy || !quizValid}
                            className={`flex items-center gap-2 font-bold px-8 py-2 rounded-xl transition ${
                                busy || !quizValid
                                    ? "bg-gray-700 text-gray-400 cursor-not-allowed"
                                    : "bg-[#0097a7] text-black hover:scale-105"
                            }`}>
                            <Save size={16} />
                            {busy ? "Saving..." : "Save Changes"}
                        </button>
                    </div>
                </div>
            )}

            {material?.quizzes?.length === 0 && !quizDraft && (
                <p className="text-gray-500 mb-10">
                    This module has no quiz yet.
                </p>
            )}

            <div className="space-y-4 mb-12">
                {material?.quizzes?.map((quiz) => (
                    <div key={quiz.id} className="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                        <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                            <div className="min-w-0">
                                <div className="flex items-center gap-3 flex-wrap mb-2">
                                    <h3 className="text-lg font-bold">{quiz.title}</h3>

                                    <span className={`text-xs px-3 py-1 rounded-full border ${statusStyle(quiz.review_status)}`}>
                                        {quiz.review_status}
                                    </span>

                                    {quiz.from_ai && (
                                        <span className="text-xs px-3 py-1 rounded-full border border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2] flex items-center gap-1">
                                            <Sparkles size={12} /> from the AI
                                        </span>
                                    )}
                                </div>

                                <p className="text-xs text-gray-500">
                                    {quiz.questions.length} question{quiz.questions.length === 1 ? "" : "s"}
                                </p>

                                {quiz.review_notes && (
                                    <p className="mt-2 text-xs text-gray-400 border-l-2 border-white/10 pl-3">
                                        Reviewer said: {quiz.review_notes}
                                    </p>
                                )}
                            </div>

                            <div className="flex gap-3 shrink-0">
                                <button onClick={() => editQuiz(quiz)}
                                    className="p-3 rounded-xl border border-[#0097a7]/30 text-[#0097a7] hover:bg-[#0097a7]/10">
                                    <Pencil size={18} />
                                </button>
                                <button onClick={() => deleteQuiz(quiz)}
                                    className="p-3 rounded-xl border border-red-400/30 text-red-400 hover:bg-red-400/10">
                                    <Trash2 size={18} />
                                </button>
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            {/* ------------------------------------------------ */}
            {/* Projects                                         */}
            {/* ------------------------------------------------ */}
            <div className="flex items-center gap-3 mb-5">
                <Code2 className="text-green-400" />
                <h2 className="text-2xl font-bold">Projects</h2>
                <span className="text-xs px-3 py-1 rounded-full bg-white/5 text-gray-400">
                    {material?.projects?.length ?? 0}
                </span>
            </div>

            {projectDraft && (
                <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-6 mb-8">
                    <div className="flex items-center justify-between mb-5">
                        <h3 className="text-xl font-bold">Edit Project</h3>

                        <button onClick={() => setProjectDraft(null)}
                            className="flex items-center gap-2 text-sm text-gray-400 hover:text-white">
                            <X size={16} /> Cancel
                        </button>
                    </div>

                    <div className="grid md:grid-cols-2 gap-4">
                        <input type="text" placeholder="Project title"
                            value={projectDraft.title}
                            onChange={(e) => setProjectDraft({ ...projectDraft, title: e.target.value })}
                            className="p-3 rounded-xl bg-black/40 border border-white/10 text-white" />

                        <select value={projectDraft.difficulty}
                            onChange={(e) => setProjectDraft({ ...projectDraft, difficulty: e.target.value })}
                            className="p-3 rounded-xl bg-black/40 border border-white/10 text-white">
                            <option value="beginner">Beginner</option>
                            <option value="intermediate">Intermediate</option>
                            <option value="advanced">Advanced</option>
                        </select>
                    </div>

                    <textarea rows="10" placeholder="The brief: what to build, the requirements, and when it is done"
                        value={projectDraft.description}
                        onChange={(e) => setProjectDraft({ ...projectDraft, description: e.target.value })}
                        className="w-full mt-4 p-3 rounded-xl bg-black/40 border border-white/10 text-white font-mono text-sm" />

                    <input type="url" placeholder="Starter repository (optional)"
                        value={projectDraft.github_url}
                        onChange={(e) => setProjectDraft({ ...projectDraft, github_url: e.target.value })}
                        className="w-full mt-4 p-3 rounded-xl bg-black/40 border border-white/10 text-white" />

                    <button onClick={saveProject} disabled={busy || !projectValid}
                        className={`mt-6 flex items-center gap-2 font-bold px-8 py-3 rounded-xl transition ${
                            busy || !projectValid
                                ? "bg-gray-700 text-gray-400 cursor-not-allowed"
                                : "bg-[#0097a7] text-black hover:scale-105"
                        }`}>
                        <Save size={16} />
                        {busy ? "Saving..." : "Save Changes"}
                    </button>
                </div>
            )}

            {material?.projects?.length === 0 && !projectDraft && (
                <p className="text-gray-500">This module has no project yet.</p>
            )}

            <div className="space-y-4">
                {material?.projects?.map((project) => (
                    <div key={project.id} className="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                        <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                            <div className="min-w-0">
                                <div className="flex items-center gap-3 flex-wrap mb-2">
                                    <h3 className="text-lg font-bold">{project.title}</h3>

                                    <span className={`text-xs px-3 py-1 rounded-full border ${statusStyle(project.review_status)}`}>
                                        {project.review_status}
                                    </span>

                                    <span className="text-xs text-gray-500 capitalize">
                                        {project.difficulty}
                                    </span>

                                    {project.from_ai && (
                                        <span className="text-xs px-3 py-1 rounded-full border border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2] flex items-center gap-1">
                                            <Sparkles size={12} /> from the AI
                                        </span>
                                    )}
                                </div>

                                <p className="text-sm text-gray-400 line-clamp-3 whitespace-pre-line">
                                    {project.description}
                                </p>

                                {project.review_notes && (
                                    <p className="mt-2 text-xs text-gray-400 border-l-2 border-white/10 pl-3">
                                        Reviewer said: {project.review_notes}
                                    </p>
                                )}
                            </div>

                            <div className="flex gap-3 shrink-0">
                                <button onClick={() => editProject(project)}
                                    className="p-3 rounded-xl border border-[#0097a7]/30 text-[#0097a7] hover:bg-[#0097a7]/10">
                                    <Pencil size={18} />
                                </button>
                                <button onClick={() => deleteProject(project)}
                                    className="p-3 rounded-xl border border-red-400/30 text-red-400 hover:bg-red-400/10">
                                    <Trash2 size={18} />
                                </button>
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            {/* ------------------------------------------------ */}
            {/* External resources                               */}
            {/* ------------------------------------------------ */}
            <div className="flex items-center gap-3 mt-12 mb-5">
                <BookOpen className="text-amber-400" />
                <h2 className="text-2xl font-bold">External resources</h2>
                <span className="text-xs px-3 py-1 rounded-full bg-white/5 text-gray-400">
                    {material?.external_resources?.length ?? 0}
                </span>
            </div>

            {resourceDraft && (
                <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-6 mb-8">
                    <div className="flex items-center justify-between mb-5">
                        <h3 className="text-xl font-bold">Edit resource</h3>

                        <button onClick={() => setResourceDraft(null)}
                            className="flex items-center gap-2 text-sm text-gray-400 hover:text-white">
                            <X size={16} /> Cancel
                        </button>
                    </div>

                    <div className="grid md:grid-cols-2 gap-4">
                        <input type="text" placeholder="Title"
                            value={resourceDraft.title}
                            onChange={(e) => setResourceDraft({ ...resourceDraft, title: e.target.value })}
                            className="p-3 rounded-xl bg-black/40 border border-white/10 text-white" />

                        <select value={resourceDraft.type}
                            onChange={(e) => setResourceDraft({ ...resourceDraft, type: e.target.value })}
                            className="p-3 rounded-xl bg-black/40 border border-white/10 text-white">
                            {RESOURCE_TYPES.map((type) => (
                                <option key={type.id} value={type.id}>{type.label}</option>
                            ))}
                        </select>
                    </div>

                    <input type="url" placeholder="https://..."
                        value={resourceDraft.url}
                        onChange={(e) => setResourceDraft({ ...resourceDraft, url: e.target.value })}
                        className="w-full mt-4 p-3 rounded-xl bg-black/40 border border-white/10 text-white" />

                    <button onClick={saveResource} disabled={busy || !resourceValid}
                        className={`mt-6 flex items-center gap-2 font-bold px-8 py-3 rounded-xl transition ${
                            busy || !resourceValid
                                ? "bg-gray-700 text-gray-400 cursor-not-allowed"
                                : "bg-[#0097a7] text-black hover:scale-105"
                        }`}>
                        <Save size={16} />
                        {busy ? "Saving..." : "Save Changes"}
                    </button>
                </div>
            )}

            {material?.external_resources?.length === 0 && !resourceDraft && (
                <p className="text-gray-500">This module has no reading list yet.</p>
            )}

            <div className="space-y-3">
                {material?.external_resources?.map((resource) => (
                    <div key={resource.id} className="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                        <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                            <div className="min-w-0">
                                <div className="flex items-center gap-3 flex-wrap mb-2">
                                    <h3 className="text-lg font-bold">{resource.title}</h3>

                                    <span className={`text-xs px-3 py-1 rounded-full border ${statusStyle(resource.review_status)}`}>
                                        {resource.review_status}
                                    </span>

                                    <span className="text-xs text-gray-500">
                                        {RESOURCE_TYPES.find((t) => t.id === resource.type)?.label ?? resource.type}
                                    </span>

                                    {resource.from_ai && (
                                        <span className="text-xs px-3 py-1 rounded-full border border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2] flex items-center gap-1">
                                            <Sparkles size={12} /> from the AI
                                        </span>
                                    )}
                                </div>

                                <a href={resource.url} target="_blank" rel="noreferrer"
                                   className="text-sm text-[#00b0c2] hover:underline break-all">
                                    {resource.url}
                                </a>

                                {resource.review_notes && (
                                    <p className="mt-2 text-xs text-gray-400 border-l-2 border-white/10 pl-3">
                                        Reviewer said: {resource.review_notes}
                                    </p>
                                )}
                            </div>

                            <div className="flex gap-3 shrink-0">
                                <button onClick={() => editResource(resource)}
                                    className="p-3 rounded-xl border border-[#0097a7]/30 text-[#0097a7] hover:bg-[#0097a7]/10">
                                    <Pencil size={18} />
                                </button>
                                <button onClick={() => deleteResource(resource)}
                                    className="p-3 rounded-xl border border-red-400/30 text-red-400 hover:bg-red-400/10">
                                    <Trash2 size={18} />
                                </button>
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            {/* Tutorials are deliberately absent, and this says so rather than
                leaving a manager hunting for the section that edits them. A
                tutorial is a video somebody else made: the only things that
                can be changed about it are which video it is and whether it
                stays, which are regenerate and delete on the run itself.
                Nour's service takes the same line - her review stage allows an
                edit of quiz, project and external_resources, and answers a
                tutorial edit with "Tutorial cannot be edited. Use regenerate
                instead." */}
            {material?.tutorials?.length > 0 && (
                <div className="mt-10 flex items-start gap-3 rounded-2xl border border-white/10 bg-white/[0.02] p-4">
                    <Video size={18} className="text-gray-500 shrink-0 mt-0.5" />
                    <div className="min-w-0">
                        <p className="text-sm text-gray-400">
                            The {material.tutorials.length === 1 ? "tutorial" : "tutorials"} on
                            this module cannot be edited here - a tutorial is
                            somebody else's video, so the choices are to keep it
                            or ask for another one. Use regenerate on the run.
                        </p>

                        <ul className="mt-2 space-y-1">
                            {material.tutorials.map((tutorial) => (
                                <li key={tutorial.id} className="text-xs text-gray-500 truncate">
                                    {tutorial.title}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}

            {material && (
                <div className="mt-4 flex items-start gap-3 rounded-2xl border border-white/10 bg-white/[0.02] p-4">
                    <AlertTriangle size={18} className="text-gray-500 shrink-0 mt-0.5" />
                    <p className="text-sm text-gray-400">
                        Editing approved material sends it back to the
                        Content Queue, so students keep the version you
                        already signed off until you approve the change.
                    </p>
                </div>
            )}
        </div>
    );
}

export default ModuleMaterialEditor;
