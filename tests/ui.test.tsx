import { useState } from 'react';
import { describe,it,expect,afterEach } from 'vitest';
import { render,screen,fireEvent,cleanup } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';
import { MemoryRouter } from 'react-router-dom';
import ScheduleEditor from '../src/components/ScheduleEditor';
import ServiceCard from '../src/components/ServiceCard';
import { Service,Shift } from '../src/types';
afterEach(cleanup);
function Editor(){const [rows,setRows]=useState<Shift[]>([]);return <><ScheduleEditor value={rows} onChange={setRows}/><output data-testid="rows">{JSON.stringify(rows)}</output></>;}
describe('weekly schedule',()=>{it('adds selected days and removes a shift',()=>{render(<Editor/>);fireEvent.click(screen.getByRole('button',{name:'الإثنين',exact:true}));fireEvent.click(screen.getByRole('button',{name:'الأربعاء',exact:true}));fireEvent.click(screen.getByRole('button',{name:'إضافة فترة الدوام'}));expect(JSON.parse(screen.getByTestId('rows').textContent||'[]')).toHaveLength(2);fireEvent.click(screen.getByRole('button',{name:'حذف فترة الإثنين 09:00'}));expect(JSON.parse(screen.getByTestId('rows').textContent||'[]')).toEqual([{day:3,start:'09:00',end:'17:00'}]);});it('requires a day',()=>{render(<Editor/>);fireEvent.click(screen.getByRole('button',{name:'إضافة فترة الدوام'}));expect(screen.getByRole('alert')).toHaveTextContent('اختر الأيام');});});
const fixture:Service={id:1,name:'خدمة اختبار غير حقيقية',category_id:1,area_id:1,address:'عنوان اختبار',phone:'+963900000001',whatsapp:'',description:'',details:{},schedule:[],category_name:'صيدليات',category_slug:'pharmacies',area_name:'منطقة اختبار',icon:'pill',color:'#0f766a',kind:'pharmacy',supports_duty:1,approval:'approved',status:'unknown',status_source:'unverified',on_duty:false,updated_at:'2026-09-22T00:00:00Z',override_status:null,override_until:null,duty_until:null,source_url:'',source_checked_at:null};
describe('service card',()=>{it('shows unknown availability honestly and links to telephone',()=>{render(<MemoryRouter><ServiceCard service={fixture}/></MemoryRouter>);expect(screen.getByText('غير مؤكد')).toBeInTheDocument();expect(screen.getByRole('link',{name:'اتصل بـ خدمة اختبار غير حقيقية'})).toHaveAttribute('href','tel:+963900000001');expect(screen.queryByText('خريطة')).not.toBeInTheDocument();});it('does not generate a telephone link for missing data',()=>{render(<MemoryRouter><ServiceCard service={{...fixture,phone:''}}/></MemoryRouter>);expect(screen.queryByRole('link',{name:/اتصل/})).not.toBeInTheDocument();});});
