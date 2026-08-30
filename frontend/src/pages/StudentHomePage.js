import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import api from "../api";
import RoadmapCard from "../components/RoadmapCard";

function StudentHomePage() {
    const navigate = useNavigate();
    const [roadmaps, setRoadmaps] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        const loadRoadmaps = async () => {
            try {
                const response = await api.get("/roadmaps");
                setRoadmaps(response.data.data ?? []);
            } catch (err) {
                console.error(err);
                setError("Could not load roadmaps.");
            } finally {
                setLoading(false);
            }
        };

        loadRoadmaps();
    }, []);

    return (
        <div className="min-h-screen bg-black text-white overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full bg-[radial-gradient(circle_at_center,rgba(0,255,200,0.15),transparent_55%)] pointer-events-none" />

            {/* Same padding and heading treatment as the other dashboard
                pages, so the title lands in the same place on every one. */}
            <section className="pt-20 relative z-10 px-8 pb-24">
                <div className="mb-10">
                    <h1 className="text-3xl lg:text-4xl font-black text-[#0097a7]">
                        Explore Software Engineering Domains
                    </h1>
                    <p className="text-gray-400 mt-2">
                        Structured learning paths for modern tech careers.
                    </p>
                </div>

                {loading && (
                    <div className="text-gray-400 py-20 text-center">
                        Loading roadmaps...
                    </div>
                )}

                {error && (
                    <div className="text-red-400 py-20 text-center">{error}</div>
                )}

                {!loading && !error && roadmaps.length === 0 && (
                    <div className="text-gray-400 py-20 text-center">
                        No roadmaps are available yet.
                    </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {roadmaps.map((roadmap) => (
                        <RoadmapCard
                            key={roadmap.id}
                            roadmap={roadmap}
                            onOpen={(r) => navigate(`/roadmap/${r.id}`)}
                        />
                    ))}
                </div>
            </section>
        </div>
    );
}

export default StudentHomePage;
