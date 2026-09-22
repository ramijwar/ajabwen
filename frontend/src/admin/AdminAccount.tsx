import { FormEvent, useState } from 'react';
import { api } from '../api/client';
import { useAuth } from './AuthContext';

export default function AdminAccount() {
  const { admin, setAdmin } = useAuth();
  const [username, setUsername] = useState(admin?.username ?? '');
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState(false);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setError('');
    setSuccess(false);
    try {
      const res = await api.post<{ success: boolean; username: string }>('/admin/account.php', {
        username, current_password: currentPassword, new_password: newPassword,
      }, true);
      setAdmin({ id: admin?.id ?? 0, username: res.username });
      setCurrentPassword('');
      setNewPassword('');
      setSuccess(true);
    } catch (err: any) {
      setError(err.message || 'حدث خطأ أثناء الحفظ.');
    }
  };

  return (
    <>
      <div className="admin-topbar"><h1>🔑 إعدادات الحساب</h1></div>

      {success && <div className="alert success">تم تحديث بيانات الحساب بنجاح.</div>}
      {error && <div className="alert error">{error}</div>}

      <div className="form-card" style={{ maxWidth: 480, margin: 0 }}>
        <form onSubmit={submit}>
          <div className="form-group">
            <label>اسم المستخدم</label>
            <input className="field" value={username} onChange={(e) => setUsername(e.target.value)} required />
          </div>
          <div className="form-group">
            <label>كلمة المرور الحالية *</label>
            <input className="field" type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} required />
          </div>
          <div className="form-group">
            <label>كلمة مرور جديدة (اتركها فارغة لعدم التغيير)</label>
            <input className="field" type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} />
          </div>
          <button className="btn" type="submit" style={{ width: '100%' }}>حفظ</button>
        </form>
      </div>
    </>
  );
}
