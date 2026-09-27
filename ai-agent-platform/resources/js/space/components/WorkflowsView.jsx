import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronLeft } from 'lucide-react';
import { api } from '../api';
import { useSpace } from '../context';
import { queryKeys } from '../query';
import DraftList from './workflows/DraftList';
import TemplateCard from './workflows/TemplateCard';
import TemplatePreview from './workflows/TemplatePreview';
import WorkflowConfig from './workflows/WorkflowConfig';
import PostCommentWorkflowConfig from './workflows/PostCommentWorkflowConfig';
import DmKeywordWorkflowConfig from './workflows/DmKeywordWorkflowConfig';
import WorkflowsSidebar from './workflows/WorkflowsSidebar';
import { SearchField } from './form';
import { CardGridSkeleton, PageSkeleton } from './inboxSkeletons';

function localDmWorkflow(template, extras = {}) {
    const defaults = template?.default_config || {};
    return {
        id: null,
        _local: true,
        template_key: 'dm_keyword',
        kind: 'dm',
        name: template?.title || 'DM keyword automation',
        status: 'active',
        config: {
            platforms: extras.platforms || defaults.platforms || [],
            platform: extras.platform || defaults.platform || null,
            match: 'contains',
            keywords: [],
            steps: defaults.steps || [{ type: 'dm_reply', mode: 'fixed', text: null, image_path: null }],
        },
        posts: [],
        steps: template?.steps || [],
    };
}

function localPostWorkflow(template, posts = []) {
    const defaults = template?.default_config || {};
    return {
        id: null,
        _local: true,
        template_key: 'post_comment',
        kind: 'engagement',
        name: template?.title || 'Post comment automation',
        status: 'active',
        config: {
            steps: defaults.steps || [
                { type: 'public_reply', enabled: true, mode: 'agent', text: null, image_path: null },
                { type: 'private_dm', enabled: true, mode: 'agent', text: null },
            ],
        },
        posts,
        steps: template?.steps || [],
    };
}

export default function WorkflowsView() {
    const { setError, can, workflowNav, clearWorkflowNav } = useSpace();
    const queryClient = useQueryClient();
    const [browsing, setBrowsing] = useState(false);
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('all');
    const [preview, setPreview] = useState(null);
    const [editing, setEditing] = useState(null);
    const navHandled = useRef(false);

    const templatesQuery = useQuery({
        queryKey: queryKeys.workflowTemplates,
        queryFn: async () => {
            const { data } = await api.get('/workflows/templates');
            return data.templates || [];
        },
        staleTime: 5 * 60_000,
    });

    const workflowsQuery = useQuery({
        queryKey: queryKeys.workflows,
        queryFn: async () => {
            const { data } = await api.get('/workflows');
            return data.workflows || [];
        },
    });

    const templates = templatesQuery.data || [];
    const items = workflowsQuery.data || [];
    const loading = (templatesQuery.isLoading && !templatesQuery.data)
        || (workflowsQuery.isLoading && !workflowsQuery.data);

    useEffect(() => {
        const err = templatesQuery.error || workflowsQuery.error;
        if (err) setError(err.response?.data?.message || 'Could not load workflows.');
    }, [templatesQuery.error, workflowsQuery.error, setError]);

    const setItems = (updater) => {
        queryClient.setQueryData(queryKeys.workflows, (prev = []) => (
            typeof updater === 'function' ? updater(prev) : updater
        ));
    };

    const load = async () => {
        const result = await workflowsQuery.refetch();
        const list = result.data || [];
        return { templates, workflows: list };
    };

    useEffect(() => {
        if (!workflowNav) {
            navHandled.current = false;
            return undefined;
        }
        if (loading || navHandled.current) return undefined;
        navHandled.current = true;

        (async () => {
            try {
                if (workflowNav.wf) {
                    const list = items.length ? items : (await load()).workflows;
                    const found = list.find((row) => String(row.id) === String(workflowNav.wf));
                    if (found) setEditing(found);
                    clearWorkflowNav?.();
                    return;
                }

                if (workflowNav.create === 'post_comment') {
                    if (!can('settings.manage')) {
                        setError('You need permission to create workflows.');
                        clearWorkflowNav?.();
                        return;
                    }
                    const posts = [];
                    if (workflowNav.account_id && workflowNav.platform_post_id) {
                        posts.push({
                            account_id: Number(workflowNav.account_id),
                            platform_post_id: String(workflowNav.platform_post_id),
                        });
                    } else if (Array.isArray(workflowNav.posts)) {
                        workflowNav.posts.forEach((post) => {
                            if (post?.account_id && post?.platform_post_id) {
                                posts.push({
                                    account_id: Number(post.account_id),
                                    platform_post_id: String(post.platform_post_id),
                                });
                            }
                        });
                    }

                    if (posts.length === 1) {
                        const list = items.length ? items : (await load()).workflows;
                        const existing = list.find((row) => (
                            (row.kind === 'engagement' || row.template_key === 'post_comment')
                            && (row.posts || []).some((p) => (
                                Number(p.account_id) === posts[0].account_id
                                && String(p.platform_post_id) === posts[0].platform_post_id
                            ))
                        ));
                        if (existing) {
                            setEditing(existing);
                            clearWorkflowNav?.();
                            return;
                        }
                    }

                    const tplList = templates.length ? templates : (await load()).templates;
                    const template = tplList.find((row) => row.id === 'post_comment');
                    setBrowsing(false);
                    setEditing(localPostWorkflow(template, posts));
                    clearWorkflowNav?.();
                    return;
                }

                if (workflowNav.create === 'dm_keyword') {
                    if (!can('settings.manage')) {
                        setError('You need permission to create workflows.');
                        clearWorkflowNav?.();
                        return;
                    }
                    const tplList = templates.length ? templates : (await load()).templates;
                    const template = tplList.find((row) => row.id === 'dm_keyword');
                    setBrowsing(false);
                    setEditing(localDmWorkflow(template, {
                        platform: workflowNav.platform || null,
                        platforms: workflowNav.platform ? [workflowNav.platform] : [],
                    }));
                    clearWorkflowNav?.();
                }
            } catch (err) {
                setError(err.response?.data?.message || 'Could not open automation.');
                clearWorkflowNav?.();
            }
        })();

        return undefined;
    }, [loading, workflowNav, items, templates, can, setError, clearWorkflowNav]);

    const visible = useMemo(() => {
        const needle = query.trim().toLowerCase();
        return templates.filter((item) => {
            const matchesCategory = category === 'all' || item.category === category;
            const matchesQuery = !needle || `${item.title} ${item.body}`.toLowerCase().includes(needle);
            return matchesCategory && matchesQuery;
        });
    }, [templates, query, category]);

    const useTemplate = async (template, config = {}, activate = true) => {
        if (!can('settings.manage')) {
            setError('You need permission to activate workflows.');
            return;
        }

        // Canvas templates open locally — nothing is stored until Save completes.
        if (template.id === 'post_comment' || template.id === 'dm_keyword') {
            setPreview(null);
            setBrowsing(false);
            setEditing(template.id === 'dm_keyword'
                ? localDmWorkflow(template)
                : localPostWorkflow(template, []));
            return;
        }

        try {
            const { data } = await api.post('/workflows/use', {
                template_key: template.id,
                activate,
                config,
            });
            const workflow = data.workflow;
            setItems((list) => {
                const rest = list.filter((row) => row.id !== workflow.id);
                return [workflow, ...rest];
            });
            setPreview(null);
            setBrowsing(false);
            setEditing(workflow);
        } catch (err) {
            setError(err.response?.data?.message || 'Could not use this template.');
        }
    };

    const saveWorkflow = async (workflow, payload) => {
        const isLocal = !workflow?.id || workflow._local;
        if (isLocal) {
            const { data } = await api.post('/workflows/use', {
                template_key: workflow.template_key,
                activate: true,
                name: payload.name,
                config: payload.config,
                posts: payload.posts || [],
            });
            const saved = data.workflow;
            setItems((list) => [saved, ...list.filter((row) => row.id !== saved.id)]);
            setEditing(saved);
            return saved;
        }

        const body = {
            ...payload,
            status: payload.status === 'active' ? 'active' : 'paused',
        };
        const { data } = await api.patch(`/workflows/${workflow.id}`, body);
        const saved = data.workflow;
        setItems((list) => list.map((row) => (row.id === saved.id ? saved : row)));
        setEditing(saved);
        return saved;
    };

    if (loading) {
        return (
            <PageSkeleton>
                <CardGridSkeleton count={6} />
            </PageSkeleton>
        );
    }

    const isEngagement = editing && (editing.kind === 'engagement' || editing.template_key === 'post_comment');
    const isDm = editing && (editing.kind === 'dm' || editing.template_key === 'dm_keyword');
    const savedItems = items.filter((row) => row.status !== 'draft');

    return (
        <div className="relative flex h-full min-h-0 flex-col bg-white">
            {!browsing ? (
                <DraftList
                    items={savedItems}
                    onBrowse={() => setBrowsing(true)}
                    onShowLibrary={() => setBrowsing(true)}
                    onSelect={setEditing}
                    canManage={can('settings.manage')}
                />
            ) : (
                <div className="flex h-full min-h-0">
                    <WorkflowsSidebar
                        mode="library"
                        query={query}
                        setQuery={setQuery}
                        category={category}
                        setCategory={setCategory}
                        counts={{
                            saved: savedItems.length,
                            active: savedItems.filter((item) => item.status === 'active').length,
                        }}
                        onShowLibrary={() => {}}
                        onShowSaved={() => setBrowsing(false)}
                    />

                    <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                        <header className="flex items-center justify-between gap-3 border-b border-line px-3 py-2 sm:px-4">
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        aria-label="Back to your workflows"
                                        onClick={() => setBrowsing(false)}
                                        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-ink hover:bg-bubble"
                                    >
                                        <ChevronLeft size={18} />
                                    </button>
                                    <h1 className="truncate text-base font-semibold text-ink">Workflow templates</h1>
                                </div>
                                <p className="ps-[42px] text-[13px] text-muted">
                                    DM keywords, post comments, or lead signals for inbox chats.
                                </p>
                            </div>
                        </header>

                        <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
                            <div className="mb-4 lg:hidden">
                                <SearchField value={query} onChange={setQuery} placeholder="Search templates" />
                            </div>
                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                                {visible.map((template) => (
                                    <TemplateCard
                                        key={template.id}
                                        template={template}
                                        onOpen={setPreview}
                                    />
                                ))}
                            </div>
                            {visible.length === 0 && (
                                <p className="mt-4 text-sm text-muted">No templates match that search.</p>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {preview && (
                <TemplatePreview
                    template={preview}
                    onClose={() => setPreview(null)}
                    onUse={(config, activate) => useTemplate(preview, config, activate)}
                />
            )}

            {editing && isDm && (
                <DmKeywordWorkflowConfig
                    workflow={editing}
                    onClose={() => setEditing(null)}
                    onSave={(payload) => saveWorkflow(editing, payload)}
                />
            )}

            {editing && isEngagement && !isDm && (
                <PostCommentWorkflowConfig
                    workflow={editing}
                    onClose={() => setEditing(null)}
                    onSave={(payload) => saveWorkflow(editing, payload)}
                />
            )}

            {editing && !isEngagement && !isDm && (
                <WorkflowConfig
                    workflow={editing}
                    onClose={() => setEditing(null)}
                    onSave={(payload) => saveWorkflow(editing, payload)}
                />
            )}
        </div>
    );
}
