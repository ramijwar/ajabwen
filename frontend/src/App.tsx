import { Route, Routes } from 'react-router-dom';
import Layout from './components/Layout';
import HomePage from './pages/HomePage';
import ListingPage from './pages/ListingPage';
import AddRequestPage from './pages/AddRequestPage';
import AboutPage from './pages/AboutPage';
import TermsPage from './pages/TermsPage';
import ContactPage from './pages/ContactPage';

import { AuthProvider } from './admin/AuthContext';
import AdminLogin from './admin/AdminLogin';
import AdminLayout from './admin/AdminLayout';
import AdminDashboard from './admin/AdminDashboard';
import AdminItems from './admin/AdminItems';
import AdminItemForm from './admin/AdminItemForm';
import AdminRequests from './admin/AdminRequests';
import AdminAreas from './admin/AdminAreas';
import AdminWipe from './admin/AdminWipe';
import AdminAccount from './admin/AdminAccount';

export default function App() {
  return (
    <AuthProvider>
      <Routes>
        {/* الموقع العام */}
        <Route path="/" element={<Layout><HomePage /></Layout>} />
        <Route path="/pharmacies" element={<Layout><ListingPage slug="pharmacies" subtitle="حالة الصيدليات وآخر تحديثات المناوبة" showDutyFilter /></Layout>} />
        <Route path="/doctors" element={<Layout><ListingPage slug="doctors" subtitle="أطباء المدينة واختصاصاتهم وأرقام عياداتهم" /></Layout>} />
        <Route path="/stations" element={<Layout><ListingPage slug="stations" subtitle="حالة محطات الوقود لحظةً بلحظة" showOpenFilter /></Layout>} />
        <Route path="/transport" element={<Layout><ListingPage slug="transport" subtitle="خطوط النقل الداخلي في المدينة" showOpenFilter /></Layout>} />
        <Route path="/add-request" element={<Layout><AddRequestPage /></Layout>} />
        <Route path="/about" element={<Layout><AboutPage /></Layout>} />
        <Route path="/terms" element={<Layout><TermsPage /></Layout>} />
        <Route path="/contact" element={<Layout><ContactPage /></Layout>} />

        {/* لوحة التحكم */}
        <Route path="/admin/login" element={<AdminLogin />} />
        <Route path="/admin" element={<AdminLayout><AdminDashboard /></AdminLayout>} />
        <Route path="/admin/items/:slug" element={<AdminLayout><AdminItems /></AdminLayout>} />
        <Route path="/admin/items/:slug/new" element={<AdminLayout><AdminItemForm /></AdminLayout>} />
        <Route path="/admin/items/:slug/:id" element={<AdminLayout><AdminItemForm /></AdminLayout>} />
        <Route path="/admin/areas" element={<AdminLayout><AdminAreas /></AdminLayout>} />
        <Route path="/admin/requests" element={<AdminLayout><AdminRequests /></AdminLayout>} />
        <Route path="/admin/wipe" element={<AdminLayout><AdminWipe /></AdminLayout>} />
        <Route path="/admin/account" element={<AdminLayout><AdminAccount /></AdminLayout>} />
      </Routes>
    </AuthProvider>
  );
}
