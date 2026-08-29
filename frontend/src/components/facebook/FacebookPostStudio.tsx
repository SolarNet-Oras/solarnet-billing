import React, { useEffect, useRef, useState } from 'react';
import { CheckCircle2, CircleAlert, ImagePlus, Megaphone, RefreshCw, RotateCcw, Send, Sparkles, Trash2, Upload, X } from 'lucide-react';
import api from '@/services/api';

type PageConnection = { id: string; page_name: string; is_active: boolean };

type PostDraft = {
  id: string;
  connection_id: string;
  topic: string;
  message_text: string;
  status: 'draft' | 'publishing' | 'published' | 'failed' | string;
  facebook_post_id: string | null;
  has_image: boolean;
  last_error: string | null;
  created_at: string | null;
  published_at: string | null;
};

const formatDate = (value: string | null): string => value ? new Date(value).toLocaleString('en-PH') : 'Not yet';

export function FacebookPostStudio({ connections }: { connections: PageConnection[] }): React.JSX.Element {
  const [posts, setPosts] = useState<PostDraft[]>([]);
  const [connectionId, setConnectionId] = useState('');
  const [topic, setTopic] = useState('');
  const [details, setDetails] = useState('');
  const [messageText, setMessageText] = useState('');
  const [imageToken, setImageToken] = useState('');
  const [selectedImagePreview, setSelectedImagePreview] = useState('');
  const [selectedImageName, setSelectedImageName] = useState('');
  const [postImageUrls, setPostImageUrls] = useState<Record<string, string>>({});
  const [draggingImage, setDraggingImage] = useState(false);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const previewRef = useRef('');
  const postImageUrlsRef = useRef<Record<string, string>>({});

  const revokePreview = (value: string): void => {
    if (value.startsWith('blob:')) URL.revokeObjectURL(value);
  };

  const clearSelectedImage = (): void => {
    revokePreview(previewRef.current);
    previewRef.current = '';
    setImageToken('');
    setSelectedImagePreview('');
    setSelectedImageName('');
  };

  const setSelectedImage = (token: string, preview: string, name: string): void => {
    revokePreview(previewRef.current);
    previewRef.current = preview;
    setImageToken(token);
    setSelectedImagePreview(preview);
    setSelectedImageName(name);
  };

  const load = async (): Promise<void> => {
    try {
      const response = await api.get('/facebook-automation/posts');
      const rows = (response.data.data || []) as PostDraft[];
      const images = await Promise.all(rows.filter((post) => post.has_image).map(async (post) => {
        try {
          const imageResponse = await api.get(`/facebook-automation/posts/${post.id}/image`, { responseType: 'blob' });
          return [post.id, URL.createObjectURL(imageResponse.data as Blob)] as const;
        } catch {
          return null;
        }
      }));
      Object.values(postImageUrlsRef.current).forEach(revokePreview);
      const nextImageUrls = Object.fromEntries(images.filter((image): image is readonly [string, string] => image !== null));
      postImageUrlsRef.current = nextImageUrls;
      setPostImageUrls(nextImageUrls);
      setPosts(rows);
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not load the Facebook post history.');
    }
  };

  useEffect(() => { void load(); }, []);
  useEffect(() => {
    if (!connectionId) setConnectionId(connections.find((connection) => connection.is_active)?.id || '');
  }, [connectionId, connections]);
  useEffect(() => () => {
    revokePreview(previewRef.current);
    Object.values(postImageUrlsRef.current).forEach(revokePreview);
  }, []);

  const generate = async (): Promise<void> => {
    setBusy('generate'); setError(''); setNotice('');
    try {
      const response = await api.post('/facebook-automation/posts/generate', { topic: topic.trim(), details: details.trim() || undefined });
      setMessageText(response.data.message_text || '');
      setNotice('AI post text generated. Review and edit it before saving.');
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'AI could not prepare a post draft.');
    } finally { setBusy(''); }
  };

  const stageUploadedImage = async (file: File): Promise<void> => {
    if (!['image/jpeg', 'image/png'].includes(file.type)) {
      setError('Upload a PNG or JPEG image.');
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      setError('Upload an image no larger than 10 MB.');
      return;
    }

    setBusy('upload-image'); setError(''); setNotice('');
    const preview = URL.createObjectURL(file);
    try {
      const form = new FormData();
      form.append('image', file);
      const response = await api.post('/facebook-automation/posts/image-upload', form);
      setSelectedImage(response.data.image_token, preview, file.name);
      setNotice('Photo attached privately. It will be sent only if an administrator publishes this draft.');
    } catch (requestError: any) {
      URL.revokeObjectURL(preview);
      setError(requestError.response?.data?.message || 'Could not upload the selected photo.');
    } finally { setBusy(''); }
  };

  const generateImage = async (): Promise<void> => {
    if (!window.confirm('Generate one AI marketing image from this topic? This uses the server OpenAI image-generation budget and will not publish anything automatically.')) return;
    setBusy('generate-image'); setError(''); setNotice('');
    try {
      const response = await api.post('/facebook-automation/posts/image-generate', { topic: topic.trim(), details: details.trim() || undefined });
      setSelectedImage(response.data.image_token, response.data.preview_data_url, 'AI-generated marketing image');
      setNotice('AI image generated privately. Review it before saving the post draft.');
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'AI could not prepare a marketing image.');
    } finally { setBusy(''); }
  };

  const saveDraft = async (): Promise<void> => {
    setBusy('save'); setError(''); setNotice('');
    try {
      await api.post('/facebook-automation/posts', {
        connection_id: connectionId,
        topic: topic.trim(),
        message_text: messageText.trim(),
        image_token: imageToken || undefined,
      });
      setTopic(''); setDetails(''); setMessageText(''); clearSelectedImage();
      setNotice('Post saved as a draft. It has not been published.');
      await load();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not save this Facebook post draft.');
    } finally { setBusy(''); }
  };

  const publish = async (post: PostDraft): Promise<void> => {
    if (!window.confirm(`Publish this approved post${post.has_image ? ' and its attached photo' : ''} to the SolarNet Facebook Page now?\n\n${post.message_text}`)) return;
    setBusy(`publish:${post.id}`); setError(''); setNotice('');
    try {
      await api.post(`/facebook-automation/posts/${post.id}/publish`, { confirm_publish: true });
      setNotice('Post published to Facebook.');
      await load();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Facebook could not publish this post.');
      await load();
    } finally { setBusy(''); }
  };

  const retry = async (post: PostDraft): Promise<void> => {
    const selectedPage = activeConnections.find((connection) => connection.id === connectionId);
    if (!selectedPage) {
      setError('Choose the active Facebook Page before preparing this repost.');
      return;
    }
    if (!window.confirm(`Create a new draft for reposting to ${selectedPage.page_name}?\n\nIt will not publish until you review and explicitly approve it.`)) return;
    setBusy(`retry:${post.id}`); setError(''); setNotice('');
    try {
      await api.post(`/facebook-automation/posts/${post.id}/retry`, { connection_id: selectedPage.id });
      setNotice('Repost draft created. Review it below, then use Approve & publish now when ready.');
      await load();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not prepare this post for reposting.');
    } finally { setBusy(''); }
  };

  const remove = async (post: PostDraft): Promise<void> => {
    if (!window.confirm(`Delete this unpublished ${post.status} Facebook post record${post.has_image ? ' and its private photo' : ''}? This cannot be undone.`)) return;
    setBusy(`delete:${post.id}`); setError(''); setNotice('');
    try {
      await api.delete(`/facebook-automation/posts/${post.id}`);
      setNotice('Unpublished post record deleted.');
      await load();
    } catch (requestError: any) {
      setError(requestError.response?.data?.message || 'Could not delete this Facebook post record.');
    } finally { setBusy(''); }
  };

  const activeConnections = connections.filter((connection) => connection.is_active);

  return <section className="rounded-2xl border border-border bg-card p-4 sm:p-5">
    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div className="flex gap-2"><Megaphone className="mt-0.5 h-5 w-5 shrink-0 text-primary" /><div><h2 className="font-semibold text-foreground">AI Post Studio</h2><p className="mt-1 text-sm text-muted-foreground">Create a public Facebook Page post, attach or generate one reviewable photo, then publish it manually. AI never posts by itself.</p></div></div>
      <button type="button" onClick={() => void load()} disabled={busy !== ''} className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-border px-3 py-2 text-xs font-semibold text-foreground hover:bg-muted disabled:opacity-60"><RefreshCw className="h-3.5 w-3.5" />Refresh posts</button>
    </div>

    {error && <p role="alert" className="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200">{error}</p>}
    {notice && <p className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-200">{notice}</p>}

    <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
      <div className="space-y-3 rounded-xl border border-border bg-background p-3 sm:p-4">
        <label className="block text-sm font-medium text-foreground">Facebook Page<select value={connectionId} onChange={(event) => setConnectionId(event.target.value)} className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm"><option value="">Select Page</option>{activeConnections.map((connection) => <option key={connection.id} value={connection.id}>{connection.page_name}</option>)}</select></label>
        <label className="block text-sm font-medium text-foreground">Post topic<input value={topic} onChange={(event) => setTopic(event.target.value)} maxLength={160} placeholder="Example: New installation inquiries in Oras" className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm" /></label>
        <label className="block text-sm font-medium text-foreground">Verified details for AI (optional)<textarea value={details} onChange={(event) => setDetails(event.target.value)} maxLength={1000} rows={4} placeholder="Only facts you approve: exact offer, location, contact channel, or dates." className="mt-1 w-full rounded-lg border border-input bg-background px-3 py-2 text-sm" /></label>
        <button type="button" onClick={() => void generate()} disabled={busy !== '' || topic.trim().length < 3} className="inline-flex items-center gap-2 rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-sm font-semibold text-violet-800 hover:bg-violet-100 disabled:opacity-60 dark:border-violet-900/60 dark:bg-violet-950/20 dark:text-violet-200"><Sparkles className="h-4 w-4" />{busy === 'generate' ? 'Writing...' : 'Generate AI post draft'}</button>

        <div className="rounded-xl border border-dashed border-border p-3">
          <div className="flex items-start gap-2"><ImagePlus className="mt-0.5 h-4 w-4 shrink-0 text-primary" /><div><p className="text-sm font-semibold text-foreground">Optional post photo</p><p className="mt-0.5 text-xs leading-5 text-muted-foreground">Drag a PNG/JPEG here, or generate one AI image from the approved topic. It stays private until publication.</p></div></div>
          <div onDragOver={(event) => { event.preventDefault(); setDraggingImage(true); }} onDragLeave={() => setDraggingImage(false)} onDrop={(event) => { event.preventDefault(); setDraggingImage(false); const file = event.dataTransfer.files.item(0); if (file) void stageUploadedImage(file); }} className={`mt-3 rounded-lg border p-3 transition-colors ${draggingImage ? 'border-primary bg-primary/5' : 'border-border bg-card'}`}>
            {selectedImagePreview ? <div className="space-y-2"><img src={selectedImagePreview} alt="Selected Facebook post preview" className="max-h-52 w-full rounded-md object-cover" /><div className="flex items-center justify-between gap-2"><span className="truncate text-xs text-muted-foreground">{selectedImageName}</span><button type="button" onClick={clearSelectedImage} disabled={busy !== ''} className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-red-700 hover:underline dark:text-red-300"><X className="h-3.5 w-3.5" />Remove</button></div></div> : <label className="flex cursor-pointer items-center justify-center gap-2 py-5 text-center text-xs font-semibold text-muted-foreground hover:text-foreground"><Upload className="h-4 w-4" />Drop a photo here or choose a local file<input type="file" accept="image/png,image/jpeg" className="sr-only" onChange={(event) => { const file = event.target.files?.[0]; if (file) void stageUploadedImage(file); event.currentTarget.value = ''; }} /></label>}
          </div>
          <button type="button" onClick={() => void generateImage()} disabled={busy !== '' || topic.trim().length < 3} className="mt-3 inline-flex items-center gap-2 rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-xs font-semibold text-violet-800 hover:bg-violet-100 disabled:opacity-60 dark:border-violet-900/60 dark:bg-violet-950/20 dark:text-violet-200"><Sparkles className="h-3.5 w-3.5" />{busy === 'generate-image' ? 'Generating image...' : 'Generate AI photo'}</button>
        </div>
      </div>

      <div className="rounded-xl border border-border bg-background p-3 sm:p-4"><div className="flex items-center justify-between gap-2"><label className="text-sm font-medium text-foreground">Post text</label><span className="text-xs text-muted-foreground">{messageText.length}/5000</span></div><textarea value={messageText} onChange={(event) => setMessageText(event.target.value)} maxLength={5000} rows={15} placeholder="Generate an AI draft or write the approved public post here." className="mt-2 w-full rounded-lg border border-input bg-card px-3 py-2 text-sm text-foreground" /><div className="mt-3 flex flex-wrap items-center justify-between gap-2"><p className="max-w-md text-xs leading-5 text-muted-foreground">Review factual accuracy, image, price, locations, and tone. Do not include client, payment, or account information.</p><button type="button" onClick={() => void saveDraft()} disabled={busy !== '' || !connectionId || topic.trim().length < 3 || messageText.trim().length < 3} className="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-60">{busy === 'save' ? 'Saving...' : imageToken ? 'Save post + photo draft' : 'Save post draft'}</button></div></div>
    </div>

    <div className="mt-5 border-t border-border pt-4"><div className="flex items-center justify-between gap-2"><div><h3 className="font-semibold text-foreground">Post history</h3><p className="mt-1 text-xs text-muted-foreground">Drafts can only be sent by an explicit Administrator action. Failed posts can be deleted or copied into a new draft for review.</p></div><span className="rounded-full bg-muted px-2.5 py-1 text-xs font-bold text-muted-foreground">{posts.length}</span></div><div className="mt-3 space-y-3">{posts.map((post) => <article key={post.id} className="rounded-xl border border-border bg-background p-3"><div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"><div><p className="font-semibold text-foreground">{post.topic}</p><p className="mt-1 text-xs text-muted-foreground">Created {formatDate(post.created_at)}{post.published_at ? ` - published ${formatDate(post.published_at)}` : ''}</p></div><span className={`w-fit rounded-full px-2 py-1 text-[11px] font-bold ${post.status === 'published' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : post.status === 'draft' ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300' : post.status === 'failed' ? 'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300' : 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300'}`}>{post.status.toUpperCase()}</span></div>{postImageUrls[post.id] && <img src={postImageUrls[post.id]} alt={`Attached image for ${post.topic}`} className="mt-3 max-h-80 w-full rounded-lg object-cover" />}{post.has_image && !postImageUrls[post.id] && <p className="mt-3 text-xs text-muted-foreground">Photo attached privately.</p>}<p className="mt-3 whitespace-pre-wrap break-words text-sm text-muted-foreground">{post.message_text}</p>{post.last_error && <p className="mt-2 text-xs text-red-700 dark:text-red-300">{post.last_error}</p>}<div className="mt-3 flex flex-wrap items-center gap-2">{post.status === 'draft' && <button type="button" onClick={() => void publish(post)} disabled={busy !== ''} className="inline-flex items-center gap-2 rounded-lg bg-sky-700 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-800 disabled:opacity-60"><Send className="h-3.5 w-3.5" />{busy === `publish:${post.id}` ? 'Publishing...' : 'Approve & publish now'}</button>}{post.status === 'failed' && <button type="button" onClick={() => void retry(post)} disabled={busy !== '' || !connectionId} className="inline-flex items-center gap-2 rounded-lg bg-sky-700 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-800 disabled:opacity-60"><RotateCcw className="h-3.5 w-3.5" />{busy === `retry:${post.id}` ? 'Preparing...' : 'Repost as draft'}</button>}{(post.status === 'draft' || post.status === 'failed') && <button type="button" onClick={() => void remove(post)} disabled={busy !== ''} className="inline-flex items-center gap-2 rounded-lg border border-red-200 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50 disabled:opacity-60 dark:border-red-900/60 dark:text-red-300 dark:hover:bg-red-950/30"><Trash2 className="h-3.5 w-3.5" />{busy === `delete:${post.id}` ? 'Deleting...' : 'Delete'}</button>}{post.status === 'published' && <p className="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300"><CheckCircle2 className="h-3.5 w-3.5" />Published to the linked Facebook Page</p>}</div></article>)}{!posts.length && <div className="rounded-xl border border-dashed border-border p-5 text-center"><CircleAlert className="mx-auto h-5 w-5 text-muted-foreground" /><p className="mt-2 text-sm text-muted-foreground">No Facebook Page post drafts yet.</p></div>}</div></div>
  </section>;
}
