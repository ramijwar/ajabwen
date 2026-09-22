export interface User { id: number; phone: string; full_name: string; birth_date: string | null; avatar: string | null; role: 'user' | 'admin' }
export interface Category { id: number; name: string; slug: string; icon: string; color: string; layout: 'cards' | 'list'; supports_duty: number; kind: 'general' | 'pharmacy' | 'doctor' | 'station' | 'transport'; sort_order: number }
export interface Area { id: number; name: string; scope: 'city' | 'rural' }
export interface Shift { day: number; start: string; end: string }
export interface ServiceInput { name: string; category_id: number; area_id: number; address: string; phone: string; whatsapp: string; description: string; details: Record<string,string>; schedule: Shift[]; owner_id?: number | null }
export interface Service extends ServiceInput { id: number; category_name: string; category_slug: string; area_name: string; icon: string; color: string; kind: Category['kind']; supports_duty: number; approval: 'pending' | 'approved' | 'rejected'; rejection_reason?: string; status: 'open' | 'closed' | 'unknown'; status_source: 'owner' | 'schedule' | 'unverified'; on_duty: boolean; updated_at: string; override_status: 'open' | 'closed' | null; override_until: string | null; duty_until: string | null; source_url: string; source_checked_at: string | null }
export interface Meta { categories: Category[]; areas: Area[] }
export const days = ['الأحد','الإثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
export const statusLabels = { open: 'تعمل الآن', closed: 'مغلقة الآن', unknown: 'غير مؤكد' };
export const approvalLabels = { pending: 'بانتظار الموافقة', approved: 'معتمدة', rejected: 'مرفوضة' };
export const detailLabels: Record<string,string> = {specialty:'الاختصاص',gasoline:'بنزين',diesel:'مازوت',electric:'شحن كهربائي',vehicle:'وسيلة النقل',stops:'المواقف بالترتيب',fare:'الأجرة',frequency:'الفاصل بين الرحلات'};
