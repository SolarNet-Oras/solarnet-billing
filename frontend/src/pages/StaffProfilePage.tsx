import { useRef, useState } from 'react';
import { Camera, Loader2, Trash2, UserRound } from 'lucide-react';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';

export default function StaffProfilePage() {
  const { user, refreshUser } = useAuth();
  const inputRef = useRef<HTMLInputElement>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const selectPhoto = (selected?: File): void => {
    if (!selected) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(selected.type) || selected.size > 4 * 1024 * 1024) {
      setError('Choose a JPG, PNG, or WebP image up to 4 MB.');
      return;
    }
    if (preview) URL.revokeObjectURL(preview);
    setPreview(URL.createObjectURL(selected));
    setFile(selected);
    setError(null);
    setMessage(null);
  };

  const upload = async (): Promise<void> => {
    if (!file) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      const body = new FormData();
      body.append('photo', file);
      await api.post('/auth/profile/photo', body, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      await refreshUser();
      if (preview) URL.revokeObjectURL(preview);
      setPreview(null); setFile(null);
      setMessage('Profile picture updated.');
    } catch (requestError: any) {
      const errors = requestError.response?.data?.errors;
      setError(errors ? Object.values(errors).flat().join(' ') : requestError.response?.data?.message || 'Could not upload the profile picture.');
    } finally { setBusy(false); }
  };

  const remove = async (): Promise<void> => {
    if (!user?.profile_photo_url || !window.confirm('Remove your profile picture?')) return;
    setBusy(true); setError(null); setMessage(null);
    try {
      await api.delete('/auth/profile/photo');
      await refreshUser();
      setPreview(null); setFile(null);
      setMessage('Profile picture removed.');
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not remove the profile picture.');
    } finally { setBusy(false); }
  };

  const roles = user?.roles?.map((role) => typeof role === 'string' ? role : role.display_name || role.name).join(', ') || 'Staff';

  return <DashboardLayout>
    <div className="mx-auto max-w-2xl space-y-5">
      <div><h1 className="text-2xl font-bold text-foreground">My Profile</h1><p className="mt-1 text-sm text-muted-foreground">Manage the picture shown on your SolarNet staff account.</p></div>
      <section className="rounded-2xl border border-border bg-card p-5 shadow-sm sm:p-7">
        <div className="flex flex-col items-center gap-5 sm:flex-row sm:items-start">
          <div className="relative h-32 w-32 shrink-0 overflow-hidden rounded-full border-4 border-background bg-primary text-primary-foreground shadow-lg">
            {preview || user?.profile_photo_url ? <img src={preview || user?.profile_photo_url || ''} alt={`${user?.name || 'Staff'} profile`} className="h-full w-full object-cover" /> : <div className="flex h-full w-full items-center justify-center"><UserRound className="h-14 w-14" /></div>}
          </div>
          <div className="w-full min-w-0 space-y-3 text-center sm:text-left">
            <div><p className="truncate text-xl font-semibold text-foreground">{user?.name}</p><p className="truncate text-sm text-muted-foreground">{user?.email}</p><p className="mt-1 text-xs font-medium uppercase tracking-wide text-primary">{roles}</p></div>
            <input ref={inputRef} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(event) => { selectPhoto(event.target.files?.[0]); event.currentTarget.value = ''; }} />
            {file && <p className="truncate text-sm font-medium text-foreground">Selected: {file.name}</p>}
            <div className="flex flex-wrap justify-center gap-2 sm:justify-start">
              <button type="button" disabled={busy} onClick={() => inputRef.current?.click()} className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-60"><Camera className="h-4 w-4" />Choose picture</button>
              {file && <button type="button" disabled={busy} onClick={() => void upload()} className="inline-flex items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-semibold text-foreground disabled:opacity-60">{busy && <Loader2 className="h-4 w-4 animate-spin" />}Save picture</button>}
              {user?.profile_photo_url && !file && <button type="button" disabled={busy} onClick={() => void remove()} className="inline-flex items-center gap-2 rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 disabled:opacity-60 dark:text-red-300"><Trash2 className="h-4 w-4" />Remove</button>}
            </div>
            <p className="text-xs leading-5 text-muted-foreground">JPG, PNG, or WebP · maximum 4 MB · minimum 128 × 128 pixels.</p>
          </div>
        </div>
        {message && <p className="mt-5 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">{message}</p>}
        {error && <p className="mt-5 rounded-lg bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950/30 dark:text-red-200">{error}</p>}
      </section>
    </div>
  </DashboardLayout>;
}
