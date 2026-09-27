import { useEffect, useMemo, useState } from 'react';
import { LoaderCircle, X } from 'lucide-react';
import { api } from '../../api';
import { useSpace } from '../../context';
import { SwitchField } from '../form';
import FlowCanvas from './FlowCanvas';
import WorkflowBriefing, { engagementBriefing } from './WorkflowBriefing';

function defaultSteps(configSteps) {
    const list = Array.isArray(configSteps) ? configSteps : [];
    const reply = list.find((s) => s.type === 'public_reply') || {
        type: 'public_reply', enabled: true, mode: 'agent', text: null, image_path: null,
    };
    const dm = list.find((s) => s.type === 'private_dm') || {
        type: 'private_dm', enabled: true, mode: 'agent', text: null,
    };
    return [
        {
            type: 'public_reply',
            enabled: reply.enabled !== false,
            mode: reply.mode === 'fixed' ? 'fixed' : 'agent',
            text: reply.text || '',
            image_path: reply.image_path || null,
            image_url: reply.image_url || null,
        },
        {
            type: 'private_dm',
            enabled: dm.enabled !== false,
            mode: dm.mode === 'fixed' ? 'fixed' : 'agent',
            text: dm.text || '',
        },
    ];
}

export default function PostCommentWorkflowConfig({ workflow, onClose, onSave }) {
    const { setError } = useSpace();
    const isNew = !workflow?.id || workflow._local;
    const [name, setName] = useState(workflow.name || workflow.title || 'Post comment automation');
    const [active, setActive] = useState(isNew ? true : workflow.status === 'active');
    const [steps, setSteps] = useState(() => defaultSteps(workflow.config?.steps));
    const [posts, setPosts] = useState(() => (workflow.posts || []).map((p) => ({
        account_id: p.account_id,
        platform_post_id: p.platform_post_id,
    })));
    const [catalog, setCatalog] = useState([]);
    const [loadingPosts, setLoadingPosts] = useState(true);
    const [busy, setBusy] = useState(false);
    const [uploading, setUploading] = useState(false);

    useEffect(() => {
        let cancelled = false;
        (async () => {
            setLoadingPosts(true);
            try {
                const { data } = await api.get('/posts', { params: { limit: 20 } });
                if (!cancelled) setCatalog(data.posts || []);
            } catch {
                if (!cancelled) setCatalog([]);
            } finally {
                if (!cancelled) setLoadingPosts(false);
            }
        })();
        return () => { cancelled = true; };
    }, []);

    const displaySteps = useMemo(() => {
        const reply = steps.find((s) => s.type === 'public_reply') || {};
        const dm = steps.find((s) => s.type === 'private_dm') || {};
        return [
            {
                kind: 'trigger',
                key: 'posts',
                title: 'Comment on posts',
                body: posts.length > 0 ? `${posts.length} post${posts.length === 1 ? '' : 's'}` : 'Pick posts',
                color: '#e91e63',
            },
            {
                kind: 'action',
                key: 'public_reply',
                title: 'Public reply',
                body: reply.enabled === false ? 'Off' : (reply.mode === 'fixed' ? 'Fixed text' : 'AI agent'),
                color: '#1b70ff',
            },
            {
                kind: 'action',
                key: 'private_dm',
                title: 'Auto DM',
                body: dm.enabled === false ? 'Off' : (dm.mode === 'fixed' ? 'Custom message' : 'Leave to agent'),
                color: '#4caf50',
            },
            { kind: 'chip', label: 'Done' },
        ];
    }, [steps, posts]);

    const reply = steps.find((s) => s.type === 'public_reply') || {};
    const dm = steps.find((s) => s.type === 'private_dm') || {};
    const replyLabel = reply.enabled === false
        ? 'Off'
        : (reply.mode === 'fixed' ? 'Fixed text' : 'AI agent');
    const dmLabel = dm.enabled === false
        ? 'Off'
        : (dm.mode === 'fixed' ? 'Custom message' : 'Leave to agent');
    const briefing = engagementBriefing({
        postCount: posts.length,
        replyLabel,
        dmLabel,
    });

    const updateStep = (type, patch) => {
        setSteps((list) => list.map((step) => (step.type === type ? { ...step, ...patch } : step)));
    };

    const togglePost = (post) => {
        const key = `${post.account_id}:${post.platform_post_id}`;
        setPosts((list) => {
            const exists = list.some((row) => `${row.account_id}:${row.platform_post_id}` === key);
            if (exists) {
                return list.filter((row) => `${row.account_id}:${row.platform_post_id}` !== key);
            }
            return [...list, { account_id: post.account_id, platform_post_id: post.platform_post_id }];
        });
    };

    const uploadImage = async (files) => {
        const file = files?.[0];
        if (!file) return;
        setUploading(true);
        try {
            const body = new FormData();
            body.append('image', file);
            const { data } = await api.post('/posts/ai-settings/image', body, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            updateStep('public_reply', {
                image_path: data.path || null,
                image_url: data.url || null,
            });
        } catch (err) {
            setError(err.response?.data?.message || 'Could not upload image.');
        } finally {
            setUploading(false);
        }
    };

    const save = async () => {
        const reply = steps.find((s) => s.type === 'public_reply');
        const dm = steps.find((s) => s.type === 'private_dm');
        if (reply?.enabled !== false && reply?.mode === 'fixed' && !String(reply.text || '').trim()) {
            setError('Fixed public reply needs message text.');
            return;
        }
        if (dm?.enabled !== false && dm?.mode === 'fixed' && !String(dm.text || '').trim()) {
            setError('Custom Auto DM needs message text.');
            return;
        }
        if (posts.length === 0) {
            setError('Pick at least one post before saving.');
            return;
        }
        if (isNew && !active) {
            setError('Turn Active on to save this automation.');
            return;
        }

        setBusy(true);
        try {
            await onSave({
                name: name.trim() || 'Post comment automation',
                status: active ? 'active' : 'paused',
                posts,
                config: {
                    steps: steps.map((step) => ({
                        type: step.type,
                        enabled: step.enabled !== false,
                        mode: step.mode === 'fixed' ? 'fixed' : 'agent',
                        text: step.mode === 'fixed' ? (String(step.text || '').trim() || null) : null,
                        ...(step.type === 'public_reply'
                            ? { image_path: step.mode === 'fixed' ? (step.image_path || null) : null }
                            : {}),
                    })),
                },
            });
            onClose();
        } catch (err) {
            setError(err.response?.data?.message || 'Could not save workflow.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="absolute inset-0 z-30 flex flex-col overflow-hidden bg-white lg:flex-row">
            <section className="flex max-h-[42%] w-full shrink-0 flex-col border-b border-line bg-white lg:max-h-none lg:h-full lg:w-[min(42%,420px)] lg:border-b-0 lg:border-e">
                <header className="flex items-center justify-between gap-3 border-b border-line px-3 py-2">
                    <div className="min-w-0 flex-1">
                        <input
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            className="w-full truncate bg-transparent text-base font-semibold text-ink outline-none"
                            aria-label="Workflow name"
                        />
                        <p className="text-[12px] text-muted">Click canvas steps to configure reply and DM</p>
                    </div>
                    <button type="button" aria-label="Close" onClick={onClose} className="rounded-lg p-1 text-ink hover:bg-bubble">
                        <X size={18} />
                    </button>
                </header>
                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                    <WorkflowBriefing
                        eyebrow={briefing.eyebrow}
                        title={briefing.title}
                        summary={briefing.summary}
                        bullets={briefing.bullets}
                        how={briefing.how}
                        status={(
                            <div className="mt-3 border-t border-line/70 pt-3">
                                <SwitchField
                                    label="Active"
                                    description={active ? 'Will run on matching comments after you save' : (isNew ? 'Turn on to save this automation' : 'Paused — not running')}
                                    checked={active}
                                    onChange={setActive}
                                />
                            </div>
                        )}
                    />
                    <div className="flex justify-end gap-2 border-t border-line pt-4">
                        <button type="button" onClick={onClose} className="h-9 rounded-lg border border-line px-3 text-sm font-semibold text-ink hover:bg-bubble">
                            Cancel
                        </button>
                        <button
                            type="button"
                            disabled={busy || uploading}
                            onClick={save}
                            className="inline-flex h-9 items-center gap-2 rounded-lg bg-coral px-3.5 text-sm font-semibold text-white disabled:opacity-60"
                        >
                            {busy ? <LoaderCircle size={14} className="animate-spin" /> : null}
                            {isNew ? 'Save & activate' : 'Save'}
                        </button>
                    </div>
                </div>
            </section>
            <section className="min-h-[58dvh] w-full flex-1 lg:h-full lg:min-h-0">
                <FlowCanvas
                    steps={displaySteps}
                    editable
                    engagement={{
                        steps,
                        posts,
                        catalog,
                        loadingPosts,
                        uploading,
                        onTogglePost: togglePost,
                        onUpdateStep: updateStep,
                        onUploadImage: uploadImage,
                        onClearImage: () => updateStep('public_reply', { image_path: null, image_url: null }),
                    }}
                />
            </section>
        </div>
    );
}
