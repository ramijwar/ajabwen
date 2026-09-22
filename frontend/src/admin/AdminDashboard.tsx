import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api/client';
import { useAuth } from './AuthContext';
import { DashboardStats } from '../types';

export default function AdminDashboard() {
  const { admin } = useAuth();
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);
    setError('');
    api.get<DashboardStats>('/admin/stats.php', true)
      .then(setStats)
      .catch((err) => setError(err?.message || 'تعذر تحميل بيانات لوحة التحكم'))
      .finally(() => setLoading(false));
  }, []);

  return (
    <>
      <div className="admin-topbar"><h1>مرحباً، {admin?.username} 👋</h1></div>

      {loading && <div className="alert info">جارٍ تحميل بيانات لوحة التحكم...</div>}
      {error && <div className="alert error">{error}</div>}

      {stats && (
        <>
          <div className="stat-cards">
            <div className="stat-card"><div className="num">{stats.total_items}</div><div className="label">إجمالي الخدمات المنشورة</div></div>
            <div className="stat-card"><div className="num">{stats.pending_requests}</div><div className="label">طلبات بانتظار المراجعة</div></div>
            <div className="stat-card"><div className="num">{stats.areas_count}</div><div className="label">عدد المناطق المُدارة</div></div>
          </div>

          <h2 style={{ fontSize: '1.1rem' }}>الخدمات حسب التصنيف</h2>
          <div className="stat-cards">
            {stats.by_category.map((c) => (
              <Link className="stat-card" style={{ display: 'block' }} key={c.slug} to={`/admin/items/${c.slug}`}>
                <div className="num">{c.count}</div>
                <div className="label">{c.icon} {c.name}</div>
              </Link>
            ))}
          </div>

          {stats.pending_requests > 0 && (
            <div className="alert info">
              لديك <b>{stats.pending_requests}</b> طلب إضافة خدمة بانتظار المراجعة.{' '}
              <Link to="/admin/requests" style={{ textDecoration: 'underline', fontWeight: 800 }}>مراجعتها الآن</Link>
            </div>
          )}
        </>
      )}
    </>
  );
}
