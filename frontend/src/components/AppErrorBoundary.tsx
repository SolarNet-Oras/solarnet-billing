import React from 'react';

type State = { failed: boolean; recovering: boolean };

export default class AppErrorBoundary extends React.Component<React.PropsWithChildren, State> {
  state: State = { failed: false, recovering: false };

  static getDerivedStateFromError(): State {
    return { failed: true, recovering: false };
  }

  componentDidCatch(error: Error): void {
    console.error('SolarNet interface error:', error);
  }

  private recover = async (): Promise<void> => {
    this.setState({ recovering: true });
    try {
      if ('serviceWorker' in navigator) {
        const registrations=await navigator.serviceWorker.getRegistrations();
        await Promise.all(registrations.map((registration)=>registration.unregister()));
      }
      if ('caches' in window) {
        const keys=await window.caches.keys();
        await Promise.all(keys.map((key)=>window.caches.delete(key)));
      }
    } finally {
      const url=new URL(window.location.href);
      url.searchParams.set('app_refresh',Date.now().toString());
      window.location.replace(url.toString());
    }
  };

  render(): React.ReactNode {
    if (!this.state.failed) return this.props.children;

    return (
      <main className="flex min-h-screen items-center justify-center bg-slate-50 p-5 text-slate-950">
        <section className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-xl">
          <h1 className="text-xl font-bold">SolarNet could not display this page</h1>
          <p className="mt-3 text-sm leading-6 text-slate-600">Your account is safe. Refresh the cached application files to load the current SolarNet app.</p>
          <button type="button" disabled={this.state.recovering} onClick={()=>void this.recover()} className="mt-5 rounded-xl bg-blue-700 px-5 py-3 font-semibold text-white disabled:cursor-wait disabled:opacity-70">
            {this.state.recovering?'Refreshing application…':'Refresh application'}
          </button>
        </section>
      </main>
    );
  }
}
