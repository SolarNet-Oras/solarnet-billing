import React, { useEffect, useState } from 'react';
import { ArrowLeft, ExternalLink, Info, Mail, MapPinned, MessageCircle, ShieldCheck, Wifi } from 'lucide-react';
import { Link, useNavigate } from 'react-router-dom';
import customerPortalService from '@/services/customerPortalService';

export default function CustomerAboutPage(): React.JSX.Element {
  const navigate = useNavigate();
  const [branding, setBranding] = useState({ name: 'SolarNet Internet', logo_url: '' });

  useEffect(() => {
    if (!localStorage.getItem('customer_token')) {
      navigate('/customer/login', { replace: true });
      return;
    }
    void customerPortalService.getBranding().then(setBranding).catch(() => undefined);
  }, [navigate]);

  return (
    <main className="min-h-screen bg-gradient-to-b from-sky-50 via-white to-blue-50 px-4 py-8 text-slate-900 sm:px-6">
      <div className="mx-auto max-w-5xl">
        <Link to="/customer/dashboard" className="inline-flex items-center gap-2 text-sm font-bold text-blue-700 hover:text-blue-900">
          <ArrowLeft className="h-4 w-4" /> Back to dashboard
        </Link>

        <section className="mt-5 overflow-hidden rounded-3xl border border-blue-100 bg-white shadow-xl shadow-blue-950/5">
          <header className="bg-gradient-to-br from-blue-950 via-blue-800 to-cyan-700 px-6 py-10 text-white sm:px-10">
            <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
              <img src={branding.logo_url || '/solarnet-mark.svg'} alt={`${branding.name} logo`} className="h-24 w-24 rounded-3xl border border-white/25 bg-white/10 object-contain p-2 shadow-lg" />
              <div><p className="text-xs font-bold uppercase tracking-[0.22em] text-cyan-200">About us</p><h1 className="mt-2 text-3xl font-black sm:text-4xl">{branding.name}</h1><p className="mt-3 max-w-2xl leading-7 text-blue-100">Connecting customers through reliable internet service, convenient digital billing, secure online payment options, and responsive local support.</p></div>
            </div>
          </header>

          <div className="grid gap-5 p-6 sm:grid-cols-3 sm:p-10">
            <Value icon={<Wifi />} title="Internet service" detail="Customer connectivity supported by SolarNet's field and network operations team." />
            <Value icon={<ShieldCheck />} title="Secure account access" detail="Your invoices, payments, service details, and support activity stay linked to your authenticated customer account." />
            <Value icon={<MessageCircle />} title="Customer care" detail="Contact SolarNet when you need billing assistance, a service check, or a technician visit." />
          </div>

          <div className="border-t border-slate-100 p-6 sm:p-10">
            <h2 className="flex items-center gap-2 text-xl font-black"><Info className="h-5 w-5 text-blue-600" /> Contact and customer resources</h2>
            <div className="mt-5 grid gap-3 sm:grid-cols-2">
              <a href="mailto:solarnet.connection@gmail.com" className="flex items-center gap-3 rounded-2xl border border-slate-200 p-4 font-semibold hover:border-blue-300 hover:bg-blue-50"><Mail className="h-5 w-5 text-blue-600" /><span>Email customer support<small className="block break-all font-normal text-slate-500">solarnet.connection@gmail.com</small></span></a>
              <a href="https://www.facebook.com/SolarnetConnectionInstallationandServices" target="_blank" rel="noreferrer" className="flex items-center gap-3 rounded-2xl border border-slate-200 p-4 font-semibold hover:border-blue-300 hover:bg-blue-50"><MessageCircle className="h-5 w-5 text-blue-600" /><span>Official Facebook Page<small className="block font-normal text-slate-500">Message SolarNet support</small></span><ExternalLink className="ml-auto h-4 w-4 text-slate-400" /></a>
              <Link to="/service-map" className="flex items-center gap-3 rounded-2xl border border-slate-200 p-4 font-semibold hover:border-blue-300 hover:bg-blue-50"><MapPinned className="h-5 w-5 text-blue-600" /><span>Public service-area viewer<small className="block font-normal text-slate-500">General coverage reference</small></span></Link>
              <Link to="/privacy-policy" className="flex items-center gap-3 rounded-2xl border border-slate-200 p-4 font-semibold hover:border-blue-300 hover:bg-blue-50"><ShieldCheck className="h-5 w-5 text-blue-600" /><span>Privacy Policy<small className="block font-normal text-slate-500">How SolarNet protects customer information</small></span></Link>
            </div>
          </div>
        </section>
      </div>
    </main>
  );
}

function Value({ icon, title, detail }: { icon: React.ReactNode; title: string; detail: string }): React.JSX.Element {
  return <article className="rounded-2xl border border-blue-100 bg-blue-50/50 p-5"><div className="grid h-11 w-11 place-items-center rounded-xl bg-blue-600 text-white">{icon}</div><h2 className="mt-4 font-black">{title}</h2><p className="mt-2 text-sm leading-6 text-slate-600">{detail}</p></article>;
}
