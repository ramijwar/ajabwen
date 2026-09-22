import { ReactNode } from 'react';
import { Navigate, NavLink, useLocation } from 'react-router-dom';
import { useAuth } from './AuthContext';

export default function AdminLayout({ children }: { children: ReactNode }) {
  const { admin, loading, logout } = useAuth();
  const location = useLocation();

  if (loading) return <div className="admin-body" style={{ padding: 40, textAlign: 'center' }}>جارٍ التحميل...</div>;
  if (!admin) return <Navigate to="/admin/login" state={{ from: location }} replace />;

  return (
    <div className="admin-body">
      <div className="admin-shell">
        <aside className="admin-sidebar">
          <div className="brand"><span className="brand-icon">📍</span><span>لوحة التحكم</span></div>
          <NavLink to="/admin" end>الرئيسية</NavLink>
          <NavLink to="/admin/items/pharmacies">💊 الصيدليات</NavLink>
          <NavLink to="/admin/items/doctors">🩺 الأطباء</NavLink>
          <NavLink to="/admin/items/stations">⛽ الكازيات</NavLink>
          <NavLink to="/admin/items/transport">🚌 النقل</NavLink>
          <NavLink to="/admin/areas">🗺️ المناطق</NavLink>
          <NavLink to="/admin/requests">📥 طلبات المستخدمين</NavLink>
          <NavLink to="/admin/wipe">🧹 مسح بيانات القاعدة</NavLink>
          <NavLink to="/admin/account">🔑 الحساب</NavLink>
          <button className="link" onClick={logout}>🚪 تسجيل الخروج</button>
          <a href={import.meta.env.BASE_URL || '/'} target="_blank" rel="noreferrer">↗ عرض الموقع</a>
        </aside>
        <div className="admin-main">{children}</div>
      </div>
    </div>
  );
}
