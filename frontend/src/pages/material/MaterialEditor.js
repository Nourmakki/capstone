import React, { useCallback, useEffect, useState } from "react";
import api from "../../api";
import RoadmapPicker from "../../components/RoadmapPicker";
import {
    Brain, Code2, Plus, Pencil, Trash2, X, Save, Sparkles, AlertTriangle,
} from "lucide-react";

const QUESTION_TYPES = [
    { id: "multiple_choice", label: "Multiple choice" },
    { id: "true_false", label: "True / false" },
    { id: "short_answer", label: "Short answer" },
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

const blankQuiz = () => ({ id: null, title: "", questions: [blankQuestion()] });

const blankProject = (difficulty = "beginner") => ({
    id: null,
    title: "",
    description: "",
    difficulty,
    github_url: "",
});

/**
 * Correcting the quizzes and projects already on a module.
 *
 * Most of this arrives from the AI, so a wrong answer or a clumsy sentence is
 * the common repair. Creating from scratch is here for the modules it has not
 * covered yet.
 */
function MaterialEditor({ onChanged }) {
    const [roadmaps, setRoadmaps] = useState([]);
    const [roadmapId, setRoadmapId] = useState(null);
    const [versions, setVersions] = useState([]);
    const [versionId, setVersionId] = useState(null);
    const [modules, setModules] = useState([]);
    const [moduleId, setModuleId] = useState(null);

    const [material, setMaterial] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);

    // Only one thing is edited at a time - a half-finished quiz and a
    // half-finished project on screen together invites saving the wrong one.
    const [quizDraft, setQuizDraft] = useState(null);
    const [projectDraft, setProjectDraft] = useState(null);

    useEffect(() => {
        api.get("/roadmaps")
            .then((r) => {
                const list = r.data.data ?? [];
                setRoadmaps(list);
                // Open on a roadmap proper, not whichever topic sorts first.
            setRoadmapId((prev) =>
                prev ?? list.find((r) => !r.parent_roadmap_id)?.id ?? list[0]?.id ?? null
            );
            })
            .catch((err) => console.error(err))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => {
        if (!roadmapId) return;

        api.get(`/roadmaps/${roadmapId}/versions`)
            .then((r) => {
                const list = r.data.data ?? [];
                setVersions(list);

                // The published version, not the newest. Fixing something a
                // student reported is the common reason to be here, and the
                // newest version is usually a draft they cannot see.
                const live = list.find((v) => v.status === "Published");

                setVersionId((live ?? list[0])?.id ?? null);
            })
            .catch((err) => console.error(err));
    }, [roadmapId]);

    useEffect(() => {
        if (!versionId) return;

        api.get(`/roadmap-versions/${versionId}/graph`)
            .then((r) => {
                const list = r.data.nodes ?? [];
                setModules(list);
                setModuleId(list[0]?.version_node_id ?? null);
            })
            .catch((err) => console.error(err));
    }, [versionId]);

    const loadMaterial = useCallback(async () => {
        if (!moduleId) {
            setMaterial(null);
            return;
        }

        try {
            const response = await api.get(`/version-nodes/${moduleId}/material`);
            setMaterial(response.data);
        } catch (err) {
            console.error(err);
        }
    }, [moduleId]);

    useEffect(() => {
        loadMaterial();
        setQuizDraft(null);
        setProjectDraft(null);
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

            if (quizDraft.id) {
                await api.put(`/quizzes/${quizDraft.id}`, payload);
            } else {
                await api.post(`/version-nodes/${moduleId}/quizzes`, payload);
            }

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

            if (projectDraft.id) {
                await api.put(`/projects/${projectDraft.id}`, payload);
            } else {
                await api.post(`/version-nodes/${moduleId}/projects`, payload);
            }

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

    const statusStyle = (s) =>
        s === "approved" ? "border-green-400/30 bg-green-400/10 text-green-300"
        : s === "rejected" ? "border-red-400/30 bg-red-400/10 text-red-300"
        : "border-yellow-400/30 bg-yellow-400/10 text-yellow-300";

    // Which version is actually live, so the panel can say when an edit will
    // not reach anybody.
    const selectedVersion = versions.find((v) => v.id === versionId);
    const publishedVersion = versions.find((v) => v.status === "Published");
    const editingOffstage = selectedVersion && selectedVersion.status !== "Published";

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
            || original.options.join(" ")
                !== q.options.map((o) => o.trim()).filter(Boolean).join(" ");

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

    return (
        <div className="p-8">
            <div className="flex items-center gap-3">
                <Pencil size={30} className="text-[#0097a7]" />
                <h1 className="text-3xl lg:text-5xl font-black text-[#0097a7]">
                    Edit Material
                </h1>
            </div>

            <p className="text-gray-400 mt-2 mb-10">
                Fix the quizzes and projects on a module. Editing approved
                material sends it back for review.
            </p>

            {loading ? (
                <p className="text-gray-400">Loading...</p>
            ) : (
                <>
                    {/* Roadmap */}
                    <div className="flex flex-wrap gap-3 mb-5">
                        <RoadmapPicker roadmaps={roadmaps} selectedId={roadmapId}
                            onSelect={setRoadmapId} />
                    </div>

                    {/* Version + module */}
                    <div className="grid md:grid-cols-2 gap-4 mb-10">
                        <div>
                            <label className="block text-sm text-gray-300 mb-2">Version</label>
                            <select value={versionId ?? ""}
                                onChange={(e) => setVersionId(Number(e.target.value))}
                                className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white">
                                {versions.map((v) => (
                                    <option key={v.id} value={v.id}>
                                        v{v.version_number}{v.title ? ` · ${v.title}` : ""} ({v.status})
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="block text-sm text-gray-300 mb-2">Module</label>
                            <select value={moduleId ?? ""}
                                onChange={(e) => setModuleId(Number(e.target.value))}
                                className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white">
                                {modules.length === 0 && <option value="">No modules in this version</option>}
                                {modules.map((m) => (
                                    <option key={m.version_node_id} value={m.version_node_id}>
                                        {m.title}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    {/* Every version keeps its own copy of the material, so
                        an edit here reaches nobody unless this version is the
                        published one. */}
                    {editingOffstage && (
                        <div className="mb-10 flex items-start gap-3 rounded-2xl border border-yellow-400/30 bg-yellow-400/5 p-4">
                            <AlertTriangle size={18} className="text-yellow-400 shrink-0 mt-0.5" />
                            <div>
                                <p className="font-semibold text-yellow-300">
                                    Editing v{selectedVersion.version_number}, which is {selectedVersion.status.toLowerCase()}
                                </p>

                                <p className="text-sm text-gray-300 mt-1">
                                    {publishedVersion
                                        ? `Students are on v${publishedVersion.version_number} and will not see these changes. `
                                        : "No version of this roadmap is published, so students see none of this. "}

                                    {publishedVersion && (
                                        <button
                                            onClick={() => setVersionId(publishedVersion.id)}
                                            className="text-[#00b0c2] underline hover:no-underline">
                                            Switch to v{publishedVersion.version_number}
                                        </button>
                                    )}
                                </p>
                            </div>
                        </div>
                    )}

                    {/* ------------------------------------------------ */}
                    {/* Quizzes                                          */}
                    {/* ------------------------------------------------ */}
                    <div className="flex items-center gap-3 mb-5">
                        <Brain className="text-purple-400" />
                        <h2 className="text-2xl font-bold">Quizzes</h2>
                        <span className="text-xs px-3 py-1 rounded-full bg-white/5 text-gray-400">
                            {material?.quizzes?.length ?? 0}
                        </span>

                        {!quizDraft && moduleId && (
                            <button onClick={() => { setProjectDraft(null); setQuizDraft(blankQuiz()); }}
                                className="ml-auto flex items-center gap-2 px-5 py-2 rounded-xl border border-[#0097a7]/30 text-[#00b0c2] hover:bg-[#0097a7]/10 transition text-sm">
                                <Plus size={16} /> New Quiz
                            </button>
                        )}
                    </div>

                    {quizDraft && (
                        <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-6 mb-8">
                            <div className="flex items-center justify-between mb-5">
                                <h3 className="text-xl font-bold">
                                    {quizDraft.id ? "Edit Quiz" : "New Quiz"}
                                </h3>

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
                                    {busy ? "Saving..." : quizDraft.id ? "Save Changes" : "Create Quiz"}
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

                        {!projectDraft && moduleId && (
                            <button
                                onClick={() => {
                                    setQuizDraft(null);
                                    setProjectDraft(blankProject(material?.module?.difficulty));
                                }}
                                className="ml-auto flex items-center gap-2 px-5 py-2 rounded-xl border border-[#0097a7]/30 text-[#00b0c2] hover:bg-[#0097a7]/10 transition text-sm">
                                <Plus size={16} /> New Project
                            </button>
                        )}
                    </div>

                    {projectDraft && (
                        <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-6 mb-8">
                            <div className="flex items-center justify-between mb-5">
                                <h3 className="text-xl font-bold">
                                    {projectDraft.id ? "Edit Project" : "New Project"}
                                </h3>

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
                                {busy ? "Saving..." : projectDraft.id ? "Save Changes" : "Create Project"}
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

                    {material && (
                        <div className="mt-10 flex items-start gap-3 rounded-2xl border border-white/10 bg-white/[0.02] p-4">
                            <AlertTriangle size={18} className="text-gray-500 shrink-0 mt-0.5" />
                            <p className="text-sm text-gray-400">
                                Editing approved material sends it back to the
                                Content Queue, so students keep the version you
                                already signed off until you approve the change.
                            </p>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

export default MaterialEditor;
