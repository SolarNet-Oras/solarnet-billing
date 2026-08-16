import api from './api';

export interface SpeedtestConnection {
  public_ip: string | null;
  provider_display_name: string;
  detected_isp: string | null;
  detected_asn: string | null;
  detected_org: string | null;
  detected_city: string | null;
  detected_country: string | null;
}

export const speedtestService = {
  async connection(): Promise<SpeedtestConnection> {
    const response = await api.get<{ status: string; data: SpeedtestConnection }>('/speedtest/connection', {
      headers: { 'Cache-Control': 'no-store' },
    });

    return response.data.data;
  },
};
