import React, {useState} from "react";

const Signup=()=>{
    const[name, setName]=useState("");
    const[email, setEmail]=useState("");
    const[password, setPassword]=useState("");
    const[location, setLocation]=useState("");
    const[role, setRole]=useState("");

    const handleSignup=(e)=>{
        e.preventDefault();
        console.log({name,email,password,location,role});
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
                        <input type="password" value={password} onChange={(e)=>setPassword(e.target.value)} placeholder="Create password" className="w-full p-2 border border-gray-600 bg-gray-700 text-white placeholder-gray-400 rounded-lg" />
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

                    <button type="submit" className="w-full bg-[#0097a7] text-white p-2 rounded-lg">
                        Sign Up
                    </button>
                </form>
                <p className="text-sm text-center mt-4 text-gray-300">
                    Already have an account?{""}
                    <span className="text-[#0097a7] cursor-pointer">
                        Login
                    </span>
                </p>
            </div>
        </div>
    );
};

export default Signup;