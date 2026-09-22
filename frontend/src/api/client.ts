const BASE_URL = (import.meta.env.VITE_API_URL || '/storeact/backend/api').replace(/\/$/, '');

function getToken(): string | null {
  return localStorage.getItem('admin_token');
}

export function setToken(token: string | null) {
  if (token) localStorage.setItem('admin_token', token);
  else localStorage.removeItem('admin_token');
}

async function request<T>(path: string, options: RequestInit = {}, auth = false): Promise<T> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  if (auth) {
    const token = getToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;
  }
  const res = await fetch(`${BASE_URL}${path}`, { ...options, headers: { ...headers, ...(options.headers as any) } });
  let data: any = null;
  try {
    data = await res.json();
  } catch {
    // استجابة بدون محتوى
  }
  if (!res.ok) {
    const error = new Error((data && data.error) || 'حدث خطأ غير متوقع') as Error & { status?: number };
    error.status = res.status;
    throw error;
  }
  return data as T;
}

export const api = {
  get: <T>(path: string, auth = false) => request<T>(path, { method: 'GET' }, auth),
  post: <T>(path: string, body: unknown, auth = false) =>
    request<T>(path, { method: 'POST', body: JSON.stringify(body) }, auth),
};
