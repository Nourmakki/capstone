import React, { useCallback, useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import api from "../api";
import {
    ArrowLeft, GitBranch, Trophy, Code2, CheckCircle2,
    UserRound, Clock3, AlertCircle, Brain,
} from "lucide-react";

function ProjectSubmissionPage() {
    const navigate = useNavigate();
    const { projectId } = useParams();

    const [project, setProject] = useState(null);
    const [githubUrl, setGithubUrl] = useState("");
    const [file, setFile] = useState(null);
    const [notes, setNotes] = useState("");
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);

    const load = useCallback(async () => {
        try {
            const response = await api.get(`/projects/${projectId}`);
            setProject(response.data);
        } catch (err) {
            console.error(err);
            setError("Could not load this project.");
        } finally {
            setLoading(false);
        }
    }, [projectId]);

    useEffect(() => { load(); }, [load]);

    const handleSubmit = async () => {
        if (!githubUrl.trim()) {
            alert("Add the link to your GitHub repository.");
            return;
        }

        if (!file) {
            alert("Attach your project as a ZIP file.");
            return;
        }

        setSubmitting(true);

        try {
            const form = new FormData();
            if (githubUrl.trim()) form.append("github_url", githubUrl.trim());
            if (file) form.append("file", file);
            if (notes.trim()) form.append("notes", notes.trim());

            await api.post(`/projects/${projectId}/submit`, form);

            setGithubUrl("");
            setFile(null);
            setNotes("");
            await load();
        } catch (err) {
            console.error(err);
            const errors = err.response?.data?.errors;
            alert(errors ? Object.values(errors).flat().join("\n") : "Submission failed.");
        } finally {
            setSubmitting(false);
        }
    };

    const requestReview = async (submissionId) => {
        try {
            await api.post(`/submissions/${submissionId}/request-review`);
            alert("A professor will review your submission.");
            await load();
        } catch (err) {
            alert(err.response?.data?.message ?? "Could not request a review.");
        }
    };

    if (loading) {
        return <div className="min-h-screen bg-black text-white flex items-center justify-center">Loading project...</div>;
    }

    if (error || !project) {
        return (
            <div className="min-h-screen bg-black text-white flex flex-col items-center justify-center gap-4">
                <p className="text-red-400">{error ?? "Project not found."}</p>
                <button onClick={() => navigate(-1)} className="text-[#0097a7]">Go back</button>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.15),transparent_55%)] pointer-events-none"></div>

            <header className="relative z-10 px-8 lg:px-20 py-8 border-b border-white/10">
                <button
                    onClick={() => navigate(`/learning/${project.version_node_id}`)}
                    className="flex items-center gap-2 text-gray-400 hover:text-[#0097a7] transition mb-6"
                >
                    <ArrowLeft size={18} />
                    Back To Learning Page
                </button>

                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-8">
                    <div>
                        <div className="inline-flex items-center gap-2 px-4 py-1 rounded-full border border-[#0097a7]/20 bg-[#0097a7]/10 text-[#00b0c2] text-sm mb-5">
                            {project.node_title}
                        </div>
                        <h1 className="text-5xl font-black">{project.title}</h1>
                        <p className="mt-5 text-lg text-gray-400 max-w-3xl">{project.description}</p>
                    </div>

                    <div className="rounded-3xl border border-[#0097a7]/20 bg-[#0097a7]/10 p-6 min-w-[240px]">
                        <div className="flex items-center gap-3 mb-3">
                            <Trophy size={20} />
                            <span className="font-semibold">Difficulty</span>
                        </div>
                        <h2 className="text-3xl font-black capitalize">{project.difficulty}</h2>
                    </div>
                </div>
            </header>

            <main className="relative z-10 px-8 lg:px-20 py-10">
                <div className="grid lg:grid-cols-[1fr_360px] gap-8">
                    <div>
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                            <div className="flex items-center gap-3 mb-6">
                                <Code2 size={22} />
                                <h2 className="text-2xl font-bold">Submit Your Work</h2>
                            </div>

                            <label className="block text-gray-300 mb-2">
                                GitHub Repository Link <span className="text-red-400">*</span>
                            </label>
                            <div className="relative mb-6">
                                <GitBranch size={18} className="absolute left-4 top-4 text-gray-400" />
                                <input
                                    type="url"
                                    value={githubUrl}
                                    onChange={(e) => setGithubUrl(e.target.value)}
                                    placeholder="https://github.com/username/project"
                                    className="w-full pl-11 p-4 rounded-2xl bg-black/30 border border-white/10 text-white"
                                />
                            </div>

                            <label className="block text-gray-300 mb-2">
                                Project ZIP <span className="text-red-400">*</span>
                                <span className="text-gray-500 text-sm"> (max 10 MB)</span>
                            </label>
                            <input
                                type="file"
                                accept=".zip"
                                onChange={(e) => setFile(e.target.files[0] ?? null)}
                                className="w-full p-4 rounded-2xl bg-black/30 border border-white/10"
                            />
                            {file && (
                                <p className="text-xs text-[#00b0c2] mt-2">
                                    {file.name} ({Math.round(file.size / 1024)} KB)
                                </p>
                            )}
                            <div className="mb-6" />

                            <label className="block text-gray-300 mb-2">Notes (optional)</label>
                            <textarea
                                rows="4"
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                placeholder="Anything the reviewer should know..."
                                className="w-full p-4 rounded-2xl bg-black/30 border border-white/10 mb-6"
                            />

                            <button
                                onClick={handleSubmit}
                                disabled={submitting}
                                className={`w-full py-4 rounded-2xl font-bold transition ${
                                    submitting ? "bg-gray-700 text-gray-400" : "bg-[#0097a7] text-black hover:scale-[1.01]"
                                }`}
                            >
                                {submitting ? "Submitting..." : "Submit Project"}
                            </button>
                        </div>
                    </div>

                    <div className="h-fit">
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-6">
                            <h2 className="text-2xl font-bold mb-6">Your Submissions</h2>

                            {project.submissions.length === 0 ? (
                                <p className="text-gray-500 text-sm">Nothing submitted yet.</p>
                            ) : (
                                <div className="space-y-5">
                                    {project.submissions.map((s) => (
                                        <div key={s.id} className="rounded-2xl border border-white/10 bg-black/30 p-5">
                                            <div className="flex items-center justify-between mb-3">
                                                <span className="text-xs text-gray-500 flex items-center gap-2">
                                                    <Clock3 size={13} />
                                                    {new Date(s.created_at).toLocaleDateString()}
                                                </span>

                                                {s.human_reviewed ? (
                                                    <span className="text-xs text-green-400 flex items-center gap-1">
                                                        <CheckCircle2 size={13} /> Professor reviewed
                                                    </span>
                                                ) : s.ai_score !== null ? (
                                                    <span className="text-xs text-[#00b0c2] flex items-center gap-1">
                                                        <Brain size={13} /> AI estimate
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-yellow-400 flex items-center gap-1">
                                                        <AlertCircle size={13} /> {s.status}
                                                    </span>
                                                )}
                                            </div>

                                            {s.github_url && (
                                                <a href={s.github_url} target="_blank" rel="noreferrer"
                                                   className="text-[#00b0c2] text-sm break-all hover:underline">
                                                    {s.github_url}
                                                </a>
                                            )}

                                            {s.has_file && (
                                                <p className="text-xs text-gray-400 mt-2">ZIP file attached</p>
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
                                                            {s.ai_score}%
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

                                            {!s.human_reviewed && (
                                                <button
                                                    onClick={() => requestReview(s.id)}
                                                    className="mt-4 w-full flex items-center justify-center gap-2 py-2 rounded-xl border border-[#0097a7]/20 bg-[#0097a7]/10 text-[#00b0c2] text-sm hover:bg-[#0097a7]/20 transition"
                                                >
                                                    <UserRound size={16} />
                                                    Request Professor Review
                                                </button>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </main>
        </div>
    );
}

export default ProjectSubmissionPage;