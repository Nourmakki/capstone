import React, {useState} from "react";
import api from "../api";
import { useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";

const Signup=()=>{
    const[name, setName]=useState("");
    const[email, setEmail]=useState("");
    const[password, setPassword]=useState("");
    const[passwordConfirmation, setPasswordConfirmation]=useState("");
    const[location, setLocation]=useState("");
    const[role, setRole]=useState("");
    const[submitting, setSubmitting]=useState(false);
    const navigate = useNavigate();
    const { login } = useAuth();

    // Only flag a mismatch once the user has actually started typing the confirmation.
    const passwordsMismatch =
        passwordConfirmation.length > 0 && password !== passwordConfirmation;

    const handleSignup = async (e) => {
        e.preventDefault();

        if (!role) {
            alert("Please choose whether you are signing up as a student or a professor.");
            return;
        }

        if (password !== passwordConfirmation) {
            alert("The two passwords do not match.");
            return;
        }

        setSubmitting(true);

        try {
            const response = await api.post("/register", {
                name,
                email,
                password,
                password_confirmation: passwordConfirmation,
                location,
                role,
            });

            // Pending professors get no token - they wait for admin approval.
            if (response.data.pending) {
                navigate("/professor-pending");
                return;
            }

            login(response.data.user, response.data.token);
            navigate("/dashboard");

        } catch (error) {
            console.error(error);

            // Laravel returns 422 with a field-keyed errors object.
            const errors = error.response?.data?.errors;

            alert(
                errors
                    ? Object.values(errors).flat().join("\n")
                    : error.response?.data?.message ?? "Registration failed."
            );
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="min-h-screen flex items-center justify-center bg-[#050B0D]">
            <div className="w-full max-w-md bg-[#0c1d24] p-8 rounded-2xl shadow-lg shadow-black/40">
                <h2 className="text-2xl font-bold text-center mb-6 text-white">
                    Create Account
                </h2>
                <form onSubmit={handleSignup}>
                    <div className="mb-4">
                        <label className="block text-left text-sm mb-1 text-gray-300">
                            Full Name
                        </label>
                        <input type="text" value={name} onChange={(e)=>setName(e.target.value)} placeholder="Enter full name" className="w-full p-2 border border-gray-600 bg-gray-700 text-white placeholder-gray-400 rounded-lg" />
                    </div>

                    <div className="mb-4">
                        <label className="block text-left text-sm mb-1 text-gray-300">
                            Email
                        </label>
                        <input type="email" value={email} onChange={(e)=>setEmail(e.target.value)} placeholder="Enter Email" className="w-full p-2 border border-gray-600 bg-gray-700 text-white placeholder-gray-400 rounded-lg" />
                    </div>

                    <div className="mb-4">
                        <label className="block text-left text-sm mb-1 text-gray-300">
                            Password
                        </label>
                        <input type="password" value={password} onChange={(e)=>setPassword(e.target.value)} placeholder="Create password" minLength={8} className="w-full p-2 border border-gray-600 bg-gray-700 text-white placeholder-gray-400 rounded-lg" />
                        <p className="text-xs text-gray-500 mt-1 text-left">
                            At least 8 characters.
                        </p>
                    </div>

                    <div className="mb-4">
                        <label className="block text-left text-sm mb-1 text-gray-300">
                            Confirm Password
                        </label>
                        <input
                            type="password"
                            value={passwordConfirmation}
                            onChange={(e)=>setPasswordConfirmation(e.target.value)}
                            placeholder="Re-enter password"
                            className={`w-full p-2 border bg-gray-700 text-white placeholder-gray-400 rounded-lg ${
                                passwordsMismatch
                                    ? "border-red-500"
                                    : "border-gray-600"
                            }`}
                        />
                        {passwordsMismatch && (
                            <p className="text-xs text-red-400 mt-1 text-left">
                                Passwords do not match.
                            </p>
                        )}
                    </div>

                    <div className="mb-4">
                        <label className="block text-left text-sm mb-1 text-gray-300">
                            Location (optional)
                        </label>
                        <input type="text" value={location} onChange={(e) => setLocation(e.target.value)} placeholder="City / Country" className="w-full p-2 bprder border-gray-600 bg-gray-700 text-white placeholder-gray-400 rounded-lg" />
                    </div>

                    <div className="mb-5">
                        <label className="block text-left text-sm mb-2 text-gray-300">
                            Role
                        </label>
                        <div className="flex gap-2">
                            <button type="button" onClick={()=> setRole("student")} className={`flex-1 p-2 rounded-lg border ${
                                role==="student"
                                    ? "bg-[#0097a7] text-white border-[#0097a7]"
                                    : "bg-gray-700 text-gray-300 border-gray-600"
                            }`}>
                                Student
                            </button>
                            <button type="button" onClick={()=> setRole("professor")} className={`flex-1 p-2 rounded-lg border ${
                                role==="professor"
                                ? "bg-[#0097a7] text-white border-[#0097a7]"
                                : "bg-gray-700 text-gray-300 border-gray-600"
                            }`}>
                                Professor Request
                            </button>
                        </div>
                    </div>

                    <button type="submit" disabled={submitting || passwordsMismatch} className={`w-full p-2 rounded-lg text-white transition ${
                        submitting || passwordsMismatch
                            ? "bg-gray-600 cursor-not-allowed"
                            : "bg-[#0097a7] hover:brightness-110"
                    }`}>
                        {submitting ? "Creating account..." : "Sign Up"}
                    </button>
                </form>
                <p className="text-sm text-center mt-4 text-gray-300">
                    Already have an account?{""}
                    <span onClick={() => navigate("/login")}
                        className="text-[#0097a7] cursor-pointer hover:underline"
                    >
                        Login
                    </span>
                </p>
            </div>
        </div>
    );
};

export default Signup;