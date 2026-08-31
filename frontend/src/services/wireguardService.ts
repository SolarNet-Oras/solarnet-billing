import { api } from './api';

export interface WireguardRouter { id: string; name: string; host: string; connection_status: string }
export interface WireguardPeer {
  id: string; router_id: string; name: string; interface_name: string;
  router_public_key: string; server_public_key: string; server_endpoint: string;
  server_port: number; server_tunnel_address: string; peer_tunnel_address: string;
  router_listen_port: number; persistent_keepalive: number; enabled: boolean;
  rx_bytes: number; tx_bytes: number; last_tested_at?: string; last_test_status?: string;
  last_error?: string; router: WireguardRouter;
}
export type WireguardPeerInput = Omit<WireguardPeer, 'id' | 'rx_bytes' | 'tx_bytes' | 'last_tested_at' | 'last_test_status' | 'last_error' | 'router'>;

export const wireguardService = {
  async index(): Promise<{ peers: WireguardPeer[]; routers: WireguardRouter[]; safety: string }> {
    return (await api.get('/wireguard')).data.data;
  },
  async create(input: WireguardPeerInput): Promise<WireguardPeer> {
    return (await api.post('/wireguard/peers', input)).data.data;
  },
  async remove(id: string): Promise<void> { await api.delete(`/wireguard/peers/${id}`); },
  async scripts(id: string): Promise<{ mikrotik: string; vpsPeer: string; firewall: string }> {
    return (await api.get(`/wireguard/peers/${id}/scripts`)).data.data;
  },
  async inspect(id: string): Promise<{ message: string; data: Record<string, unknown> }> {
    const response = await api.post(`/wireguard/peers/${id}/inspect`); return response.data;
  },
  async test(id: string): Promise<{ message: string }> {
    return (await api.post(`/wireguard/peers/${id}/test`)).data;
  },
};
