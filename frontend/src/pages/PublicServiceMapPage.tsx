import React from 'react';
import { ExternalLink, LockKeyhole, MapPinned, MessageCircle, ShieldCheck } from 'lucide-react';

const mapUrl = 'https://www.google.com/maps?q=Oras%2C%20Eastern%20Samar%2C%20Philippines&z=12&output=embed';
const directionsUrl = 'https://www.google.com/maps/search/?api=1&query=Oras%2C%20Eastern%20Samar%2C%20Philippines';
const facebookUrl = 'https://www.facebook.com/SolarnetConnectionInstallationandServices';

export default function PublicServiceMapPage(): React.JSX.Element {
  return (
    <main className="min-h-screen bg-gradient-to-br from-slate-950 via-blue-950 to-cyan-950 px-4 py-8 text-white sm:px-6 sm:py-12">
      <div className="mx-auto max-w-6xl">
        <header className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p className="text-xs font-bold uppercase tracking-[0.22em] text-cyan-300">SolarNet Internet</p>
            <h1 className="mt-2 text-3xl font-black tracking-tight sm:text-4xl">Public service-area viewer</h1>
            <p className="mt-3 max-w-2xl text-sm leading-6 text-blue-100 sm:text-base">
              View SolarNet&apos;s general service area around Oras, Eastern Samar. Contact support to confirm availability for an exact installation address.
            </p>
          </div>
          <span className="inline-flex w-fit items-center gap-2 rounded-full border border-emerald-300/30 bg-emerald-400/10 px-3 py-1.5 text-xs font-bold text-emerald-200">
            <ShieldCheck className="h-4 w-4" /> Customer-safe view
          </span>
        </header>

        <section className="overflow-hidden rounded-3xl border border-white/15 bg-white/10 shadow-2xl backdrop-blur">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 px-4 py-3 sm:px-6">
            <div className="flex items-center gap-2">
              <MapPinned className="h-5 w-5 text-cyan-300" />
              <div><h2 className="font-bold">Oras service area</h2><p className="text-xs text-blue-100/75">General geographic reference—not a guarantee of signal or port availability.</p></div>
            </div>
            <a href={directionsUrl} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 rounded-xl border border-white/20 bg-white/10 px-3 py-2 text-xs font-bold hover:bg-white/20">
              Open full map <ExternalLink className="h-3.5 w-3.5" />
            </a>
          </div>
          <iframe title="SolarNet general service area in Oras, Eastern Samar" src={mapUrl} loading="lazy" referrerPolicy="no-referrer-when-downgrade" className="h-[58vh] min-h-[420px] w-full border-0 bg-slate-200" />
        </section>

        <section className="mt-5 grid gap-4 md:grid-cols-2">
          <article className="rounded-2xl border border-white/15 bg-white/10 p-5 backdrop-blur">
            <div className="flex items-start gap-3"><LockKeyhole className="mt-0.5 h-5 w-5 shrink-0 text-cyan-300" /><div><h2 className="font-bold">Privacy-protected public map</h2><p className="mt-2 text-sm leading-6 text-blue-100/80">Customer homes, employee locations, routers, IP addresses, NAPs, poles, and fiber routes are intentionally excluded from this public page.</p></div></div>
          </article>
          <article className="rounded-2xl border border-white/15 bg-white/10 p-5 backdrop-blur">
            <div className="flex items-start gap-3"><MessageCircle className="mt-0.5 h-5 w-5 shrink-0 text-yellow-300" /><div><h2 className="font-bold">Check installation availability</h2><p className="mt-2 text-sm leading-6 text-blue-100/80">Send your name, phone number, and complete address to SolarNet Customer Support for a verified coverage check.</p><a href={facebookUrl} target="_blank" rel="noreferrer" className="mt-3 inline-flex items-center gap-1.5 rounded-xl bg-blue-500 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-400">Message SolarNet <ExternalLink className="h-4 w-4" /></a></div></div>
          </article>
        </section>
      </div>
    </main>
  );
}
