import React, { useState } from "react";
import api from "../api";
import { useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import {
    User, Mail, Lock, MapPin, Eye, EyeOff, ArrowRight, ArrowLeft, GraduationCap,
    FileText,
} from "lucide-react";

const Signup = () => {
    const [name, setName] = useState("");
    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");
    const [passwordConfirmation, setPasswordConfirmation] = useState("");
    const [location, setLocation] = useState("");
    const [role, setRole] = useState("");
    const [qualification, setQualification] = useState("");
    const [cv, setCv] = useState(null);
    const [showPassword, setShowPassword] = useState(false);
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    const navigate = useNavigate();
    const { login } = useAuth();

    // Only flag a mismatch once the user has actually started typing the confirmation.
    const passwordsMismatch =
        passwordConfirmation.length > 0 && password !== passwordConfirmation;

    const handleSignup = async (e) => {
        e.preventDefault();
        setError(null);

        if (!role) {
            setError("Choose whether you are signing up as a student or a professor.");
            return;
        }

        if (password !== passwordConfirmation) {
            setError("The two passwords do not match.");
            return;
        }

        if (role === "professor" && !cv) {
            setError("Attach your CV as a PDF. An administrator reads it before approving you.");
            return;
        }

        setSubmitting(true);

        try {
            // Sent as multipart because professor applications carry a file.
            const form = new FormData();
            form.append("name", name);
            form.append("email", email);
            form.append("password", password);
            form.append("password_confirmation", passwordConfirmation);
            form.append("role", role);

            if (location) {
                form.append("location", location);
            }

            if (role === "professor") {
                form.append("qualification", qualification);
                form.append("cv", cv);
            }

            const response = await api.post("/register", form);

            // Pending professors get no token - they wait for admin approval.
            if (response.data.pending) {
                navigate("/professor-pending");
                return;
            }

            login(response.data.user, response.data.token);
            navigate("/dashboard");
        } catch (err) {
            console.error(err);

            // Laravel returns 422 with a field-keyed errors object.
            const errors = err.response?.data?.errors;

            setError(
                errors
                    ? [...new Set(Object.values(errors).flat())].join("\n")
                    : err.response?.data?.message ?? "Could not create your account."
            );
        } finally {
            setSubmitting(false);
        }
    };

    const labelClass =
        "block text-[11px] font-semibold tracking-[0.12em] text-gray-400 mb-1.5 shorter:mb-1";

    const fieldClass =
        "w-full pl-11 pr-4 py-2.5 shorter:py-2 rounded-xl bg-black/40 border border-white/10 text-white " +
        "placeholder-gray-600 outline-none transition focus:border-[#0097a7]/50 " +
        "focus:ring-1 focus:ring-[#0097a7]/30";

    const iconClass = "absolute left-4 top-1/2 -translate-y-1/2 text-gray-500";

    return (
        <div className="relative min-h-screen flex flex-col bg-black text-white">
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_30%,rgba(0,151,167,0.20),transparent_58%)] pointer-events-none" />
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_80%_85%,rgba(0,176,194,0.10),transparent_55%)] pointer-events-none" />

            <header className="relative z-10 shrink-0 flex items-center gap-3 px-6 lg:px-10 py-6 short:py-4 shorter:py-3">
                {/* The mark doubles as the way back - it is where people click
                    first anyway. The explicit link is for everyone else. */}
                <button
                    type="button"
                    onClick={() => navigate("/")}
                    className="flex items-center gap-3 hover:opacity-80 transition"
                >
                    <img
                        src="/logo-mark.png"
                        alt=""
                        className="w-10 h-10 shorter:w-8 shorter:h-8 object-contain"
                    />
                    <span className="text-xl font-black tracking-tight">CS Mastery</span>
                </button>

                <button
                    type="button"
                    onClick={() => navigate("/")}
                    className="ml-auto flex items-center gap-1.5 text-sm text-gray-400 hover:text-[#00b0c2] transition"
                >
                    <ArrowLeft size={15} />
                    Back to home
                </button>
            </header>

            {/* No padding above the card and some below, so it sits high rather
                than centred in the space under the header. overflow-y-auto is
                the floor: choosing Professor adds a CV block, and on a phone
                seven fields will not fit however they are arranged. */}
            <main className="relative z-10 flex-1 min-h-0 overflow-y-auto flex items-start justify-center px-5 pt-0 pb-6 short:pt-0 short:pb-4">
                {/* Pinned rather than a max-w preset: the width is solved from
                    the measured height so the card holds its proportion as the
                    spacing inside it scales down. */}
                <div className="w-full max-w-[419px]">
                    <div className="rounded-3xl border border-white/10 bg-white/[0.035] backdrop-blur-sm p-5 lg:p-6 short:p-4 shadow-[0_0_70px_-20px_rgba(0,151,167,0.45)]">
                        <div className="flex justify-center">
                            <img
                                src="/logo-mark.png"
                                alt="CS Mastery"
                                className="w-14 h-14 short:w-12 short:h-12 object-contain"
                            />
                        </div>

                        <h1 className="mt-2 short:mt-2 text-center text-2xl font-black tracking-tight">
                            Create your account
                        </h1>
                        <p className="mt-1 text-center text-sm text-gray-400">
                            Start a roadmap and track what you finish.
                        </p>

                        {error && (
                            <div
                                role="alert"
                                className="mt-6 short:mt-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300 whitespace-pre-line"
                            >
                                {error}
                            </div>
                        )}

                        <form onSubmit={handleSignup} className="mt-5 short:mt-4 space-y-3 short:space-y-2.5">
                            {/* Paired on a wide screen so the form stays close to
                                one screen tall; stacked below sm. */}
                            <div className="grid sm:grid-cols-2 gap-4 short:gap-3">
                                <div>
                                    <label htmlFor="name" className={labelClass}>FULL NAME</label>
                                    <div className="relative">
                                        <User size={17} className={iconClass} />
                                        <input
                                            id="name"
                                            type="text"
                                            autoComplete="name"
                                            value={name}
                                            onChange={(e) => setName(e.target.value)}
                                            placeholder="Your name"
                                            className={fieldClass}
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label htmlFor="email" className={labelClass}>EMAIL ADDRESS</label>
                                    <div className="relative">
                                        <Mail size={17} className={iconClass} />
                                        <input
                                            id="email"
                                            type="email"
                                            autoComplete="email"
                                            value={email}
                                            onChange={(e) => setEmail(e.target.value)}
                                            placeholder="you@mail.com"
                                            className={fieldClass}
                                        />
                                    </div>
                                </div>
                            </div>

                            <div className="grid sm:grid-cols-2 gap-4 short:gap-3">
                                <div>
                                    <label htmlFor="password" className={labelClass}>PASSWORD</label>
                                    <div className="relative">
                                        <Lock size={17} className={iconClass} />
                                        <input
                                            id="password"
                                            type={showPassword ? "text" : "password"}
                                            autoComplete="new-password"
                                            value={password}
                                            onChange={(e) => setPassword(e.target.value)}
                                            placeholder="••••••••"
                                            minLength={8}
                                            className={`${fieldClass} pr-12`}
                                        />

                                        {/* One toggle for both fields: two eyes on one
                                            form is clutter, and they are always
                                            revealed for the same reason. */}
                                        <button
                                            type="button"
                                            onClick={() => setShowPassword((v) => !v)}
                                            aria-label={showPassword ? "Hide passwords" : "Show passwords"}
                                            className="absolute right-3 top-1/2 -translate-y-1/2 p-1.5 rounded-lg text-gray-500 hover:text-gray-300 transition"
                                        >
                                            {showPassword ? <EyeOff size={17} /> : <Eye size={17} />}
                                        </button>
                                    </div>

                                    <p className="text-xs text-gray-500 mt-1">At least 8 characters.</p>
                                </div>

                                <div>
                                    <label htmlFor="password_confirmation" className={labelClass}>
                                        CONFIRM PASSWORD
                                    </label>
                                    <div className="relative">
                                        <Lock size={17} className={iconClass} />
                                        <input
                                            id="password_confirmation"
                                            type={showPassword ? "text" : "password"}
                                            autoComplete="new-password"
                                            value={passwordConfirmation}
                                            onChange={(e) => setPasswordConfirmation(e.target.value)}
                                            placeholder="••••••••"
                                            className={`${fieldClass} ${
                                                passwordsMismatch ? "border-red-500/60" : ""
                                            }`}
                                        />
                                    </div>

                                    {passwordsMismatch && (
                                        <p className="text-xs text-red-400 mt-1">
                                            Passwords do not match.
                                        </p>
                                    )}
                                </div>
                            </div>

                            <div>
                                <label htmlFor="location" className={labelClass}>
                                    LOCATION <span className="text-gray-600 tracking-normal">(optional)</span>
                                </label>
                                <div className="relative">
                                    <MapPin size={17} className={iconClass} />
                                    <input
                                        id="location"
                                        type="text"
                                        value={location}
                                        onChange={(e) => setLocation(e.target.value)}
                                        placeholder="City / Country"
                                        className={fieldClass}
                                    />
                                </div>
                            </div>

                            <div>
                                <span className={labelClass}>I AM SIGNING UP AS</span>

                                <div className="grid grid-cols-2 gap-2.5">
                                    {[
                                        { id: "student", label: "Student", icon: <User size={17} /> },
                                        { id: "professor", label: "Professor", icon: <GraduationCap size={17} /> },
                                    ].map((option) => (
                                        <button
                                            key={option.id}
                                            type="button"
                                            onClick={() => setRole(option.id)}
                                            className={`flex items-center justify-center gap-2 py-2.5 shorter:py-2 rounded-xl border font-semibold text-sm transition ${
                                                role === option.id
                                                    ? "bg-[#0097a7] border-[#0097a7] text-black"
                                                    : "bg-black/40 border-white/10 text-gray-300 hover:border-[#0097a7]/40"
                                            }`}
                                        >
                                            {option.icon}
                                            {option.label}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* An admin approves a professor by reading their CV. */}
                            {role === "professor" && (
                                <div className="rounded-2xl border border-[#0097a7]/30 bg-[#0097a7]/5 p-4 space-y-3">
                                    <p className="text-xs text-gray-300">
                                        An administrator will review your CV before approving your account.
                                    </p>

                                    <div>
                                        <label htmlFor="qualification" className={labelClass}>
                                            QUALIFICATION
                                        </label>
                                        <div className="relative">
                                            <GraduationCap size={17} className={iconClass} />
                                            <input
                                                id="qualification"
                                                type="text"
                                                value={qualification}
                                                onChange={(e) => setQualification(e.target.value)}
                                                placeholder="PhD in Computer Science, 6 years teaching"
                                                className={fieldClass}
                                            />
                                        </div>
                                    </div>

                                    <div>
                                        <label htmlFor="cv" className={labelClass}>
                                            CV <span className="text-gray-600 tracking-normal">(PDF, max 5 MB)</span>
                                        </label>
                                        <input
                                            id="cv"
                                            type="file"
                                            accept="application/pdf"
                                            onChange={(e) => setCv(e.target.files[0] ?? null)}
                                            className="w-full px-4 py-2.5 shorter:py-2 rounded-xl bg-black/40 border border-white/10 text-sm text-gray-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-[#0097a7]/20 file:text-[#00b0c2] file:font-semibold"
                                        />

                                        {cv && (
                                            <p className="flex items-center gap-1.5 text-xs text-[#00b0c2] mt-2">
                                                <FileText size={13} />
                                                {cv.name} ({Math.round(cv.size / 1024)} KB)
                                            </p>
                                        )}
                                    </div>
                                </div>
                            )}

                            <button
                                type="submit"
                                disabled={submitting || passwordsMismatch}
                                className={`w-full flex items-center justify-center gap-2 py-3 short:py-2.5 rounded-xl font-bold text-sm tracking-[0.1em] transition ${
                                    submitting || passwordsMismatch
                                        ? "bg-gray-700 text-gray-400 cursor-not-allowed"
                                        : "bg-[#0097a7] text-black hover:bg-[#00b0c2]"
                                }`}
                            >
                                {submitting ? "CREATING ACCOUNT..." : "CREATE ACCOUNT"}
                                {!submitting && !passwordsMismatch && <ArrowRight size={17} />}
                            </button>
                        </form>

                        <p className="mt-5 short:mt-4 text-center text-sm text-gray-400">
                            Already have an account?{" "}
                            <button
                                type="button"
                                onClick={() => navigate("/login")}
                                className="text-[#00b0c2] font-semibold hover:underline"
                            >
                                Log in
                            </button>
                        </p>
                    </div>
                </div>
            </main>
        </div>
    );
};

export default Signup;
