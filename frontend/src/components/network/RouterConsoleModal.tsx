import { useState } from 'react';
import { AlertTriangle, Play, Radio, Terminal, X } from 'lucide-react';
import { routerService } from '@/services/routerService';

interface RouterConsoleModalProps {
  isOpen: boolean;
  onClose: () => void;
  routerId: string;
  routerName: string;
}

export function RouterConsoleModal({ isOpen, onClose, routerId, routerName }: RouterConsoleModalProps) {
  const [script, setScript] = useState('');
  const [pingAddress, setPingAddress] = useState('8.8.8.8');
  const [busy, setBusy] = useState<'script' | 'ping' | null>(null);
  const [output, setOutput] = useState('');

  if (!isOpen) return null;

  const executeScript = async () => {
    if (!script.trim()) return;
    if (!window.confirm(`Run this RouterOS script on ${routerName}? It can change router configuration. The script will be removed from the router after it runs.`)) return;
    setBusy('script');
    setOutput('Running one-time RouterOS script…');
    try {
      const result = await routerService.runConsoleScript(routerId, script);
      setOutput(`${result.message}\n${result.result ? JSON.stringify(result.result, null, 2) : ''}`.trim());
    } catch (error: any) {
      setOutput(error.response?.data?.message || error.message || 'Script failed.');
    } finally {
      setBusy(null);
    }
  };

  const runPing = async () => {
    if (!pingAddress.trim()) return;
    setBusy('ping');
    setOutput(`Pinging ${pingAddress.trim()} from ${routerName}…`);
    try {
      const result = await routerService.consolePing(routerId, pingAddress.trim());
      setOutput(`${result.message}\n${JSON.stringify(result.rows ?? [], null, 2)}`);
    } catch (error: any) {
      setOutput(error.response?.data?.message || error.message || 'Ping failed.');
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={onClose}>
      <div className="flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl border border-border bg-card shadow-2xl" onClick={(event) => event.stopPropagation()}>
        <div className="flex items-center justify-between border-b border-border p-5">
          <div className="flex items-center gap-3">
            <div className="rounded-lg bg-slate-900 p-2 text-emerald-400"><Terminal className="h-5 w-5" /></div>
            <div><h2 className="font-bold text-foreground">MikroTik Console</h2><p className="text-sm text-muted-foreground">Connected router: {routerName}</p></div>
          </div>
          <button type="button" onClick={onClose} className="rounded p-2 hover:bg-secondary" aria-label="Close console"><X className="h-5 w-5" /></button>
        </div>

        <div className="grid gap-5 overflow-y-auto p-5 md:grid-cols-[1fr_300px]">
          <div className="space-y-3">
            <label className="block text-sm font-medium text-foreground">One-time RouterOS script</label>
            <textarea value={script} onChange={(event) => setScript(event.target.value)} rows={13} spellCheck={false}
              placeholder={'/ip firewall filter print\n:put "Hello from SolarNet"'}
              className="w-full rounded-lg border border-slate-700 bg-slate-950 p-4 font-mono text-sm text-emerald-300 outline-none focus:ring-2 focus:ring-primary" data-testid="router-console-script" />
            <div className="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-200"><AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" /><span>Commands run with this router&apos;s billing API account. Review pasted scripts carefully. They execute once and are deleted from RouterOS immediately afterward.</span></div>
            <button type="button" onClick={() => void executeScript()} disabled={busy !== null || !script.trim()} className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50" data-testid="router-console-run-script"><Play className="h-4 w-4" />{busy === 'script' ? 'Running…' : 'Run Script'}</button>
          </div>

          <div className="space-y-4">
            <div className="rounded-lg border border-border p-4">
              <div className="mb-2 flex items-center gap-2 font-medium text-foreground"><Radio className="h-4 w-4 text-primary" />Ping from router</div>
              <input value={pingAddress} onChange={(event) => setPingAddress(event.target.value)} placeholder="8.8.8.8 or example.com" className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm" data-testid="router-console-ping-address" />
              <button type="button" onClick={() => void runPing()} disabled={busy !== null || !pingAddress.trim()} className="mt-3 w-full rounded-md border border-primary px-3 py-2 text-sm text-primary hover:bg-primary/10 disabled:opacity-50" data-testid="router-console-ping">{busy === 'ping' ? 'Pinging…' : 'Ping'}</button>
            </div>
            <div><div className="mb-2 text-sm font-medium text-foreground">Output</div><pre className="min-h-48 max-h-80 overflow-auto rounded-lg bg-slate-950 p-3 text-xs text-slate-200 whitespace-pre-wrap">{output || 'No command run yet.'}</pre></div>
          </div>
        </div>
      </div>
    </div>
  );
}
