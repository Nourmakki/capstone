import React, { useState } from "react";
import api from "../api";
import { useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";
import { Mail, Lock, Eye, EyeOff, ArrowRight } from "lucide-react";

const Login = () => {
    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");
    const [showPassword, setShowPassword] = useState(false);
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    const navigate = useNavigate();
    const { login } = useAuth();

    const handleLogin = async (e) => {
        e.preventDefault();
        setError(null);
        setSubmitting(true);

        try {
            const response = await api.post("/login", { email, password });

            login(response.data.user, response.data.token);

            const role = response.data.user.role;

            if (role === "Student") navigate("/dashboard");
            else if (role === "Professor") navigate("/professor-dashboard");
            else if (role === "Admin") navigate("/admin-dashboard");
            else if (role === "MaterialManager") navigate("/material-dashboard");
        } catch (err) {
            if (err.response?.status === 403 && err.response?.data?.pending) {
                navigate("/professor-pending");
                return;
            }

            // Shown in the card rather than in an alert(): a browser dialog
            // covers the form it is complaining about, and has to be dismissed
            // before the field can be corrected.
            setError(err.response?.data?.message ?? "Could not log you in. Check your details and try again.");
        } finally {
            setSubmitting(false);
        }
    };

    const fieldClass =
        "w-full pl-11 pr-4 py-3 shorter:py-2.5 rounded-xl bg-black/40 border border-white/10 text-white " +
        "placeholder-gray-600 outline-none transition focus:border-[#0097a7]/50 " +
        "focus:ring-1 focus:ring-[#0097a7]/30";

    return (
        // A column with the card centred in what is left, rather than pushed
        // down by a fixed top padding: on a short screen that padding was the
        // difference between fitting and scrolling.
        <div className="relative min-h-screen flex flex-col bg-black text-white">
            {/* Two offset pools of light rather than one centred wash, so the
                card sits in the brighter part instead of on a flat field. */}
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_38%,rgba(0,151,167,0.20),transparent_58%)] pointer-events-none" />
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_80%_85%,rgba(0,176,194,0.10),transparent_55%)] pointer-events-none" />

            <header className="relative z-10 shrink-0 flex items-center gap-3 px-6 lg:px-10 py-6 short:py-4 shorter:py-3 tiny:py-2">
                <img
                    src="/logo-mark.png"
                    alt=""
                    className="w-10 h-10 shorter:w-8 shorter:h-8 object-contain"
                />
                <span className="text-xl font-black tracking-tight">CS Mastery</span>
            </header>

            {/* overflow-y-auto, not hidden: below about 470px nothing will make
                this fit, and a form that cannot be scrolled to is worse than
                one that scrolls. */}
            <main className="relative z-10 flex-1 min-h-0 overflow-y-auto flex items-center justify-center px-5 py-6 short:py-4 shorter:py-2 tiny:py-1">
                <div className="w-full max-w-md">
                    <div className="rounded-3xl border border-white/10 bg-white/[0.035] backdrop-blur-sm p-7 lg:p-9 short:p-6 shorter:p-5 tiny:p-4 shadow-[0_0_70px_-20px_rgba(0,151,167,0.45)]">
                        {/* No bordered badge around it: the mark carries its own
                            glow, and a box fights that rather than framing it. */}
                        <div className="flex justify-center">
                            <img
                                src="/logo-mark.png"
                                alt="CS Mastery"
                                className="w-20 h-20 short:w-16 short:h-16 shorter:w-14 shorter:h-14 tiny:w-12 tiny:h-12 object-contain"
                            />
                        </div>

                        <h1 className="mt-6 short:mt-4 shorter:mt-3 text-center text-3xl shorter:text-2xl font-black tracking-tight">
                            Log in
                        </h1>
                        <p className="mt-2 shorter:mt-1 text-center text-sm text-gray-400">
                            Pick up where you left off.
                        </p>

                        {error && (
                            <div
                                role="alert"
                                className="mt-6 short:mt-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300"
                            >
                                {error}
                            </div>
                        )}

                        <form onSubmit={handleLogin} className="mt-7 short:mt-5 shorter:mt-4 space-y-5 shorter:space-y-3">
                            <div>
                                <label
                                    htmlFor="email"
                                    className="block text-[11px] font-semibold tracking-[0.12em] text-gray-400 mb-2 shorter:mb-1.5"
                                >
                                    EMAIL ADDRESS
                                </label>

                                <div className="relative">
                                    <Mail size={17} className="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500" />
                                    <input
                                        id="email"
                                        type="email"
                                        autoComplete="email"
                                        value={email}
                                        onChange={(e) => setEmail(e.target.value)}
                                        placeholder="you@example.com"
                                        className={fieldClass}
                                    />
                                </div>
                            </div>

                            <div>
                                <label
                                    htmlFor="password"
                                    className="block text-[11px] font-semibold tracking-[0.12em] text-gray-400 mb-2 shorter:mb-1.5"
                                >
                                    PASSWORD
                                </label>

                                <div className="relative">
                                    <Lock size={17} className="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500" />
                                    <input
                                        id="password"
                                        type={showPassword ? "text" : "password"}
                                        autoComplete="current-password"
                                        value={password}
                                        onChange={(e) => setPassword(e.target.value)}
                                        placeholder="••••••••"
                                        className={`${fieldClass} pr-12`}
                                    />

                                    <button
                                        type="button"
                                        onClick={() => setShowPassword((v) => !v)}
                                        aria-label={showPassword ? "Hide password" : "Show password"}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 p-1.5 rounded-lg text-gray-500 hover:text-gray-300 transition"
                                    >
                                        {showPassword ? <EyeOff size={17} /> : <Eye size={17} />}
                                    </button>
                                </div>
                            </div>

                            <button
                                type="submit"
                                disabled={submitting}
                                className={`w-full flex items-center justify-center gap-2 py-3.5 short:py-3 rounded-xl font-bold text-sm tracking-[0.1em] transition ${
                                    submitting
                                        ? "bg-gray-700 text-gray-400 cursor-not-allowed"
                                        : "bg-[#0097a7] text-black hover:bg-[#00b0c2]"
                                }`}
                            >
                                {submitting ? "LOGGING IN..." : "LOG IN"}
                                {!submitting && <ArrowRight size={17} />}
                            </button>
                        </form>

                        <p className="mt-7 short:mt-5 shorter:mt-4 text-center text-sm text-gray-400">
                            Don't have an account?{" "}
                            <button
                                type="button"
                                onClick={() => navigate("/signup")}
                                className="text-[#00b0c2] font-semibold hover:underline"
                            >
                                Sign up
                            </button>
                        </p>
                    </div>
                </div>
            </main>
        </div>
    );
};

export default Login;
