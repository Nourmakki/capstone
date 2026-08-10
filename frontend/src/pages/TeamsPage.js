import React, {use, useState} from "react";
import {
    Users,
    Plus,
    UserPlus,
    FolderOpen,
    Send,
    MessageSquare,
} from "lucide-react";

function TeamsPage(){
    const [teamName, setTeamName]=useState("");
    const [roadmap, setRoadmap]=useState("");
    const [project, setProject]= useState("");

    const [chatMessage, setChatMessage]=useState("");
    const [selectedTeam, setSelectedTeam]=useState("");

    const [teams, setTeams]=useState([
        {
            id: 1,
            name: "React Masters",
            roadmapId: "Web Development",
            projectNodeId: "React Final Project",
            members:["Anna", "John", "Sarah",],
            messages: [
                {
                    sender:"John",
                    text:"Welcome everyone!"
                },
                {
                    sender:"Sarah",
                    text: "Let's finish the UI today."
                }
            ],
            sharedProjects:[
                "Authentication System",
                "React Dashboard"
            ]
        },
        {
            id:2,
            name: "AI Builders",
            roadmapId:"Artificial Intelligence",
            projectNodeId: "Neural Networks",
            members:[
                "Mike",
                "Emma"
            ],
            messages:[
                {
                    sender:"Emma",
                    text:"Dataset uploaded."
                }
            ],
            sharedProjects:[
                "Image Classification"
            ]
        }
    ]);
    const createTeam=()=>{
        if (!teamName.trim() || !roadmap.trim() || !project.trim()) 
            return;
        const newTeam={
            id:Date.now(),
            name:teamName,
            roadmapId:roadmap,
            projectNodeId:project,
            members:["You"],
            messages:[
                {
                    sender:"System",
                    text:"Team created successfully!"
                }
            ],
            sharedProjects:[project],
        };
        setTeams([...teams,newTeam]);
        setTeamName("");
        setRoadmap("");
        setProject("");
    };
    const joinTeam=(id)=>{
        setTeams(
            teams.map((team)=>{
                if(team.id===id && !team.members.includes("You")){
                    return {
                        ...team,
                        members: [...team.members, "You"],
                    };
                }
                return team;
            })
        );
    };
    const sendMessage=()=>{
        if (!chatMessage.trim() || !selectedTeam) return ;
        const updatedTeams=teams.map((team)=>{
            if (team.id===selectedTeam.id){
                return {
                    ...team,
                    messages:[
                        ...team.messages,
                        {
                            sender:"You",
                            text:chatMessage,
                        },
                    ],
                };
            }
            return team;
        });
        setTeams(updatedTeams);
        const updatedSelected=updatedTeams.find(
            (t)=>t.id===selectedTeam.id
        );
        setSelectedTeam(updatedSelected);
        setChatMessage("");
    };
    return (
        <div className="min-h-screen bg-black text-white p-8">
            <div className="mb-10">
                <h1 className="text-5xl font-black text-[#0097a7]">
                    Teams & Collaboration
                </h1>
                <p className="text-gray-400 mt-2">
                    Work together, chat, and build projects with others students.
                </p>
            </div>
            <div className="bg-white/[0.03] border border-white/10 rounded-3xl p-6 mb-10">
                <h2 className="text-2xl font-bold mb-6 flex items-center gap-2">
                    <Plus/>
                    Create Team
                </h2>
                <div className="grid md:grid-cols-3 gap-4">
                    <input
                        type="text"
                        placeholder="Team Name"
                        value={teamName}
                        onChange={(e)=>setTeamName(e.target.value)}
                        className="bg-black border border-white/10 rounded-lg p-3f focus:outline-none focus:border-[#0097a7]"
                    />
                    <input
                        type="text"
                        placeholder="Roadmap"
                        value={roadmap}
                        onChange={(e)=>setRoadmap(e.target.value)}
                        className="bg-black border border-white/10 rounded-lg p-3f focus:outline-none focus:border-[#0097a7]"
                    />
                    <input
                        type="text"
                        placeholder="Current Project"
                        value={project}
                        onChange={(e)=>setProject(e.target.value)}
                        className="bg-black border border-white/10 rounded-lg p-3f focus:outline-none focus:border-[#0097a7]"
                    />
                </div>
                <button onClick={createTeam} className="mt-6 bg-[#0097a7] text-black font-bold px-8 py-3 rounded-xl hover:scale-105 transition">
                    Create Team
                </button>
            </div>
            <div>
                <h2 className="text-3xl font-bold mb-6">
                    Available Teams
                </h2>
                <div className="space-y-5">

                {teams.map((team) => (
                <div key={team.id} className="bg-white/[0.03] border border-white/10 rounded-3xl p-6 hover:border-[#0097a7]/40 transition"> 
                    <div className="flex flex-col lg:flex-row justify-between lg:items-center gap-5">
                        <div>
                            <h3 className="text-2xl font-bold">
                                {team.name}
                            </h3>
                            <p className="text-2xl font-bold">
                                Roadmap:{team.roadmapId}
                            </p>
                            <p className="text-gray-400">
                                Project: {team.projectNodeId}
                            </p>
                            <div className="flec items-center gap-2 mt-4 text-[#0097a7]">
                                <Users size={18}/>
                                {team.members.length} Members
                            </div>
                        </div>
                        <div className="flex gap-3">
                            <button onClick={()=>joinTeam(team.id)} className="flex items-center gap-2 border border-[#0097a7]/30 px-5 py-3 rounded-xl hover:bg-[#0097a7]/10 transition">
                                <UserPlus size={18}/>
                                Join
                            </button>
                            <button onClick={()=>setSelectedTeam(team)} className="flex items-center gap-2 bg-[#0097a7] text-black px-5 py-3 rounded-xl font-bold hover:scale-105 transition">
                                <MessageSquare size={18}/>
                                Open
                            </button>
                        </div>
                    </div>
                </div>
                ))}
              </div>
            </div>
            {selectedTeam &&(
                <div className="mt-12 grid lg:grid-cols-2 gap-8">
                    <div className="bg-white/[0.03] border border-white/10 rounded-3xl p-6">
                        <h2 className="text-2xl font-bold flex items-center gap-2 mb-6">
                            <MessageSquare/>
                            {selectedTeam.name} Chat
                        </h2>
                        <div className="bg-black rounded-2xl border border-white/10 h-80 overflow-y-auto p-4 space-y-4">
                            {selectedTeam.messages.map((message, index)=>(
                                <div key={index} className="border border-white/10 rounded-xl p-3">
                                    <p className="text-[#0097a7] font-semibold">
                                        {message.sender}
                                    </p>
                                    <p className="text-gray-300 mt-1">
                                        {message.text}
                                    </p>
                                </div>
                            ))}
                        </div>
                        <div className="flex gap-3 mt-5">
                            <input
                                type="text"
                                placeholder="Write a message..."
                                value={chatMessage}
                                onChange={(e)=>setChatMessage(e.target.value)}
                                className="flex-1 bg-black border border-white/10 rounded-xl p-3 focus:outline-none focus:border-[#0097a7]"
                            /> 
                            <button onClick={sendMessage} className="bg-[#0097a7] text-black px-5 rounded-xl hover:scale-105 transition">
                                <Send/>
                            </button>
                        </div>
                    </div>
                    <div className="bg-white/[0.03] border border-white/10 rounded-3xl p-6">
                        <h2 className="text-2xl font-bold flex items-center gap-2 mb-6">
                            <FolderOpen/>
                            Shared Projects
                        </h2>
                        <div className="space-y-4">
                            {selectedTeam.sharedProjects.map((project,index)=>(
                                <div key={index} className="border border-white/10 p-4 hover:border-[#0097a7]/40 transition">
                                    <h3 className="font-semibold text-lg">
                                        {project}
                                    </h3>
                                    <p className="text-gray-400 mt-2">
                                        Collaborate with your teammates on this project.
                                    </p>
                                </div>
                            ))}
                        </div>
                        <div className="mt-8">
                            <h3 className="text-xl font-bold mb-4">
                                Team Members
                            </h3>
                            <div className="flex flex-wrap gap-3">
                                {selectedTeam.members.map((member,index)=>(
                                    <div key={index} className="px-4 py-2 rounded-full bg-[#0097a7]/10 border border-[#0097a7]/20 text-[#00b0c2]">
                                        {member}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            )}
            {!selectedTeam && (
                <div className="mt-12 bg-white/[0.03] border border-white/10 rounded-3xl p-12 text-center">
                    <Users size={60} className="mx-auto text-gray-500 mb-6"/>
                    <h2 className="text-3xl font-bold">
                        No Team Selected
                    </h2>
                    <p className="text-gray-400 mt-4">
                        Join an existing team or create your own to start collaborating.
                    </p>
                </div>
            )}
        </div>
    );
}

export default TeamsPage;