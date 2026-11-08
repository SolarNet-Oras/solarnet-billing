<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BootstrapSolarNetTenantFoundation extends Command
{
    private const CONFIRMATION = 'CREATE SOLARNET TENANT FOUNDATION';

    protected $signature = 'saas:bootstrap-solarnet
                            {--execute : Create the primary tenant and legacy memberships}
                            {--confirm= : Exact execution confirmation phrase}';

    protected $description = 'Audit or safely bootstrap the inert SolarNet SaaS foundation';

    public function handle(): int
    {
        if (! Schema::hasTable('tenants') || ! Schema::hasTable('tenant_user')) {
            $this->error('SaaS foundation tables are missing. Run migrations first.');
            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        if ($execute && ! hash_equals(self::CONFIRMATION, (string) $this->option('confirm'))) {
            $this->error('Execution stopped. Use --confirm="'.self::CONFIRMATION.'".');
            return self::FAILURE;
        }

        $slug = (string) config('saas.primary_tenant_slug', 'solarnet');
        $existing = Tenant::withTrashed()->where('slug', $slug)->first();
        $primaryCount = Tenant::withTrashed()->where('is_primary', true)->count();
        $users = User::withTrashed()
            ->with(['roles:id,name', 'tenantMemberships:id,tenant_id,user_id,status'])
            ->orderBy('id')
            ->get();
        $existingMemberships = $existing
            ? TenantMembership::where('tenant_id', $existing->id)->count()
            : 0;
        $membershipCandidates = $users->filter(function (User $user) use ($existing): bool {
            if ($existing && $user->tenantMemberships->contains('tenant_id', $existing->id)) {
                return false;
            }

            return $user->tenantMemberships->isEmpty();
        });
        $crossTenantUsers = $users->filter(function (User $user) use ($existing): bool {
            if ($existing && $user->tenantMemberships->contains('tenant_id', $existing->id)) {
                return false;
            }

            return $user->tenantMemberships->isNotEmpty();
        });

        $preview = [
            'mode' => $execute ? 'execute' : 'audit',
            'saas_enabled' => (bool) config('saas.enabled'),
            'enforcement_mode' => config('saas.enforcement_mode'),
            'tenant_slug' => $slug,
            'tenant_exists' => (bool) $existing,
            'primary_tenant_count' => $primaryCount,
            'legacy_users' => $users->count(),
            'existing_memberships' => $existingMemberships,
            'memberships_to_create' => $membershipCandidates->count(),
            'users_preserved_in_other_tenants' => $crossTenantUsers->count(),
            'preserved_other_tenant_users' => $crossTenantUsers
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])->values()->all(),
        ];

        if (! $execute) {
            $preview['action'] = 'no_changes_audit_only';
            $this->line(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($primaryCount > 1 || ($primaryCount === 1 && ! $existing?->is_primary)) {
            $this->error('Bootstrap stopped: an ambiguous primary tenant already exists.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($slug, $users): void {
            $tenant = Tenant::withTrashed()->where('slug', $slug)->lockForUpdate()->first();

            if ($tenant === null) {
                $tenant = Tenant::create([
                    'slug' => $slug,
                    'name' => 'SolarNet',
                    'legal_name' => 'Solarnet Connection Solar Power Installation Services',
                    'contact_email' => 'solarnet.connection@gmail.com',
                    'status' => 'active',
                    'is_primary' => true,
                    'subscription_exempt' => true,
                    'metadata' => ['bootstrap' => 'legacy_primary_tenant'],
                ]);
            } else {
                if ($tenant->deleted_at !== null) {
                    throw new \RuntimeException('Bootstrap stopped: the SolarNet tenant is soft deleted.');
                }
                if (! $tenant->is_primary || ! $tenant->subscription_exempt) {
                    throw new \RuntimeException('Bootstrap stopped: the existing SolarNet tenant does not match the protected primary configuration.');
                }
            }

            foreach ($users as $user) {
                if ($user->tenantMemberships->contains('tenant_id', $tenant->id)) {
                    continue;
                }

                // A user already owned by another ISP must never be silently
                // enrolled into the protected SolarNet workspace.
                if ($user->tenantMemberships->isNotEmpty()) {
                    continue;
                }

                $isSuperAdmin = $user->roles->contains('name', 'super_admin');
                TenantMembership::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                    [
                        'membership_role' => $isSuperAdmin ? 'tenant_admin' : 'legacy_staff',
                        'status' => $user->is_active && $user->deleted_at === null ? 'active' : 'inactive',
                        'is_owner' => $isSuperAdmin,
                    ],
                );
            }
        }, 3);

        $tenant = Tenant::where('slug', $slug)->firstOrFail();
        $preview['action'] = 'foundation_created_or_verified';
        $preview['tenant_id'] = $tenant->id;
        $preview['verified_memberships'] = TenantMembership::where('tenant_id', $tenant->id)->count();
        $this->line(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
