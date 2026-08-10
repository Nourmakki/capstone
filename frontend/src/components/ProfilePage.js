import React, {useState} from "react";
import {
    User,
    Mail,
    MapPin,
    Trophy,
    BookOpen,
    Target,
    Edit3,
    Award,
    Flame,
} from "lucide-react";

function ProfilePage(){
    const [location, setLocation]=useState("Beirut, Lebanon");
    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.08), transparent_55%)] pointer-events-none">
            </div>
            <header className="relative z-10 px-8 lg:px-20 py-10 border-b border-white/10">
                <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-8">
                    <div className="flex items-center gap-5">
                        <div className="w-24 h-24 rounded-full bg-[#0097a7] text-black flex items-center justify-center font-black text-4xl">
                            A
                        </div>
                        <div>
                            <h1 className="text-4xl font-black">
                                Anna Beth
                            </h1>
                            <p className="text-gray-400 mt-2">
                                Computer Science Student
                            </p>
                        </div>
                    </div>
                    <button className="px-6 py-3 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-105 transition">
                        Edit Profile
                    </button>
                </div>
            </header>
            <main className="relative z-10 px-8 lg:px-20 py-12">
                <div className="grid lg:grid-cols-[1fr_350px] gap-8">
                    <div className="space-y-8">
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                            <h2 className="text-2xl font-bold mb-6">
                                User Information
                            </h2>
                            <div className="space-y-5">
                                <div className="flex items-center gap-4">
                                    <User className="text-[#0097a7]"/>
                                    <span>Anna Beth</span>
                                </div>
                                <div className="flex items-center gap-4">
                                    <Mail className="text-[#0097a7]"/>
                                    <span>anna@example.com</span>
                                </div>
                                <div className="flex items-center gap-4">
                                    <MapPin className="text-[#0097a7]"/>
                                    <span>{location}</span>
                                </div>
                            </div>
                        </div>
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                            <h2 className="text-2xl font-bold mb-6">
                                Selected Roadmaps
                            </h2>
                            <div className="space-y-6">
                                <div>
                                    <div className="flex justify-between mb-2">
                                        <span>Web Development</span>
                                        <span className="text-[#00b0c2]">35%</span>
                                    </div>
                                    <div className="w-full h-3 bg-black/30 rounded-full overflow-hidden">
                                        <div className="h-full bg-gradient-to-r from-blue-400 to-[#0097a7]" style={{width:"35%"}}>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <div className="flex justify-between mb-2">
                                        <span>AI / ML</span>
                                        <span className="text-[#00b0c2]">12%</span>
                                    </div>
                                    <div className="w-full h-3 bg-black/30 rounded-full overflow-hidden">
                                        <div className="h-full bg-gradient-to-r from-violet-500 to-purple-500" style={{width:"12%"}}>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                            <h2 className="text-2xl font-bold mb-6">
                                Achievements
                            </h2>
                            <div className="grid md:grid-cols-2 gap-5">
                                <div className="rounded-2xl border border-yellow-400/20 bg-yellow-500/5 p-5">
                                    <Award className="text-yellow-400 mb-3" />
                                    <h3 className="font-bold">
                                        First Quiz Passed
                                    </h3>
                                </div>
                                <div className="rounded-2xl border border-[#0097a7]/20 bg-[#0097a7]/5 p-5">
                                    <Flame className="text-[#0097a7] mb-3"/>
                                    <h3 className="font-bold">
                                        7-Day Streak
                                    </h3>
                                    <p className="text-sm text-gray-400 mt-2">
                                        Studied for 7 consecutive days.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="space-y-8">
                        <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                            <h2 className="text-2xl font-bold mb-6">
                                Learning Stats
                            </h2>
                            <div className="flex justify-between">
                                <div className="flex items-center gap-3">
                                    <BookOpen className="text-[#0097a7]"/>
                                    Modules Completed
                                </div>
                                <span>18</span>
                            </div>
                            <div className="flex justify-between">
                                <div className="flex items-center gap-3">
                                    <Target className="text-[#0097a7]"/>
                                    Quizzes Passed
                                </div>
                                <span>12</span>
                            </div>
                            <div className="flex justify-between">
                                <div className="flex items-center gap-3">
                                    <Trophy className="text-[#0097a7]"/>
                                    Projects Completed
                                </div>
                                <span>6</span>
                            </div>
                        </div>
                    
                    <div className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                        <div className="flex items-center justify-between mb-6">
                            <h2 className="text-2xl font-bold">
                                Location Settings
                            </h2>
                            <Edit3 size={18} className="text-[#0097a7] cursor-pointer"/>
                        </div>
                        <label className="block text-gray-300 mb-2">
                            Location
                        </label>
                        <input type="text" value={location} onChange={(e)=>setLocation(e.target.value)} className="w-full p-3 rounded-xl bg-black/30 border border-white/10 text-white" />
                        <p className="text-gray-400 text-sm mt-4">
                            Used to recommend nearby workshops and events.
                        </p>
                    </div>
                    </div>
                </div>
            </main>
        </div>
    );
}

export default ProfilePage;