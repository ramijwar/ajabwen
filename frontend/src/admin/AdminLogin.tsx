import { FormEvent, useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { useAuth } from './AuthContext';

export default function AdminLogin() {
  const { admin, login } = useAuth();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const navigate = useNavigate();

  if (admin) return <Navigate to="/admin" replace />;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError('');
    setSubmitting(true);
    try {
      await login(username, password);
      navigate('/admin');
    } catch (err: any) {
      setError(err.message || 'فشل تسجيل الدخول');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="login-wrap">
      <div className="form-card" style={{ width: '100%', maxWidth: 380 }}>
        <h2 style={{ textAlign: 'center', color: 'var(--brand-800)', marginTop: 0 }}>🔒 دخول لوحة التحكم</h2>
        {error && <div className="alert error">{error}</div>}
        <form onSubmit={submit}>
          <div className="form-group">
            <label>اسم المستخدم</label>
            <input className="field" value={username} onChange={(e) => setUsername(e.target.value)} required autoFocus />
          </div>
          <div className="form-group">
            <label>كلمة المرور</label>
            <input className="field" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
          </div>
          <button className="btn" type="submit" style={{ width: '100%' }} disabled={submitting}>
            {submitting ? 'جارٍ الدخول...' : 'دخول'}
          </button>
        </form>
        <p style={{ fontSize: '.78rem', color: 'var(--muted)', textAlign: 'center', marginTop: 14 }}>
          الحساب الافتراضي: admin / admin123<br />يرجى تغييره فوراً بعد الدخول.
        </p>
      </div>
    </div>
  );
}
