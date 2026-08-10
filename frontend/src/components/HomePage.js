import React from "react";
import {Code2, Brain, Smartphone, Database, Cloud, Cpu, Gamepad2, Monitor, ArrowRight,} from "lucide-react";

const roadmapCards=[
    {
        title:"Web Development",
        description:"Frontend, backend, APIs, and the full stack engineering.",
        icon: <Code2 size={28}/>,
        color:"from-cyan-500 to-blue-500",
    },
    {
        title: "AI / ML",
        description: "Machine learning, deep learning, LLMs, and AI systems.",
        icon: <Brain size={28} />,
        color: "from-[#0097a7] to-green-400",
    },
    {
        title: "Mobile Development",
        description: "Android. iOS, Flutter, and React Native.",
        icon: <Smartphone size={28} />,
        color: "from-yellow-400 to-orange-500",
    },
    {
        title: "DevOps / Cloud",
        description: "CI/CD, Docker, Kubernetes, and cloud infrastructure.",
        icon: <Cloud size={28} />,
        color: "from-pink-500 to-rose-500",
    },
    {
        title: "Embedded /IOT",
        description:"Firmware systems and hardware programming.",
        icon: <Gamepad2 size={28} />,
        color: "from-orange-500 to-red-500",
    },
    {
        title:"Desktop Apps",
        description:"Modern desktop software with Electron and more.",
        icon: <Monitor size={28} />,
        color: "from-fuchsia-500 to-purple-600",
    },
];

function HomePage(){
    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            
            <div className= "absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center, rgba(0,255,200, 0.08), transparent_55%)] pointer-events-none">
            </div>
            <nav className="relative z-10 flex items-center justify-between px-8 py-5 border-b border-white/10">
                <div>
                    <h1 className="text-2xl font-bold tracking-wide text-[#0097a7]">
                        CS Mastery
                    </h1>
                    <p className="text-sm text-gray-400">
                        Structured CS Learning Platform
                    </p>
                </div>
                <div className="flex items-center gap-4">
                    <button className="px-5 py-2 rounded-xl border border-[#0097a7]/30 text-[#00b0c2] hover:bg-[#0097a7]/10 transition">
                        Login
                    </button>
                    <button className="px-5 py-2 rounded-xl bg-[#0097a7] text-black font-semibold hover:scale-105 transition">
                        Sign Up
                    </button>
                </div>
            </nav>
            <section className="relative z-10 px-8 lg:px-20 pt-20 pb-16 ">
                <div className="max-w-4xl">
                    <div className="inline-block px-4 py-1 rounded-full border border-[#0097a7]/30 bg-[#0097a7]/10 text-[#00b0c2] text-sm mb-6">
                        CS Roadmaps • AI Guidane • Projects • Quizzes
                    </div>
                    <h1 className="text-5xl lg:text-7xl font-black leading-tight">
                        Learn Computer Science
                        <span className="block text-[#0097a7]">
                            The Structured Way
                        </span>
                    </h1>
                    <p className="mt-6 text-lg text-gray-400 max-w-2xl leading-relaxed">
                        Doscover clear software engineering rpadmaps. AI-guided learning,
                        quizzes, projects, teamwork, and organized study plans - all in one 
                        trusted platform.
                    </p>
                    <div className="flex flex-wrap gap-4 mt-10">
                        <button className="flex items-center gap-2 px-7 py-4 rounded-2xl bg-[#0097a7] text-black font-bold hover:scale-105 transition">
                            Start Learning
                            <ArrowRight size={18}/>
                        </button>
                        <button className="px-7 py-4 rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 transition">
                            Explore Roadmaps
                        </button>
                    </div>
                </div>
            </section>
            <section className="relative z-10 px-8 lg:px-20 pb-24">
                <div className="flex items-center justify-between mb-10">
                    <div>
                        <h2 className="text-3xl font-bold">
                            Explore Software Engineering Domains
                        </h2>
                        <p className="text-gray-400 mt-2">
                            Structured learning paths for modern tech careers.
                        </p>
                    </div>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
                    {roadmapCards.map((card, index) => (
                        <div key={index} className="group relative rounded-3xl border border-white/10 bg-white/[0.03] backdrop-blur-md p-6 hover:border-[#0097a7]/40 transition duration-300 overflow-hidden">
                            <div className={`absolute inset-0 opacity-0 group-hover:opacity-20 transition bg-gradient-to-br ${card.color}`}>
                            </div>
                            <div className={`w-14 h-14 rounded-2xl flex items-center justify-center bg-gradient-to-br ${card.color}`}>
                                {card.icon}
                            </div>
                            <h3 className="mt-6 text-xl font-bold">
                                {card.title}
                            </h3>
                            <p className="mt-3 text-sm text-gray-400 leading-relaxed">
                                {card.description}
                            </p>
                            <button className="mt-6 text-[#0097a7] text-sm flex items-center gap-2 group-hover:gap-3 transition-all">
                                View Roadmap
                                <ArrowRight size={16}/>
                            </button>
                        </div>
                    ))}
                </div>
            </section>
            
            <section className="relative z-10 px-8 lg:px-20 pb-28">
                    <div className="grid lg:grid-cols-3 gap-6">
                        {[
                            {
                                title: "AI Guided Learning",
                                text: "AI recommends tutorials, evaluates quizzes, and gives instant feedback.",
                            },
                            {
                                title:"Mandatory Projects",
                                text:"Students build projects and pass checkpoints before progressing.",
                            },
                            {
                                title:"Team Collaboration",
                                text:"Create teams, collaborate on projects, and help each other learn.",
                            },
                        ].map((feature, i)=>(
                            <div key={i} className="rounded-3xl border border-white/10 bg-white/[0.03] p-8">
                                <h3 className="text-2xl font-bold mb-4">
                                    {feature.title}
                                </h3>
                                <p className="text-gray-400 leading-relaxed">
                                    {feature.text}
                                </p>
                            </div>
                        ))}
                    </div>
            </section>
            
           {/* <footer className="relative z-10 border-t border-white/10 px-8 lg:px-20 py-8 text-sm text-gray-500 flex flex-col md:flex-row items-center justify-between">

            </footer>*/}
        </div>
    );
}


export default HomePage;