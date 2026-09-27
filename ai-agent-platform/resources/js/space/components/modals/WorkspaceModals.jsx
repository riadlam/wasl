import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { channels, contacts, shop } from '../../data';
import { useSpace } from '../../context';
import { api } from '../../api';
import { mobilePrimaryNav, workspaceNav, workspaceTopTools, workspaceUtility } from '../../navConfig';
import Modal from './Modal';
import { SelectField, TextField, SearchField } from '../form';
import { setupProgress } from '../../setup';
import { Check } from 'lucide-react';
import { LogoutForm } from '../LogoutForm';

export default function WorkspaceModals() {
    const { modal, closeModal, openModal, moreOpen, setMoreOpen, setView, view, conversations, setSelectedId, addContact, can } = useSpace();
    const moreItems = workspaceNav.filter(
        (item) => !mobilePrimaryNav.includes(item.id) && (!item.permission || can(item.permission)),
    );

    return (
        <>
            <ConnectModal open={modal === 'connect'} onClose={closeModal} />
            <UpgradeModal open={modal === 'upgrade'} onClose={closeModal} />
            <ChecklistModal open={modal === 'checklist'} onClose={closeModal} />
            <WorkspaceMenu open={modal === 'workspace'} onClose={closeModal} />
            <HelpModal open={modal === 'help'} onClose={closeModal} onChecklist={() => openModal('checklist')} onConnect={() => openModal('connect')} />
            <NotificationsModal open={modal === 'notifications'} onClose={closeModal} />
            <UserModal open={modal === 'user'} onClose={closeModal} onSettings={() => { closeModal(); setView('settings'); }} onHelp={() => openModal('help')} />
            <SearchModal
                open={modal === 'search'}
                onClose={closeModal}
                onPickView={(id) => { closeModal(); setView(id); }}
                onPickChat={(id) => { closeModal(); setView('inbox'); setSelectedId(id); }}
                conversations={conversations}
            />
            <ContactModal open={modal === 'contact'} onClose={closeModal} onSave={addContact} />
            {moreOpen && (
                <div className="fixed inset-0 z-30 md:hidden" onClick={() => setMoreOpen(false)}>
                    <div className="absolute inset-x-0 bottom-[68px] rounded-t-2xl border border-line bg-white p-3 shadow-2xl" onClick={(e) => e.stopPropagation()}>
                        {moreItems.map((item) => {
                            const Icon = item.icon;
                            const active = view === item.id;
                            return (
                                <button
                                    key={item.id}
                                    type="button"
                                    onClick={() => setView(item.id)}
                                    className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-semibold ${active ? 'bg-selected text-ink' : 'text-ink hover:bg-bubble'}`}
                                >
                                    <Icon size={18} />
                                    {item.label}
                                </button>
                            );
                        })}
                        <div className="mt-2 grid grid-cols-2 gap-2 border-t border-line pt-2">
                            {[...workspaceTopTools, ...workspaceUtility].map((item) => {
                                const Icon = item.icon;
                                return (
                                    <button
                                        key={item.id}
                                        type="button"
                                        onClick={() => { setMoreOpen(false); openModal(item.modal); }}
                                        className="flex items-center justify-center gap-2 rounded-xl bg-bubble px-3 py-2 text-sm font-semibold text-ink"
                                    >
                                        <Icon size={14} />
                                        {item.label}
                                    </button>
                                );
                            })}
                            <button
                                type="button"
                                onClick={() => { setMoreOpen(false); openModal('connect'); }}
                                className="rounded-xl bg-bubble px-3 py-2 text-sm font-semibold text-ink"
                            >
                                Connect
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

function ConnectModal({ open, onClose }) {
    const { can, setError, setView } = useSpace();
    const [busy, setBusy] = useState('');
    const platforms = [
        { id: 'instagram', label: 'Instagram' },
        { id: 'facebook', label: 'Facebook' },
        { id: 'whatsapp', label: 'WhatsApp' },
    ];

    const connect = async (platform) => {
        if (!can('settings.manage') || busy) return;
        setBusy(platform);
        try {
            const { data } = await api.post('/social-accounts/connect', { platform });
            if (data.auth_url) {
                window.location.assign(data.auth_url);
                return;
            }
            setError('Could not start connect.');
        } catch (err) {
            setError(err.response?.data?.message || 'Could not start connect.');
        } finally {
            setBusy('');
        }
    };

    return (
        <Modal open={open} title="Connect a channel" onClose={onClose}>
            <p className="mb-3 text-sm text-muted">Connect Instagram, Facebook, or WhatsApp. Manage connected accounts in Settings.</p>
            <div className="space-y-2">
                {platforms.map((platform) => (
                    <button
                        key={platform.id}
                        type="button"
                        disabled={!can('settings.manage') || Boolean(busy)}
                        onClick={() => connect(platform.id)}
                        className="flex w-full items-center justify-between rounded-xl border border-line px-3 py-2.5 text-sm font-semibold text-ink hover:bg-bubble disabled:opacity-60"
                    >
                        {platform.label}
                        <span className="text-xs font-semibold text-accent">{busy === platform.id ? 'Opening…' : 'Connect'}</span>
                    </button>
                ))}
            </div>
            <button
                type="button"
                className="mt-3 text-sm font-semibold text-accent"
                onClick={() => { onClose(); setView('channels'); }}
            >
                Open channels
            </button>
        </Modal>
    );
}

function UpgradeModal({ open, onClose }) {
    const [sent, setSent] = useState(false);
    return (
        <Modal open={open} title="Upgrade now" onClose={onClose}>
            {sent ? (
                <p className="rounded-xl bg-teal/10 px-3 py-4 text-sm font-semibold text-teal-dark">We’ll write you on WhatsApp about Growth. No charge yet.</p>
            ) : (
                <>
                    <ul className="space-y-2 text-sm">
                        <li className="rounded-xl border border-line bg-white px-3 py-2 text-ink">Unlimited inbox seats for the shop</li>
                        <li className="rounded-xl border border-line bg-white px-3 py-2 text-ink">Agent replies, carousels, and weekly stats</li>
                        <li className="rounded-xl border border-line bg-white px-3 py-2 text-ink">WhatsApp, Instagram, Facebook, TikTok</li>
                    </ul>
                    <button type="button" onClick={() => setSent(true)} className="mt-4 w-full rounded-xl bg-coral py-2.5 text-sm font-semibold text-white">
                        Ask for Growth
                    </button>
                </>
            )}
        </Modal>
    );
}

function ChecklistModal({ open, onClose }) {
    const { me, can, socialAccounts, conversations, shopSignals, setView } = useSpace();
    const progress = setupProgress({
        me,
        socialAccounts,
        conversations,
        aiEnabled: shopSignals?.aiEnabled,
        leadCount: shopSignals?.leadCount,
        orderCount: shopSignals?.orderCount,
        canView: can,
    });
    const pct = progress.total ? Math.round((progress.done / progress.total) * 100) : 0;

    return (
        <Modal open={open} title="Onboarding" onClose={onClose}>
            <p className="text-[13px] text-muted">
                {progress.done} of {progress.total} done.
            </p>
            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-line">
                <div className="h-full rounded-full bg-accent" style={{ width: `${pct}%` }} />
            </div>
            {progress.done === progress.total && progress.total > 0 && (
                <p className="mt-4 rounded-lg bg-teal/10 px-3 py-3 text-[13px] font-medium text-teal-dark">Shop is ready.</p>
            )}
            <div className="mt-3 divide-y divide-line overflow-hidden rounded-xl border border-line">
                {progress.items.map((item) => (
                    <div key={item.id} className="flex items-center gap-3 px-3 py-2.5">
                        <span className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full ${
                            item.done ? 'bg-teal/15 text-teal-dark' : 'border border-line text-muted'
                        }`}>
                            {item.done && <Check size={12} strokeWidth={3} />}
                        </span>
                        <span className={`min-w-0 flex-1 text-[13px] ${item.done ? 'text-muted' : 'font-medium text-ink'}`}>
                            {item.label}
                        </span>
                        <button
                            type="button"
                            onClick={() => { onClose(); setView(item.view); }}
                            className="h-7 rounded-lg border border-line px-2.5 text-[12px] font-semibold text-ink hover:bg-bubble"
                        >
                            Go
                        </button>
                    </div>
                ))}
            </div>
        </Modal>
    );
}

function WorkspaceMenu({ open, onClose }) {
    const { shop } = useSpace();
    return (
        <Modal open={open} title="Workspace" onClose={onClose}>
            <p className="text-sm font-bold">{shop.name}</p>
            <p className="mt-1 text-sm text-muted">{shop.owner ? `Signed in as ${shop.owner}` : 'Shop workspace'}</p>
            <p className="mt-3 text-xs text-muted">Switching shops arrives with accounts. This workspace is the only one for now.</p>
        </Modal>
    );
}

function HelpModal({ open, onClose, onChecklist, onConnect }) {
    return (
        <Modal open={open} title="Help" onClose={onClose}>
            <div className="space-y-2">
                <button type="button" onClick={onChecklist} className="block w-full rounded-xl border border-line bg-white px-3 py-2.5 text-left text-sm font-semibold text-ink hover:bg-bubble">Open onboarding checklist</button>
                <button type="button" onClick={onConnect} className="block w-full rounded-xl border border-line bg-white px-3 py-2.5 text-left text-sm font-semibold text-ink hover:bg-bubble">Connect a channel</button>
                <a href="/" className="block rounded-xl border border-line bg-white px-3 py-2.5 text-sm font-semibold text-ink hover:bg-bubble">Back to the Wasl site</a>
            </div>
        </Modal>
    );
}

function NotificationsModal({ open, onClose }) {
    const { alerts, openInboxConversation, setView } = useSpace();

    return (
        <Modal open={open} title="Alerts" onClose={onClose}>
            {!alerts?.length ? (
                <p className="py-6 text-center text-[13px] text-muted">You’re caught up.</p>
            ) : (
                <div className="divide-y divide-line overflow-hidden rounded-xl border border-line">
                    {alerts.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => {
                                onClose();
                                if (item.kind === 'order') {
                                    setView('orders');
                                    return;
                                }
                                openInboxConversation(item.conversation_id, item.conversation_status);
                            }}
                            className="block w-full px-3 py-2.5 text-start hover:bg-bubble"
                        >
                            <div className="flex items-baseline justify-between gap-2">
                                <span className="text-[13px] font-medium text-ink">{item.title}</span>
                                <span className="shrink-0 text-[11px] text-muted">{item.time}</span>
                            </div>
                            <p className="mt-0.5 truncate text-[12px] text-muted">
                                {item.name}{item.body ? ` · ${item.body}` : ''}
                            </p>
                        </button>
                    ))}
                </div>
            )}
        </Modal>
    );
}

function UserModal({ open, onClose, onSettings, onHelp }) {
    const { shop, me } = useSpace();
    return (
        <Modal open={open} title={me?.user?.name || shop.owner} onClose={onClose}>
            <p className="text-sm text-muted">
                {me?.role === 'owner' ? 'Owner' : me?.role === 'staff' ? 'Staff' : 'Member'} of {shop.name}
            </p>
            <button type="button" onClick={onSettings} className="mt-3 w-full rounded-xl border border-line bg-white px-3 py-2.5 text-sm font-semibold text-ink">Settings</button>
            <button type="button" onClick={onHelp} className="mt-2 w-full rounded-xl border border-line bg-white px-3 py-2.5 text-sm font-semibold text-ink">Help</button>
            <LogoutForm
                className="mt-3"
                buttonClassName="w-full rounded-xl bg-coral/10 px-3 py-2.5 text-sm font-semibold text-coral ring-1 ring-coral/25 hover:bg-coral/15"
                label="Log out"
            />
        </Modal>
    );
}

function SearchModal({ open, onClose, onPickView, onPickChat, conversations }) {
    const [q, setQ] = useState('');
    const views = useMemo(() => workspaceNav.filter((item) => item.label.toLowerCase().includes(q.trim().toLowerCase())), [q]);
    const chats = useMemo(
        () => conversations.filter((c) => `${c.name} ${c.preview}`.toLowerCase().includes(q.trim().toLowerCase())).slice(0, 5),
        [conversations, q],
    );
    const people = contacts.filter((c) => c.name.toLowerCase().includes(q.trim().toLowerCase())).slice(0, 4);

    return (
        <Modal open={open} title="Search" onClose={onClose} wide>
            <SearchField value={q} onChange={setQ} placeholder="Views, chats, contacts" />
            <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted">Views</p>
            {views.map((item) => (
                <button key={item.id} type="button" onClick={() => onPickView(item.id)} className="block w-full rounded-lg px-2 py-1.5 text-left text-sm font-semibold text-ink hover:bg-bubble">{item.label}</button>
            ))}
            <p className="mb-1 mt-3 text-[11px] font-semibold uppercase tracking-wide text-muted">Chats</p>
            {chats.map((c) => (
                <button key={c.id} type="button" onClick={() => onPickChat(c.id)} className="block w-full rounded-lg px-2 py-1.5 text-left text-sm hover:bg-bubble">
                    <span className="font-semibold text-ink">{c.name}</span>
                    <span className="ms-2 text-muted">{c.preview}</span>
                </button>
            ))}
            <p className="mb-1 mt-3 text-[11px] font-semibold uppercase tracking-wide text-muted">Contacts</p>
            {people.map((c) => (
                <div key={c.id} className="px-2 py-1.5 text-sm">{c.name} · {c.city}</div>
            ))}
        </Modal>
    );
}

function ContactModal({ open, onClose, onSave }) {
    const { register, handleSubmit, reset, setValue, watch, formState: { errors } } = useForm({
        defaultValues: { name: '', city: 'Oran', channel: 'WhatsApp' },
        resolver: zodResolver(z.object({
            name: z.string().trim().min(1, 'Name is required.'),
            city: z.string().default(''),
            channel: z.string().default('WhatsApp'),
        })),
    });

    return (
        <Modal open={open} title="Add contact" onClose={onClose}>
            <form
                className="space-y-3"
                onSubmit={handleSubmit((values) => {
                    onSave(values);
                    reset({ name: '', city: 'Oran', channel: 'WhatsApp' });
                })}
            >
                <TextField label="Name" error={errors.name?.message} {...register('name')} />
                <TextField label="City" {...register('city')} />
                <SelectField
                    label="Channel"
                    value={watch('channel')}
                    onChange={(value) => setValue('channel', value)}
                    options={channels.map((item) => ({ value: item, label: item }))}
                />
                <button type="submit" className="w-full rounded-xl bg-coral py-2.5 text-sm font-semibold text-white">Add to Mine</button>
            </form>
        </Modal>
    );
}
