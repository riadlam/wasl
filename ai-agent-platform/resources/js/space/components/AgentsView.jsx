import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Listbox, ListboxButton, ListboxOption, ListboxOptions } from '@headlessui/react';
import { motion, AnimatePresence, useReducedMotion } from 'framer-motion';
import { Check, ChevronDown, FileText, LoaderCircle, Maximize2, Mic, MicOff, Paperclip, Plus, SendHorizonal, Trash2, X } from 'lucide-react';
import { useDropzone } from 'react-dropzone';
import SpeechRecognition, { useSpeechRecognition } from 'react-speech-recognition';
import { api } from '../api';
import { useSpace } from '../context';
import { queryKeys } from '../query';
import AgentMark from './AgentMark';
import BehaviorRulesPanel from './BehaviorRulesPanel';
import gptIcon from '../icons/chatgpt.svg';
import geminiIcon from '../icons/gemini.svg';
import fluxIcon from '../icons/flux.svg';

/** max-w-xl (36rem) + 20% ≈ 43.2rem */
const CHAT_MAX = 'max-w-[43.2rem]';

export default function AgentsView() {
    const { can, me, setError, setMe, shop } = useSpace();
    const queryClient = useQueryClient();
    const reduceMotion = useReducedMotion();
    const canManage = can('agents.manage');
    const canView = can('agents.view') || canManage;
    const [messages, setMessages] = useState([]);
    const [pendingAction, setPendingAction] = useState(null);
    const [sending, setSending] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [interviewBusy, setInterviewBusy] = useState(false);
    const [pendingAssets, setPendingAssets] = useState([]);
    const [activeChatId, setActiveChatId] = useState(null);
    const [drafting, setDrafting] = useState(false);
    const [chatBusy, setChatBusy] = useState(false);
    const scrollRef = useRef(null);
    const bottomRef = useRef(null);
    const chatHydrated = useRef(false);
    const imagePollActive = useRef(false);

    const walletBalance = me?.wallet?.balance_da ?? me?.wallet?.balance;
    const walletCurrency = me?.wallet?.currency || 'DZD';

    const applyWallet = useCallback((wallet) => {
        if (!wallet || typeof setMe !== 'function') return;
        setMe((prev) => (prev ? { ...prev, wallet } : prev));
    }, [setMe]);

    const agentQuery = useQuery({
        queryKey: queryKeys.agent,
        queryFn: async () => {
            const { data } = await api.get('/agent');
            return data;
        },
    });

    const imageModelsQuery = useQuery({
        queryKey: ['agent', 'image-models'],
        queryFn: async () => {
            const { data } = await api.get('/agent/image-models');
            return data;
        },
        enabled: canView,
        staleTime: 60_000,
    });

    const [imageModel, setImageModel] = useState('gpt_image_2');
    const [savingImageModel, setSavingImageModel] = useState(false);
    const [llmModel, setLlmModel] = useState('claude_sonnet');
    const [savingLlmModel, setSavingLlmModel] = useState(false);

    const llmModelsQuery = useQuery({
        queryKey: ['agent', 'llm-models'],
        queryFn: async () => {
            const { data } = await api.get('/agent/llm-models');
            return data;
        },
        enabled: canView,
        staleTime: 60_000,
    });

    useEffect(() => {
        const preferred = agentQuery.data?.settings?.image_model;
        if (preferred) setImageModel(preferred);
        const llm = agentQuery.data?.settings?.llm_model;
        if (llm) setLlmModel(llm);
    }, [agentQuery.data?.settings?.image_model, agentQuery.data?.settings?.llm_model]);

    const saveImageModel = useCallback(async (next) => {
        if (!canManage || next === imageModel) return;
        const prev = imageModel;
        setImageModel(next);
        setSavingImageModel(true);
        try {
            const { data } = await api.patch('/agent/image-model', { image_model: next });
            setImageModel(data.image_model || next);
            queryClient.setQueryData(queryKeys.agent, (old) => {
                if (!old) return old;
                return {
                    ...old,
                    settings: { ...(old.settings || {}), image_model: data.image_model || next },
                };
            });
        } catch (e) {
            setImageModel(prev);
            setError(e.response?.data?.message || 'Could not save image model');
        } finally {
            setSavingImageModel(false);
        }
    }, [canManage, imageModel, queryClient, setError]);

    const saveLlmModel = useCallback(async (next) => {
        if (!canManage || next === llmModel) return;
        const prev = llmModel;
        setLlmModel(next);
        setSavingLlmModel(true);
        try {
            const { data } = await api.patch('/agent/llm-model', { llm_model: next });
            setLlmModel(data.llm_model || next);
            queryClient.setQueryData(queryKeys.agent, (old) => {
                if (!old) return old;
                return {
                    ...old,
                    settings: { ...(old.settings || {}), llm_model: data.llm_model || next },
                };
            });
        } catch (e) {
            setLlmModel(prev);
            setError(e.response?.data?.message || 'Could not save chat model');
        } finally {
            setSavingLlmModel(false);
        }
    }, [canManage, llmModel, queryClient, setError]);

    const chatsQuery = useQuery({
        queryKey: queryKeys.agentChats,
        queryFn: async () => {
            const { data } = await api.get('/agent/chats');
            return data;
        },
        enabled: canView,
        staleTime: 15_000,
    });

    const chatQuery = useQuery({
        queryKey: queryKeys.agentChat(activeChatId),
        queryFn: async () => {
            const { data } = await api.get('/agent/chat', {
                params: activeChatId ? { chat_id: activeChatId } : undefined,
            });
            return data;
        },
        enabled: canView && activeChatId != null,
        staleTime: 15_000,
        refetchInterval: (query) => {
            const rows = query.state.data?.messages;
            if (!Array.isArray(rows)) return false;
            const queued = rows.some((row) => {
                const jobs = row.image_jobs || row.meta?.image_jobs || [];
                return jobs.some((job) => job?.status === 'queued');
            });
            return queued ? 3000 : false;
        },
    });

    useEffect(() => {
        if (drafting) return;
        const list = chatsQuery.data?.chats;
        if (!Array.isArray(list) || list.length === 0) {
            return;
        }
        if (activeChatId && list.some((c) => Number(c.id) === Number(activeChatId))) return;
        setActiveChatId(list[0].id);
    }, [chatsQuery.data, chatsQuery.isFetched, activeChatId, drafting]);

    const interviewQuery = useQuery({
        queryKey: queryKeys.profileInterview,
        queryFn: async () => {
            const { data } = await api.get('/agent/profile-interview');
            return data;
        },
        enabled: canView,
        staleTime: 15_000,
    });

    const agent = agentQuery.data?.agent || null;
    const loading = (agentQuery.isLoading && !agentQuery.data)
        || (canView && activeChatId != null && chatQuery.isLoading && !chatQuery.data && !chatHydrated.current);

    useEffect(() => {
        chatHydrated.current = false;
        imagePollActive.current = false;
        if (activeChatId == null && !drafting) {
            setMessages([]);
            setPendingAction(null);
        } else if (activeChatId != null) {
            setPendingAction(null);
        }
    }, [activeChatId, drafting]);

    useEffect(() => {
        if (agentQuery.error) {
            setError(agentQuery.error.response?.data?.message || 'Could not load agent');
        }
        if (chatQuery.error) {
            setError(chatQuery.error.response?.data?.message || 'Could not load agent chat');
        }
        if (interviewQuery.error) {
            setError(interviewQuery.error.response?.data?.message || 'Could not load profile interview');
        }
        if (chatsQuery.error) {
            setError(chatsQuery.error.response?.data?.message || 'Could not load chats');
        }
    }, [agentQuery.error, chatQuery.error, interviewQuery.error, chatsQuery.error, setError]);

    useEffect(() => {
        if (chatHydrated.current) return;

        if (!canView) {
            if (!agentQuery.data) return;
            setMessages(welcomeMessages({
                canManage,
                userName: (me?.user?.name || 'there').split(' ')[0],
                shopName: shop?.name || me?.business?.name || 'your shop',
                agentName: agentQuery.data.agent?.name || 'Shop assistant',
            }));
            chatHydrated.current = true;
            return;
        }

        if (activeChatId == null) {
            if (!drafting && !agentQuery.data) return;
            setMessages(welcomeMessages({
                canManage,
                userName: (me?.user?.name || 'there').split(' ')[0],
                shopName: shop?.name || me?.business?.name || 'your shop',
                agentName: agentQuery.data?.agent?.name || 'Shop assistant',
            }));
            chatHydrated.current = true;
            return;
        }

        if (!chatQuery.data) return;
        const chat = chatQuery.data;
        const rows = Array.isArray(chat.messages) ? chat.messages : [];
        if (rows.length === 0) {
            setMessages(welcomeMessages({
                canManage,
                userName: (me?.user?.name || 'there').split(' ')[0],
                shopName: shop?.name || me?.business?.name || 'your shop',
                agentName: agentQuery.data?.agent?.name || 'Shop assistant',
            }));
        } else {
            setMessages(rows.map(apiMessageToUi));
        }
        setPendingAction(chat.pending_action || null);
        if (chat.wallet) applyWallet(chat.wallet);
        chatHydrated.current = true;
    }, [chatQuery.data, agentQuery.data, canView, canManage, me, shop, applyWallet, activeChatId, drafting]);

    useEffect(() => {
        if (!chatHydrated.current || !chatQuery.data) return;
        const rows = Array.isArray(chatQuery.data.messages) ? chatQuery.data.messages : [];
        const queued = rows.some((row) => (row.image_jobs || []).some((job) => job?.status === 'queued'));
        if (!queued && !imagePollActive.current) return;
        imagePollActive.current = queued;
        setMessages(rows.map(apiMessageToUi));
        if (chatQuery.data.wallet) applyWallet(chatQuery.data.wallet);
    }, [chatQuery.dataUpdatedAt]);

    useEffect(() => {
        if (loading || messages.length === 0) return undefined;

        const jump = () => {
            const scroller = scrollRef.current;
            if (!scroller) return;
            scroller.scrollTop = scroller.scrollHeight;
        };

        jump();
        const t0 = requestAnimationFrame(jump);
        const t1 = window.setTimeout(jump, 50);
        const t2 = window.setTimeout(jump, 250);

        return () => {
            cancelAnimationFrame(t0);
            window.clearTimeout(t1);
            window.clearTimeout(t2);
        };
    }, [loading, messages, pendingAction, sending]);

    const uploadFiles = async (files) => {
        if (!canManage || !files?.length) return;
        setError('');
        const locals = [...files].map((file) => {
            const localId = `local-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
            return {
                localId,
                id: null,
                url: URL.createObjectURL(file),
                mime: file.type || 'application/octet-stream',
                original_name: file.name || 'file',
                uploading: true,
                file,
            };
        });
        setPendingAssets((prev) => [...prev, ...locals]);
        setUploading(true);

        for (const local of locals) {
            try {
                const body = new FormData();
                body.append('file', local.file);
                const { data } = await api.post('/agent/assets', body, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });
                const asset = data?.asset;
                if (!asset?.id) {
                    throw new Error('Upload failed');
                }
                setPendingAssets((prev) => prev.map((row) => {
                    if (row.localId !== local.localId) return row;
                    if (row.url?.startsWith('blob:')) URL.revokeObjectURL(row.url);
                    return { ...asset, uploading: false };
                }));
                queryClient.setQueryData(queryKeys.agent, (prev) => ({
                    ...(prev || {}),
                    assets: [asset, ...(prev?.assets || []).filter((a) => a.id !== asset.id)],
                }));
            } catch (e) {
                setPendingAssets((prev) => prev.filter((row) => {
                    if (row.localId === local.localId && row.url?.startsWith('blob:')) {
                        URL.revokeObjectURL(row.url);
                    }
                    return row.localId !== local.localId;
                }));
                setError(e.response?.data?.message || 'Could not upload image');
            }
        }
        setUploading(false);
    };

    const removePendingAsset = (key) => {
        setPendingAssets((prev) => prev.filter((row) => {
            const match = row.localId === key || row.id === key;
            if (match && row.url?.startsWith('blob:')) URL.revokeObjectURL(row.url);
            return !match;
        }));
    };

    const useGeneratedAsset = (asset) => {
        if (!asset?.id || !canManage) return;
        setPendingAssets((prev) => {
            if (prev.some((row) => Number(row.id) === Number(asset.id))) return prev;
            return [
                ...prev,
                {
                    id: asset.id,
                    url: asset.url,
                    mime: asset.mime || 'image/jpeg',
                    original_name: asset.original_name || `generated-${asset.id}.jpg`,
                    uploading: false,
                },
            ];
        });
    };

    const sendText = async (text, voiceLang, assetIds = []) => {
        const trimmed = String(text || '').trim();
        const ids = Array.isArray(assetIds) ? assetIds.filter(Boolean) : [];
        if ((!trimmed && ids.length === 0) || !canManage || sending) return;
        if (pendingAssets.some((a) => a.uploading)) return;

        const attached = pendingAssets.filter((a) => ids.includes(a.id));
        setSending(true);
        setError('');
        const tempId = `local-${Date.now()}`;
        setMessages((prev) => [
            ...prev.filter((m) => !String(m.id).startsWith('welcome-')),
            {
                id: tempId,
                role: 'user',
                text: trimmed || '(attachment)',
                assets: attached,
            },
        ]);
        setPendingAssets([]);

        try {
            const { data } = await api.post('/agent/chat', {
                text: trimmed || undefined,
                voice_lang: voiceLang || undefined,
                asset_ids: ids.length ? ids : undefined,
                chat_id: activeChatId || undefined,
            });
            if (data.chat?.id) {
                setDrafting(false);
                setActiveChatId(data.chat.id);
                queryClient.invalidateQueries({ queryKey: queryKeys.agentChats });
            }
            setMessages((prev) => {
                const withoutTemp = prev.filter((m) => m.id !== tempId);
                const next = [...withoutTemp];
                if (data.user_message) next.push(apiMessageToUi(data.user_message));
                if (data.message) next.push(apiMessageToUi(data.message));
                return next;
            });
            setPendingAction(data.pending_action || null);
            if (data.wallet) applyWallet(data.wallet);
            const chatKey = queryKeys.agentChat(data.chat?.id || activeChatId);
            queryClient.setQueryData(chatKey, (prev) => ({
                ...(prev || {}),
                chat: data.chat || prev?.chat,
                messages: [
                    ...(Array.isArray(prev?.messages) ? prev.messages : []),
                    data.user_message,
                    data.message,
                ].filter(Boolean),
                pending_action: data.pending_action || null,
                wallet: data.wallet || prev?.wallet,
            }));
            queryClient.invalidateQueries({ queryKey: queryKeys.profileInterview });
        } catch (e) {
            setMessages((prev) => prev.filter((m) => m.id !== tempId));
            if (attached.length) {
                setPendingAssets((prev) => [...attached, ...prev]);
            }
            if (e.response?.status === 402) {
                const available = e.response?.data?.available_da;
                setError(
                    available != null
                        ? `Insufficient wallet balance (available: ${available} DA). Top up to keep chatting.`
                        : (e.response?.data?.message || 'Insufficient wallet balance.'),
                );
            } else {
                setError(e.response?.data?.message || 'Could not send message');
            }
        } finally {
            setSending(false);
        }
    };

    const confirmPending = async () => {
        if (!pendingAction?.id || confirming) return;
        setConfirming(true);
        setError('');
        try {
            const { data } = await api.post('/agent/chat/confirm', {
                action_id: pendingAction.id,
                chat_id: activeChatId || undefined,
            });
            if (data.message) setMessages((prev) => [...prev, apiMessageToUi(data.message)]);
            setPendingAction(data.pending_action || null);
            if (data.wallet) applyWallet(data.wallet);
            if (!data.ok) setError(data.result?.error || 'Could not confirm action');
            queryClient.invalidateQueries({ queryKey: ['agent', 'chat'] });
        } catch (e) {
            setError(e.response?.data?.message || e.response?.data?.result?.error || 'Could not confirm action');
            if (e.response?.data?.message) {
                setMessages((prev) => [...prev, apiMessageToUi(e.response.data.message)]);
            }
            if (e.response?.data?.pending_action !== undefined) {
                setPendingAction(e.response.data.pending_action);
            }
        } finally {
            setConfirming(false);
        }
    };

    const cancelPending = async () => {
        if (!pendingAction?.id || confirming) return;
        setConfirming(true);
        setError('');
        try {
            const { data } = await api.post('/agent/chat/cancel', {
                action_id: pendingAction.id,
                chat_id: activeChatId || undefined,
            });
            if (data.message) setMessages((prev) => [...prev, apiMessageToUi(data.message)]);
            setPendingAction(data.pending_action || null);
            if (data.wallet) applyWallet(data.wallet);
            queryClient.invalidateQueries({ queryKey: ['agent', 'chat'] });
        } catch (e) {
            setError(e.response?.data?.message || 'Could not cancel action');
        } finally {
            setConfirming(false);
        }
    };

    const startProfileInterview = async () => {
        if (!canManage || interviewBusy) return;
        setInterviewBusy(true);
        try {
            const { data } = await api.post('/agent/profile-interview/start');
            if (data.message?.content) {
                setMessages((prev) => [...prev, apiMessageToUi(data.message)]);
            }
            queryClient.setQueryData(queryKeys.profileInterview, {
                needed: data.needed,
                interview: data.interview,
                progress: data.progress,
            });
            queryClient.invalidateQueries({ queryKey: queryKeys.profileInterview });
            queryClient.invalidateQueries({ queryKey: ['agent', 'chat'] });
        } catch (e) {
            setError(e.response?.data?.message || 'Could not start profile interview');
        } finally {
            setInterviewBusy(false);
        }
    };

    const abandonProfileInterview = async () => {
        if (!canManage || interviewBusy) return;
        setInterviewBusy(true);
        try {
            const { data } = await api.post('/agent/profile-interview/abandon');
            queryClient.setQueryData(queryKeys.profileInterview, data);
        } catch (e) {
            setError(e.response?.data?.message || 'Could not abandon profile interview');
        } finally {
            setInterviewBusy(false);
        }
    };

    const startNewDraft = () => {
        if (!canManage || chatBusy) return;
        setError('');
        setDrafting(true);
        setActiveChatId(null);
        setPendingAction(null);
        chatHydrated.current = true;
        setMessages(welcomeMessages({
            canManage,
            userName: (me?.user?.name || 'there').split(' ')[0],
            shopName: shop?.name || me?.business?.name || 'your shop',
            agentName: agentQuery.data?.agent?.name || 'Shop assistant',
        }));
    };

    const selectChat = (chatId) => {
        setDrafting(false);
        setActiveChatId(chatId);
    };

    const deleteChat = async (chatId) => {
        if (!canManage || chatBusy || !chatId) return;
        if (!window.confirm('Delete this chat? Messages will be removed.')) return;
        setChatBusy(true);
        setError('');
        const current = Array.isArray(chatsQuery.data?.chats) ? chatsQuery.data.chats : [];
        const remaining = current.filter((c) => Number(c.id) !== Number(chatId));
        queryClient.setQueryData(queryKeys.agentChats, (old) => ({
            ...(old || {}),
            chats: remaining,
        }));
        queryClient.removeQueries({ queryKey: queryKeys.agentChat(chatId) });
        if (Number(activeChatId) === Number(chatId)) {
            setDrafting(false);
            setActiveChatId(remaining[0]?.id ?? null);
            if (remaining.length === 0) {
                chatHydrated.current = true;
                setMessages(welcomeMessages({
                    canManage,
                    userName: (me?.user?.name || 'there').split(' ')[0],
                    shopName: shop?.name || me?.business?.name || 'your shop',
                    agentName: agentQuery.data?.agent?.name || 'Shop assistant',
                }));
            }
        }
        try {
            await api.delete(`/agent/chats/${chatId}`);
            await queryClient.invalidateQueries({ queryKey: queryKeys.agentChats });
        } catch (e) {
            await queryClient.invalidateQueries({ queryKey: queryKeys.agentChats });
            setError(e.response?.data?.message || 'Could not delete chat');
        } finally {
            setChatBusy(false);
        }
    };

    const userLetter = (me?.user?.name || shop?.letter || 'U').slice(0, 1).toUpperCase();
    const agentName = agent?.name || 'Shop assistant';
    const aiOn = Boolean(agent?.ai_enabled);

    // Temporarily hidden — Complete profile card (Channel identity interview).
    const showInterview = false;

    const chatList = Array.isArray(chatsQuery.data?.chats) ? chatsQuery.data.chats : [];

    return (
        <div className="relative flex h-full min-h-0 overflow-hidden bg-white">
            {showInterview && (
                <div className="pointer-events-none absolute inset-y-0 right-3 z-20 flex items-center sm:right-5">
                    <div className="pointer-events-auto">
                        <ProfileInterviewCard
                            interview={interviewQuery.data?.interview}
                            progress={interviewQuery.data?.progress}
                            busy={interviewBusy}
                            onStart={startProfileInterview}
                            onAbandon={abandonProfileInterview}
                        />
                    </div>
                </div>
            )}

            <AgentChatSidebar
                canManage={canManage}
                canView={canView}
                chatBusy={chatBusy}
                chatList={chatList}
                chatsLoading={chatsQuery.isLoading}
                activeChatId={activeChatId}
                drafting={drafting}
                reduceMotion={reduceMotion}
                onNewDraft={startNewDraft}
                onSelectChat={selectChat}
                onDeleteChat={deleteChat}
            />

            <div className={`mx-auto flex h-full min-h-0 w-full ${CHAT_MAX} flex-col`}>
                <ChatHeader
                    agentName={agentName}
                    aiOn={aiOn}
                    loading={loading}
                    walletBalance={walletBalance}
                    walletCurrency={walletCurrency}
                    imageModel={imageModel}
                    imageModels={imageModelsQuery.data?.models || []}
                    llmModel={llmModel}
                    llmModels={llmModelsQuery.data?.models || []}
                    canManage={canManage}
                    savingImageModel={savingImageModel}
                    savingLlmModel={savingLlmModel}
                    onImageModelChange={saveImageModel}
                    onLlmModelChange={saveLlmModel}
                />

                <div ref={scrollRef} className="agent-chat-scroll min-h-0 flex-1 overflow-y-auto px-4 py-5 sm:px-6">
                    {loading ? (
                        <ChatSkeleton />
                    ) : (
                        <div className="space-y-5 pb-2">
                            {messages.map((msg, index) => {
                                const prev = messages[index - 1];
                                const showAvatar = msg.role === 'agent' && prev?.role !== 'agent';
                                return (
                                    <ChatBubble
                                        key={msg.id}
                                        message={msg}
                                        index={index}
                                        reduceMotion={reduceMotion}
                                        userLetter={userLetter}
                                        showAvatar={showAvatar}
                                        canManage={canManage}
                                        onUseAsset={useGeneratedAsset}
                                        onRegenerateAsset={() => sendText(regeneratePrompt(agent?.language))}
                                    />
                                );
                            })}
                            {sending && (
                                <AgentRow showAvatar>
                                    <TypingDots />
                                </AgentRow>
                            )}
                            {pendingAction && canManage && (
                                <PendingCard
                                    action={pendingAction}
                                    busy={confirming}
                                    onConfirm={confirmPending}
                                    onCancel={cancelPending}
                                    onPendingUpdated={setPendingAction}
                                    onError={setError}
                                />
                            )}
                            <div ref={bottomRef} className="h-px w-full shrink-0" aria-hidden />
                        </div>
                    )}
                </div>

                <Composer
                    canManage={canManage}
                    uploading={uploading}
                    sending={sending}
                    pendingAssets={pendingAssets}
                    onRemovePending={removePendingAsset}
                    onUpload={uploadFiles}
                    onSend={sendText}
                    language={agent?.language}
                />
            </div>
        </div>
    );
}

const sidebarListMotion = {
    initial: { opacity: 0, x: -6 },
    animate: { opacity: 1, x: 0 },
    exit: { opacity: 0, x: -8, height: 0, marginBottom: 0 },
};

function AgentChatSidebar({
    canManage,
    canView,
    chatBusy,
    chatList,
    chatsLoading,
    activeChatId,
    drafting,
    reduceMotion,
    onNewDraft,
    onSelectChat,
    onDeleteChat,
}) {
    // ChatGPT-style: draft is local only — nothing appears in the list until first message is sent.
    const showEmpty = !chatsLoading && chatList.length === 0;
    const listTransition = reduceMotion ? { duration: 0 } : { duration: 0.18, ease: 'easeOut' };

    return (
        <aside className="flex w-[15rem] shrink-0 flex-col border-r border-line/80 bg-bubble/35 sm:w-[16.5rem]">
            <div className="flex items-center justify-between gap-2 px-3 py-2.5">
                <p className="text-[12px] font-semibold uppercase tracking-[0.06em] text-muted">Chats</p>
                {canManage && (
                    <button
                        type="button"
                        disabled={chatBusy}
                        onClick={onNewDraft}
                        className={`inline-flex h-8 w-8 items-center justify-center rounded-lg border transition disabled:opacity-50 ${
                            drafting
                                ? 'border-ink/25 bg-white text-ink'
                                : 'border-line/80 bg-white text-ink hover:border-ink/20'
                        }`}
                        title="New chat"
                        aria-label="New chat"
                        aria-pressed={drafting}
                    >
                        <Plus size={16} strokeWidth={2.25} />
                    </button>
                )}
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto px-2 pb-1">
                {chatsLoading ? (
                    <p className="px-2 py-4 text-center text-[12px] text-muted">Loading…</p>
                ) : showEmpty ? (
                    <p className="px-2 py-6 text-center text-[12px] leading-5 text-muted">
                        Send a message to start a chat.
                    </p>
                ) : (
                    <ul className="space-y-0.5">
                        <AnimatePresence initial={false}>
                            {chatList.map((chat) => {
                                const active = !drafting && Number(chat.id) === Number(activeChatId);
                                return (
                                    <motion.li
                                        key={chat.id}
                                        layout={!reduceMotion}
                                        initial={reduceMotion ? false : sidebarListMotion.initial}
                                        animate={reduceMotion ? {} : sidebarListMotion.animate}
                                        exit={reduceMotion ? {} : sidebarListMotion.exit}
                                        transition={listTransition}
                                        className="group relative"
                                    >
                                        <button
                                            type="button"
                                            onClick={() => onSelectChat(chat.id)}
                                            className={`w-full rounded-lg px-2.5 py-2 pr-9 text-left text-[13px] transition-colors ${
                                                active
                                                    ? 'bg-white font-medium text-ink'
                                                    : 'text-ink/75 hover:bg-white/70 hover:text-ink'
                                            }`}
                                        >
                                            <span className="line-clamp-1">{chat.title || 'Chat'}</span>
                                        </button>
                                        {canManage && (
                                            <button
                                                type="button"
                                                disabled={chatBusy}
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    onDeleteChat(chat.id);
                                                }}
                                                className="absolute right-1 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-md text-muted opacity-70 transition hover:bg-bubble hover:text-ink focus-visible:opacity-100 group-hover:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                                                title="Delete chat"
                                                aria-label="Delete chat"
                                            >
                                                <Trash2 size={14} />
                                            </button>
                                        )}
                                    </motion.li>
                                );
                            })}
                        </AnimatePresence>
                    </ul>
                )}
            </div>
            {canView && <BehaviorRulesPanel canManage={canManage} />}
        </aside>
    );
}

function ProfileInterviewCard({ interview, progress, busy, onStart, onAbandon }) {
    const active = interview?.status === 'active';
    const current = Number(progress?.current || 0);
    const total = Number(progress?.total || 0);
    const pct = total > 0 ? Math.min(100, Math.round((current / total) * 100)) : 0;

    return (
        <div className="w-[15.5rem] overflow-hidden rounded-2xl border border-line bg-white shadow-[0_18px_40px_-22px_rgba(18,24,31,0.4)]">
            <div className="h-1 bg-bubble">
                <div
                    className="h-full bg-coral transition-[width] duration-500"
                    style={{ width: active ? `${pct}%` : '0%' }}
                />
            </div>
            <div className="px-3.5 py-3">
                <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-coral">Channel identity</p>
                {active ? (
                    <>
                        <div className="mt-1.5 flex items-baseline justify-between gap-2">
                            <p className="text-sm font-semibold text-ink">Complete profile</p>
                            <p className="text-xs font-semibold tabular-nums text-ink">{current}/{total}</p>
                        </div>
                        <p className="mt-1 text-xs leading-snug text-muted">Answer in the chat. You can switch topics anytime.</p>
                        <button
                            type="button"
                            disabled={busy}
                            onClick={onAbandon}
                            className="mt-3 inline-flex h-8 items-center rounded-lg border border-line px-2.5 text-xs font-semibold text-ink hover:bg-bubble disabled:opacity-50"
                        >
                            {busy ? 'Leaving…' : 'Abandon'}
                        </button>
                    </>
                ) : (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={onStart}
                        className="mt-1.5 w-full text-left disabled:opacity-50"
                    >
                        <p className="text-sm font-semibold text-ink">Complete profile</p>
                        <p className="mt-1 text-xs leading-snug text-muted">Up to 10 short questions so DM replies stay accurate.</p>
                        <span className="mt-3 inline-flex h-8 items-center rounded-lg bg-ink px-2.5 text-xs font-semibold text-white">
                            {busy ? 'Starting…' : 'Start'}
                        </span>
                    </button>
                )}
            </div>
        </div>
    );
}

function scrubAssetIdLeakText(text) {
    return scrubLeakedPrompt(String(text || '')
        .replace(/^[ \t]*\[?(?:Previously |User )?attached agent asset ids?:?[^\]]*\]?[ \t]*$/gim, '')
        .replace(/^[ \t]*Attached agent asset ids?:?[^\n]*$/gim, '')
        .replace(/\n{3,}/g, '\n\n')
        .trim());
}

function scrubLeakedPrompt(text) {
    return String(text || '')
        .split('\n')
        .filter((line) => {
            const trim = line.trim();
            if (!trim) return true;
            if (/(الوصف اللي|الوصف لي|ها هو الوصف|نستعملو|prompt:|visual prompt|scene description)/i.test(trim)) {
                return false;
            }
            const latin = (trim.match(/[A-Za-z]/g) || []).length;
            const arabic = (trim.match(/[\u0600-\u06FF]/g) || []).length;
            return !(latin >= 40 && arabic < 8);
        })
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

function isImageAsset(asset) {
    if (!asset?.url) return false;
    const mime = String(asset.mime || '').toLowerCase();
    return mime === '' || mime.startsWith('image/');
}

function apiMessageToUi(row) {
    const role = row.role === 'assistant' ? 'agent' : row.role;
    const assets = Array.isArray(row.assets)
        ? row.assets
        : (Array.isArray(row.meta?.assets) ? row.meta.assets : []);
    return {
        id: row.id,
        role,
        text: scrubAssetIdLeakText(row.content || row.text || ''),
        assets,
        imageJobs: Array.isArray(row.image_jobs)
            ? row.image_jobs
            : (Array.isArray(row.meta?.image_jobs) ? row.meta.image_jobs : []),
    };
}

function welcomeMessages({ canManage, userName, shopName, agentName }) {
    return [
        {
            id: 'welcome-sys',
            role: 'system',
            text: canManage ? 'You manage this agent' : 'View only · owners manage settings',
        },
        {
            id: 'welcome-a1',
            role: 'agent',
            text: `Salam ${userName}.\n\nI’m ${agentName} for ${shopName}. Ask me to draft a post, schedule one, or generate an image — I’ll confirm before publishing.`,
        },
    ];
}

function PendingCard({ action, busy, onConfirm, onCancel, onPendingUpdated, onError }) {
    const [viewOpen, setViewOpen] = useState(false);
    const [savingChannels, setSavingChannels] = useState(false);
    const preview = action?.preview && typeof action.preview === 'object' ? action.preview : null;
    const assets = Array.isArray(preview?.assets) ? preview.assets : [];
    const caption = String(preview?.text || '').trim();
    const available = Array.isArray(preview?.available_channels) ? preview.available_channels : [];
    const initialIds = Array.isArray(preview?.account_ids) ? preview.account_ids.map(Number) : [];
    const [selectedIds, setSelectedIds] = useState(initialIds);
    const canPreview = Boolean(caption || assets.length || available.length);

    useEffect(() => {
        setSelectedIds(Array.isArray(preview?.account_ids) ? preview.account_ids.map(Number) : []);
    }, [action?.id, preview?.account_ids]);

    const selectedLabels = available
        .filter((c) => selectedIds.includes(Number(c.id)))
        .map((c) => channelLabel(c));

    const toggleChannel = (id) => {
        const num = Number(id);
        setSelectedIds((prev) => (
            prev.includes(num) ? prev.filter((x) => x !== num) : [...prev, num]
        ));
    };

    const saveChannelsIfNeeded = async () => {
        const current = [...initialIds].sort((a, b) => a - b).join(',');
        const next = [...selectedIds].sort((a, b) => a - b).join(',');
        if (current === next) return true;
        if (selectedIds.length === 0) {
            onError?.('Pick at least one channel.');
            return false;
        }
        setSavingChannels(true);
        try {
            const { data } = await api.post('/agent/chat/pending-channels', {
                action_id: action.id,
                account_ids: selectedIds,
            });
            if (!data.ok) {
                onError?.(data.error || 'Could not update channels');
                return false;
            }
            if (data.pending_action) onPendingUpdated?.(data.pending_action);
            return true;
        } catch (e) {
            onError?.(e.response?.data?.error || e.response?.data?.message || 'Could not update channels');
            return false;
        } finally {
            setSavingChannels(false);
        }
    };

    const confirmWithChannels = async () => {
        const ok = await saveChannelsIfNeeded();
        if (!ok) return;
        setViewOpen(false);
        onConfirm();
    };

    const locked = busy || savingChannels;

    return (
        <>
            <div className="rounded-2xl border border-line bg-bubble/70 px-4 py-3.5 text-left">
                <p className="text-xs font-semibold uppercase tracking-wide text-muted">Needs your confirm</p>
                <p className="mt-1.5 text-[15px] leading-6 text-ink">{action.summary || 'Pending action'}</p>
                {selectedLabels.length > 0 && (
                    <p className="mt-2 text-xs text-muted">
                        Channels: {selectedLabels.join(' · ')}
                    </p>
                )}
                {assets[0] && String(assets[0].mime || '').startsWith('image/') && (
                    <button
                        type="button"
                        onClick={() => setViewOpen(true)}
                        className="mt-3 block overflow-hidden rounded-xl ring-1 ring-line"
                    >
                        <img
                            src={assets[0].url}
                            alt=""
                            className="h-28 w-full max-w-[16rem] object-cover"
                        />
                    </button>
                )}
                <div className="mt-3 flex flex-wrap gap-2">
                    {canPreview && (
                        <button
                            type="button"
                            disabled={locked}
                            onClick={() => setViewOpen(true)}
                            className="inline-flex h-9 items-center rounded-xl bg-white px-4 text-sm font-semibold text-ink ring-1 ring-line disabled:opacity-50"
                        >
                            View
                        </button>
                    )}
                    <button
                        type="button"
                        disabled={locked}
                        onClick={confirmWithChannels}
                        className="inline-flex h-9 items-center rounded-xl bg-ink px-4 text-sm font-semibold text-white disabled:opacity-50"
                    >
                        {locked ? 'Working…' : 'Confirm'}
                    </button>
                    <button
                        type="button"
                        disabled={locked}
                        onClick={onCancel}
                        className="inline-flex h-9 items-center rounded-xl bg-white px-4 text-sm font-semibold text-ink ring-1 ring-line disabled:opacity-50"
                    >
                        Cancel
                    </button>
                </div>
            </div>

            {viewOpen && (
                <div
                    className="fixed inset-0 z-50 flex items-end justify-center bg-ink/50 p-3 sm:items-center sm:p-6"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Post preview"
                    onClick={() => !locked && setViewOpen(false)}
                >
                    <div
                        className="flex max-h-[90dvh] w-full max-w-lg flex-col overflow-hidden rounded-2xl bg-white shadow-[0_24px_80px_-24px_rgba(15,23,42,0.5)]"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                            <p className="text-sm font-semibold text-ink">Post preview</p>
                            <button
                                type="button"
                                disabled={locked}
                                onClick={() => setViewOpen(false)}
                                className="flex h-8 w-8 items-center justify-center rounded-lg text-muted hover:bg-bubble hover:text-ink disabled:opacity-50"
                                aria-label="Close preview"
                            >
                                <X size={16} />
                            </button>
                        </div>
                        <div className="agent-chat-scroll min-h-0 flex-1 overflow-y-auto px-4 py-4">
                            {caption && (
                                <p className="whitespace-pre-wrap text-[15px] leading-6 text-ink">{caption}</p>
                            )}
                            {assets.length > 0 && (
                                <div className={`space-y-3 ${caption ? 'mt-4' : ''}`}>
                                    {assets.map((asset) => {
                                        const isImage = String(asset.mime || '').startsWith('image/');
                                        return isImage ? (
                                            <img
                                                key={asset.id || asset.url}
                                                src={asset.url}
                                                alt={asset.original_name || ''}
                                                className="w-full rounded-xl object-contain ring-1 ring-line"
                                            />
                                        ) : (
                                            <div
                                                key={asset.id || asset.url}
                                                className="rounded-xl bg-bubble px-3 py-2 text-sm text-ink ring-1 ring-line"
                                            >
                                                {asset.original_name || 'Attachment'}
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                            {!caption && assets.length === 0 && (
                                <p className="text-sm text-muted">No caption or media on this draft.</p>
                            )}

                            <div className="mt-5">
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-muted">Post to</p>
                                <p className="mt-1 text-xs text-muted">
                                    Keep the AI selection or change channels for this post.
                                </p>
                                {available.length === 0 ? (
                                    <p className="mt-3 text-sm text-muted">No linked channels.</p>
                                ) : (
                                    <div className="mt-3 space-y-2">
                                        {available.map((channel) => {
                                            const id = Number(channel.id);
                                            const checked = selectedIds.includes(id);
                                            return (
                                                <label
                                                    key={id}
                                                    className={`flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 ring-1 transition ${
                                                        checked ? 'bg-bubble ring-ink/20' : 'bg-white ring-line hover:bg-bubble/50'
                                                    }`}
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={checked}
                                                        disabled={locked}
                                                        onChange={() => toggleChannel(id)}
                                                        className="h-4 w-4 rounded border-line text-ink focus:ring-ink"
                                                    />
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-sm font-semibold text-ink">
                                                            {channelLabel(channel)}
                                                        </span>
                                                        <span className="block truncate text-[11px] font-medium uppercase tracking-wide text-muted">
                                                            {channel.platform || 'channel'}
                                                        </span>
                                                    </span>
                                                </label>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>

                            {preview?.publish_now === false && preview?.scheduled_at && (
                                <p className="mt-4 text-xs font-medium text-muted">
                                    Scheduled: {preview.scheduled_at}
                                </p>
                            )}
                            {preview?.publish_now === true && (
                                <p className="mt-4 text-xs font-medium text-muted">Publishes immediately on confirm.</p>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-2 border-t border-line px-4 py-3">
                            <button
                                type="button"
                                disabled={locked || selectedIds.length === 0}
                                onClick={confirmWithChannels}
                                className="inline-flex h-10 flex-1 items-center justify-center rounded-xl bg-ink px-4 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                {locked ? 'Working…' : 'Confirm'}
                            </button>
                            <button
                                type="button"
                                disabled={locked}
                                onClick={() => setViewOpen(false)}
                                className="inline-flex h-10 items-center justify-center rounded-xl bg-white px-4 text-sm font-semibold text-ink ring-1 ring-line disabled:opacity-50"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

function channelLabel(channel) {
    return String(channel?.name || channel?.username || `Channel #${channel?.id || '?'}`).trim();
}

const DEFAULT_LLM_MODELS = [
    { id: 'claude_sonnet', label: 'Claude Sonnet', recommended: true, price_da: 25 },
    { id: 'gpt', label: 'GPT', recommended: false, price_da: 20 },
    { id: 'gemini', label: 'Gemini', recommended: false, price_da: 10 },
];

const DEFAULT_IMAGE_MODELS = [
    { id: 'gpt_image_2', label: 'GPT Image 2', recommended: true, price_da: 14 },
    { id: 'nano_banana_2', label: 'Nano Banana 2', recommended: false, price_da: 20 },
    { id: 'flux', label: 'Flux', recommended: false, price_da: 1 },
];

const MODEL_ICONS = {
    gpt_image_2: gptIcon,
    nano_banana_2: geminiIcon,
    flux: fluxIcon,
};

function ModelMark({ id, size = 20 }) {
    const src = MODEL_ICONS[id];
    if (!src) return <span className="block rounded-md bg-bubble" style={{ width: size, height: size }} />;
    return (
        <img
            src={src}
            alt=""
            width={size}
            height={size}
            className="block shrink-0 object-contain"
            style={{ width: size, height: size }}
            draggable={false}
        />
    );
}

function LlmModelPicker({ value, models, disabled = false, saving = false, onChange }) {
    const options = models.length > 0 ? models : DEFAULT_LLM_MODELS;
    const selected = options.find((m) => m.id === value) ?? options[0];

    return (
        <Listbox value={value || selected?.id} onChange={onChange} disabled={disabled || saving}>
            <ListboxButton
                className="group flex h-9 max-w-[min(100%,14rem)] items-center gap-2 rounded-lg border border-line bg-white px-2.5 text-left text-ink transition hover:border-ink/25 data-open:border-ink disabled:cursor-not-allowed disabled:opacity-55"
                aria-label="Chat model"
            >
                <span className="min-w-0 truncate text-[13px] font-medium leading-none">
                    {selected?.label ?? 'Chat'}
                </span>
                <span className="shrink-0 text-[12px] tabular-nums text-muted">
                    {selected?.price_da ?? '—'} DA
                </span>
                {saving ? (
                    <LoaderCircle size={14} className="shrink-0 animate-spin text-muted" />
                ) : (
                    <ChevronDown size={14} strokeWidth={2} className="shrink-0 text-muted" />
                )}
            </ListboxButton>
            <ListboxOptions
                anchor="bottom end"
                className="z-50 mt-1 w-56 rounded-xl border border-line bg-white p-1 shadow-lg focus:outline-none"
            >
                {options.map((model) => (
                    <ListboxOption
                        key={model.id}
                        value={model.id}
                        className="flex cursor-pointer items-center justify-between rounded-lg px-2.5 py-2 text-sm text-ink data-focus:bg-bubble"
                    >
                        <span>{model.label}{model.recommended ? ' · Recommended' : ''}</span>
                        <span className="tabular-nums text-muted">{model.price_da} DA</span>
                    </ListboxOption>
                ))}
            </ListboxOptions>
        </Listbox>
    );
}

function ImageModelPicker({
    value,
    models = DEFAULT_IMAGE_MODELS,
    disabled = false,
    saving = false,
    onChange,
}) {
    const options = models.length > 0 ? models : DEFAULT_IMAGE_MODELS;
    const selected = options.find((m) => m.id === value) ?? options[0];

    return (
        <Listbox value={value || selected?.id} onChange={onChange} disabled={disabled || saving}>
            <ListboxButton
                className="group flex h-9 max-w-[min(100%,15.5rem)] items-center gap-2 rounded-lg border border-line bg-white px-2 pr-2 text-left text-ink transition hover:border-ink/25 data-open:border-ink disabled:cursor-not-allowed disabled:opacity-55"
                aria-label="Image generation model"
            >
                <ModelMark id={selected?.id} size={18} />
                <span className="min-w-0 truncate text-[13px] font-medium leading-none">
                    {selected?.label ?? 'Model'}
                </span>
                <span className="shrink-0 text-[12px] tabular-nums text-muted">
                    {selected?.price_da ?? '—'} DA
                </span>
                {saving ? (
                    <LoaderCircle size={14} className="shrink-0 animate-spin text-muted" />
                ) : (
                    <ChevronDown
                        size={14}
                        strokeWidth={2}
                        className="shrink-0 text-muted transition-transform duration-200 group-data-open:rotate-180"
                    />
                )}
            </ListboxButton>
            <ListboxOptions
                anchor="bottom end"
                transition
                className="z-[70] mt-1.5 w-[min(calc(100vw-2rem),16.5rem)] origin-top overflow-hidden rounded-xl border border-line bg-white p-1 shadow-[var(--shadow-card)] transition duration-150 ease-out data-closed:scale-[0.98] data-closed:opacity-0"
            >
                {options.map((m) => (
                    <ListboxOption
                        key={m.id}
                        value={m.id}
                        className="cursor-pointer rounded-lg px-2 py-2 outline-none data-focus:bg-bubble data-selected:bg-selected"
                    >
                        {({ selected: isSelected }) => (
                            <div className="flex items-center gap-2.5">
                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-white ring-1 ring-line/80">
                                    <ModelMark id={m.id} size={18} />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[13px] font-medium text-ink">{m.label}</span>
                                    <span className="block text-[11px] text-muted">
                                        {m.recommended ? 'Recommended · ' : ''}
                                        {m.price_da} DA
                                    </span>
                                </span>
                                <Check
                                    size={14}
                                    strokeWidth={2.25}
                                    className={isSelected ? 'shrink-0 text-ink' : 'shrink-0 text-transparent'}
                                />
                            </div>
                        )}
                    </ListboxOption>
                ))}
            </ListboxOptions>
        </Listbox>
    );
}

function AgentImageLightbox({ open, onClose, src, alt, canManage, onUse, onRegenerate }) {
    const reduceMotion = useReducedMotion();

    useEffect(() => {
        if (!open) return undefined;
        const onKey = (e) => {
            if (e.key === 'Escape') onClose();
        };
        const prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);
        return () => {
            document.body.style.overflow = prevOverflow;
            window.removeEventListener('keydown', onKey);
        };
    }, [open, onClose]);

    if (typeof document === 'undefined') return null;

    return createPortal(
        <AnimatePresence>
            {open && (
                <motion.div
                    role="dialog"
                    aria-modal="true"
                    aria-label={alt || 'Image preview'}
                    className="fixed inset-0 z-[100] flex flex-col"
                    initial={reduceMotion ? false : { opacity: 0 }}
                    animate={{ opacity: 1 }}
                    exit={reduceMotion ? undefined : { opacity: 0 }}
                    transition={{ duration: reduceMotion ? 0 : 0.22 }}
                >
                    <button
                        type="button"
                        className="absolute inset-0 bg-ink/75 backdrop-blur-sm"
                        aria-label="Close preview"
                        onClick={onClose}
                    />
                    <div className="relative z-10 flex shrink-0 items-center justify-end px-4 pt-4 sm:px-6">
                        <button
                            type="button"
                            onClick={onClose}
                            className="flex h-9 w-9 items-center justify-center rounded-lg bg-white/10 text-white transition hover:bg-white/20"
                            aria-label="Close"
                        >
                            <X size={18} />
                        </button>
                    </div>
                    <div
                        className="relative z-10 flex min-h-0 flex-1 items-center justify-center px-5 pb-4 sm:px-12"
                        onClick={onClose}
                    >
                        <motion.img
                            src={src}
                            alt={alt}
                            className="max-h-[min(78dvh,960px)] max-w-full select-none rounded-xl bg-white object-contain shadow-[0_24px_80px_-24px_rgb(0_0_0/0.55)]"
                            initial={reduceMotion ? false : { opacity: 0, y: 8 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={reduceMotion ? undefined : { opacity: 0 }}
                            transition={{ duration: reduceMotion ? 0 : 0.22, ease: [0.22, 1, 0.36, 1] }}
                            onClick={(e) => e.stopPropagation()}
                            draggable={false}
                        />
                    </div>
                    {canManage && (
                        <div className="relative z-10 flex shrink-0 justify-center gap-2 px-4 pb-6">
                            <button
                                type="button"
                                onClick={() => {
                                    onRegenerate?.();
                                    onClose();
                                }}
                                className="inline-flex h-10 items-center rounded-lg bg-white/10 px-4 text-sm font-medium text-white ring-1 ring-white/20 transition hover:bg-white/20"
                            >
                                Regenerate
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    onUse?.();
                                    onClose();
                                }}
                                className="inline-flex h-10 items-center rounded-lg bg-white px-4 text-sm font-medium text-ink transition hover:bg-white/90"
                            >
                                Use in post
                            </button>
                        </div>
                    )}
                </motion.div>
            )}
        </AnimatePresence>,
        document.body,
    );
}

function ChatHeader({
    agentName,
    aiOn,
    loading,
    walletBalance,
    walletCurrency = 'DZD',
    imageModel,
    imageModels = [],
    llmModel,
    llmModels = [],
    canManage = false,
    savingImageModel = false,
    savingLlmModel = false,
    onImageModelChange,
    onLlmModelChange,
}) {
    const models = Array.isArray(imageModels) && imageModels.length > 0
        ? imageModels
        : DEFAULT_IMAGE_MODELS;

    const balanceLabel = walletBalance != null
        ? `${Number(walletBalance).toLocaleString(undefined, { maximumFractionDigits: 0 })} ${walletCurrency === 'DZD' ? 'DA' : walletCurrency}`
        : 'Shop assistant';

    return (
        <header className="shrink-0 border-b border-line px-4 py-3.5 sm:px-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-3">
                <div className="flex min-w-0 flex-1 items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-bubble">
                        <AgentMark size={24} active />
                    </div>
                    <div className="min-w-0 flex-1 text-left">
                        <p className="truncate text-base font-semibold text-ink">{agentName}</p>
                        <p className="text-sm text-muted">{balanceLabel}</p>
                    </div>
                    <span
                        className={`hidden shrink-0 rounded-full px-2.5 py-1 text-xs font-medium sm:inline-flex ${
                            loading
                                ? 'bg-bubble text-muted'
                                : aiOn
                                    ? 'bg-teal/10 text-teal-dark'
                                    : 'bg-bubble text-muted'
                        }`}
                    >
                        {loading ? '…' : aiOn ? 'On' : 'Off'}
                    </span>
                </div>
                <div className="flex items-center justify-between gap-2 sm:shrink-0 sm:justify-end">
                    <LlmModelPicker
                        value={llmModel || 'claude_sonnet'}
                        models={Array.isArray(llmModels) && llmModels.length ? llmModels : DEFAULT_LLM_MODELS}
                        disabled={!canManage || loading}
                        saving={savingLlmModel}
                        onChange={onLlmModelChange}
                    />
                    <ImageModelPicker
                        value={imageModel || 'gpt_image_2'}
                        models={models}
                        disabled={!canManage || loading}
                        saving={savingImageModel}
                        onChange={onImageModelChange}
                    />
                    <span
                        className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-medium sm:hidden ${
                            loading
                                ? 'bg-bubble text-muted'
                                : aiOn
                                    ? 'bg-teal/10 text-teal-dark'
                                    : 'bg-bubble text-muted'
                        }`}
                    >
                        {loading ? '…' : aiOn ? 'On' : 'Off'}
                    </span>
                </div>
            </div>
        </header>
    );
}

function ChatBubble({ message, index, reduceMotion, userLetter, showAvatar, canManage = false, onUseAsset, onRegenerateAsset }) {
    const delay = reduceMotion ? 0 : Math.min(0.12, 0.03 + index * 0.02);
    const motionProps = reduceMotion
        ? {}
        : {
            initial: { opacity: 0, y: 8 },
            animate: { opacity: 1, y: 0 },
            transition: { duration: 0.28, delay, ease: [0.22, 1, 0.36, 1] },
        };

    if (message.role === 'system') {
        return (
            <motion.p {...motionProps} className="mx-auto max-w-[20rem] text-center text-xs leading-5 text-muted">
                {message.text}
            </motion.p>
        );
    }

    if (message.role === 'user') {
        const assets = Array.isArray(message.assets) ? message.assets : [];
        return (
            <motion.div {...motionProps} className="flex items-end justify-end gap-2.5 pl-8">
                <div className="max-w-[min(100%,24rem)] rounded-2xl rounded-br-md bg-ink px-4 py-3 text-left text-[15px] leading-6 tracking-[0.01em] text-white">
                    {assets.length > 0 && (
                        <div className="mb-2 flex flex-wrap gap-2">
                            {assets.map((asset) => (
                                <MessageAssetPreview key={asset.id || asset.url} asset={asset} dark />
                            ))}
                        </div>
                    )}
                    {message.text && message.text !== '(attachment)' && (
                        <p className="whitespace-pre-wrap">{message.text}</p>
                    )}
                    {(!message.text || message.text === '(attachment)') && assets.length === 0 && (
                        <p>{message.text}</p>
                    )}
                </div>
                <div className="mb-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-coral text-xs font-semibold text-white">
                    {userLetter}
                </div>
            </motion.div>
        );
    }

    const assets = Array.isArray(message.assets) ? message.assets : [];
    const imageAssets = assets.filter(isImageAsset);
    const imageJobs = Array.isArray(message.imageJobs) ? message.imageJobs : [];
    const queuedJob = imageAssets.length === 0 && imageJobs.some((job) => job?.status === 'queued');
    const failedJob = imageAssets.length === 0 && imageJobs.some((job) => job?.status === 'failed');

    return (
        <motion.div {...motionProps}>
            <AgentRow showAvatar={showAvatar}>
                <div className="min-w-0 max-w-[min(100%,24rem)] space-y-2.5 text-left">
                    {message.text && (
                        <div className="rounded-2xl rounded-bl-md bg-bubble px-4 py-3 text-[15px] leading-6 tracking-[0.01em] text-ink">
                            <p className="whitespace-pre-wrap">{message.text}</p>
                            {message.chips?.length > 0 && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {message.chips.map((chip) => (
                                        <span
                                            key={chip}
                                            className="inline-flex rounded-full bg-white px-3 py-1.5 text-xs font-medium text-ink ring-1 ring-line"
                                        >
                                            {chip}
                                        </span>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                    {imageAssets.map((asset) => (
                        <GeneratedImageCard
                            key={asset.id || asset.url}
                            asset={asset}
                            canManage={canManage}
                            onUse={() => onUseAsset?.(asset)}
                            onRegenerate={() => onRegenerateAsset?.(asset)}
                        />
                    ))}
                    {queuedJob && <ImageGenSkeleton />}
                    {failedJob && (
                        <p className="rounded-2xl rounded-bl-md bg-bubble px-4 py-3 text-[15px] leading-6 text-ink">
                            ما قدرتش نولّد الصورة دَرْوك. جرّب مرة أخرى.
                        </p>
                    )}
                    {!message.text && imageAssets.length === 0 && assets.length > 0 && (
                        <div className="rounded-2xl rounded-bl-md bg-bubble px-4 py-3">
                            <div className="flex flex-wrap gap-2">
                                {assets.map((asset) => (
                                    <MessageAssetPreview key={asset.id || asset.url} asset={asset} />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </AgentRow>
        </motion.div>
    );
}

function ImageGenSkeleton() {
    return (
        <div className="aspect-square w-full max-w-[20rem] animate-pulse rounded-xl bg-line/80 ring-1 ring-line" />
    );
}

function GeneratedImageCard({ asset, canManage = false, onUse, onRegenerate }) {
    const [viewOpen, setViewOpen] = useState(false);
    const name = asset.original_name || 'Generated image';

    return (
        <>
            <div className="max-w-[20rem] overflow-hidden rounded-xl bg-white ring-1 ring-line">
                <button
                    type="button"
                    onClick={() => setViewOpen(true)}
                    className="group relative block w-full text-left"
                >
                    <img
                        src={asset.url}
                        alt={name}
                        className="max-h-64 w-full bg-bubble object-cover"
                    />
                    <span className="absolute inset-0 bg-ink/0 transition duration-200 group-hover:bg-ink/15" />
                    <span className="absolute right-2 top-2 flex h-8 w-8 items-center justify-center rounded-md bg-white text-ink opacity-0 shadow-sm ring-1 ring-line/60 transition duration-200 group-hover:opacity-100">
                        <Maximize2 size={14} strokeWidth={2} />
                    </span>
                </button>
                <div className="flex flex-wrap items-center gap-1 border-t border-line px-2.5 py-2">
                    <button
                        type="button"
                        onClick={() => setViewOpen(true)}
                        className="inline-flex h-8 items-center rounded-md px-2.5 text-[12px] font-medium text-ink transition hover:bg-bubble"
                    >
                        View
                    </button>
                    {canManage && (
                        <>
                            <button
                                type="button"
                                onClick={onRegenerate}
                                className="inline-flex h-8 items-center rounded-md px-2.5 text-[12px] font-medium text-ink transition hover:bg-bubble"
                            >
                                Regenerate
                            </button>
                            <button
                                type="button"
                                onClick={onUse}
                                className="inline-flex h-8 items-center rounded-md bg-ink px-2.5 text-[12px] font-medium text-white transition hover:bg-ink/90"
                            >
                                Use
                            </button>
                        </>
                    )}
                </div>
            </div>

            <AgentImageLightbox
                open={viewOpen}
                onClose={() => setViewOpen(false)}
                src={asset.url}
                alt={name}
                canManage={canManage}
                onUse={onUse}
                onRegenerate={onRegenerate}
            />
        </>
    );
}

function AgentRow({ children, showAvatar = true }) {
    return (
        <div className="flex items-end justify-start gap-2.5 pr-8">
            {showAvatar ? (
                <div className="mb-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-bubble">
                    <AgentMark size={16} active />
                </div>
            ) : (
                <div className="h-8 w-8 shrink-0" aria-hidden />
            )}
            {children}
        </div>
    );
}

function TypingDots() {
    return (
        <div className="flex items-center gap-1.5 rounded-2xl rounded-bl-md bg-bubble px-4 py-3.5">
            {[0, 1, 2].map((i) => (
                <span
                    key={i}
                    className="h-1.5 w-1.5 animate-bounce rounded-full bg-muted/50"
                    style={{ animationDelay: `${i * 0.15}s` }}
                />
            ))}
        </div>
    );
}

function MessageAssetPreview({ asset, dark = false, onRemove }) {
    const isImage = String(asset.mime || '').startsWith('image/');
    const key = asset.localId || asset.id;
    return (
        <div className="group relative h-[4.5rem] w-[4.5rem] shrink-0 overflow-hidden rounded-2xl bg-bubble ring-1 ring-line">
            {isImage ? (
                <img src={asset.url} alt={asset.original_name || ''} className="h-full w-full object-cover" />
            ) : (
                <div className={`flex h-full w-full flex-col items-center justify-center gap-0.5 px-1 ${dark ? 'bg-white/10' : 'bg-bubble'}`}>
                    <FileText size={16} className={dark ? 'text-white/80' : 'text-muted'} />
                    <span className={`line-clamp-2 text-center text-[8px] font-medium leading-tight ${dark ? 'text-white/80' : 'text-ink'}`}>
                        {asset.original_name || 'File'}
                    </span>
                </div>
            )}
            {asset.uploading && (
                <div className="absolute inset-0 flex items-center justify-center bg-ink/45">
                    <LoaderCircle size={18} className="animate-spin text-white" />
                </div>
            )}
            {onRemove && !asset.uploading && (
                <button
                    type="button"
                    onClick={() => onRemove(key)}
                    className="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full bg-ink/80 text-white"
                    aria-label="Remove attachment"
                >
                    <X size={10} />
                </button>
            )}
        </div>
    );
}

function Composer({ canManage, uploading, sending, pendingAssets = [], onRemovePending, onUpload, onSend, language }) {
    const reduceMotion = useReducedMotion();
    const [draft, setDraft] = useState('');
    const [baseDraft, setBaseDraft] = useState('');
    const [voiceLang, setVoiceLang] = useState(() => defaultVoiceChoice(language));

    const {
        transcript,
        listening,
        resetTranscript,
        browserSupportsSpeechRecognition,
        isMicrophoneAvailable,
    } = useSpeechRecognition();

    useEffect(() => {
        setVoiceLang(defaultVoiceChoice(language));
    }, [language]);

    useEffect(() => {
        if (listening) {
            const next = [baseDraft, transcript].filter(Boolean).join(baseDraft && transcript ? ' ' : '');
            setDraft(next);
        }
    }, [transcript, listening, baseDraft]);

    useEffect(() => () => {
        SpeechRecognition.stopListening();
        SpeechRecognition.abortListening();
    }, []);

    const { getRootProps, getInputProps, open, isDragActive } = useDropzone({
        onDrop: (files) => onUpload(files),
        multiple: true,
        disabled: !canManage || sending,
        noClick: true,
        noKeyboard: true,
        accept: {
            'image/jpeg': ['.jpg', '.jpeg'],
            'image/png': ['.png'],
            'image/webp': ['.webp'],
            'image/gif': ['.gif'],
            'application/pdf': ['.pdf'],
        },
    });

    const startVoice = async (locale) => {
        setBaseDraft(draft.trim());
        resetTranscript();
        await SpeechRecognition.startListening({
            continuous: true,
            interimResults: true,
            language: locale,
        });
    };

    const stopVoice = async () => {
        await SpeechRecognition.stopListening();
        setBaseDraft(draft.trim());
        resetTranscript();
    };

    const toggleListen = async () => {
        if (!browserSupportsSpeechRecognition) return;
        if (listening) {
            await stopVoice();
            return;
        }
        await startVoice(voiceLocale(voiceLang));
    };

    const onVoiceLangChange = async (next) => {
        setVoiceLang(next);
        if (listening) {
            await SpeechRecognition.stopListening();
            resetTranscript();
            setBaseDraft(draft.trim());
            await SpeechRecognition.startListening({
                continuous: true,
                interimResults: true,
                language: voiceLocale(next),
            });
        }
    };

    const openFiles = () => {
        if (listening) {
            SpeechRecognition.stopListening();
            setBaseDraft(draft.trim());
            resetTranscript();
        }
        open();
    };

    const submit = async () => {
        const text = draft.trim();
        if (pendingAssets.some((a) => a.uploading)) return;
        const ids = pendingAssets.filter((a) => a.id).map((a) => a.id);
        if ((!text && ids.length === 0) || sending) return;
        if (listening) {
            await SpeechRecognition.stopListening();
            resetTranscript();
        }
        setDraft('');
        setBaseDraft('');
        await onSend(text, voiceLang, ids);
    };

    if (!canManage) {
        return (
            <div className="shrink-0 border-t border-line px-4 py-3 sm:px-6 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                <p className="rounded-2xl bg-bubble px-4 py-3.5 text-center text-sm leading-6 text-muted">
                    View only — ask an owner to chat with the agent.
                </p>
            </div>
        );
    }

    const micReady = browserSupportsSpeechRecognition && isMicrophoneAvailable !== false;
    const readyIds = pendingAssets.filter((a) => a.id && !a.uploading);
    const stillUploading = pendingAssets.some((a) => a.uploading) || uploading;
    const canSend = (draft.trim().length > 0 || readyIds.length > 0) && !sending && !stillUploading;

    return (
        <div className="shrink-0 border-t border-line px-4 py-3 sm:px-6 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
            {pendingAssets.length > 0 && (
                <div className="mb-2.5 flex flex-wrap gap-2 px-1">
                    {pendingAssets.map((asset) => (
                        <MessageAssetPreview
                            key={asset.localId || asset.id}
                            asset={asset}
                            onRemove={onRemovePending}
                        />
                    ))}
                </div>
            )}
            <div
                {...getRootProps({
                    className: `outline-none rounded-2xl transition ${
                        isDragActive ? 'ring-2 ring-coral/40 ring-offset-2 ring-offset-white' : ''
                    }`,
                })}
            >
                <input {...getInputProps()} />
                {isDragActive && (
                    <div className="mb-2 rounded-xl border border-dashed border-coral/50 bg-coral/5 px-3 py-2 text-center text-xs font-semibold text-coral">
                        Drop images to attach
                    </div>
                )}
                <div
                    className={`flex items-center gap-1.5 rounded-2xl bg-bubble p-1.5 transition ${
                        listening ? 'ring-2 ring-coral/30' : ''
                    } ${isDragActive ? 'bg-cream-deep' : ''}`}
                >
                    <button
                        type="button"
                        disabled={sending}
                        onClick={openFiles}
                        className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-muted transition hover:bg-white hover:text-ink disabled:opacity-50"
                        aria-label="Attach image"
                        title="Attach image"
                    >
                        <Paperclip size={18} />
                    </button>

                    <input
                        type="text"
                        value={draft}
                        disabled={sending}
                        onChange={(e) => {
                            if (listening) {
                                SpeechRecognition.stopListening();
                                resetTranscript();
                            }
                            setDraft(e.target.value);
                            setBaseDraft(e.target.value);
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                submit();
                            }
                        }}
                        placeholder={listening ? 'Listening…' : sending ? 'Thinking…' : 'Type or tap the mic…'}
                        className="min-w-0 flex-1 bg-transparent px-1 py-2.5 text-[15px] text-ink outline-none placeholder:text-muted/70 disabled:opacity-60"
                        aria-label="Message your agent"
                    />

                    <div
                        className="flex h-8 shrink-0 items-center rounded-lg bg-white p-0.5 ring-1 ring-line"
                        role="group"
                        aria-label="Speech language"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {[
                            { id: 'darija', label: 'DZ' },
                            { id: 'french', label: 'FR' },
                        ].map((opt) => {
                            const active = voiceLang === opt.id;
                            return (
                                <button
                                    key={opt.id}
                                    type="button"
                                    onClick={() => onVoiceLangChange(opt.id)}
                                    title={opt.id === 'french' ? 'French' : 'Darija'}
                                    className={`h-7 min-w-[1.75rem] rounded-md px-1.5 text-[10px] font-bold tracking-wide transition ${
                                        active
                                            ? 'bg-ink text-white'
                                            : 'text-muted hover:text-ink'
                                    }`}
                                >
                                    {opt.label}
                                </button>
                            );
                        })}
                    </div>

                    <MicButton
                        listening={listening}
                        disabled={!micReady || sending}
                        reduceMotion={reduceMotion}
                        onClick={toggleListen}
                    />

                    <button
                        type="button"
                        disabled={!canSend}
                        onClick={submit}
                        className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white transition ${
                            canSend ? 'bg-ink hover:opacity-90' : 'bg-line'
                        }`}
                        aria-label="Send"
                        title="Send"
                    >
                        <SendHorizonal size={16} />
                    </button>
                </div>
                <p className="mt-2 text-center text-xs leading-5 text-muted">
                    {listening
                        ? `Listening in ${voiceLang === 'french' ? 'French' : 'Darija'}… tap the mic to stop`
                        : !browserSupportsSpeechRecognition
                            ? 'Voice not supported in this browser — try Chrome'
                            : isMicrophoneAvailable === false
                                ? 'Allow microphone access to use voice'
                                : 'Confirm before any post goes out'}
                </p>
            </div>
        </div>
    );
}

function MicButton({ listening, disabled, reduceMotion, onClick }) {
    return (
        <motion.button
            type="button"
            disabled={disabled}
            onClick={onClick}
            aria-label={listening ? 'Stop listening' : 'Start voice input'}
            title={listening ? 'Stop listening' : 'Speak'}
            className={`relative flex h-10 w-10 shrink-0 items-center justify-center rounded-xl transition disabled:opacity-40 ${
                listening
                    ? 'bg-coral text-white'
                    : 'text-muted hover:bg-white hover:text-ink'
            }`}
            animate={
                listening && !reduceMotion
                    ? { scale: [1, 1.08, 1] }
                    : { scale: 1 }
            }
            transition={
                listening && !reduceMotion
                    ? { duration: 1.1, repeat: Infinity, ease: 'easeInOut' }
                    : { duration: 0.15 }
            }
        >
            {listening && !reduceMotion && (
                <span className="absolute inset-0 animate-ping rounded-xl bg-coral/35" aria-hidden />
            )}
            <span className="relative z-[1]">
                {listening ? <Mic size={18} /> : <MicOff size={18} />}
            </span>
        </motion.button>
    );
}

function defaultVoiceChoice(language) {
    const value = String(language || '').toLowerCase();
    if (value.includes('french') || value === 'fr') return 'french';
    return 'darija';
}

function regeneratePrompt(language) {
    const value = String(language || '').toLowerCase();
    if (value.includes('french') || value === 'fr') return 'Régénère cette image';
    return 'عاود لي هاد الصورة';
}

function voiceLocale(choice) {
    return choice === 'french' ? 'fr-FR' : 'ar-DZ';
}

function ChatSkeleton() {
    return (
        <div className="space-y-5" role="status" aria-label="Loading agent chat">
            <div className="flex items-end gap-2.5">
                <div className="h-8 w-8 animate-pulse rounded-full bg-bubble" />
                <div className="h-14 w-[78%] animate-pulse rounded-2xl bg-bubble" />
            </div>
            <div className="flex items-end justify-end gap-2.5">
                <div className="h-12 w-[52%] animate-pulse rounded-2xl bg-ink/10" />
                <div className="h-8 w-8 animate-pulse rounded-full bg-coral/25" />
            </div>
            <div className="flex items-end gap-2.5">
                <div className="h-8 w-8 animate-pulse rounded-full bg-bubble" />
                <div className="h-16 w-[70%] animate-pulse rounded-2xl bg-bubble" />
            </div>
        </div>
    );
}
