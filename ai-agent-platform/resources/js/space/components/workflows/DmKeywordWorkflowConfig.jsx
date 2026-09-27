import { useMemo, useState } from 'react';
import { LoaderCircle, X } from 'lucide-react';
import { api } from '../../api';
import { useSpace } from '../../context';
import { SwitchField } from '../form';
import FlowCanvas from './FlowCanvas';
import WorkflowBriefing, { dmKeywordBriefing } from './WorkflowBriefing';

const PLATFORMS = [
    { id: 'instagram', label: 'Instagram' },
    { id: 'facebook', label: 'Facebook' },
    { id: 'whatsapp', label: 'WhatsApp' },
    { id: 'threads', label: 'Threads' },
    { id: 'twitter', label: 'X / Twitter' },
    { id: 'telegram', label: 'Telegram' },
];

function initialPlatforms(config) {
    if (Array.isArray(config?.platforms) && config.platforms.length > 0) {
        return [...config.platforms];
    }
    if (typeof config?.platform === 'string' && config.platform.trim() !== '') {
        return [config.platform.trim()];
    }
    return [];
}

function defaultReply(config) {
    const steps = Array.isArray(config?.steps) ? config.steps : [];
    const reply = steps.find((s) => s.type === 'dm_reply') || {};
    return {
        type: 'dm_reply',
        mode: reply.mode === 'agent' ? 'agent' : 'fixed',
        text: reply.text || '',
        image_path: reply.image_path || null,
        image_url: reply.image_url || null,
    };
}

export default function DmKeywordWorkflowConfig({ workflow, onClose, onSave }) {
    const { setError, socialAccounts } = useSpace();
    const isNew = !workflow?.id || workflow._local;
    const [name, setName] = useState(workflow.name || workflow.title || 'DM keyword automation');
    const [active, setActive] = useState(isNew ? true : workflow.status === 'active');
    const [selectedPlatforms, setSelectedPlatforms] = useState(() => initialPlatforms(workflow.config));
    const [keywords, setKeywords] = useState(() => [...(workflow.config?.keywords || [])]);
    const [keywordInput, setKeywordInput] = useState('');
    const [reply, setReply] = useState(() => defaultReply(workflow.config));
    const [busy, setBusy] = useState(false);
    const [uploading, setUploading] = useState(false);

    const linkedPlatforms = useMemo(() => {
        const ids = new Set(
            (socialAccounts || [])
                .filter((a) => a.platform && a.platform !== 'simulator' && a.status !== 'disconnected')
                .map((a) => a.platform),
        );
        const preferred = PLATFORMS.filter((p) => ids.has(p.id));
        return preferred.length > 0 ? preferred : PLATFORMS;
    }, [socialAccounts]);

    const platformLabel = useMemo(() => {
        if (selectedPlatforms.length === 0) return 'Pick platforms';
        if (selectedPlatforms.length === 1) {
            return linkedPlatforms.find((p) => p.id === selectedPlatforms[0])?.label || selectedPlatforms[0];
        }
        return `${selectedPlatforms.length} platforms`;
    }, [selectedPlatforms, linkedPlatforms]);

    const displaySteps = useMemo(() => {
        const keywordLabel = keywords.length > 0
            ? `${keywords.length} keyword${keywords.length === 1 ? '' : 's'}`
            : 'Add keywords';
        return [
            {
                kind: 'trigger',
                key: 'keywords',
                title: 'When someone messages',
                body: `${platformLabel} · ${keywordLabel}`,
                color: '#e91e63',
            },
            {
                kind: 'action',
                key: 'dm_reply',
                title: 'Send reply',
                body: reply.mode === 'agent' ? 'AI agent' : 'Fixed message',
                color: '#1b70ff',
            },
            { kind: 'chip', label: 'Done' },
        ];
    }, [platformLabel, keywords, reply.mode]);

    const briefing = dmKeywordBriefing({
        platform: platformLabel,
        keywordCount: keywords.length,
        replyMode: reply.mode,
    });

    const togglePlatform = (id) => {
        setSelectedPlatforms((list) => (
            list.includes(id) ? list.filter((row) => row !== id) : [...list, id]
        ));
    };

    const addKeyword = () => {
        const value = keywordInput.trim();
        if (!value) return;
        if (keywords.some((k) => k.toLowerCase() === value.toLowerCase())) {
            setKeywordInput('');
            return;
        }
        setKeywords((list) => [...list, value]);
        setKeywordInput('');
    };

    const removeKeyword = (word) => {
        setKeywords((list) => list.filter((k) => k !== word));
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
            setReply((current) => ({
                ...current,
                image_path: data.path || null,
                image_url: data.url || null,
            }));
        } catch (err) {
            setError(err.response?.data?.message || 'Could not upload image.');
        } finally {
            setUploading(false);
        }
    };

    const save = async () => {
        if (selectedPlatforms.length === 0) {
            setError('Select at least one platform.');
            return;
        }
        if (keywords.length === 0) {
            setError('Add at least one keyword before saving.');
            return;
        }
        if (reply.mode === 'fixed' && !String(reply.text || '').trim()) {
            setError('Fixed DM reply needs message text.');
            return;
        }
        if (isNew && !active) {
            setError('Turn Active on to save this automation.');
            return;
        }

        setBusy(true);
        try {
            await onSave({
                name: name.trim() || 'DM keyword automation',
                status: active ? 'active' : 'paused',
                config: {
                    platforms: selectedPlatforms,
                    platform: selectedPlatforms[0],
                    match: 'contains',
                    keywords,
                    steps: [{
                        type: 'dm_reply',
                        mode: reply.mode === 'agent' ? 'agent' : 'fixed',
                        text: reply.mode === 'fixed' ? (String(reply.text || '').trim() || null) : null,
                        image_path: reply.mode === 'fixed' ? (reply.image_path || null) : null,
                    }],
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
                            aria-label="Automation name"
                        />
                        <p className="text-[12px] text-muted">Name this automation — it shows in the Inbox sidebar</p>
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
                                    description={active ? 'Will run on matching DMs after you save' : (isNew ? 'Turn on to save this automation' : 'Paused — not running')}
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
                    dm={{
                        platforms: linkedPlatforms,
                        selectedPlatforms,
                        keywords,
                        keywordInput,
                        reply,
                        uploading,
                        onTogglePlatform: togglePlatform,
                        onKeywordInput: setKeywordInput,
                        onAddKeyword: addKeyword,
                        onRemoveKeyword: removeKeyword,
                        onReply: (patch) => setReply((current) => ({ ...current, ...patch })),
                        onUploadImage: uploadImage,
                        onClearImage: () => setReply((current) => ({ ...current, image_path: null, image_url: null })),
                    }}
                />
            </section>
        </div>
    );
}
