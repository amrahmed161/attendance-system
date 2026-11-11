import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import Login from './pages/Login'
import ProtectedRoute from './components/ProtectedRoute'
import ProtectedLayout from './components/layout/ProtectedLayout'
import AttendanceChecklist from './pages/AttendanceChecklist'
import AttendanceReports from './pages/AttendanceReports'
import DashboardHome from './pages/DashboardHome'
import StudentsManager from './pages/StudentsManager'

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />

        <Route
          element={
            <ProtectedRoute>
              <ProtectedLayout />
            </ProtectedRoute>
          }
        >
          <Route index element={<DashboardHome />} />
          <Route path="attendance" element={<AttendanceChecklist />} />
          <Route path="students" element={<StudentsManager />} />
          <Route path="reports" element={<AttendanceReports />} />
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  )
}