import React, { useCallback, useEffect, useState } from 'react';
import { Loader2, RefreshCw, ShieldCheck, Wifi } from 'lucide-react';
import { speedtestService, type SpeedtestConnection } from '@/services/speedtestService';

/**
 * Application identity panel for the SolarNet speed-test route.
 *
 * This page deliberately only owns the display mapping. Existing or future
 * measurement clients continue to own download, upload, ping, and server
 * selection; no visitor traffic is routed through this component.
 */
const SpeedtestPage: React.FC = () => {
  const [connection, setConnection] = useState<SpeedtestConnection | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const loadConnection = useCallback(async (): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      setConnection(await speedtestService.connection());
    } catch (err: any) {
      setConnection(null);
      setError(err?.response?.data?.message || 'Public IP unavailable');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void loadConnection(); }, [loadConnection]);

  const hasNetworkDetails = Boolean(
    connection?.detected_isp || connection?.detected_asn || connection?.detected_org || connection?.detected_city || connection?.detected_country,
  );

  return (
    <main className="min-h-screen bg-slate-950 px-4 py-8 text-slate-100 sm:px-6 sm:py-14">
      <section className="mx-auto max-w-2xl overflow-hidden rounded-3xl border border-sky-400/20 bg-slate-900 shadow-2xl shadow-sky-950/30">
        <header className="flex flex-wrap items-center justify-between gap-4 border-b border-white/10 px-6 py-5 sm:px-8">
          <div className="flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-sky-400 to-blue-600 shadow-lg shadow-sky-500/20"><Wifi className="h-5 w-5" aria-hidden="true" /></div>
            <div><p className="font-semibold tracking-tight">SolarNet Speedtest</p><p className="text-xs text-slate-400">Connection identity</p></div>
          </div>
          <button type="button" onClick={() => void loadConnection()} disabled={loading} className="inline-flex items-center gap-2 rounded-lg border border-sky-300/25 bg-sky-400/10 px-3 py-2 text-xs font-semibold text-sky-100 transition hover:bg-sky-400/20 disabled:opacity-50">
            {loading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />} Refresh identity
          </button>
        </header>

        <div className="p-6 sm:p-8">
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-sky-300">Connections</p>
          <p className="mt-1 text-sm text-slate-400">Multi</p>

          <div className="mt-5 rounded-2xl border border-white/10 bg-slate-950/45 p-5 sm:p-6" aria-live="polite">
            {loading ? (
              <div className="flex items-center gap-3 text-sm text-slate-300"><Loader2 className="h-5 w-5 animate-spin text-sky-300" />Detecting your current public IP…</div>
            ) : error ? (
              <div><p className="text-lg font-semibold text-slate-100">Speedtest identity</p><p className="mt-1 text-sm text-amber-200">{error}</p></div>
            ) : (
              <>
                <p className="text-2xl font-semibold tracking-tight text-white">{connection?.provider_display_name || 'Provider unavailable'}</p>
                <p className="mt-2 font-mono text-lg text-sky-200">{connection?.public_ip || 'Public IP unavailable'}</p>
              </>
            )}
          </div>

          <div className="mt-5 flex gap-3 rounded-2xl border border-emerald-300/15 bg-emerald-400/5 p-4 text-sm text-slate-300">
            <ShieldCheck className="mt-0.5 h-5 w-5 shrink-0 text-emerald-300" aria-hidden="true" />
            <p>The provider label is SolarNet application branding. Your public IP is detected for this request and remains your actual connection address.</p>
          </div>

          {hasNetworkDetails && (
            <div className="mt-5 rounded-2xl border border-white/10 bg-white/[0.03] p-4 text-sm">
              <p className="font-semibold text-slate-100">Network details</p>
              <dl className="mt-3 grid gap-2 text-slate-300 sm:grid-cols-2">
                {connection?.detected_isp && <><dt className="text-slate-500">Actual upstream/network</dt><dd>{connection.detected_isp}</dd></>}
                {connection?.detected_asn && <><dt className="text-slate-500">ASN</dt><dd>{connection.detected_asn}</dd></>}
                {connection?.detected_org && <><dt className="text-slate-500">Organization</dt><dd>{connection.detected_org}</dd></>}
                {connection?.detected_city && <><dt className="text-slate-500">Location</dt><dd>{[connection.detected_city, connection.detected_country].filter(Boolean).join(', ')}</dd></>}
              </dl>
            </div>
          )}
        </div>
      </section>
    </main>
  );
};

export default SpeedtestPage;
