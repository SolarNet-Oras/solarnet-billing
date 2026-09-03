import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Cable, CircleDot, Crosshair, Edit3, Layers3, MapPinned, MapPin, Navigation, Network, Plus, RefreshCw, Search, Trash2, Wifi, WifiOff, ZoomIn, ZoomOut } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';

type Coordinates = { latitude: number; longitude: number };
type MapView = Coordinates & { zoom: number };
type NetworkState = 'online' | 'offline' | 'restricted' | 'unknown';
type ClientPin = {
  id: string;
  account_number: string;
  full_name: string;
  address: string | null;
  customer_status: string;
  latitude: number;
  longitude: number;
  location_source: string;
  network_state: NetworkState;
  network_label: string;
  lease: { ip_address: string | null; status: string; last_seen_at: string | null; router_name: string | null } | null;
};
type AssetType = 'nap' | 'pole' | 'fiber_route';
type MapAsset = {
  id: string;
  asset_type: AssetType;
  name: string;
  latitude: number | null;
  longitude: number | null;
  route_coordinates: Coordinates[] | null;
  status: string;
  notes: string | null;
  created_by: { id: string; name: string } | null;
  updated_at: string | null;
};
type StaffLocation = Coordinates & {
  user_id: string;
  name: string;
  role: string;
  accuracy_meters: number | null;
  captured_at: string;
};
type MapData = {
  clients: ClientPin[];
  assets: MapAsset[];
  staff_locations: StaffLocation[];
  summary: {
    mapped_clients: number;
    unmapped_clients: number;
    network_states: Record<NetworkState, number>;
    assets: { naps: number; poles: number; fiber_routes: number };
  };
  source_note: string;
  generated_at: string;
};
type LayerKey = 'clients' | 'status' | 'naps' | 'poles' | 'fiber';
type AssetForm = {
  id?: string;
  asset_type: AssetType;
  name: string;
  latitude: string;
  longitude: string;
  route_coordinates: string;
  status: string;
  notes: string;
};
type Bounds = { minLatitude: number; maxLatitude: number; minLongitude: number; maxLongitude: number };

const MAP_WIDTH = 1000;
const MAP_HEIGHT = 560;
const TILE_SIZE = 256;
const MIN_ZOOM = 11;
const MAX_ZOOM = 18;
const MAX_MERCATOR_LATITUDE = 85.05112878;
const defaultLayers: Record<LayerKey, boolean> = { clients: true, status: true, naps: true, poles: true, fiber: true };
const emptyAssetForm = (): AssetForm => ({ asset_type: 'nap', name: '', latitude: '', longitude: '', route_coordinates: '', status: 'active', notes: '' });
const STATE_STYLE: Record<NetworkState, { label: string; marker: string; chip: string }> = {
  online: { label: 'Live DHCP lease', marker: '#10b981', chip: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300' },
  offline: { label: 'No current DHCP lease', marker: '#ef4444', chip: 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300' },
  restricted: { label: 'Billing restricted', marker: '#f59e0b', chip: 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300' },
  unknown: { label: 'Network state unavailable', marker: '#64748b', chip: 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200' },
};
const layerMeta: Array<{ key: LayerKey; label: string }> = [
  { key: 'clients', label: 'Clients' },
  { key: 'status', label: 'Service status' },
  { key: 'naps', label: 'NAPs' },
  { key: 'poles', label: 'Pole attachments' },
  { key: 'fiber', label: 'Fiber lines' },
];

const assetLabel = (type: AssetType): string => ({ nap: 'NAP', pole: 'Pole attachment', fiber_route: 'Fiber optic line' })[type];
const isCoordinate = (value: unknown): value is Coordinates => typeof value === 'object' && value !== null && Number.isFinite(Number((value as Coordinates).latitude)) && Number.isFinite(Number((value as Coordinates).longitude));

function clamp(value: number, minimum: number, maximum: number): number {
  return Math.min(Math.max(value, minimum), maximum);
}

function mapBounds(clients: ClientPin[], assets: MapAsset[]): Bounds | null {
  const points: Coordinates[] = [
    ...clients.map((client) => ({ latitude: client.latitude, longitude: client.longitude })),
    ...assets.flatMap((asset) => asset.asset_type === 'fiber_route'
      ? (asset.route_coordinates || []).filter(isCoordinate)
      : (asset.latitude !== null && asset.longitude !== null ? [{ latitude: asset.latitude, longitude: asset.longitude }] : [])),
  ];
  if (!points.length) return null;
  return {
    minLatitude: Math.min(...points.map((point) => point.latitude)),
    maxLatitude: Math.max(...points.map((point) => point.latitude)),
    minLongitude: Math.min(...points.map((point) => point.longitude)),
    maxLongitude: Math.max(...points.map((point) => point.longitude)),
  };
}

function worldPoint(point: Coordinates, zoom: number): { x: number; y: number } {
  const scale = TILE_SIZE * (2 ** zoom);
  const latitude = clamp(point.latitude, -MAX_MERCATOR_LATITUDE, MAX_MERCATOR_LATITUDE);
  const radians = (latitude * Math.PI) / 180;
  const mercator = Math.log(Math.tan(radians) + (1 / Math.cos(radians)));
  return {
    x: ((point.longitude + 180) / 360) * scale,
    y: ((1 - (mercator / Math.PI)) / 2) * scale,
  };
}

function coordinatesFromWorld(point: { x: number; y: number }, zoom: number): Coordinates {
  const scale = TILE_SIZE * (2 ** zoom);
  const wrappedX = ((point.x % scale) + scale) % scale;
  const mercator = Math.PI - ((2 * Math.PI * point.y) / scale);
  return {
    longitude: (wrappedX / scale) * 360 - 180,
    latitude: clamp((180 / Math.PI) * Math.atan(Math.sinh(mercator)), -MAX_MERCATOR_LATITUDE, MAX_MERCATOR_LATITUDE),
  };
}

function fitMapView(bounds: Bounds): MapView {
  const center: Coordinates = {
    latitude: (bounds.minLatitude + bounds.maxLatitude) / 2,
    longitude: (bounds.minLongitude + bounds.maxLongitude) / 2,
  };
  const southWest = worldPoint({ latitude: bounds.minLatitude, longitude: bounds.minLongitude }, 0);
  const northEast = worldPoint({ latitude: bounds.maxLatitude, longitude: bounds.maxLongitude }, 0);
  const longitudePixels = Math.max(Math.abs(northEast.x - southWest.x), 0.01);
  const latitudePixels = Math.max(Math.abs(northEast.y - southWest.y), 0.01);
  const zoom = Math.floor(Math.min(
    Math.log2((MAP_WIDTH * 0.78) / longitudePixels),
    Math.log2((MAP_HEIGHT * 0.72) / latitudePixels),
  ));
  return { ...center, zoom: clamp(zoom, MIN_ZOOM, MAX_ZOOM) };
}

function screenPoint(point: Coordinates, view: MapView): { x: number; y: number } {
  const coordinate = worldPoint(point, view.zoom);
  const center = worldPoint(view, view.zoom);
  const worldSize = TILE_SIZE * (2 ** view.zoom);
  let deltaX = coordinate.x - center.x;
  if (deltaX > worldSize / 2) deltaX -= worldSize;
  if (deltaX < -worldSize / 2) deltaX += worldSize;
  return { x: (MAP_WIDTH / 2) + deltaX, y: (MAP_HEIGHT / 2) + coordinate.y - center.y };
}

function mapTiles(view: MapView): Array<{ id: string; href: string; x: number; y: number }> {
  const center = worldPoint(view, view.zoom);
  const tilesAcross = 2 ** view.zoom;
  const minimumX = Math.floor((center.x - (MAP_WIDTH / 2)) / TILE_SIZE);
  const maximumX = Math.floor((center.x + (MAP_WIDTH / 2)) / TILE_SIZE);
  const minimumY = Math.floor((center.y - (MAP_HEIGHT / 2)) / TILE_SIZE);
  const maximumY = Math.floor((center.y + (MAP_HEIGHT / 2)) / TILE_SIZE);
  const tiles: Array<{ id: string; href: string; x: number; y: number }> = [];
  for (let tileY = minimumY; tileY <= maximumY; tileY += 1) {
    if (tileY < 0 || tileY >= tilesAcross) continue;
    for (let tileX = minimumX; tileX <= maximumX; tileX += 1) {
      const wrappedX = ((tileX % tilesAcross) + tilesAcross) % tilesAcross;
      tiles.push({
        id: `${view.zoom}/${wrappedX}/${tileY}`,
        href: `https://tile.openstreetmap.org/${view.zoom}/${wrappedX}/${tileY}.png`,
        x: (tileX * TILE_SIZE) - center.x + (MAP_WIDTH / 2),
        y: (tileY * TILE_SIZE) - center.y + (MAP_HEIGHT / 2),
      });
    }
  }
  return tiles;
}

function parseRouteCoordinates(value: string): Coordinates[] {
  const points = value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean).map((line, index) => {
    const [latitude, longitude, ...extra] = line.split(',').map((item) => item.trim());
    if (extra.length || !latitude || !longitude || !Number.isFinite(Number(latitude)) || !Number.isFinite(Number(longitude))) {
      throw new Error(`Line ${index + 1} must use latitude, longitude.`);
    }
    return { latitude: Number(latitude), longitude: Number(longitude) };
  });
  if (points.length < 2) throw new Error('A fiber route needs at least two coordinate points.');
  return points;
}

export default function OperationsMapPage(): React.JSX.Element {
  const { user } = useAuth();
  const [data, setData] = useState<MapData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [query, setQuery] = useState('');
  const [layers, setLayers] = useState(defaultLayers);
  const [selectedKey, setSelectedKey] = useState('');
  const [mapView, setMapView] = useState<MapView | null>(null);
  const [assetForm, setAssetForm] = useState<AssetForm | null>(null);
  const [savingAsset, setSavingAsset] = useState(false);
  const [capturingLocation, setCapturingLocation] = useState(false);
  const roles = [user?.role, ...(user?.roles || []).map((role) => typeof role === 'string' ? role : role.name)].filter(Boolean);
  const canManageAssets = ['super_admin', 'admin', 'technician', 'noc'].some((role) => roles.includes(role));
  const isFieldStaff = ['collector', 'technician'].some((role) => roles.includes(role));
  const canViewStaff = ['super_admin', 'admin'].some((role) => roles.includes(role));

  const load = useCallback(async (): Promise<void> => {
    setLoading(true);
    setError('');
    try {
      const response = await api.get('/operations-map');
      setData(response.data.data as MapData);
    } catch (requestError: unknown) {
      const response = requestError as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Could not load the Operations Map.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => {
    if (!canViewStaff) return undefined;
    const refresh = window.setInterval(() => { void load(); }, 30000);
    return () => window.clearInterval(refresh);
  }, [canViewStaff, load]);


  const visibleClients = useMemo(() => {
    const term = query.trim().toLowerCase();
    const staffPins: ClientPin[] = canViewStaff ? (data?.staff_locations || []).map((staff) => ({
      id: `staff-${staff.user_id}`,
      account_number: (staff.role || 'field staff').replaceAll('_', ' ').toUpperCase(),
      full_name: staff.name,
      address: `Live staff GPS · accuracy ${staff.accuracy_meters ? Math.round(staff.accuracy_meters) + ' m' : 'unknown'}`,
      customer_status: 'live field staff',
      latitude: staff.latitude,
      longitude: staff.longitude,
      location_source: `staff_live:${staff.captured_at}`,
      network_state: 'unknown',
      network_label: `Live ${staff.role || 'field staff'} location`,
      lease: null,
    })) : [];
    const pins = [...(data?.clients || []), ...staffPins];
    if (!term) return pins;
    return pins.filter((client) => `${client.full_name} ${client.account_number} ${client.address || ''}`.toLowerCase().includes(term));
  }, [canViewStaff, data?.clients, data?.staff_locations, query]);
  const bounds = useMemo(() => mapBounds(visibleClients, data?.assets || []), [visibleClients, data?.assets]);
  useEffect(() => { setMapView(bounds ? fitMapView(bounds) : null); }, [bounds]);

  const selectedClient = (data?.clients || []).find((client) => selectedKey === `client:${client.id}`) || null;
  const selectedAsset = (data?.assets || []).find((asset) => selectedKey === `asset:${asset.id}`) || null;
  const selectedCoordinate: Coordinates | null = (() => {
    if (selectedClient) return { latitude: selectedClient.latitude, longitude: selectedClient.longitude };
    if (!selectedAsset) return null;
    if (selectedAsset.asset_type === 'fiber_route') return selectedAsset.route_coordinates?.[0] || null;
    return selectedAsset.latitude !== null && selectedAsset.longitude !== null
      ? { latitude: selectedAsset.latitude, longitude: selectedAsset.longitude }
      : null;
  })();

  const toggleLayer = (key: LayerKey): void => setLayers((current) => ({ ...current, [key]: !current[key] }));
  const openNewAsset = (): void => { setError(''); setAssetForm(emptyAssetForm()); };
  const openEditAsset = (asset: MapAsset): void => setAssetForm({
    id: asset.id,
    asset_type: asset.asset_type,
    name: asset.name,
    latitude: asset.latitude?.toString() || '',
    longitude: asset.longitude?.toString() || '',
    route_coordinates: (asset.route_coordinates || []).map((point) => `${point.latitude}, ${point.longitude}`).join('\n'),
    status: asset.status || 'active',
    notes: asset.notes || '',
  });
  const captureAssetLocation = (): void => {
    if (!assetForm) return;
    if (!navigator.geolocation) { setError('This device cannot provide GPS location. Enter verified coordinates manually.'); return; }
    setCapturingLocation(true);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setAssetForm((current) => current ? { ...current, latitude: position.coords.latitude.toFixed(6), longitude: position.coords.longitude.toFixed(6) } : current);
        setCapturingLocation(false);
      },
      () => { setCapturingLocation(false); setError('Location permission was not granted or GPS could not be read.'); },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    );
  };
  const saveAsset = async (): Promise<void> => {
    if (!assetForm) return;
    setSavingAsset(true);
    setError('');
    try {
      const payload: Record<string, unknown> = { asset_type: assetForm.asset_type, name: assetForm.name, status: assetForm.status, notes: assetForm.notes || null };
      if (assetForm.asset_type === 'fiber_route') payload.route_coordinates = parseRouteCoordinates(assetForm.route_coordinates);
      else {
        const latitude = Number(assetForm.latitude);
        const longitude = Number(assetForm.longitude);
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) throw new Error('Enter valid verified latitude and longitude values.');
        payload.latitude = latitude;
        payload.longitude = longitude;
      }
      const response = assetForm.id ? await api.put(`/operations-map/assets/${assetForm.id}`, payload) : await api.post('/operations-map/assets', payload);
      setData(response.data.data as MapData);
      setAssetForm(null);
    } catch (requestError: unknown) {
      const response = requestError as { response?: { data?: { message?: string; errors?: Record<string, string[]> } }; message?: string };
      setError(response.response?.data?.errors ? Object.values(response.response.data.errors).flat().join(' ') : response.response?.data?.message || response.message || 'Could not save this map asset.');
    } finally {
      setSavingAsset(false);
    }
  };
  const deleteAsset = async (asset: MapAsset): Promise<void> => {
    if (!window.confirm(`Remove ${asset.name} from the Operations Map? This does not affect routers, OLTs, or clients.`)) return;
    setError('');
    try {
      const response = await api.delete(`/operations-map/assets/${asset.id}`);
      setData(response.data.data as MapData);
      if (selectedKey === `asset:${asset.id}`) setSelectedKey('');
    } catch (requestError: unknown) {
      const response = requestError as { response?: { data?: { message?: string } } };
      setError(response.response?.data?.message || 'Could not remove this map asset.');
    }
  };

  return <DashboardLayout><main className="mx-auto max-w-[1600px] space-y-4">
    <header className="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4 sm:p-5 lg:flex-row lg:items-center lg:justify-between"><div><div className="flex items-center gap-2"><MapPinned className="h-5 w-5 text-primary" /><h1 className="text-xl font-bold text-foreground sm:text-2xl">Operations Map</h1></div><p className="mt-1 max-w-3xl text-sm text-muted-foreground">A shared geographic view of saved client locations, mapped service coverage, NAPs, pole attachments, and fiber routes.</p></div><div className="flex flex-wrap items-center gap-2"><span className="rounded-lg bg-muted px-3 py-2 text-xs font-medium text-muted-foreground">Updated {data?.generated_at ? new Date(data.generated_at).toLocaleString('en-PH') : '—'}</span><button type="button" onClick={() => void load()} disabled={loading} className="inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-3 text-sm font-semibold text-primary-foreground disabled:opacity-60"><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />Refresh</button></div></header>
    {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800 dark:border-rose-900/70 dark:bg-rose-950/40 dark:text-rose-200">{error}</p>}
    {isFieldStaff && <section className="rounded-2xl border border-sky-500/30 bg-sky-500/10 p-4"><h2 className="font-semibold text-foreground">Field location supervision</h2><p className="mt-1 text-xs text-muted-foreground">Required work-location sharing starts automatically throughout the signed-in staff application. It is visible only to authorized administrators and becomes inactive when the app can no longer send updates.</p></section>}
    {canViewStaff && <section className="rounded-2xl border border-border bg-card p-4"><div className="flex items-center justify-between gap-3"><div><h2 className="font-semibold text-foreground">Live field team</h2><p className="text-xs text-muted-foreground">Collector and technician positions updated within the last five minutes.</p></div><span className="rounded-full bg-sky-500/10 px-3 py-1 text-xs font-bold text-sky-700 dark:text-sky-300">{data?.staff_locations?.length || 0} sharing</span></div><div className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">{(data?.staff_locations || []).map((staff) => <button type="button" key={staff.user_id} onClick={() => { setQuery(staff.name); setSelectedKey(`client:staff-${staff.user_id}`); }} className="rounded-xl border border-border bg-background p-3 text-left hover:border-sky-500"><p className="font-semibold text-foreground">{staff.name}</p><p className="text-xs capitalize text-muted-foreground">{staff.role} · {staff.accuracy_meters ? `±${Math.round(staff.accuracy_meters)} m` : 'accuracy unavailable'}</p><p className="mt-1 text-[11px] text-muted-foreground">Updated {new Date(staff.captured_at).toLocaleTimeString('en-PH')}</p></button>)}{!data?.staff_locations?.length && <p className="text-sm text-muted-foreground">No recent field-staff location update.</p>}</div></section>}
    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><SummaryCard icon={<MapPin className="h-4 w-4 text-sky-600" />} label="Mapped clients" value={data?.summary.mapped_clients ?? 0} detail={`${data?.summary.unmapped_clients ?? 0} need coordinates`} /><SummaryCard icon={<Wifi className="h-4 w-4 text-emerald-600" />} label="Live DHCP lease" value={data?.summary.network_states.online ?? 0} detail="Latest RouterOS lease sync" /><SummaryCard icon={<WifiOff className="h-4 w-4 text-rose-600" />} label="No current lease" value={data?.summary.network_states.offline ?? 0} detail={`${data?.summary.network_states.restricted ?? 0} billing restricted`} /><SummaryCard icon={<Network className="h-4 w-4 text-violet-600" />} label="Physical network map" value={(data?.summary.assets.naps ?? 0) + (data?.summary.assets.poles ?? 0) + (data?.summary.assets.fiber_routes ?? 0)} detail={`${data?.summary.assets.naps ?? 0} NAPs · ${data?.summary.assets.poles ?? 0} poles · ${data?.summary.assets.fiber_routes ?? 0} routes`} /></section>
    <section className="rounded-2xl border border-border bg-card p-3 sm:p-4"><div className="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between"><div><div className="flex items-center gap-2"><Layers3 className="h-4 w-4 text-primary" /><h2 className="font-semibold text-foreground">Map layers</h2></div><p className="mt-1 text-xs text-muted-foreground">Choose one or more layers to appear on the map.</p></div><div className="flex flex-wrap gap-2"><button type="button" onClick={() => setLayers(defaultLayers)} className="rounded-lg border border-primary/30 bg-primary/10 px-3 py-2 text-xs font-bold text-primary">All layers</button>{layerMeta.map((layer) => <button key={layer.key} type="button" onClick={() => toggleLayer(layer.key)} aria-pressed={layers[layer.key]} className={`rounded-lg border px-3 py-2 text-xs font-semibold transition-colors ${layers[layer.key] ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background text-muted-foreground hover:bg-muted'}`}>{layer.label}</button>)}</div></div><div className="relative mt-4"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search client name, account number, or address" className="w-full rounded-lg border border-input bg-background py-2.5 pl-10 pr-3 text-sm text-foreground lg:max-w-lg" /></div></section>
    <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_330px]"><article className="overflow-hidden rounded-2xl border border-border bg-slate-950 shadow-inner"><div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 bg-slate-900/70 px-4 py-3"><div><h2 className="font-semibold text-white">Service-area responsibility</h2><p className="mt-0.5 text-xs text-slate-300">OpenStreetMap base map with saved operational overlays—not a legal boundary.</p></div><MapLegend showStatus={layers.status} /></div>{!bounds || !mapView ? <EmptyMap /> : <RealOperationsMap view={mapView} clients={visibleClients} assets={data?.assets || []} layers={layers} selectedKey={selectedKey} onSelect={setSelectedKey} onChangeView={setMapView} onFit={() => setMapView(fitMapView(bounds))} />}</article><aside className="rounded-2xl border border-border bg-card p-4"><h2 className="font-semibold text-foreground">Selected map item</h2>{selectedClient ? <SelectedClient client={selectedClient} /> : selectedAsset ? <SelectedAsset asset={selectedAsset} /> : <p className="mt-3 text-sm text-muted-foreground">Select a client pin, NAP, pole, or fiber line to inspect it and open directions.</p>}{selectedCoordinate && <a href={`https://www.google.com/maps/dir/?api=1&destination=${selectedCoordinate.latitude},${selectedCoordinate.longitude}`} target="_blank" rel="noreferrer" className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-3 py-2.5 text-sm font-semibold text-primary-foreground"><Navigation className="h-4 w-4" />Open Google Maps</a>}<div className="mt-5 border-t pt-4"><p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Network indicator source</p><p className="mt-1 text-xs leading-5 text-muted-foreground">{data?.source_note || 'Loading source details…'}</p></div></aside></section>
    <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]"><article className="rounded-2xl border border-border bg-card p-4 sm:p-5"><div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-semibold text-foreground">Mapped network assets</h2><p className="mt-1 text-sm text-muted-foreground">Field-verified NAPs, pole attachments, and fiber lines. These records do not change network equipment.</p></div>{canManageAssets && <button type="button" onClick={openNewAsset} className="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground"><Plus className="h-4 w-4" />Add map asset</button>}</div><div className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">{(data?.assets || []).map((asset) => <AssetCard key={asset.id} asset={asset} selected={selectedKey === `asset:${asset.id}`} editable={canManageAssets} onSelect={() => setSelectedKey(`asset:${asset.id}`)} onEdit={() => openEditAsset(asset)} onDelete={() => void deleteAsset(asset)} />)}{!loading && !(data?.assets || []).length && <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground md:col-span-2 xl:col-span-3">No physical network assets are recorded yet. Add only a NAP, pole attachment, or fiber route verified in the field.</div>}</div></article>{canManageAssets && <AssetEditor form={assetForm} busy={savingAsset} capturingLocation={capturingLocation} onChange={setAssetForm} onCapture={captureAssetLocation} onSave={() => void saveAsset()} onCancel={() => setAssetForm(null)} />}</section>
  </main></DashboardLayout>;
}

function EmptyMap(): React.JSX.Element { return <div className="flex min-h-[420px] items-center justify-center p-6 text-center text-sm text-slate-300"><div><MapPinned className="mx-auto h-10 w-10 text-slate-500" /><p className="mt-3 font-semibold text-white">No mapped coordinates yet</p><p className="mt-1 max-w-sm">Capture client coordinates or add verified NAP, pole, or fiber-route locations to begin building the service-area map.</p></div></div>; }

function RealOperationsMap({ view, clients, assets, layers, selectedKey, onSelect, onChangeView, onFit }: { view: MapView; clients: ClientPin[]; assets: MapAsset[]; layers: Record<LayerKey, boolean>; selectedKey: string; onSelect: (key: string) => void; onChangeView: (view: MapView) => void; onFit: () => void }): React.JSX.Element {
  const tiles = useMemo(() => mapTiles(view), [view]);
  const zoom = (change: number): void => onChangeView({ ...view, zoom: clamp(view.zoom + change, MIN_ZOOM, MAX_ZOOM) });
  const point = (coordinates: Coordinates): { x: number; y: number } => screenPoint(coordinates, view);
  const drag = useRef<{ pointerId: number; clientX: number; clientY: number; view: MapView; moved: boolean } | null>(null);
  const suppressPinClick = useRef(false);
  const latestView = useRef(view);

  useEffect(() => { latestView.current = view; }, [view]);

  useEffect(() => {
    const canvas = document.querySelector<SVGSVGElement>('svg[aria-label="OpenStreetMap with SolarNet client and infrastructure overlays"]');
    if (!canvas) return undefined;

    canvas.style.cursor = 'grab';
    canvas.style.touchAction = 'none';
    const onPointerDown = (event: PointerEvent): void => {
      if (event.button !== 0) return;
      canvas.setPointerCapture(event.pointerId);
      drag.current = { pointerId: event.pointerId, clientX: event.clientX, clientY: event.clientY, view: latestView.current, moved: false };
    };
    const onPointerMove = (event: PointerEvent): void => {
      const activeDrag = drag.current;
      if (!activeDrag || activeDrag.pointerId !== event.pointerId) return;
      const deltaX = event.clientX - activeDrag.clientX;
      const deltaY = event.clientY - activeDrag.clientY;
      if (Math.abs(deltaX) > 3 || Math.abs(deltaY) > 3) activeDrag.moved = true;
      if (!activeDrag.moved) return;
      const bounds = canvas.getBoundingClientRect();
      const start = worldPoint(activeDrag.view, activeDrag.view.zoom);
      const center = coordinatesFromWorld({
        x: start.x - ((deltaX / bounds.width) * MAP_WIDTH),
        y: start.y - ((deltaY / bounds.height) * MAP_HEIGHT),
      }, activeDrag.view.zoom);
      onChangeView({ ...center, zoom: activeDrag.view.zoom });
    };
    const endDrag = (event: PointerEvent): void => {
      const activeDrag = drag.current;
      if (!activeDrag || activeDrag.pointerId !== event.pointerId) return;
      suppressPinClick.current = activeDrag.moved;
      drag.current = null;
      if (canvas.hasPointerCapture(event.pointerId)) canvas.releasePointerCapture(event.pointerId);
    };
    const suppressClick = (event: MouseEvent): void => {
      if (!suppressPinClick.current) return;
      event.preventDefault();
      event.stopPropagation();
      suppressPinClick.current = false;
    };

    canvas.addEventListener('pointerdown', onPointerDown);
    canvas.addEventListener('pointermove', onPointerMove);
    canvas.addEventListener('pointerup', endDrag);
    canvas.addEventListener('pointercancel', endDrag);
    canvas.addEventListener('click', suppressClick, true);
    return () => {
      canvas.removeEventListener('pointerdown', onPointerDown);
      canvas.removeEventListener('pointermove', onPointerMove);
      canvas.removeEventListener('pointerup', endDrag);
      canvas.removeEventListener('pointercancel', endDrag);
      canvas.removeEventListener('click', suppressClick, true);
    };
  }, [onChangeView]);
  return <div className="relative min-h-[420px] overflow-hidden bg-slate-200"><svg viewBox={`0 0 ${MAP_WIDTH} ${MAP_HEIGHT}`} className="block min-h-[420px] w-full" role="img" aria-label="OpenStreetMap with SolarNet client and infrastructure overlays"><rect width={MAP_WIDTH} height={MAP_HEIGHT} fill="#cbd5e1" />{tiles.map((tile) => <image key={tile.id} href={tile.href} x={tile.x} y={tile.y} width={TILE_SIZE} height={TILE_SIZE} preserveAspectRatio="none" />)}<defs><filter id="operations-map-glow"><feGaussianBlur stdDeviation="3" result="blur" /><feMerge><feMergeNode in="blur" /><feMergeNode in="SourceGraphic" /></feMerge></filter></defs>{layers.fiber && assets.filter((asset) => asset.asset_type === 'fiber_route' && (asset.route_coordinates || []).length > 1).map((asset) => <polyline key={asset.id} points={(asset.route_coordinates || []).map((coordinates) => { const position = point(coordinates); return `${position.x},${position.y}`; }).join(' ')} fill="none" stroke="#0284c7" strokeWidth="5" strokeLinecap="round" strokeLinejoin="round" opacity=".9" onClick={() => onSelect(`asset:${asset.id}`)} className="cursor-pointer" />)}{layers.clients && clients.map((client) => { const position = point(client); const selected = selectedKey === `client:${client.id}`; const color = layers.status ? STATE_STYLE[client.network_state].marker : '#0284c7'; return <g key={client.id} role="button" tabIndex={0} onClick={() => onSelect(`client:${client.id}`)} onKeyDown={(event) => { if (event.key === 'Enter' || event.key === ' ') onSelect(`client:${client.id}`); }} className="cursor-pointer"><circle cx={position.x} cy={position.y} r={selected ? 16 : 12} fill={color} opacity=".3" filter="url(#operations-map-glow)" /><circle cx={position.x} cy={position.y} r={selected ? 8 : 6} fill={color} stroke="#fff" strokeWidth={selected ? 3 : 2} /><title>{`${client.full_name} · ${client.network_label}`}</title></g>; })}{layers.naps && assets.filter((asset) => asset.asset_type === 'nap' && asset.latitude !== null && asset.longitude !== null).map((asset) => { const position = point({ latitude: asset.latitude as number, longitude: asset.longitude as number }); const selected = selectedKey === `asset:${asset.id}`; return <g key={asset.id} role="button" tabIndex={0} onClick={() => onSelect(`asset:${asset.id}`)} onKeyDown={(event) => { if (event.key === 'Enter' || event.key === ' ') onSelect(`asset:${asset.id}`); }} className="cursor-pointer"><rect x={position.x - 9} y={position.y - 9} width="18" height="18" rx="4" fill="#7e22ce" stroke={selected ? '#fff' : '#f3e8ff'} strokeWidth={selected ? 3 : 1.5} /><title>{`NAP · ${asset.name}`}</title></g>; })}{layers.poles && assets.filter((asset) => asset.asset_type === 'pole' && asset.latitude !== null && asset.longitude !== null).map((asset) => { const position = point({ latitude: asset.latitude as number, longitude: asset.longitude as number }); const selected = selectedKey === `asset:${asset.id}`; return <g key={asset.id} role="button" tabIndex={0} onClick={() => onSelect(`asset:${asset.id}`)} onKeyDown={(event) => { if (event.key === 'Enter' || event.key === ' ') onSelect(`asset:${asset.id}`); }} className="cursor-pointer"><path d={`M ${position.x} ${position.y - 10} L ${position.x} ${position.y + 10} M ${position.x - 7} ${position.y - 3} L ${position.x + 7} ${position.y - 3}`} stroke={selected ? '#fff' : '#b45309'} strokeWidth={selected ? 4.5 : 3.5} strokeLinecap="round" /><title>{`Pole attachment · ${asset.name}`}</title></g>; })}</svg><div className="absolute right-3 top-3 flex flex-col overflow-hidden rounded-lg border border-slate-300 bg-white shadow-lg"><button type="button" onClick={() => zoom(1)} disabled={view.zoom >= MAX_ZOOM} aria-label="Zoom in" className="grid h-9 w-9 place-items-center border-b border-slate-200 text-slate-800 hover:bg-slate-100 disabled:opacity-40"><ZoomIn className="h-4 w-4" /></button><button type="button" onClick={() => zoom(-1)} disabled={view.zoom <= MIN_ZOOM} aria-label="Zoom out" className="grid h-9 w-9 place-items-center text-slate-800 hover:bg-slate-100 disabled:opacity-40"><ZoomOut className="h-4 w-4" /></button></div><button type="button" onClick={onFit} className="absolute bottom-8 right-3 inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white/95 px-2.5 py-2 text-xs font-semibold text-slate-800 shadow hover:bg-white"><Crosshair className="h-3.5 w-3.5" />Fit service area</button><p className="absolute bottom-0 left-0 right-0 bg-slate-950/75 px-3 py-1.5 text-[11px] text-slate-100">{clients.length} displayed client pin{clients.length === 1 ? '' : 's'} · Base map © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noreferrer" className="underline">OpenStreetMap contributors</a></p></div>;
}

function SummaryCard({ icon, label, value, detail }: { icon: React.ReactNode; label: string; value: number; detail: string }): React.JSX.Element { return <article className="rounded-xl border border-border bg-card p-3.5"><div className="flex items-center gap-2 text-muted-foreground">{icon}<p className="text-xs font-semibold uppercase tracking-wide">{label}</p></div><p className="mt-2 text-2xl font-bold text-foreground">{value.toLocaleString('en-PH')}</p><p className="mt-1 text-xs text-muted-foreground">{detail}</p></article>; }
function MapLegend({ showStatus }: { showStatus: boolean }): React.JSX.Element { return <div className="flex flex-wrap gap-2 text-[11px] text-slate-200"><span className="inline-flex items-center gap-1"><i className="h-2.5 w-2.5 rounded-sm bg-violet-500" />NAP</span><span className="inline-flex items-center gap-1"><i className="h-2.5 w-2.5 rounded-sm bg-amber-400" />Pole</span><span className="inline-flex items-center gap-1"><i className="h-0.5 w-3 bg-sky-400" />Fiber</span>{showStatus && <><span className="inline-flex items-center gap-1"><i className="h-2.5 w-2.5 rounded-full bg-emerald-500" />Live lease</span><span className="inline-flex items-center gap-1"><i className="h-2.5 w-2.5 rounded-full bg-rose-500" />No lease</span><span className="inline-flex items-center gap-1"><i className="h-2.5 w-2.5 rounded-full bg-amber-500" />Restricted</span></>}</div>; }
function SelectedClient({ client }: { client: ClientPin }): React.JSX.Element { const style = STATE_STYLE[client.network_state]; return <div className="mt-3 space-y-2 text-sm"><div><p className="font-semibold text-foreground">{client.full_name}</p><p className="text-xs text-muted-foreground">{client.account_number} · {client.address || 'No address saved'}</p></div><span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${style.chip}`}>{style.label}</span><Detail label="Customer status" value={client.customer_status} /><Detail label="Lease" value={client.lease ? `${client.lease.ip_address || 'No IP'} · ${client.lease.status}` : 'No current lease'} /><Detail label="Router" value={client.lease?.router_name || 'Not supplied'} /><Detail label="Coordinates" value={`${client.latitude.toFixed(6)}, ${client.longitude.toFixed(6)}`} /><Detail label="Location source" value={client.location_source.replaceAll('_', ' ')} /></div>; }
function SelectedAsset({ asset }: { asset: MapAsset }): React.JSX.Element { const first = asset.asset_type === 'fiber_route' ? asset.route_coordinates?.[0] : null; return <div className="mt-3 space-y-2 text-sm"><div><p className="font-semibold text-foreground">{asset.name}</p><p className="text-xs text-muted-foreground">{assetLabel(asset.asset_type)} · {asset.status}</p></div><Detail label="Location" value={first ? `${asset.route_coordinates?.length || 0} mapped fiber points` : asset.latitude !== null && asset.longitude !== null ? `${asset.latitude.toFixed(6)}, ${asset.longitude.toFixed(6)}` : 'No coordinate saved'} />{asset.notes && <Detail label="Notes" value={asset.notes} />}{asset.created_by && <Detail label="Recorded by" value={asset.created_by.name} />}</div>; }
function Detail({ label, value }: { label: string; value: string }): React.JSX.Element { return <div><p className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{label}</p><p className="mt-0.5 break-words text-foreground">{value}</p></div>; }
function AssetCard({ asset, selected, editable, onSelect, onEdit, onDelete }: { asset: MapAsset; selected: boolean; editable: boolean; onSelect: () => void; onEdit: () => void; onDelete: () => void }): React.JSX.Element { const Icon = asset.asset_type === 'nap' ? CircleDot : asset.asset_type === 'pole' ? MapPin : Cable; return <article className={`rounded-xl border p-3 ${selected ? 'border-primary bg-primary/5' : 'border-border bg-background/50'}`}><button type="button" onClick={onSelect} className="w-full text-left"><div className="flex items-start gap-2"><Icon className="mt-0.5 h-4 w-4 shrink-0 text-primary" /><div className="min-w-0"><p className="truncate font-semibold text-foreground">{asset.name}</p><p className="mt-0.5 text-xs text-muted-foreground">{assetLabel(asset.asset_type)} · {asset.status}</p></div></div></button>{editable && <div className="mt-3 flex gap-2 border-t pt-2"><button type="button" onClick={onEdit} className="inline-flex items-center gap-1 text-xs font-semibold text-primary"><Edit3 className="h-3.5 w-3.5" />Edit</button><button type="button" onClick={onDelete} className="inline-flex items-center gap-1 text-xs font-semibold text-rose-600"><Trash2 className="h-3.5 w-3.5" />Remove</button></div>}</article>; }
function AssetEditor({ form, busy, capturingLocation, onChange, onCapture, onSave, onCancel }: { form: AssetForm | null; busy: boolean; capturingLocation: boolean; onChange: (form: AssetForm | null) => void; onCapture: () => void; onSave: () => void; onCancel: () => void }): React.JSX.Element { if (!form) return <aside className="rounded-2xl border border-dashed border-border bg-card p-5 text-sm text-muted-foreground"><Crosshair className="mb-3 h-7 w-7 text-primary" /><p className="font-semibold text-foreground">Map asset editor</p><p className="mt-1 leading-6">Add verified field references here. This is a geographic registry only; it never changes MikroTik, OLT, DHCP, or customer records.</p></aside>; const pointAsset = form.asset_type !== 'fiber_route'; return <aside className="rounded-2xl border border-primary/25 bg-card p-4 sm:p-5"><div className="flex items-start justify-between gap-3"><div><h2 className="font-semibold text-foreground">{form.id ? 'Edit map asset' : 'Add map asset'}</h2><p className="mt-1 text-xs text-muted-foreground">Save only after the location is verified in the field.</p></div><button type="button" onClick={onCancel} className="text-xs font-semibold text-muted-foreground hover:text-foreground">Cancel</button></div><div className="mt-4 space-y-3"><label className="block text-sm font-medium text-foreground">Asset type<select value={form.asset_type} onChange={(event) => onChange({ ...form, asset_type: event.target.value as AssetType })} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"><option value="nap">NAP</option><option value="pole">Pole attachment</option><option value="fiber_route">Fiber optic line</option></select></label><label className="block text-sm font-medium text-foreground">Name<input value={form.name} onChange={(event) => onChange({ ...form, name: event.target.value })} placeholder={form.asset_type === 'nap' ? 'NAP-01 Kalawit' : form.asset_type === 'pole' ? 'Pole P-18' : 'Kalawit backbone segment'} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm" /></label>{pointAsset ? <><div className="grid grid-cols-2 gap-2"><label className="block text-sm font-medium text-foreground">Latitude<input value={form.latitude} onChange={(event) => onChange({ ...form, latitude: event.target.value })} inputMode="decimal" placeholder="11.123456" className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm" /></label><label className="block text-sm font-medium text-foreground">Longitude<input value={form.longitude} onChange={(event) => onChange({ ...form, longitude: event.target.value })} inputMode="decimal" placeholder="125.123456" className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm" /></label></div><button type="button" onClick={onCapture} disabled={capturingLocation} className="inline-flex items-center gap-2 rounded-lg border border-primary/30 px-3 py-2 text-xs font-semibold text-primary disabled:opacity-60"><Crosshair className="h-3.5 w-3.5" />{capturingLocation ? 'Getting GPS…' : 'Use this device GPS'}</button></> : <label className="block text-sm font-medium text-foreground">Route coordinate points<textarea value={form.route_coordinates} onChange={(event) => onChange({ ...form, route_coordinates: event.target.value })} rows={6} placeholder={'11.123456, 125.123456\n11.123480, 125.123520'} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-xs" /><span className="mt-1 block text-xs text-muted-foreground">One verified latitude, longitude pair per line. The line is drawn only between these points.</span></label>}<label className="block text-sm font-medium text-foreground">Map status<select value={form.status} onChange={(event) => onChange({ ...form, status: event.target.value })} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"><option value="active">Active</option><option value="planned">Planned</option><option value="retired">Retired</option></select></label><label className="block text-sm font-medium text-foreground">Notes<textarea value={form.notes} onChange={(event) => onChange({ ...form, notes: event.target.value })} rows={3} placeholder="Field reference, feeder, or inspection note" className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm" /></label><button type="button" disabled={busy || !form.name.trim()} onClick={onSave} className="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-3 py-2.5 text-sm font-semibold text-primary-foreground disabled:opacity-60"><Plus className="h-4 w-4" />{busy ? 'Saving…' : form.id ? 'Save map asset' : 'Add map asset'}</button></div></aside>; }
