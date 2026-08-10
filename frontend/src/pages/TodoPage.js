import React, {useState} from "react";
import {
    Trash2,
    Pencil,
    Calendar,
    Bell,
    BellOff,
    CheckCircle2,
} from "lucide-react";
import DatePicker from "react-datepicker";
import "react-datepicker/dist/react-datepicker.css";

function TodoPage(){
    const [taskText, setTaskText]=useState("");
    const [deadline, setDeadline]=useState(null);
    const [reminder, setReminder]=useState(false);
    const [roadmapId, setRoadmapId]=useState("");
    const [nodeId, setNodeId]=useState("");

    const [tasks, setTasks]=useState([
        {
            id:1,
            text:"Complete React Basics Quiz",
            deadline:"2026-06-30",
            reminder:true,
            completed:false,
            roadmapId:"web-development",
            nodeId:"react-basics",
        },
    ]);
    const [editingId, setEditingId]=useState(null);
    const addTask=()=> {
        if (!taskText.trim())return;
        const newTask={
            id: Date.now(),
            text:taskText,
            deadline: deadline
                ? deadline.toISOString().split("T")[0]
                : "",
            reminder,
            completed:false,
            roadmapId,
            nodeId,
        };
        setTasks([...tasks, newTask]);
        
        setTaskText("");
        setDeadline("");
        setReminder(false);
        setRoadmapId("");
        setNodeId("");
    };

        const deleteTask=(id)=>{
            setTasks(tasks.filter((task)=>task.id!==id))
        };
        const startEdit=(task)=>{
            setEditingId(task.id);
            setTaskText(task.text);
            setDeadline(task.deadline);
            setReminder(task.reminder);
            setRoadmapId(task.roadmapId || "");
            setNodeId(task.nodeId || "");
        };
        const saveEdit=()=>{
            setTasks(
                tasks.map((task)=>
                task.id===editingId
                ? {
                    ...task,
                    text:taskText,
                    deadline: deadline
                        ? deadline.toISOString().split("T")[0]
                        : "",
                    reminder,
                    roadmapId,
                    nodeId,
                }
                : task
            )
            );

            setEditingId(null);
            setTaskText("");
            setDeadline("");
            setReminder(false);
            setRoadmapId("");
            setNodeId("");
        };
        const toggleCompleted=(id)=>{
            setTasks(
                tasks.map((task)=>
                    task.id===id
                    ? {...task, completed:!task.completed}
                    :task
                )
            );
        };

        return (
            <div className="min-h-screen bg-black text-white p-8">
                <div className="mb-10">
                    <h1 className="text-5xl font-black text-[#0097a7]">
                        My To-Do List
                    </h1>
                    <p className="text-gray-400 mt-2">
                        Organize your learning journey and stay productive.
                    </p>
                </div>
                <div className="bg-white/[0.03] border border-white/10 rounded-3xl p-6 mb-10">
                    <h2 className="text-2xl font-bold mb-5">
                        {editingId ? "Edit Task" : "Add New Task"}
                    </h2>
                    <div className="flex flex-col gap-4">
                        <input type="text" placeholder="Enter task..." value={taskText} onChange={(e)=>setTaskText(e.target.value)} className="bg-black border border-white/10 rounded-xl p-3 focus:outline-none focus:border-[#0097a7]" />
                        <div className="flex flex-col md:flex-row gap-4">
                            <div className="flex items-center gap-2 bg-black border border-white/10 rounded-xl p-3 flex-1">
                                <Calendar size={18} className="text-[#0097a7]" />
                                <DatePicker selected={deadline}
                                onChange={(date) => setDeadline(date)}
                                dateFormat="yyyy-MM-dd"
                                placeholderText="Select a deadline"
                                className="bg-transparent text-white w-full outline-none"
                                />
                            </div>
                            <button onClick={()=>setReminder(!reminder)} className={`flex items-center justify-center gap-2 px-4 rounded-xl border transition ${
                                reminder 
                                    ? "border-[#0097a7] text-[#0097a7]"
                                    : "border-white/10" 
                            }`}>
                                {reminder ? <Bell size={18}/> : <BellOff size={18}/>}
                                Reminder
                            </button>
                        </div>
                        <div className="grid md:grid-cols-2 gap-4">
                            <input type="text" placeholder="Roadmap ID (optional)" value={roadmapId} onChange={(e)=> setRoadmapId(e.target.value)} className="bg-black border border-white/10 rounded-xl p-3 focus:outline-none focus:border-[#0097a7]" />
                            <input type="text" placeholder="Node ID (optional)" value={nodeId} onChange={(e)=> setNodeId(e.target.value)} className="bg-black border border-white/10 rounded-xl p-3 focus:outline-none focus:border-[#0097a7]" />
                        </div>
                        <button onClick={editingId ? saveEdit : addTask} className="bg-[#0097a7] text-black font-bold py-3 rounded-xl hover:scale-[1.02] transition">
                            {editingId ? "Save Changes" : "Add Task"}
                        </button>
                    </div>
                </div>
                <div>
                    <h2 className="text-3xl font-bold mb-6">
                        Tasks ({tasks.length})
                    </h2>
                    <div className="space-y-4">
                        {tasks.map((task)=>(
                            <div key={task.id} className="bg-white/[0.03] border border-white/10 rounded-2xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                                <div className="flex items-start gap-4">
                                    <button onClick={()=> toggleCompleted(task.id)}>
                                        <CheckCircle2 size={24} className={task.completed ? "text-green-400" : "text-green-500"}/>
                                    </button>
                                    <div>
                                        <h3 className={`font-semibold text-lg ${task.completed ?"line-through text-gray-500" : ""}`}>
                                            {task.text}
                                        </h3>
                                        <div className="flex flex-wrap gap-4 mt-2 text-sm text-gray-400">
                                            {task.deadline && (
                                                <span>
                                                    📅 Deadline: {task.deadline}
                                                </span>
                                            )}
                                            <span>
                                                {task.reminder
                                                    ? "🔔 Reminder On"
                                                    : "🔕 Reminder Off"}
                                            </span>
                                        </div>
                                        {task.roadmapId && (
                                            <p className="text-xs text-[#0097a7] mt-2">
                                                Roadmap: {task.roadmapId}
                                            </p>
                                        )}
                                        {task.nodeId && (
                                            <p className="text-xs text-purple-400">
                                                Node: {task.nodeId}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <div className="flex gap-3">
                                    <button onClick={()=> startEdit(task)} className="p-3 rounded-xl border border-[#0097a7]/30 text-[#0097a7] hover:bg-[#0097a7]/10">
                                        <Pencil size={18}/>
                                    </button>
                                    <button onClick={()=>deleteTask(task.id)} className="p-3 rounded-xl border border-red-400/30 text-red-400 hover:bg-red-400/10">
                                        <Trash2 size={18}/>
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                    {tasks.length ===0 && (
                        <div className="text-center text-gray-500 mt-10">
                            No tasks yet.
                        </div>
                    )}
                </div>
            </div>
        );
}

export default TodoPage;