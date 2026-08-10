import { useNavigate } from "react-router-dom";

function WaitingApproval() {
    const navigate = useNavigate();

    return (
        <div className="min-h-screen flex items-center justify-center bg-black text-white">
            <div className="bg-[#091617] p-10 rounded-2xl text-center max-w-lg">
                <h1 className="text-3xl font-bold text-[#0097a7] mb-4">
                    Request Submitted
                </h1>

                <p className="text-gray-300">
                    Your professor request has been submitted successfully.
                </p>

                <p className="text-gray-400 mt-4">
                    An administrator will review your request.
                    Once approved, you can log in as a professor.
                </p>

                <button onClick={() => navigate("/login")}
                    className="mt-8 px-6 py-3 rounded-xl bg-[#0097a7] text-black font-bold hover:scale-105 transition"
                >
                    Back to Login
                </button>
            </div>
        </div>
    );
}

export default WaitingApproval;