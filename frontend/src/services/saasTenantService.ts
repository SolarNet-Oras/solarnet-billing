import axios from 'axios';

const api=axios.create({baseURL:'/api/v1/saas',timeout:30000,headers:{Accept:'application/json','Content-Type':'application/json'}});
export const SAAS_TENANT_TOKEN_KEY='saas_tenant_token';
export type TenantPlan={id:string;code:string;name:string;description?:string;amount:string;trial_days:number;grace_days:number};
export type TenantSubscriptionSummary={tenant:{id:string;name:string;slug:string};administrator:{role:string;is_owner:boolean};subscription:{id:string;status:string;payment_status?:string|null;trial_starts_at?:string|null;trial_ends_at?:string|null;current_period_ends_at?:string|null;next_payment_due_at?:string|null};plan:TenantPlan&{currency:string;billing_interval:string};trial_days_remaining:number;can_pay:boolean;payments:Array<{id:string;amount:string;currency:string;status:string;provider_reference?:string|null;verified_at?:string|null;created_at:string}>};
export const saasError=(error:unknown)=>axios.isAxiosError(error)?String(error.response?.data?.message||Object.values(error.response?.data?.errors||{}).flat()[0]||error.message):'Unexpected request error.';
export default{
  async plans():Promise<TenantPlan[]>{return (await api.get('/applications/plans')).data.data;},
  async register(payload:Record<string,unknown>){return (await api.post('/applications',payload)).data;},
  async verify(token:string){return (await api.post('/applications/verify',{token})).data;},
  async login(tenant:string,email:string,password:string){const response=await api.post('/auth/login',{tenant,email,password});sessionStorage.setItem(SAAS_TENANT_TOKEN_KEY,response.data.access_token);return response.data;},
  async forgotPassword(tenant:string,email:string){return (await api.post('/auth/forgot-password',{tenant,email})).data;},
  async resetPassword(payload:Record<string,unknown>){return (await api.post('/auth/reset-password',payload)).data;},
  async requestStaffAccess(payload:Record<string,unknown>){return (await api.post('/auth/staff-access-request',payload)).data;},
  async staffRequests(){const token=sessionStorage.getItem(SAAS_TENANT_TOKEN_KEY);return (await api.get('/tenant/staff-access-requests',{headers:{Authorization:`Bearer ${token}`}})).data.data;},
  async reviewStaffRequest(id:string,decision:'approve'|'reject'){const token=sessionStorage.getItem(SAAS_TENANT_TOKEN_KEY);return (await api.post(`/tenant/staff-access-requests/${id}/review`,{decision},{headers:{Authorization:`Bearer ${token}`}})).data;},
  async subscription():Promise<TenantSubscriptionSummary>{const token=sessionStorage.getItem(SAAS_TENANT_TOKEN_KEY);return (await api.get('/tenant/subscription',{headers:{Authorization:`Bearer ${token}`}})).data.data;},
  async startSubscriptionCheckout():Promise<{checkout_url:string;reference:string}>{const token=sessionStorage.getItem(SAAS_TENANT_TOKEN_KEY);return (await api.post('/tenant/subscription/checkout',{}, {headers:{Authorization:`Bearer ${token}`}})).data.data;},
  async startSubscriptionQrPh():Promise<{payment_id:string;reference:string;amount:number;currency:string;qr_image_url:string;expires_at:string}>{const token=sessionStorage.getItem(SAAS_TENANT_TOKEN_KEY);return (await api.post('/tenant/subscription/qrph',{}, {headers:{Authorization:`Bearer ${token}`}})).data.data;},
  async me(){const token=sessionStorage.getItem(SAAS_TENANT_TOKEN_KEY);return (await api.get('/auth/me',{headers:{Authorization:`Bearer ${token}`}})).data;},
  logout(){sessionStorage.removeItem(SAAS_TENANT_TOKEN_KEY);},
};
