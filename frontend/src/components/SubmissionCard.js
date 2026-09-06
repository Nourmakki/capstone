import React from "react";
import {
    CheckCircle2, UserRound, Clock3, AlertCircle, Brain, ChevronRight,
} from "lucide-react";

/**
 * One hand-in, folded.
 *
 * A student who has submitted four times had four full assessments stacked
 * down the side of the page, and the thing they came back to check - what did
 * I get - was somewhere inside the third one. So the closed card carries the
 * two things worth seeing at a glance: the mark, and the way to ask a person
 * to look at it. Opening one gives back everything that was there before.
 *
 * At module level rather than inside the page. A component declared inside
 * another is a new type on every render, so React would throw this away and
 * rebuild it each time - and a card that rebuilds is a card that closes.
 */
function SubmissionCard({ submission: s, expanded, onToggle, onRequestReview }) {
    // The mark that counts. A professor's grade replaces the estimate rather
    // than sitting beside it - the whole point of asking for a review is that
    // their answer is the one that stands.
    const grade = s.human_reviewed
        ? { value: `${s.score}%`, className: "text-green-400" }
        : s.ai_score !== null
        // Not a percentage: the grader adds up to 10 bonus points, so this
        // can exceed 100.
        ? { value: s.ai_score, suffix: " / 100", className: "text-[#0097a7]" }
        : null;

    return (
        <div className="rounded-2xl border border-white/10 bg-black/30 overflow-hidden">
            {/* The request-review button lives outside this one: a button
                inside a button is not markup a browser will honour, and it
                has to stay reachable while the card is shut. */}
            <button
                onClick={onToggle}
                aria-expanded={expanded}
                className="w-full text-left p-5 hover:bg-white/[0.02] transition"
            >
                <div className="flex items-center gap-3">
                    <ChevronRight
                        size={16}
                        className={`text-gray-600 shrink-0 transition-transform ${expanded ? "rotate-90" : ""}`}
                    />

                    <span className="text-xs text-gray-500 flex items-center gap-2">
                        <Clock3 size={13} />
                        {new Date(s.created_at).toLocaleDateString()}
                    </span>

                    {grade ? (
                        <span className={`ml-auto text-2xl font-black ${grade.className}`}>
                            {grade.value}
                            {grade.suffix && (
                                <span className="text-sm text-gray-500 font-normal">{grade.suffix}</span>
                            )}
                        </span>
                    ) : (
                        <span className="ml-auto text-xs text-yellow-400 flex items-center gap-1">
                            <AlertCircle size={13} /> {s.status}
                        </span>
                    )}
                </div>

                <div className="mt-2 pl-7">
                    {s.human_reviewed ? (
                        <span className="text-xs text-green-400 flex items-center gap-1">
                            <CheckCircle2 size={13} /> Professor reviewed
                        </span>
                    ) : s.ai_score !== null ? (
                        <span className="text-xs text-[#00b0c2] flex items-center gap-1">
                            <Brain size={13} /> AI estimate
                        </span>
                    ) : null}
                </div>
            </button>

            {expanded && (
                <div className="px-5 pb-1 -mt-1">
                    {s.github_url && (
                        <a href={s.github_url} target="_blank" rel="noreferrer"
                           className="text-[#00b0c2] text-sm break-all hover:underline">
                            {s.github_url}
                        </a>
                    )}

                    {s.has_file && (
                        <p className="text-xs text-gray-400 mt-2">File attached</p>
                    )}

                    {/* Appears automatically once the AI service fills ai_score. */}
                    {s.ai_score !== null && (
                        <div className="mt-4 rounded-xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-4">
                            <div className="flex items-center justify-between">
                                <span className="text-xs text-gray-400 flex items-center gap-2">
                                    <Brain size={14} className="text-[#0097a7]" />
                                    Estimated grade
                                </span>
                                <span className="text-2xl font-black text-[#0097a7]">
                                    {s.ai_score}
                                    <span className="text-sm text-gray-500 font-normal"> / 100</span>
                                </span>
                            </div>
                            {s.ai_feedback && (
                                <p className="mt-3 text-xs text-gray-300 leading-relaxed whitespace-pre-line">
                                    {s.ai_feedback}
                                </p>
                            )}
                        </div>
                    )}

                    {s.human_reviewed && (
                        <div className="mt-4 rounded-xl border border-green-400/20 bg-green-400/5 p-4">
                            <div className="flex items-center justify-between">
                                <span className="text-xs text-gray-400 flex items-center gap-2">
                                    <UserRound size={14} className="text-green-400" />
                                    Professor grade
                                </span>
                                <span className="text-2xl font-black text-green-400">
                                    {s.score}%
                                </span>
                            </div>
                            {s.comments && (
                                <p className="mt-3 text-xs text-gray-300 leading-relaxed whitespace-pre-line">
                                    {s.comments}
                                </p>
                            )}
                        </div>
                    )}
                </div>
            )}

            {!s.human_reviewed && (
                <div className="px-5 pt-4 pb-5">
                    <button
                        onClick={onRequestReview}
                        className="w-full flex items-center justify-center gap-2 py-2 rounded-xl border border-[#0097a7]/20 bg-[#0097a7]/10 text-[#00b0c2] text-sm hover:bg-[#0097a7]/20 transition"
                    >
                        <UserRound size={16} />
                        Request Professor Review
                    </button>
                </div>
            )}
        </div>
    );
}

export default SubmissionCard;
