import { Activity, Database, RadioTower, Server, ShieldCheck, Wifi, WifiOff } from 'lucide-react';
import type { Router } from '@/services/routerService';

interface NetworkDeviceTopologyProps {
  routers: Router[];
}

const routerStatus = (router: Router) => router.connection_status === 'online'
  ? { label: 'Link online', className: 'network-device-router-online', Icon: Wifi }
  : router.connection_status === 'offline'
    ? { label: 'Link offline', className: 'network-device-router-offline', Icon: WifiOff }
    : { label: 'Awaiting check', className: 'network-device-router-unknown', Icon: Activity };

/** Visual-only status topology. It deliberately performs no RouterOS requests or writes. */
export function NetworkDeviceTopology({ routers }: NetworkDeviceTopologyProps) {
  const onlineCount = routers.filter((router) => router.connection_status === 'online').length;

  return (
    <section className="network-device-topology" aria-label="SolarNet VPS to MikroTik control topology">
      <div className="network-device-topology-grid" aria-hidden="true" />
      <div className="network-device-topology-scanline" aria-hidden="true" />

      <header className="relative flex flex-col gap-3 border-b border-cyan-300/15 pb-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <p className="text-[0.66rem] font-extrabold uppercase tracking-[0.24em] text-cyan-300">SolarNet control topology</p>
          <h3 className="mt-1 text-lg font-bold tracking-tight text-white sm:text-xl">VPS command path → MikroTik edge</h3>
          <p className="mt-1 max-w-2xl text-xs leading-5 text-slate-400">Animated packets represent the configured web-control path only. They do not send traffic, alter RouterOS, or imply a VPN tunnel check.</p>
        </div>
        <div className="flex flex-wrap gap-2 text-[0.65rem] font-bold uppercase tracking-[0.08em]">
          <span className="rounded-full border border-cyan-300/25 bg-cyan-400/10 px-2.5 py-1 text-cyan-100"><Server className="mr-1 inline h-3.5 w-3.5" /> VPS control plane</span>
          <span className="rounded-full border border-emerald-300/25 bg-emerald-400/10 px-2.5 py-1 text-emerald-100"><Activity className="mr-1 inline h-3.5 w-3.5" /> {onlineCount}/{routers.length} router links online</span>
        </div>
      </header>

      <div className="network-device-topology-stage relative mt-4">
        <article className="network-device-vps-node">
          <div className="network-device-vps-orb"><Server className="h-7 w-7" /></div>
          <div className="min-w-0">
            <p className="network-device-eyebrow">SolarNet VPS</p>
            <h4>Billing control plane</h4>
            <p className="truncate font-mono">billing.solarnetportal.com</p>
          </div>
          <div className="network-device-vps-services" aria-label="VPS services">
            <span><ShieldCheck className="h-3.5 w-3.5" /> Secure API</span>
            <span><Database className="h-3.5 w-3.5" /> Billing data</span>
          </div>
        </article>

        <div className={`network-device-main-link ${onlineCount > 0 ? 'network-device-main-link-active' : 'network-device-main-link-idle'}`} aria-hidden="true">
          <span className="network-device-flow-caption network-device-flow-caption-top">Encrypted API control</span>
          <span className="network-device-flow-caption network-device-flow-caption-bottom">Status &amp; lease telemetry</span>
          <i /><i /><i />
          <b /><b />
        </div>

        <div className="network-device-router-grid">
          {routers.map((router) => {
            const status = routerStatus(router);
            const StatusIcon = status.Icon;
            return (
              <article key={router.id} className={`network-device-router-node ${status.className}`}>
                <div className="network-device-router-signal" aria-hidden="true"><RadioTower className="h-5 w-5" /></div>
                <div className="min-w-0 flex-1">
                  <p className="network-device-eyebrow">MikroTik edge</p>
                  <h4 className="truncate" title={router.name}>{router.name}</h4>
                  <p className="truncate font-mono" title={`${router.host}:${router.port}`}>{router.host}:{router.port}</p>
                  <div className="mt-2 flex flex-wrap items-center gap-1.5 text-[0.62rem] text-slate-300">
                    <span className="network-device-status-pill"><StatusIcon className="h-3 w-3" /> {status.label}</span>
                    {router.location && <span className="rounded bg-slate-800/80 px-1.5 py-0.5 text-slate-400">{router.location}</span>}
                  </div>
                </div>
              </article>
            );
          })}
        </div>
      </div>

      <footer className="relative mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-cyan-300/10 pt-3 text-[0.65rem] text-slate-400">
        <span><span className="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-cyan-300 shadow-[0_0_8px_rgba(103,232,249,0.95)]" />Moving particles are visual status indicators.</span>
        <span>Router actions remain available below.</span>
      </footer>
    </section>
  );
}
