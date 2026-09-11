import React from 'react';

type State = { failed: boolean };

export default class AppErrorBoundary extends React.Component<React.PropsWithChildren, State> {
  state: State = { failed: false };

  static getDerivedStateFromError(): State {
    return { failed: true };
  }

  componentDidCatch(error: Error): void {
    console.error('SolarNet interface error:', error);
  }

  private recover = (): void => {
    if ('serviceWorker' in navigator) {
      void navigator.serviceWorker.getRegistrations()
        .then((registrations) => Promise.all(registrations.map((registration) => registration.update())))
        .finally(() => window.location.reload());
      return;
    }
    window.location.reload();
  };

  render(): React.ReactNode {
    if (!this.state.failed) return this.props.children;

    return (
      <main className="flex min-h-screen items-center justify-center bg-slate-50 p-5 text-slate-950">
        <section className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-xl">
          <h1 className="text-xl font-bold">SolarNet could not display this page</h1>
          <p className="mt-3 text-sm leading-6 text-slate-600">Your account is safe. Update this page to load the compatible version of the customer app.</p>
          <button type="button" onClick={this.recover} className="mt-5 rounded-xl bg-blue-700 px-5 py-3 font-semibold text-white">
            Update and reload
          </button>
        </section>
      </main>
    );
  }
}
