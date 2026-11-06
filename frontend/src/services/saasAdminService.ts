import axios from 'axios';

const client=axios.create({baseURL:'/api/v1/saas/platform',timeout:30000,headers:{Accept:'application/json','Content-Type':'application/json'}});
client.interceptors.request.use(config=>{const token=sessionStorage.getItem('saas_platform_token');if(token)config.headers.Authorization=`Bearer ${token}`;return config;});
client.interceptors.response.use(response=>response,error=>{if(error?.response?.status===401){sessionStorage.removeItem('saas_platform_token');}return Promise.reject(error);});

export interface PlatformPlan{id:string;code:string;name:string;amount:string;currency:string;billing_interval:string;trial_days:number;grace_days:number;is_active:boolean}
export interface PlatformTenant{id:string;slug:string;name:string;status:string;is_primary:boolean;subscription_exempt:boolean;memberships_count:number;owner?:{name:string;email:string}|null;subscription?:{status:string;trial_ends_at?:string|null;current_period_ends_at?:string|null;plan?:PlatformPlan|null}|null}
export interface PlatformOverview{summary:{tenants:number;active:number;trialing:number;suspended:number};tenants:PlatformTenant[];plans:PlatformPlan[];recent_payments:Array<{id:string;tenant?:{name:string}|null;amount:string;status:string;provider_reference?:string|null;created_at:string}>}
export const errorMessage=(error:unknown):string=>axios.isAxiosError(error)?String(error.response?.data?.message||error.message):'Unexpected platform error.';
export default{
  async login(email:string,password:string){const response=await client.post('/auth/login',{email,password});sessionStorage.setItem('saas_platform_token',response.data.access_token);return response.data;},
  async me(){return (await client.get('/auth/me')).data;},
  async overview():Promise<PlatformOverview>{return (await client.get('/overview')).data.data;},
  async previewTenant(payload:Record<string,string>){return (await client.post('/tenants/preview',payload)).data.data;},
  async createTenant(payload:Record<string,string>){return (await client.post('/tenants',payload)).data;},
  async status(id:string,status:string,confirmation:string){return (await client.patch(`/tenants/${id}/status`,{status,confirmation})).data;},
  async updatePlan(id:string,payload:Record<string,string|number|boolean>){return (await client.patch(`/plans/${id}`,payload)).data;},
  logout(){sessionStorage.removeItem('saas_platform_token');},
};
