export type AreaType = 'قرية' | 'بلدة' | 'مدينة';

export interface Area {
  id: number;
  name: string;
  type: AreaType;
  usage_count?: number;
}

export interface Category {
  id: number;
  slug: string;
  name: string;
  icon: string;
  description: string;
  count: number;
}

export interface ServiceItem {
  id: number;
  category_id: number;
  name: string;
  neighborhood: string;
  area_id: number | null;
  area_name: string | null;
  area_type: AreaType | null;
  phone: string | null;
  is_open: boolean;
  on_duty: boolean;
  specialty: string | null;
  fuel_types: string | null;
  route: string | null;
  notes: string | null;
  created_at: string;
}

export interface ServiceRequest {
  id: number;
  category_slug: string;
  category_name: string;
  category_icon: string;
  name: string;
  neighborhood: string;
  area_name: string | null;
  area_type: AreaType | null;
  phone: string | null;
  specialty: string | null;
  fuel_types: string | null;
  route: string | null;
  notes: string | null;
  status: 'pending' | 'approved' | 'rejected';
  created_at: string;
}

export interface DashboardStats {
  total_items: number;
  pending_requests: number;
  areas_count: number;
  by_category: { slug: string; name: string; icon: string; count: number }[];
}
