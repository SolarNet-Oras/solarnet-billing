import { useEffect, useState } from 'react';
import { ArrowRight, CheckCircle2, MessageCircle as Facebook, Gauge, Globe2, Mail, MapPin, Phone, ShieldCheck, Wifi } from 'lucide-react';
import { Link } from 'react-router-dom';
import api from '@/services/api';
import customerPortalService, { type CompanyBranding } from '@/services/customerPortalService';

type PublicPlan = { id: string; name: string; description?: string | null; download_speed: number; upload_speed: number; price: number };
const peso = (amount: number): string => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 0 }).format(amount);

export default function PublicHomePage(): React.JSX.Element {
  const [branding, setBranding] = useState<CompanyBranding>({ name: 'Solarnet Internet', logo_url: '/solarnet-company-logo-192.png?v=20261015-official', email: 'solarnet.connection@gmail.com', phone: '09627981988', address: 'Oras, Eastern Samar, Philippines', website: 'https://solarnetconnection.com', facebook_url: '' });
  const [plans, setPlans] = useState<PublicPlan[]>([]);

  useEffect(() => {
    void Promise.all([
      customerPortalService.getBranding().then(setBranding),
      api.get('/customer-portal/service-plans').then(response => setPlans(response.data.data || [])),
    ]).catch(() => undefined);
  }, []);

  useEffect(() => {
    document.documentElement.classList.add('public-site-active');
    return () => document.documentElement.classList.remove('public-site-active');
  }, []);

  useEffect(() => {
    const contactLink = document.querySelector<HTMLAnchorElement>('nav a[href="#contact"]');
    if (!contactLink) return;

    const aboutLink = document.createElement('a');
    aboutLink.href = '#about';
    aboutLink.textContent = 'About us';
    aboutLink.className = 'hover:text-white';
    aboutLink.dataset.aboutUs = 'true';

    const otherServicesLink = document.createElement('a');
    otherServicesLink.href = 'https://solarnetconnection.com';
    otherServicesLink.target = '_blank';
    otherServicesLink.rel = 'noopener noreferrer';
    otherServicesLink.textContent = 'Other services';
    otherServicesLink.className = 'hover:text-white';
    otherServicesLink.dataset.otherServices = 'true';

    contactLink.insertAdjacentElement('afterend', aboutLink);
    aboutLink.insertAdjacentElement('afterend', otherServicesLink);
    return () => {
      aboutLink.remove();
      otherServicesLink.remove();
    };
  }, []);

  useEffect(() => {
    const serviceArea = Array.from(document.querySelectorAll<HTMLParagraphElement>('main p'))
      .find(element => element.textContent === 'Serving Oras, Eastern Samar');
    if (serviceArea) serviceArea.textContent = 'Serving Eastern Samar';

    const introduction = Array.from(document.querySelectorAll<HTMLParagraphElement>('main p'))
      .find(element => element.textContent?.includes('secure online billing with PayMongo'));
    if (introduction) {
      introduction.textContent = 'SolarNet provides high-speed internet connections, network installation, technical support, and secure online payments via QRPh, GCash, and participating banks, powered by PayMongo.';
    }

    const footerDescription = Array.from(document.querySelectorAll<HTMLParagraphElement>('footer p'))
      .find(element => element.textContent?.includes('Public service information for PayMongo compliance'));
    if (footerDescription) {
      footerDescription.textContent = 'Private account data is available only through verified customer access.';
    }

    const companyWebsite = Array.from(document.querySelectorAll<HTMLAnchorElement>('footer a'))
      .find(element => element.textContent?.trim() === 'solarnetconnection.com');
    if (companyWebsite) companyWebsite.href = 'https://solarnetconnection.com';

    const servicesSection = document.querySelector<HTMLElement>('#services');
    if (servicesSection && !document.querySelector('#about')) {
      const aboutSection = document.createElement('section');
      aboutSection.id = 'about';
      aboutSection.className = 'bg-slate-950 py-16 text-white';
      aboutSection.innerHTML = `
        <div class="mx-auto max-w-7xl px-4 sm:px-6">
          <div class="grid gap-8 lg:grid-cols-[1.1fr_.9fr]">
            <div>
              <p class="text-xs font-bold uppercase tracking-[.2em] text-cyan-300">About us</p>
              <h2 class="mt-3 text-3xl font-black">SolarNet Internet and technical services</h2>
              <p class="mt-4 max-w-2xl leading-7 text-slate-300">SolarNet delivers reliable internet connectivity, network installation, CCTV solutions, solar installation, technical support, and secure customer billing services.</p>
              <div class="mt-6 grid gap-3 text-sm sm:grid-cols-3">
                <div class="rounded-xl border border-white/10 bg-white/5 p-4"><strong class="block text-cyan-300">NTC Permit</strong><span class="mt-1 block text-slate-200">RB-VAS-2025-1268/NEW</span></div>
                <div class="rounded-xl border border-white/10 bg-white/5 p-4"><strong class="block text-cyan-300">DTI Registration</strong><span class="mt-1 block text-slate-200">7111679</span></div>
                <div class="rounded-xl border border-white/10 bg-white/5 p-4"><strong class="block text-cyan-300">TIN</strong><span class="mt-1 block text-slate-200">700-707-761</span></div>
              </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
              <article class="rounded-2xl border border-cyan-400/20 bg-cyan-400/10 p-5"><h3 class="text-lg font-bold text-cyan-200">CCTV Installation</h3><p class="mt-2 text-sm leading-6 text-slate-300">Camera planning, installation, configuration, monitoring setup, and technical support for homes and businesses.</p></article>
              <article class="rounded-2xl border border-amber-300/20 bg-amber-300/10 p-5"><h3 class="text-lg font-bold text-amber-200">Solar Installation</h3><p class="mt-2 text-sm leading-6 text-slate-300">Solar power system assessment, equipment installation, configuration, and after-installation support.</p></article>
            </div>
          </div>
        </div>`;
      servicesSection.insertAdjacentElement('afterend', aboutSection);
    }

    const plansSection = document.querySelector<HTMLElement>('#plans');
    const plansContainer = plansSection?.firstElementChild;
    if (plansContainer && !plansContainer.querySelector('[data-realtime-payment-notice]')) {
      const paymentNotice = document.createElement('aside');
      paymentNotice.className = 'mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-950 shadow-sm';
      paymentNotice.dataset.realtimePaymentNotice = 'true';
      paymentNotice.innerHTML = '<p class="font-bold">Real-time online payment posting</p><p class="mt-1 text-sm leading-6">Verified QRPh, GCash, and participating bank payments are posted to your SolarNet account in real time. If service was suspended only because of unpaid billing, restoration starts automatically after full payment is confirmed and no open billing remains.</p>';
      plansContainer.firstElementChild?.insertAdjacentElement('afterend', paymentNotice);
    }

    document.querySelectorAll('main li').forEach(element => {
      if (element.textContent?.includes('Secure PayMongo online payments')) {
        const icon = element.firstElementChild;
        element.replaceChildren(...(icon ? [icon] : []), 'Secure payments via QRPh, GCash, and banks — powered by PayMongo');
      }
    });

    document.querySelectorAll<HTMLElement>('#plans article').forEach((card, index) => {
      const plan = plans[index];
      if (!plan) return;

      const openSignup = (): void => {
        window.location.assign(`/signup?plan=${encodeURIComponent(plan.id)}`);
      };

      card.tabIndex = 0;
      card.setAttribute('role', 'link');
      card.setAttribute('aria-label', `Apply for the ${plan.name} plan`);
      card.classList.add('cursor-pointer', 'transition', 'hover:-translate-y-1', 'hover:border-sky-300', 'hover:shadow-lg', 'focus:outline-none', 'focus:ring-2', 'focus:ring-sky-500');
      card.onclick = openSignup;
      card.onkeydown = (event): void => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openSignup();
        }
      };

      if (!card.querySelector('[data-plan-cta]')) {
        const callToAction = document.createElement('span');
        callToAction.dataset.planCta = 'true';
        callToAction.className = 'mt-6 inline-flex font-bold text-sky-700';
        callToAction.textContent = 'Choose this plan →';
        card.append(callToAction);
      }
    });
  }, [plans, branding.website]);

  return <main className="min-h-screen bg-slate-950 text-white"><nav className="sticky top-0 z-30 border-b border-white/10 bg-slate-950/90 backdrop-blur"><div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6"><a href="#top" className="flex items-center gap-3"><img src={branding.logo_url || '/solarnet-mark.svg'} alt="" className="h-10 w-10 rounded-xl object-contain"/><div><p className="font-bold leading-tight">{branding.name}</p><p className="text-[10px] uppercase tracking-[.18em] text-cyan-300">Internet & network solutions</p></div></a><div className="hidden items-center gap-6 text-sm text-slate-300 md:flex"><a href="#services" className="hover:text-white">Services</a><a href="#plans" className="hover:text-white">Plans & prices</a><a href="#contact" className="hover:text-white">Contact</a></div><Link to="/customer/login" className="inline-flex items-center gap-2 rounded-xl bg-cyan-400 px-3 py-2 text-sm font-bold text-slate-950 hover:bg-cyan-300"><ShieldCheck className="h-4 w-4"/><span className="hidden sm:inline">Find my account</span><span className="sm:hidden">Account</span></Link></div></nav><section id="top" className="relative overflow-hidden"><div className="absolute inset-0 bg-[radial-gradient(circle_at_20%_10%,rgba(34,211,238,.24),transparent_34%),radial-gradient(circle_at_85%_60%,rgba(59,130,246,.2),transparent_35%)]"/><div className="relative mx-auto grid max-w-7xl gap-10 px-4 py-20 sm:px-6 lg:grid-cols-[1.2fr_.8fr] lg:py-28"><div><p className="text-sm font-bold uppercase tracking-[.2em] text-cyan-300">Serving Oras, Eastern Samar</p><h1 className="mt-5 max-w-4xl text-4xl font-black leading-tight sm:text-6xl">Reliable internet for homes, businesses, and communities.</h1><p className="mt-6 max-w-2xl text-lg leading-8 text-slate-300">SolarNet provides high-speed internet connections, network installation, technical support, and secure online billing with PayMongo.</p><div className="mt-8 flex flex-wrap gap-3"><a href="#plans" className="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 font-bold text-slate-950">View plans and prices<ArrowRight className="h-4 w-4"/></a><Link to="/signup" className="rounded-xl border border-cyan-300/50 px-5 py-3 font-bold text-cyan-200 hover:bg-cyan-300/10">Apply for connection</Link></div></div><aside className="rounded-3xl border border-white/10 bg-white/5 p-6 backdrop-blur"><Wifi className="h-10 w-10 text-cyan-300"/><h2 className="mt-5 text-2xl font-bold">Already a customer?</h2><p className="mt-2 text-sm leading-6 text-slate-300">Open only your own account using your registered email or full name together with your SolarNet account number. No portal password is required.</p><Link to="/customer/login" className="mt-6 flex items-center justify-between rounded-2xl bg-cyan-400 p-4 font-bold text-slate-950">Search my specific account<ArrowRight className="h-5 w-5"/></Link><p className="mt-3 text-xs leading-5 text-slate-400">Billing statements and personal information remain private until both account details match.</p></aside></div></section><section id="services" className="bg-white py-16 text-slate-950"><div className="mx-auto max-w-7xl px-4 sm:px-6"><div className="max-w-2xl"><p className="text-xs font-bold uppercase tracking-[.2em] text-sky-700">What we offer</p><h2 className="mt-3 text-3xl font-black">Internet and network services</h2></div><div className="mt-8 grid gap-4 md:grid-cols-3"><Service icon={Wifi} title="Fiber & wireless internet" text="Monthly residential and business internet plans with speeds and prices shown publicly below."/><Service icon={Gauge} title="Installation & activation" text="Site assessment, client installation, ONU/router setup, and activation by SolarNet technicians."/><Service icon={Globe2} title="Technical support" text="Account support, connection troubleshooting, service requests, and billing assistance."/></div></div></section><section id="plans" className="bg-slate-100 py-16 text-slate-950"><div className="mx-auto max-w-7xl px-4 sm:px-6"><div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"><div><p className="text-xs font-bold uppercase tracking-[.2em] text-sky-700">Transparent pricing</p><h2 className="mt-3 text-3xl font-black">Internet plans</h2><p className="mt-2 text-slate-600">Current active plans offered by SolarNet. Monthly prices are VAT-inclusive where applicable.</p></div><Link to="/signup" className="font-bold text-sky-700 hover:underline">Apply for a plan →</Link></div><div className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">{plans.map(plan => <article key={plan.id} className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm"><div className="flex items-start justify-between gap-3"><h3 className="text-xl font-bold">{plan.name}</h3><Wifi className="h-6 w-6 text-sky-600"/></div><p className="mt-5 text-4xl font-black text-sky-700">{peso(Number(plan.price))}<span className="text-sm font-medium text-slate-500">/month</span></p><p className="mt-4 flex items-center gap-2 font-semibold"><Gauge className="h-4 w-4 text-emerald-600"/>{plan.download_speed} Mbps download · {plan.upload_speed} Mbps upload</p>{plan.description && <p className="mt-3 text-sm leading-6 text-slate-600">{plan.description}</p>}<ul className="mt-5 space-y-2 text-sm text-slate-700"><li className="flex gap-2"><CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600"/>SolarNet installation and support</li><li className="flex gap-2"><CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600"/>Secure PayMongo online payments</li></ul></article>)}{plans.length === 0 && <p className="rounded-2xl border border-slate-200 bg-white p-6 text-slate-600">Plan information is temporarily unavailable. Please contact SolarNet for current prices.</p>}</div></div></section><footer id="contact" className="border-t border-white/10 bg-slate-950"><div className="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-2"><div><div className="flex items-center gap-3"><img src={branding.logo_url || '/solarnet-mark.svg'} alt="" className="h-12 w-12 object-contain"/><div><h2 className="text-xl font-bold">{branding.name}</h2><p className="text-sm text-slate-400">High-Speed Internet & Network Solutions</p></div></div><p className="mt-5 max-w-lg text-sm leading-6 text-slate-400">Public service information for PayMongo compliance and customer review. Private account data is available only through verified customer access.</p></div><div className="space-y-3 text-sm text-slate-300"><Contact icon={MapPin} text={branding.address || 'Oras, Eastern Samar, Philippines'}/><Contact icon={Phone} text={branding.phone || '09627981988'} href={`tel:${branding.phone || '09627981988'}`}/><Contact icon={Mail} text={branding.email || 'solarnet.connection@gmail.com'} href={`mailto:${branding.email || 'solarnet.connection@gmail.com'}`}/>{branding.website && <Contact icon={Globe2} text={branding.website.replace(/^https?:\/\//, '')} href={branding.website}/>} {branding.facebook_url && <Contact icon={Facebook} text="SolarNet on Facebook" href={branding.facebook_url}/>}</div></div><div className="border-t border-white/10 px-4 py-5 text-center text-xs text-slate-500">© {new Date().getFullYear()} {branding.name}. Services are subject to availability, installation assessment, and applicable terms.</div></footer></main>;
}

function Service({ icon: Icon, title, text }: { icon: typeof Wifi; title: string; text: string }): React.JSX.Element { return <article className="rounded-2xl border border-slate-200 p-6"><Icon className="h-8 w-8 text-sky-600"/><h3 className="mt-4 text-lg font-bold">{title}</h3><p className="mt-2 text-sm leading-6 text-slate-600">{text}</p></article>; }
function Contact({ icon: Icon, text, href }: { icon: typeof Phone; text: string; href?: string }): React.JSX.Element { const content = <><Icon className="h-4 w-4 shrink-0 text-cyan-300"/><span>{text}</span></>; return href ? <a href={href} target={href.startsWith('http') ? '_blank' : undefined} rel={href.startsWith('http') ? 'noreferrer' : undefined} className="flex items-center gap-3 hover:text-white">{content}</a> : <p className="flex items-center gap-3">{content}</p>; }
