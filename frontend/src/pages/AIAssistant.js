import React, {useState} from "react";
import {Bot, Send, X, MessageCircle} from "lucide-react";

function AIAssistant(){
    const [isOpen, setIsOpen]=useState(false);
    const [message, setMessage]=useState("");
    const [messages, setMessages]=useState([
        {
            sender:"ai",
            text: "Hi! I'm FullHub AI. Ask me about roadmaps, programming concepts, debugging, quizzes, or projects."
        }
    ]);
    const handleSend=()=>{
        if(!message.trim()) return;
        const userMessage={
            sender:"user",
            text:message
        };
        const aiMessage={
            sender: "ai",
            text:"This is a demo response. Later this will come from your AI backend."
        };
        setMessages(prev => [...prev, userMessage, aiMessage]);
        setMessage("");
    };
    return (
        <>
            {!isOpen && (
                <button onClick={()=> setIsOpen(true)} className="fixed bottom-6 right-6 z-50 w-16 h-16 rounded-full bg-[#0097a7] text-black flex items-center justify-center shadow-lg hover: scale-110 transition">
                    <MessageCircle size={28}/>
                </button>
            )}
            {isOpen && (
                <div className="fixed bottom-6 right-6  z-50 w-[380px] h-[600px] rounded-3xl border border-[#0097a7]/20 bg-gray-950 shadow-2xl overflow-hidden flex flex-col">
                    <div className="flex items-center justify-between p-5 border-b border-white/10 bg-[#0097a7]/10">
                        <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-full bg-[#0097a7] text-black flex items-center justify-center">
                                <Bot size={20}/>
                            </div>
                            <div>
                                <h2 className="font-bold text-[#0097a7]">
                                    CS Mastery AI
                                </h2>
                                <p className="text-xs text-gray-400">
                                    Roadmaps • Concepts • Debugging
                                </p>
                            </div>
                        </div>
                        <button onClick={()=> setIsOpen(false)} className="text-gray-400 hover:text-white">
                            <X size={20}/>
                        </button>
                    </div>
                    <div className="flex-1 overflow-y-auto p-4 space-y-4">
                        {messages.map((msg, index)=>(
                            <div key={index} className={`flex ${msg.sender ==="user" ? "justify-end" : "justify-start"}`}>
                                <div className={`max-w-[80%] px-4 py-3 rounded-2xl ${
                                    msg.sender==="user" ? "bg-[#0097a7] text-black" : "bg-white/5 border border-white/10 text-white"
                                }`}>
                                    {msg.text}
                                </div>
                            </div>
                        ))}
                    </div>
                    <div className="px-4 pb-3 flex flex-wrap gap-2">
                        <button className="px-3 py-1 text-xs rounded-full bg-gray-800 text-white border border-white/10 hover:border-[#0097a7]/30">
                            Explain React Hooks
                        </button>
                        <button className="px-3 py-1 text-xs rounded-full bg-gray-800 text-white border border-white/10 hover:border-[#0097a7]/30">
                            Debugging my code
                        </button>
                        <button className="px-3 py-1 text-xs rounded-full bg-gray-800 text-white border border-white/10 hover:border-[#0097a7]/30">
                            Roadmap advice
                        </button>
                    </div>
                    <div className="-4 border-t border-white/10">
                        <div className="flex gap-3">
                            <input type="text" value={message} onChange={(e)=> setMessage(e.target.value)} onKeyDown={(e)=> e.key==="Enter" && handleSend()} placeholder="Ask FullHub AI..." className="flex-1 p-3 rounded-2xl bg-gray-900 border border-white/10 text-white outline-none focus:border-[#0097a7]/40" />
                            <button onClick={handleSend} className="w-12 h-12 rounded-2xl bg-[#0097a7] text-black flex items-center justify-center hover:scale-105 transition">
                                <Send size={18}/>
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
export default AIAssistant;