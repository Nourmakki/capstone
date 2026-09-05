import React, { useEffect, useRef, useState } from "react";
import api from "../api";
import { useAuth } from "../context/AuthContext";
import { Bot, Send, X, MessageCircle, BookOpen } from "lucide-react";

/**
 * The platform's assistant, as a panel the student can open anywhere.
 *
 * Deliberately not a page. A student asks "what should I do next" while
 * looking at a roadmap, and sending them to another screen to ask about the
 * one they just left is the wrong shape for the question.
 *
 * Mounted once, at the top of the app, rather than on each page. It used to
 * sit inside the dashboard, which meant it vanished the moment a student
 * opened a roadmap or a module - the two screens its questions are most
 * often about. Mounted above the router it is on every student page there
 * is, including any added later.
 *
 * Which is why it decides for itself whether to appear: sitting above the
 * routes, it has no route to tell it who is looking. A professor or a
 * manager gets nothing - the answers are built from a student's own
 * progress, and staff have none.
 *
 * It answers only from this platform's own content. Every answer carries the
 * roadmaps and modules it was built from, shown underneath, so a student can
 * check a claim against the platform rather than take its word - and when
 * nothing on the platform matched, it says so instead of answering anyway.
 */
function AssistantChat() {
    const { user } = useAuth();

    // Students only, and only once signed in. Decided here rather than at the
    // mount point so there is one place that decides, and no way to add a
    // student page that forgets the assistant or a staff page that gets it.
    //
    // The split matters, and is the whole reason this component exists. The
    // conversation used to live in the same component as this check, with the
    // check written below the hooks - and a component that returns null is
    // still mounted. It renders nothing and keeps every piece of its state.
    // Since the panel sits above the router, signing out never took it down
    // either: logging out is a state change, not a page load. So one student
    // could sign out, the next sign in, and find the first one's conversation
    // waiting for them.
    //
    // With the state one level down, returning null here unmounts the panel
    // and React discards its state - all of it, not only the parts somebody
    // remembered to clear.
    if (user?.role !== "Student") {
        return null;
    }

    // A second guard, for a change of student that never passes through the
    // signed-out render above. Nothing does that today, but keying by the
    // student means nothing can start to.
    return <AssistantPanel key={user.id} />;
}

function AssistantPanel() {
    const [open, setOpen] = useState(false);
    const [question, setQuestion] = useState("");
    const [messages, setMessages] = useState([]);
    const [busy, setBusy] = useState(false);

    const endRef = useRef(null);
    const inputRef = useRef(null);

    // Follow the conversation down as it grows.
    useEffect(() => {
        endRef.current?.scrollIntoView({ behavior: "smooth" });
    }, [messages, busy]);

    useEffect(() => {
        if (open) inputRef.current?.focus();
    }, [open]);

    const send = async (event) => {
        event?.preventDefault();

        const asked = question.trim();

        if (!asked || busy) return;

        setMessages((prev) => [...prev, { role: "student", text: asked }]);
        setQuestion("");
        setBusy(true);

        try {
            const response = await api.post("/assistant/ask", { question: asked });

            setMessages((prev) => [...prev, {
                role: "assistant",
                text: response.data.answer,
                sources: response.data.sources ?? [],
                grounded: response.data.grounded,
            }]);
        } catch (err) {
            // 503 is the assistant being off, which is not the same as it
            // failing to answer - the rest of the platform is unaffected and
            // the message should say so rather than look like a crash.
            setMessages((prev) => [...prev, {
                role: "assistant",
                text: err.response?.data?.message
                    ?? "Something went wrong reaching the assistant.",
                sources: [],
                error: true,
            }]);
        } finally {
            setBusy(false);
        }
    };

    if (!open) {
        return (
            <button
                onClick={() => setOpen(true)}
                aria-label="Ask the assistant"
                className="fixed bottom-6 right-6 z-40 flex items-center gap-2 px-5 py-3 rounded-2xl bg-[#0097a7] text-black font-semibold shadow-lg hover:scale-105 transition"
            >
                <MessageCircle size={20} />
                Ask
            </button>
        );
    }

    return (
        <div className="fixed bottom-6 right-6 z-40 w-[min(26rem,calc(100vw-3rem))] h-[min(34rem,calc(100vh-6rem))] flex flex-col rounded-3xl border border-white/10 bg-[#0A1113] shadow-2xl overflow-hidden">

            <header className="flex items-center gap-3 px-5 py-4 border-b border-white/10 shrink-0">
                <Bot size={20} className="text-[#0097a7]" />

                <div className="min-w-0">
                    <h2 className="font-bold leading-none">Ask CS Mastery</h2>
                    <p className="text-xs text-gray-500 mt-1">
                        Answers about this platform only
                    </p>
                </div>

                <button
                    onClick={() => setOpen(false)}
                    aria-label="Close the assistant"
                    className="ml-auto p-2 rounded-lg text-gray-400 hover:text-white hover:bg-white/5 transition"
                >
                    <X size={18} />
                </button>
            </header>

            <div className="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                {messages.length === 0 && (
                    <div className="text-sm text-gray-500">
                        <p>Ask about your roadmaps, a module, or how the platform works.</p>

                        <ul className="mt-3 space-y-1.5">
                            {[
                                "What should I learn next?",
                                "What does the React roadmap contain?",
                                "Why can't I open this module yet?",
                            ].map((example) => (
                                <li key={example}>
                                    <button
                                        onClick={() => { setQuestion(example); inputRef.current?.focus(); }}
                                        className="text-left text-[#00b0c2] hover:underline"
                                    >
                                        {example}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {messages.map((message, index) => (
                    <div key={index} className={message.role === "student" ? "text-right" : ""}>
                        <div className={`inline-block max-w-[92%] text-left rounded-2xl px-4 py-2.5 text-sm ${
                            message.role === "student"
                                ? "bg-[#0097a7] text-black"
                                : message.error
                                ? "bg-yellow-400/10 border border-yellow-400/30 text-yellow-100"
                                : "bg-white/[0.04] border border-white/10 text-gray-200"
                        }`}>
                            <p className="whitespace-pre-line">{message.text}</p>

                            {/* What the answer was built from. An answer a
                                student can check is worth more than one they
                                have to trust. */}
                            {message.sources?.length > 0 && (
                                <div className="mt-3 pt-2.5 border-t border-white/10">
                                    <p className="text-xs text-gray-500 mb-1.5 flex items-center gap-1.5">
                                        <BookOpen size={11} />
                                        From
                                    </p>

                                    <div className="flex flex-wrap gap-1.5">
                                        {/* Deduplicated: retrieval often returns
                                            several chunks of one roadmap, and
                                            citing it three times reads as three
                                            sources. */}
                                        {[...new Map(message.sources.map((s) => [s.title, s])).values()]
                                            .map((source) => (
                                                <span key={source.title}
                                                      className="text-xs px-2 py-0.5 rounded-full bg-[#0097a7]/10 text-[#00b0c2]">
                                                    {source.title}
                                                </span>
                                            ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                ))}

                {busy && (
                    <p className="text-sm text-gray-500">Looking through the platform...</p>
                )}

                <div ref={endRef} />
            </div>

            <form onSubmit={send} className="flex items-center gap-2 p-3 border-t border-white/10 shrink-0">
                <input
                    ref={inputRef}
                    value={question}
                    onChange={(e) => setQuestion(e.target.value)}
                    placeholder="Ask about your learning..."
                    maxLength={1000}
                    className="flex-1 min-w-0 p-3 rounded-xl bg-black/40 border border-white/10 text-white text-sm"
                />

                <button
                    type="submit"
                    disabled={busy || !question.trim()}
                    aria-label="Send"
                    className="p-3 rounded-xl bg-[#0097a7] text-black disabled:bg-gray-700 disabled:text-gray-500 transition"
                >
                    <Send size={18} />
                </button>
            </form>
        </div>
    );
}

export default AssistantChat;
