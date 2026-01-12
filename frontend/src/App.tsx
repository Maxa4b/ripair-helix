import { BrowserRouter, Route, Routes } from 'react-router-dom';
import RequireAuth from './components/RequireAuth';
import AppLayout from './components/AppLayout';
import LoginPage from './pages/LoginPage';
import DashboardPage from './pages/DashboardPage';
import CalendarPage from './pages/CalendarPage';
import AppointmentsPage from './pages/AppointmentsPage';
import AvailabilityPage from './pages/AvailabilityPage';
import SettingsPage from './pages/SettingsPage';
import TechniciansPage from './pages/TechniciansPage';
import GauloisPage from './pages/GauloisPage';
import ToolsPage from './pages/ToolsPage';
import LivreoPage from './pages/LivreoPage';
import RHCatalogoPage from './pages/RHCatalogoPage';
import ReviewsPage from './pages/ReviewsPage';
import CacaPage from './pages/CacaPage';

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route element={<RequireAuth />}>
          <Route element={<AppLayout />}>
            <Route index element={<DashboardPage />} />
            <Route path="calendar" element={<CalendarPage />} />
            <Route path="appointments" element={<AppointmentsPage />} />
            <Route path="technicians" element={<TechniciansPage />} />
            <Route path="reviews" element={<ReviewsPage />} />
            <Route path="caca" element={<CacaPage />} />
            <Route path="availability" element={<AvailabilityPage />} />
            <Route path="settings" element={<SettingsPage />} />
            <Route path="tools" element={<ToolsPage />} />
            <Route path="gaulois" element={<GauloisPage />} />
            <Route path="livreo" element={<LivreoPage />} />
            <Route path="rhcatalogo" element={<RHCatalogoPage />} />
          </Route>
        </Route>
        <Route path="*" element={<LoginPage />} />
      </Routes>
    </BrowserRouter>
  );
}
