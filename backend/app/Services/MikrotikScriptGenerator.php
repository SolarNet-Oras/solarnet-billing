<?php

namespace App\Services;

use App\Models\Router;

class MikrotikScriptGenerator
{
    /** PayMongo hosts the GCash checkout page; keep the walled garden narrow. */
    private const PAYMENT_CHECKOUT_HOSTS = ['checkout.paymongo.com'];

    /**
     * Generate RouterOS setup script for API configuration
     * 
     * @param Router $router
     * @param string $billingSystemIp (optional - IP of billing system for firewall rules)
     * @return string
     */
    public function generateSetupScript(Router $router, ?string $billingSystemIp = null, ?string $paymentPortalUrl = null): string
    {
        $username = $router->username;
        $password = $router->password;

        // API port on the MikroTik itself is ALWAYS hardcoded to the RouterOS
        // default 8728. This is intentional: even when the router is reached
        // by the billing app through a VPN (where $router->port may be a
        // tunneled/mapped port like 18728), the /ip service on the router
        // must still listen on 8728 for consistency and predictability.
        $apiPort = 8728;
        $isForwardedEndpoint = (int) $router->port !== $apiPort;
        $connectionEndpoint = $router->host . ':' . $router->port;
        $paymentPortalUrl = trim((string) ($paymentPortalUrl ?: config('app.url')));
        $paymentPortalHost = parse_url($paymentPortalUrl, PHP_URL_HOST);
        $paymentAccessHosts = [];
        $paymentPortalIps = $paymentPortalHost ? $this->resolveIpv4Addresses($paymentPortalHost) : [];
        if ($paymentPortalIps === [] && filter_var($billingSystemIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $paymentPortalIps = [$billingSystemIp];
        }
        if ($paymentPortalHost && $paymentPortalIps !== []) {
            $paymentAccessHosts[$paymentPortalHost] = $paymentPortalIps;
        }
        foreach (self::PAYMENT_CHECKOUT_HOSTS as $checkoutHost) {
            $checkoutIps = $this->resolveIpv4Addresses($checkoutHost);
            if ($checkoutIps !== []) {
                $paymentAccessHosts[$checkoutHost] = $checkoutIps;
            }
        }
        $paymentPortalIp = $paymentPortalIps[0] ?? null;

        $script = <<<SCRIPT
# ============================================================
# MikroTik Setup Script - {$router->name}
# ------------------------------------------------------------
# Generated : {{date}}
# Billing endpoint : {$connectionEndpoint}
# API Port  : {$apiPort}  (hardcoded - do not change, VPN-safe)
# API User  : {$username}
# ============================================================
# HOW TO USE
#   1. Winbox  ->  connect to router  ->  New Terminal
#      (or SSH into the router)
#   2. Paste this ENTIRE block and press Enter
#   3. Wait for the line "=== Setup Complete ==="
# ============================================================

:log info "[BILLING] Starting setup..."
:put "[BILLING] Starting setup..."

# --- 1/4  Create dedicated API user group (safe if it already exists)
/user group
:if ([:len [find name="billing_api_group"]] = 0) do={
    add name="billing_api_group" \\
        policy=api,read,write,policy,test,password,web,!local,!telnet,!ssh,!ftp,!reboot,!sensitive
    :put "  [+] Created group billing_api_group"
} else={
    :put "  [=] Group billing_api_group already present"
}

# --- 2/4  Create or update the API user
/user
:if ([:len [find name="{$username}"]] = 0) do={
    add name="{$username}" password="{$password}" group=billing_api_group \\
        comment="Solarnet Billing API"
    :put "  [+] Created user {$username}"
} else={
    set [find name="{$username}"] password="{$password}" group=billing_api_group
    :put "  [=] Updated existing user {$username}"
}

# --- 3/4  Enable RouterOS API service on port {$apiPort}
/ip service
set api disabled=no port={$apiPort}
:put "  [+] API service enabled on port {$apiPort}"

SCRIPT;

        // A forwarded VPN endpoint has two different ports: the public port
        // used by the billing server and RouterOS's internal API port. In
        // that setup the router sees the VPN gateway as the source, not
        // necessarily the billing server. Restricting the service to the
        // billing server IP therefore makes an otherwise valid tunnel fail.
        if ($isForwardedEndpoint) {
            $script .= <<<VPN

# --- 4/4  VPN / port-forward compatibility
# The billing endpoint above is a forwarded port. Do NOT restrict the RouterOS
# API to the billing server IP here: the VPN gateway may rewrite that source.
# Secure this connection at the VPN/port-forward gateway instead.
/ip service set api address=0.0.0.0/0
/ip firewall filter remove [find comment="Solarnet Billing API"]
:put "  [i] VPN endpoint detected; direct-IP API restriction removed"

VPN;
        } elseif ($billingSystemIp) {
            $script .= <<<FIREWALL

# --- 4/4  Firewall: allow API traffic ONLY from the billing server
/ip firewall filter
:if ([:len [find comment="Solarnet Billing API"]] = 0) do={
    add chain=input protocol=tcp dst-port={$apiPort} \\
        src-address={$billingSystemIp} action=accept \\
        comment="Solarnet Billing API" place-before=0
    :put "  [+] Firewall rule added for {$billingSystemIp}"
} else={
    :put "  [=] Firewall rule already present"
}
# Restrict the API service itself to the billing IP (defense-in-depth)
/ip service set api address={$billingSystemIp}/32

FIREWALL;
        } else {
            $script .= <<<NOFW

# --- 4/4  Firewall step skipped (billing system IP unknown).
#         Add manually:  /ip firewall filter add chain=input protocol=tcp \\
#                        dst-port={$apiPort} src-address=<YOUR_BILLING_IP> action=accept

NOFW;
        }

        $script .= <<<LIST

# --- 5/6  Billing suspension address list
# The billing system adds/removes customer IPs in this exact list name.
/ip firewall address-list
:if ([:len [find list="suspended_customers"]] = 0) do={
    add list="suspended_customers" address=0.0.0.0 disabled=yes \\
        comment="Solarnet Billing placeholder - do not enable"
    :put "  [+] Created suspended_customers list"
} else={
    :put "  [=] suspended_customers list already present"
}

LIST;

        if ($paymentPortalIp) {
            $script .= <<<BILLING

# --- 6/6  Payment-only access for suspended IPoE clients
# Safe to paste before any customer is suspended: the list is empty until the
# billing app adds an overdue customer. Suspended clients may resolve DNS and
# open the payment portal at {$paymentPortalUrl}; other forwarded traffic is blocked.
# HTTPS is NOT transparently redirected because that causes certificate errors.
/ip firewall filter
:foreach rule in=[find comment~"^Solarnet Billing: suspended"] do={ remove \$rule }
# Keep the payment destination separate from customer suspension entries. Only
# this Solarnet-managed destination entry is replaced when the script is rerun.
/ip firewall address-list
:foreach entry in=[find list="solarnet_payment_portal" comment~"^Solarnet Billing payment portal"] do={ remove \$entry }
{$this->paymentCheckoutAddressListLines($paymentAccessHosts)}/ip firewall filter
add chain=forward src-address-list=suspended_customers action=drop \\
    comment="Solarnet Billing: suspended block internet"
add chain=forward src-address-list=solarnet_payment_sessions action=accept \\
    comment="Solarnet Billing: suspended allow temporary payment checkout"
add chain=forward src-address-list=suspended_customers protocol=tcp dst-port=53 action=accept \\
    comment="Solarnet Billing: suspended allow DNS TCP"
add chain=forward src-address-list=suspended_customers protocol=udp dst-port=53 action=accept \\
    comment="Solarnet Billing: suspended allow DNS UDP"
add chain=forward src-address-list=suspended_customers protocol=tcp \\
    dst-address-list=solarnet_payment_portal dst-port=80,443 action=accept \\
    comment="Solarnet Billing: suspended allow payment portal"
# Put the allows before the drop. This explicit order is reliable on RouterOS
# versions that ignore numeric place-before values in pasted scripts.
move [find comment="Solarnet Billing: suspended allow temporary payment checkout"] destination=0
move [find comment="Solarnet Billing: suspended allow payment portal"] destination=1
move [find comment="Solarnet Billing: suspended allow DNS UDP"] destination=2
move [find comment="Solarnet Billing: suspended allow DNS TCP"] destination=3
move [find comment="Solarnet Billing: suspended block internet"] destination=4
:put "  [+] Suspended clients limited to DNS + SolarNet portal + PayMongo GCash checkout"

BILLING;
        } else {
            $script .= <<<NOPORTAL

# --- 6/6  Payment-only access not installed
# Set Network Reminder > Payment reminder URL to a valid public HTTPS URL,
# then regenerate this script. Queue throttling still works, but traffic will
# not be blocked without a verified payment portal target.
:put "  [!] Payment portal URL is not configured; suspension firewall rules skipped"

NOPORTAL;
        }

        $script .= <<<TAIL

# Verify
:put ""
:put "--- Verification ---"
/ip service print where name=api
/user print where name={$username}
/ip firewall filter print where comment~"^Solarnet Billing: suspended"

:put ""
:put "=== Setup Complete ==="
:put "Configure the billing application with these separate fields:"
:put "  Host: {$router->host}"
:put "  Port: {$router->port}"
:put "  RouterOS API port on this router: {$apiPort}"
:put "Do not include :{$router->port} in the Host field."
:log info "[BILLING] Setup complete."

TAIL;

        return str_replace('{{date}}', now()->format('Y-m-d H:i:s'), $script);
    }

    /**
     * Generate queue management script template
     * 
     * @return string
     */
    public function generateQueueManagementScript(): string
    {
        return <<<SCRIPT
# ============================================================
# Queue Management Script Template
# ============================================================
# This script will be auto-executed by the billing system
# when managing customer bandwidth.
#
# The billing system will:
# - Create simple queues for each active customer
# - Update queues when service plan changes
# - Remove/throttle queues on suspension
#
# Queue naming convention: customer-{customer_id}
# Example: customer-019f1dec-51d9-7358-b051-800af53de299
#
# ============================================================

# Example: Add a customer queue
/queue simple add \\
    name="customer-XXXXX" \\
    target=192.168.1.100/32 \\
    max-limit=100M/50M \\
    burst-limit=150M/75M \\
    burst-threshold=75M/37.5M \\
    burst-time=16s/16s \\
    priority=8/8 \\
    comment="Customer: John Doe - Plan: Gold 100Mbps"

# Example: Update queue
/queue simple set [find name="customer-XXXXX"] max-limit=200M/100M

# Example: Remove queue
/queue simple remove [find name="customer-XXXXX"]

# Example: Throttle suspended customer to 64kbps
/queue simple set [find name="customer-XXXXX"] max-limit=64k/64k

SCRIPT;
    }

    /** @param array<string, list<string>> $hosts IPv4 addresses resolved by the billing server. */
    private function paymentCheckoutAddressListLines(array $hosts): string
    {
        if ($hosts === []) {
            return '';
        }

        $lines = [
            '# PayMongo hosts the secure GCash checkout. It is allowed only for',
            '# suspended customers and only over HTTP(S), via the same allow-list.',
        ];

        foreach ($hosts as $host => $ips) {
            foreach ($ips as $ip) {
                $lines[] = 'add list="solarnet_payment_portal" address=' . $ip . ' \\';
                $lines[] = '    comment="Solarnet Billing payment portal ' . $host . '"';
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** @return list<string> */
    private function resolveIpv4Addresses(string $host): array
    {
        $ips = gethostbynamel($host) ?: [gethostbyname($host)];

        return array_values(array_unique(array_filter(
            $ips,
            fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4),
        )));
    }

    /**
     * Generate firewall redirect script for payment portal
     * 
     * @param string $paymentPortalUrl
     * @return string
     */
    public function generatePaymentRedirectScript(string $paymentPortalUrl): string
    {
        $paymentHost = parse_url($paymentPortalUrl, PHP_URL_HOST) ?: $paymentPortalUrl;

        return <<<SCRIPT
# ============================================================
# Payment Portal Redirect Script
# ============================================================
# Redirects suspended customers to payment portal
#
# Usage: Run this after setting up the billing system
# ============================================================

# Create walled garden for payment portal domain
/ip hotspot walled-garden
add dst-host={$paymentHost} comment="Allow access to payment portal"
:if ([:len [find dst-host="checkout.paymongo.com"]] = 0) do={
    add dst-host="checkout.paymongo.com" comment="Allow PayMongo GCash checkout"
}

# Create NAT rule to redirect HTTP traffic to the payment portal domain
# HTTPS should be handled by a captive portal / hotspot login page, not a
# transparent TLS redirect, to avoid certificate warnings.
/ip firewall nat
add chain=dstnat \\
    protocol=tcp \\
    dst-port=80 \\
    src-address-list=suspended_customers \\
    action=redirect \\
    to-ports=80 \\
    comment="Redirect suspended customers to payment portal"

:log info "Payment portal redirect configured for: {$paymentPortalUrl}"

SCRIPT;
    }
}
