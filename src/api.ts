import { useCallback, useEffect, useState } from 'react';
let csrfToken = '';
export async function api<T>(path: string, method = 'GET', data?: unknown): Promise<T> {
 const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 20000);
 try {
  const form = data instanceof FormData;
  const response = await fetch(`/api${path}`, { method, credentials: 'same-origin', signal: controller.signal, headers: { ...(data && !form ? {'Content-Type':'application/json'} : {}), ...(method !== 'GET' ? {'X-CSRF-Token': csrfToken} : {}) }, body: data ? (form ? data : JSON.stringify(data)) : undefined });
  const result = await response.json().catch(() => { throw new Error('استجابة غير صالحة من الخادم؛ تحقق من إعداد PHP API'); });
  if (!response.ok) throw new Error(result.error || 'تعذر إتمام العملية');
  if (typeof result.csrf === 'string') csrfToken = result.csrf;
  return result as T;
 } catch (error) { if (error instanceof DOMException && error.name === 'AbortError') throw new Error('انتهت مهلة الاتصال بالخادم'); throw error; } finally { clearTimeout(timeout); }
}
export function errorText(error: unknown) { return error instanceof Error ? error.message : 'حدث خطأ غير متوقع'; }
export function useResource<T>(path: string, poll = false) {
 const [data,setData]=useState<T|null>(null); const [error,setError]=useState(''); const [loading,setLoading]=useState(true); const [version,setVersion]=useState(0);
 const reload=useCallback(()=>setVersion(v=>v+1),[]);
 useEffect(()=> { let active=true; setLoading(true); setError(''); setData(null);
  const load=async()=>{try{const next=await api<T>(path);if(active){setData(next);setError('');}}catch(e){if(active)setError(errorText(e));}finally{if(active)setLoading(false);}};
  void load(); const timer=poll?setInterval(()=>{if(document.visibilityState==='visible')void load();},15000):undefined;
  return()=>{active=false;if(timer)clearInterval(timer);};
 },[path,version,poll]);
 return {data,error,loading,reload};
}
