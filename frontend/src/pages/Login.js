import React, {useState} from "react";
import api from "../api";
import { useNavigate } from "react-router-dom";
import { useAuth } from "../context/AuthContext";

const Login=() =>{
    const [email, setEmail]= useState("");
    const [password, setPassword]= useState("");
    const navigate = useNavigate();

    const { login } = useAuth();

const handleLogin = async (e) => {
    e.preventDefault();

    try {
        const response = await api.post("/login", { email, password });

        login(response.data.user, response.data.token);

        const role = response.data.user.role;

        if (role === "Student") navigate("/dashboard");
        else if (role === "Professor") navigate("/professor-dashboard");
        else if (role === "Admin") navigate("/admin-dashboard");
    } catch (error) {
        if (error.response?.status === 403 && error.response?.data?.pending) {
            navigate("/professor-pending");
            return;
        }

        alert(error.response?.data?.message ?? "Login failed.");
    }
};
    return (
        <div className="min-h-screen flex items-center justify-center bg-black">
            <div className="w-full max-w-md bg-[#091617] p-8 rounded-2xl shadow-lg shadow-black/40">
                <h2 className="text-2xl font-bold text-center mb-6 text-white">
                    Login
                </h2>
                <form onSubmit={handleLogin}>
                    <div className="mb-4">
                        <label className= "block text-left text-sm mb-1 text-gray-300">
                            Email
                        </label>
                        <input type="email" value={email} onChange={(e)=>setEmail(e.target.value)} placeholder="Enter email" className="w-full p-2 border border-gray-600 bg-gray-700 text-white placeholder-gray-400 rounded-lg"/>
                    </div>
                    <div className="mb-4">
                        <label className="block text-left text-sm mb-1 text-gray-300">
                            Password
                        </label>
                        <input type="password" value={password} onChange={(e)=> setPassword(e.target.value)} placeholder="Enter Password" className="w-full p-2 border border-gray-600 bg-gray-700 text-white placeholder-gray-400 rounded-lg"/>
                    </div>
                    <button type="submit" className="w-full bg-[#0097a7] text-gray-100 p-2 rounded-lg">
                        Login
                    </button>
                </form>
                <button className= "w-full mt-3 border border-[#0097a7] p-2 text-gray-200 rounded-lg">
                    Continue with Google
                </button>
                <p className="text-sm text-center mt-4 text-gray-300">
                    Don't have an account? {""}
                    <span onClick={() => navigate("/signup")}
                        className="text-[#0097a7] cursor-pointer hover:underline"
                    >
                        Sign Up
                    </span>
                </p>
            </div>
        </div>
    );
};

export default Login;