<?php

namespace App\Services;

use App\Models\Router;

class MikrotikScriptGenerator
{
    /**
     * Generate RouterOS setup script for API configuration
     * 
     * @param Router $router
     * @param string $billingSystemIp (optional - IP of billing system for firewall rules)
     * @return string
     */
    public function generateSetupScript(Router $router, ?string $billingSystemIp = null): string
    {
        $username = $router->username;
        $password = $router->password;

        // API port on the MikroTik itself is ALWAYS hardcoded to the RouterOS
        // default 8728. This is intentional: even when the router is reached
        // by the billing app through a VPN (where $router->port may be a
        // tunneled/mapped port like 18728), the /ip service on the router
        // must still listen on 8728 for consistency and predictability.
        $apiPort = 8728;

        $script = <<<SCRIPT
# ============================================================
# MikroTik Setup Script - {$router->name}
# ------------------------------------------------------------
# Generated : {{date}}
# Router    : {$router->host}
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

        if ($billingSystemIp) {
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

        $script .= <<<TAIL

# --- 5/5  Address list used by the billing system to throttle suspended customers
/ip firewall address-list
:if ([:len [find list="suspended_customers"]] = 0) do={
    :put "  [i] Address list suspended_customers will be populated by the billing system"
}

# Verify
:put ""
:put "--- Verification ---"
/ip service print where name=api
/user print where name={$username}

:put ""
:put "=== Setup Complete ==="
:put "Now go back to the billing app and click 'Save Router'."
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

    /**
     * Generate firewall redirect script for payment portal
     * 
     * @param string $paymentPortalUrl
     * @return string
     */
    public function generatePaymentRedirectScript(string $paymentPortalUrl): string
    {
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
add dst-host={$paymentPortalUrl} comment="Allow access to payment portal"

# Create NAT rule to redirect HTTP traffic to payment portal
/ip firewall nat
add chain=dstnat \\
    protocol=tcp \\
    dst-port=80 \\
    src-address-list=suspended_customers \\
    action=redirect \\
    to-ports=80 \\
    comment="Redirect suspended customers to payment portal"

# Create NAT rule to redirect HTTPS traffic
add chain=dstnat \\
    protocol=tcp \\
    dst-port=443 \\
    src-address-list=suspended_customers \\
    action=redirect \\
    to-ports=443 \\
    comment="Redirect suspended HTTPS to payment portal"

:log info "Payment portal redirect configured for: {$paymentPortalUrl}"

SCRIPT;
    }
}
