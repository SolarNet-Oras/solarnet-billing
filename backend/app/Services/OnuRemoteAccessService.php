<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\OnuRemoteSession;
use App\Models\Router;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;
use Throwable;

class OnuRemoteAccessService
{
    private const COMMENT_PREFIX = 'SolarNet ONU Remote:';

    public function open(Customer $customer, User $actor, string $sourceIp, string $path, int $targetPort): OnuRemoteSession
    {
        $router = $customer->router;
        if (! $router || ! $router->is_active) throw new \RuntimeException('The customer does not have an active MikroTik router.');
        if (! filter_var($customer->ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE)) throw new \RuntimeException('The customer does not have a valid ONU IPv4 address.');
        if (! filter_var($sourceIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new \RuntimeException('A public administrator IPv4 address is required for source-restricted access. Verify Laravel trusted-proxy handling before using this feature.');

        $client = $this->client($router);
        $cloud = $client->query(new Query('/ip/cloud/print'))->read()[0] ?? [];
        $publicHost = $this->publicHost($cloud, $router);

        $port = $this->availablePort($client);
        $session = new OnuRemoteSession([
            'customer_id'=>$customer->id, 'router_id'=>$router->id, 'created_by'=>$actor->id,
            'customer_ip'=>$customer->ip_address, 'source_ip'=>$sourceIp, 'target_port'=>$targetPort,
            'public_port'=>$port, 'public_host'=>$publicHost, 'path'=>$path,
            'status'=>'creating', 'expires_at'=>now()->addMinutes(10),
        ]);
        $session->id = (string) Str::uuid();
        $comment = self::COMMENT_PREFIX.$session->id;
        $session->router_comment = $comment;
        $session->save();

        try {
            $client->query((new Query('/ip/firewall/nat/add'))->equal('chain','dstnat')->equal('protocol','tcp')->equal('src-address',$sourceIp.'/32')->equal('dst-port',(string)$port)->equal('action','dst-nat')->equal('to-addresses',$customer->ip_address)->equal('to-ports',(string)$targetPort)->equal('comment',$comment))->read();
            $filterResponse = $client->query((new Query('/ip/firewall/filter/add'))->equal('chain','forward')->equal('protocol','tcp')->equal('src-address',$sourceIp.'/32')->equal('dst-address',$customer->ip_address)->equal('dst-port',(string)$targetPort)->equal('connection-nat-state','dstnat')->equal('action','accept')->equal('comment',$comment))->read();
            $filterId = $filterResponse[0]['ret'] ?? null;
            if ($filterId) $client->query((new Query('/ip/firewall/filter/move'))->equal('numbers',$filterId)->equal('destination','0'))->read();
            $session->update(['status'=>'active']);
            return $session->fresh(['customer:id,full_name,account_number','router:id,name']);
        } catch (Throwable $exception) {
            try { $this->removeOwnedRules($client, $comment); } catch (Throwable) {}
            $session->update(['status'=>'failed','last_error'=>$exception->getMessage(),'closed_at'=>now()]);
            throw $exception;
        }
    }

    public function close(OnuRemoteSession $session): void
    {
        if (in_array($session->status, ['closed','expired'], true)) return;
        try {
            $this->removeOwnedRules($this->client($session->router), $session->router_comment);
            $session->update(['status'=>now()->greaterThanOrEqualTo($session->expires_at)?'expired':'closed','closed_at'=>now(),'last_error'=>null]);
        } catch (Throwable $exception) {
            $session->update(['status'=>'cleanup_failed','last_error'=>$exception->getMessage()]);
            Log::error('ONU remote session cleanup failed', ['session_id'=>$session->id,'error'=>$exception->getMessage()]);
            throw $exception;
        }
    }

    public function cleanupExpired(): int
    {
        $count = 0;
        OnuRemoteSession::with('router')->whereIn('status',['active','creating','cleanup_failed'])->where('expires_at','<=',now())->each(function (OnuRemoteSession $session) use (&$count): void { try { $this->close($session); $count++; } catch (Throwable) {} });
        return $count;
    }

    public function cleanupAllOpen(): int
    {
        $count = 0;
        OnuRemoteSession::with('router')
            ->whereIn('status', ['active', 'creating', 'cleanup_failed'])
            ->each(function (OnuRemoteSession $session) use (&$count): void {
                try {
                    $this->close($session);
                    $count++;
                } catch (Throwable) {
                    // close() records cleanup_failed and the exact error for retry.
                }
            });

        return $count;
    }

    /** @return array{routers_scanned:int,rules_removed:int,routers_failed:int} */
    public function cleanupAllLegacyRouterRules(): array
    {
        $result = ['routers_scanned'=>0, 'rules_removed'=>0, 'routers_failed'=>0];

        Router::query()->each(function (Router $router) use (&$result): void {
            $result['routers_scanned']++;
            try {
                $client = $this->client($router);

                foreach (['/ip/firewall/nat', '/ip/firewall/filter'] as $menu) {
                    foreach ($client->query(new Query($menu.'/print'))->read() as $rule) {
                        $comment = (string) ($rule['comment'] ?? '');
                        $id = $rule['.id'] ?? null;

                        if ($id && str_starts_with($comment, self::COMMENT_PREFIX)) {
                            $client->query((new Query($menu.'/remove'))->equal('.id', $id))->read();
                            $result['rules_removed']++;
                        }
                    }
                }
            } catch (Throwable $exception) {
                $result['routers_failed']++;
                Log::error('Legacy ONU router-rule cleanup failed', [
                    'router_id'=>$router->id,
                    'router_name'=>$router->name,
                    'error'=>$exception->getMessage(),
                ]);
            }
        });

        return $result;
    }

    public function url(OnuRemoteSession $session): string
    {
        return ($session->target_port === 443 ? 'https' : 'http').'://'.$session->public_host.':'.$session->public_port.$session->path;
    }

    private function client(Router $router): Client
    {
        $config=(new Config())->set('host',$router->host)->set('user',$router->username)->set('pass',$router->password)->set('port',$router->port)->set('timeout',4)->set('socket_timeout',6)->set('attempts',1)->set('delay',1);
        return new Client($config);
    }

    private function availablePort(Client $client): int
    {
        for ($attempt=0;$attempt<30;$attempt++) {
            $port=random_int(40000,49999);
            if (OnuRemoteSession::where('public_port',$port)->whereIn('status',['creating','active','cleanup_failed'])->exists()) continue;
            if ($client->query((new Query('/ip/firewall/nat/print'))->where('dst-port',(string)$port))->read() === []) return $port;
        }
        throw new \RuntimeException('No safe temporary remote-access port is currently available.');
    }

    private function publicHost(array $cloud, Router $router): string
    {
        $dnsName = rtrim(trim((string) ($cloud['dns-name'] ?? '')), '.');
        if ($dnsName !== '' && filter_var($dnsName, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return $dnsName;
        }

        $publicAddress = trim((string) ($cloud['public-address'] ?? ''));
        if ($this->isPublicIpv4($publicAddress)) {
            return $publicAddress;
        }

        $configuredHost = trim((string) $router->host);
        if ($this->isPublicIpv4($configuredHost)) {
            return $configuredHost;
        }

        if (
            $configuredHost !== ''
            && filter_var($configuredHost, FILTER_VALIDATE_IP) === false
            && filter_var($configuredHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            && ! in_array(strtolower($configuredHost), ['localhost', 'localhost.localdomain'], true)
        ) {
            return rtrim($configuredHost, '.');
        }

        throw new \RuntimeException('No public MikroTik hostname or IPv4 address is available. Enable MikroTik IP Cloud/DDNS or configure a publicly reachable router host. Private and WireGuard addresses cannot be opened in a browser.');
    }

    private function isPublicIpv4(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function removeOwnedRules(Client $client, string $comment): void
    {
        foreach (['/ip/firewall/nat','/ip/firewall/filter'] as $menu) {
            $rules=$client->query((new Query($menu.'/print'))->where('comment',$comment))->read();
            foreach ($rules as $rule) if (!empty($rule['.id'])) $client->query((new Query($menu.'/remove'))->equal('.id',$rule['.id']))->read();
        }
    }
}
