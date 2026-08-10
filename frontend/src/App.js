import "./App.css";
import { BrowserRouter, Routes, Route } from "react-router-dom";

import HomePage from "./pages/HomePage";
import Login from "./pages/Login";
import Signup from "./pages/Signup";
import Dashboard from "./pages/Dashboard";
import RoadmapOverview from "./pages/RoadmapOverview";
import LearningPage from "./pages/LearningPage";
import QuizPage from "./pages/QuizPage";
import ProjectSubmissionPage from "./pages/ProjectSubmissionPage";
import ProfilePage from "./pages/ProfilePage";
import AIAssistant from "./pages/AIAssistant";
import AdminProfessorPanel from "./pages/AdminProfessorPanel";
import TodoPage from "./pages/TodoPage";
import TeamsPage from "./pages/TeamsPage";
import WaitingApproval from "./pages/WaitingApproval";
import ProfessorDashboard from "./pages/ProfessorDashboard";
import AdminDashboard from "./pages/AdminDashboard";

function App() {
    return (
        <BrowserRouter>
            <Routes>

                <Route path="/" element={<HomePage />} />

                <Route path="/login" element={<Login />} />

                <Route path="/signup" element={<Signup />} />

                <Route path="/dashboard" element={<Dashboard />} />

                <Route path="/roadmap" element={<RoadmapOverview />} />

                <Route path="/professor-dashboard" element={<ProfessorDashboard />} />
                <Route path="/admin-dashboard" element={<AdminDashboard />} />
                <Route path="/professor-pending" element={<WaitingApproval />} />

                <Route
                    path="/learning/:versionNodeId"
                    element={<LearningPage />}
                />

                <Route path="/quiz" element={<QuizPage />} />

                <Route
                    path="/project"
                    element={<ProjectSubmissionPage />}
                />

                <Route path="/profile" element={<ProfilePage />} />

                <Route path="/assistant" element={<AIAssistant />} />

                <Route
                    path="/admin"
                    element={<AdminProfessorPanel />}
                />

                <Route path="/todo" element={<TodoPage />} />

                <Route path="/teams" element={<TeamsPage />} />

            </Routes>
        </BrowserRouter>
    );
}

export default App;