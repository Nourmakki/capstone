import "./App.css";
import { BrowserRouter, Routes, Route } from "react-router-dom";

import { AuthProvider } from "./context/AuthContext";
import ProtectedRoute from "./components/ProtectedRoute";

import HomePage from "./pages/HomePage";
import Login from "./pages/Login";
import Signup from "./pages/Signup";
import Dashboard from "./pages/Dashboard";
import RoadmapOverview from "./pages/RoadmapOverview";
import LearningPage from "./pages/LearningPage";
import QuizPage from "./pages/QuizPage";
import ProjectSubmissionPage from "./pages/ProjectSubmissionPage";
import TodoPage from "./pages/TodoPage";
import TeamsPage from "./pages/TeamsPage";
import WaitingApproval from "./pages/WaitingApproval";
import ProfessorDashboard from "./pages/ProfessorDashboard";
import AdminDashboard from "./pages/AdminDashboard";

function App() {
    return (
        <BrowserRouter>
            <AuthProvider>
                <Routes>

                    {/* Public */}
                    <Route path="/" element={<HomePage />} />
                    <Route path="/login" element={<Login />} />
                    <Route path="/signup" element={<Signup />} />
                    <Route path="/professor-pending" element={<WaitingApproval />} />

                    {/* Students */}
                    <Route path="/dashboard" element={
                        <ProtectedRoute roles={["Student"]}><Dashboard /></ProtectedRoute>
                    } />
                    <Route path="/roadmap/:roadmapId" element={
                        <ProtectedRoute roles={["Student"]}><RoadmapOverview /></ProtectedRoute>
                    } />
                    <Route path="/learning/:versionNodeId" element={
                        <ProtectedRoute roles={["Student"]}><LearningPage /></ProtectedRoute>
                    } />
                    <Route path="/quiz/:quizId" element={
                        <ProtectedRoute roles={["Student"]}><QuizPage /></ProtectedRoute>
                    } />
                    <Route path="/project/:projectId" element={
                        <ProtectedRoute roles={["Student"]}><ProjectSubmissionPage /></ProtectedRoute>
                    } />
                    <Route path="/todo" element={
                        <ProtectedRoute roles={["Student"]}><TodoPage /></ProtectedRoute>
                    } />
                    <Route path="/teams" element={
                        <ProtectedRoute roles={["Student"]}><TeamsPage /></ProtectedRoute>
                    } />

                    {/* Staff */}
                    <Route path="/professor-dashboard" element={
                        <ProtectedRoute roles={["Professor"]}><ProfessorDashboard /></ProtectedRoute>
                    } />
                    <Route path="/admin-dashboard" element={
                        <ProtectedRoute roles={["Admin"]}><AdminDashboard /></ProtectedRoute>
                    } />

                </Routes>
            </AuthProvider>
        </BrowserRouter>
    );
}

export default App;