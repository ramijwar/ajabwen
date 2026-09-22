import { createContext, ReactNode, useContext, useEffect, useState } from 'react';
import { api, setToken } from '../api/client';

interface AdminUser { id: number; username: string }

interface AuthContextValue {
  admin: AdminUser | null;
  loading: boolean;
  login: (username: string, password: string) => Promise<void>;
  logout: () => void;
  setAdmin: (a: AdminUser | null) => void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [admin, setAdmin] = useState<AdminUser | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const token = localStorage.getItem('admin_token');
    if (!token) { setLoading(false); return; }
    api.get<AdminUser>('/admin/me.php', true)
      .then(setAdmin)
      .catch(() => { setToken(null); setAdmin(null); })
      .finally(() => setLoading(false));
  }, []);

  const login = async (username: string, password: string) => {
    const res = await api.post<{ token: string; username: string }>('/admin/login.php', { username, password });
    setToken(res.token);
    setAdmin({ id: 0, username: res.username });
  };

  const logout = () => {
    api.post('/admin/logout.php', {}, true).catch(() => {});
    setToken(null);
    setAdmin(null);
  };

  return (
    <AuthContext.Provider value={{ admin, loading, login, logout, setAdmin }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}
