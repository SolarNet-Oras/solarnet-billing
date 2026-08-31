import React from 'react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { StaffAppInstallCard } from '@/components/settings/AdminAppInstallCard';

export default function StaffAppInstallPage(): React.JSX.Element {
  return <DashboardLayout headerTitle="Install Staff App" headerSubtitle="Install the authorized employee workspace on this device">
    <div className="mx-auto max-w-4xl space-y-5">
      <StaffAppInstallCard />
      <section className="rounded-2xl border border-border bg-card p-5 text-sm text-muted-foreground">
        <h2 className="font-semibold text-foreground">Your access stays role-based</h2>
        <p className="mt-2 leading-6">Collectors, technicians, cashiers, administrators, NOC, accounting, and other employees continue to see only the screens and actions allowed by their SolarNet role. Installing the app does not add permissions and does not save a password inside the app package.</p>
      </section>
    </div>
  </DashboardLayout>;
}
