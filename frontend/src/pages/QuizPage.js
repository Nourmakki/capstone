import React, { useCallback, useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import api from "../api";
import {
    ArrowLeft,
    Brain,
    CheckCircle2,
    XCircle,
    AlertCircle,
    Clock3,
    UserRound,
} from "lucide-react";

function QuizPage() {
    const navigate = useNavigate();
    const { quizId } = useParams();

    const [quiz, setQuiz] = useState(null);
    const [answers, setAnswers] = useState({});
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);
    const [reviewRequested, setReviewRequested] = useState(false);

    useEffect(() => {
        const load = async () => {
            try {
                const response = await api.get(`/quizzes/${quizId}`);
                setQuiz(response.data);
            } catch (err) {
                console.error(err);
                setError("Could not load this quiz.");
            } finally {
                setLoading(false);
            }
        };

        load();
    }, [quizId]);

    const setAnswer = (questionId, value) =>
        setAnswers((prev) => ({ ...prev, [questionId]: value }));

    const allAnswered =
        quiz && quiz.questions.every((q) => (answers[q.id] ?? "").trim() !== "");

    const handleSubmit = useCallback(async () => {
        setSubmitting(true);

        try {
            const payload = {
                answers: quiz.questions.map((q) => ({
                    question_id: q.id,
                    answer: answers[q.id] ?? "",
                })),
            };

            const response = await api.post(`/quizzes/${quizId}/submit`, payload);
            setResult(response.data);
            window.scrollTo({ top: 0, behavior: "smooth" });
        } catch (err) {
            console.error(err);
            alert(err.response?.data?.message ?? "Could not submit the quiz.");
        } finally {
            setSubmitting(false);
        }
    }, [answers, quiz, quizId]);

    const retake = () => {
        setResult(null);
        setAnswers({});
        setReviewRequested(false);
    };

    /**
     * A quiz attempt is a submission, so it reuses the same review
     * endpoint that project submissions use.
     */
    const requestHumanReview = async () => {
        try {
            await api.post(`/submissions/${result.submission_id}/request-review`);
            setReviewRequested(true);
        } catch (err) {
            console.error(err);

            // 409 means a review is already pending for this attempt.
            if (err.response?.status === 409) {
                setReviewRequested(true);
                return;
            }

            alert(err.response?.data?.message ?? "Could not request a review.");
        }
    };

    if (loading) {
        return (
            <div className="min-h-screen bg-black text-white flex items-center justify-center">
                Loading quiz...
            </div>
        );
    }

    if (error || !quiz) {
        return (
            <div className="min-h-screen bg-black text-white flex flex-col items-center justify-center gap-4">
                <p className="text-red-400">{error ?? "Quiz not found."}</p>
                <button onClick={() => navigate(-1)} className="text-[#0097a7]">
                    Go back
                </button>
            </div>
        );
    }

    const feedbackFor = (questionId) =>
        result?.breakdown.find((b) => b.question_id === questionId);

    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.08),transparent_55%)] pointer-events-none"></div>

            <header className="relative z-10 px-8 lg:px-20 py-8 border-b border-white/10">
                <button
                    onClick={() => navigate(`/learning/${quiz.version_node_id}`)}
                    className="flex items-center gap-2 text-gray-400 hover:text-[#0097a7] transition mb-6"
                >
                    <ArrowLeft size={18} />
                    Back To Learning Page
                </button>

                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                    <div>
                        <div className="inline-flex items-center gap-2 px-4 py-1 rounded-full border border-[#0097a7]/20 bg-[#0097a7]/10 text-[#00b0c2] text-sm mb-5">
                            {quiz.title}
                        </div>

                        <h1 className="text-5xl font-black">Knowledge Check</h1>

                        <p className="mt-4 text-lg text-gray-400 max-w-3xl">
                            {quiz.questions.length} questions · pass mark {quiz.pass_mark}%
                        </p>
                    </div>

                    {quiz.last_attempt && !result && (
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6 min-w-[250px]">
                            <div className="flex items-center gap-3 text-gray-400">
                                <Clock3 size={18} />
                                <span className="text-sm">Previous attempt</span>
                            </div>

                            <h2 className="text-4xl font-black mt-3">
                                {quiz.last_attempt.score}%
                            </h2>

                            <p className={`mt-1 text-sm ${quiz.last_attempt.passed ? "text-green-400" : "text-yellow-400"}`}>
                                {quiz.last_attempt.passed ? "Passed" : "Not passed"}
                            </p>
                        </div>
                    )}
                </div>
            </header>

            <main className="relative z-10 px-8 lg:px-20 py-10">
                <div className="max-w-5xl mx-auto">

                    {result && (
                        <div className="mb-10 rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-8">
                            <div className="flex items-center gap-3 mb-6">
                                <Brain size={24} className="text-[#0097a7]" />
                                <h2 className="text-3xl font-bold">Your Result</h2>
                            </div>

                            <div className="grid md:grid-cols-3 gap-6">
                                <div className="rounded-2xl bg-black/30 border border-white/10 p-6">
                                    <h3 className="font-semibold mb-3 text-gray-400">Score</h3>
                                    <p className="text-5xl font-black text-[#0097a7]">
                                        {result.score}%
                                    </p>
                                </div>

                                <div className="rounded-2xl bg-black/30 border border-white/10 p-6">
                                    <h3 className="font-semibold mb-3 text-gray-400">Result</h3>
                                    <p className={`text-2xl font-bold ${result.passed ? "text-green-400" : "text-red-400"}`}>
                                        {result.passed ? "Passed" : "Not passed"}
                                    </p>
                                </div>

                                <div className="rounded-2xl bg-black/30 border border-white/10 p-6">
                                    <h3 className="font-semibold mb-3 text-gray-400">Auto-graded</h3>
                                    <p className="text-2xl font-bold">
                                        {result.auto_graded.correct} / {result.auto_graded.total}
                                    </p>
                                </div>
                            </div>

                            {result.needs_review && (
                                <div className="mt-6 flex items-center gap-3 text-yellow-400">
                                    <AlertCircle size={20} />
                                    Written answers are awaiting review, so your final score may change.
                                </div>
                            )}

                            {/* Appears once the AI service fills ai_feedback. */}
                            {result.ai_feedback && (
                                <div className="mt-6 rounded-2xl bg-black/30 border border-white/10 p-6">
                                    <div className="flex items-center gap-2 mb-3">
                                        <Brain size={18} className="text-[#0097a7]" />
                                        <h3 className="font-bold">Automated Explanation</h3>
                                    </div>
                                    <p className="text-sm text-gray-300 leading-relaxed whitespace-pre-line">
                                        {result.ai_feedback}
                                    </p>
                                </div>
                            )}

                            <button
                                onClick={retake}
                                className="mt-8 px-6 py-3 rounded-2xl border border-[#0097a7]/30 text-[#00b0c2] hover:bg-[#0097a7]/10 transition"
                            >
                                Retake Quiz
                            </button>

                            <button
                                onClick={requestHumanReview}
                                disabled={reviewRequested}
                                className={`mt-4 w-full flex items-center justify-center gap-3 py-4 rounded-2xl border transition ${
                                    reviewRequested
                                        ? "border-white/10 bg-white/[0.03] text-gray-500 cursor-not-allowed"
                                        : "border-[#0097a7]/20 bg-[#0097a7]/10 text-[#00b0c2] hover:bg-[#0097a7]/20"
                                }`}
                            >
                                <UserRound size={20} />
                                {reviewRequested
                                    ? "Review requested - a professor will check this attempt"
                                    : "Not sure about a result? Request Human Correction"}
                            </button>
                        </div>
                    )}

                    {quiz.questions.map((question, index) => {
                        const feedback = feedbackFor(question.id);

                        return (
                            <div
                                key={question.id}
                                className={`rounded-3xl border bg-white/[0.03] p-8 mb-6 ${
                                    feedback?.correct === true
                                        ? "border-green-400/30"
                                        : feedback?.correct === false
                                        ? "border-red-400/30"
                                        : "border-white/10"
                                }`}
                            >
                                <div className="flex items-start justify-between gap-4 mb-6">
                                    <h2 className="text-xl font-bold">
                                        {index + 1}. {question.question}
                                    </h2>

                                    {feedback?.correct === true && <CheckCircle2 className="text-green-400 shrink-0" />}
                                    {feedback?.correct === false && <XCircle className="text-red-400 shrink-0" />}
                                    {feedback?.awaiting_review && <AlertCircle className="text-yellow-400 shrink-0" />}
                                </div>

                                {question.type === "short_answer" ? (
                                    <textarea
                                        rows="6"
                                        disabled={!!result}
                                        value={answers[question.id] ?? ""}
                                        onChange={(e) => setAnswer(question.id, e.target.value)}
                                        placeholder="Write your answer here..."
                                        className="w-full bg-black/30 border border-white/10 rounded-2xl p-4 text-white outline-none disabled:opacity-60"
                                    />
                                ) : (
                                    <div className="space-y-4">
                                        {question.options.map((option) => (
                                            <label
                                                key={option}
                                                className={`flex items-center gap-3 ${result ? "opacity-80" : "cursor-pointer"}`}
                                            >
                                                <input
                                                    type="radio"
                                                    name={`q${question.id}`}
                                                    disabled={!!result}
                                                    checked={answers[question.id] === option}
                                                    onChange={() => setAnswer(question.id, option)}
                                                />
                                                {option}
                                            </label>
                                        ))}
                                    </div>
                                )}

                                {feedback?.correct === false && (
                                    <p className="mt-4 text-sm text-green-400">
                                        Correct answer: {feedback.correct_answer}
                                    </p>
                                )}
                            </div>
                        );
                    })}

                    {!result && (
                        <button
                            onClick={handleSubmit}
                            disabled={submitting || !allAnswered}
                            className={`w-full py-4 rounded-2xl font-bold transition ${
                                submitting || !allAnswered
                                    ? "bg-gray-700 text-gray-400 cursor-not-allowed"
                                    : "bg-[#0097a7] text-black hover:scale-[1.01]"
                            }`}
                        >
                            {submitting
                                ? "Submitting..."
                                : allAnswered
                                ? "Submit Quiz"
                                : "Answer all questions to submit"}
                        </button>
                    )}
                </div>
            </main>
        </div>
    );
}

export default QuizPage;