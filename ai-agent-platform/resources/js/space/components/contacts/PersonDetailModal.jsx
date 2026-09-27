import { useEffect, useMemo, useState } from 'react';
import { LoaderCircle, MapPin, MessageCircle, Save } from 'lucide-react';
import { api } from '../../api';
import { useSpace } from '../../context';
import { platformMeta } from '../channels/platforms';
import { TextField, SelectField } from '../form';
import Modal from '../modals/Modal';
import { LeadAvatar } from '../ui';

/**
 * Shared editable detail modal for Contacts + Leads.
 * Fields: phone, email, wilaya, commune. Language is intentionally omitted.
 */
export default function PersonDetailModal({
    person,
    title,
    badges = null,
    notes = null,
    preview = null,
    onClose,
    onOpenChat,
    onSaved,
}) {
    const { setError } = useSpace();
    const [phone, setPhone] = useState(person.phone || '');
    const [email, setEmail] = useState(person.email || '');
    const [wilaya, setWilaya] = useState(person.wilaya || '');
    const [commune, setCommune] = useState(person.commune || '');
    const [wilayas, setWilayas] = useState([]);
    const [busy, setBusy] = useState(false);
    const [dirty, setDirty] = useState(false);

    const meta = platformMeta(person.platform);
    const Icon = meta.Icon;

    useEffect(() => {
        setPhone(person.phone || '');
        setEmail(person.email || '');
        setWilaya(person.wilaya || '');
        setCommune(person.commune || '');
        setDirty(false);
    }, [person]);

    useEffect(() => {
        let cancelled = false;
        api.get('/wilayas')
            .then(({ data }) => {
                if (!cancelled) setWilayas(data.wilayas || []);
            })
            .catch(() => {});
        return () => { cancelled = true; };
    }, []);

    const wilayaOptions = useMemo(() => [
        { value: '', label: 'Select wilaya' },
        ...wilayas.map((row) => ({ value: row.name_fr, label: row.name_fr })),
    ], [wilayas]);

    const mark = (setter) => (value) => {
        setter(value);
        setDirty(true);
    };

    const save = async (event) => {
        event.preventDefault();
        if (!person?.id) return;
        setBusy(true);
        try {
            const { data } = await api.patch(`/customers/${person.id}`, {
                phone: phone.trim() || null,
                email: email.trim() || null,
                wilaya: wilaya.trim() || null,
                commune: commune.trim() || null,
            });
            const saved = data.customer || data;
            setDirty(false);
            onSaved?.(saved);
        } catch (err) {
            setError(err.response?.data?.message || 'Could not save contact details.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal open size="xl" title={title || person.name} onClose={onClose}>
            <form onSubmit={save} className="space-y-5">
                <div className="flex items-start gap-3 rounded-2xl border border-line bg-bubble/50 p-3.5">
                    <LeadAvatar name={person.name} src={person.avatar_url} size={56} />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[16px] font-semibold text-ink">{person.name}</p>
                        <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                            {badges}
                            <span className="inline-flex items-center gap-1 rounded-md bg-white px-1.5 py-0.5 text-[10px] font-semibold text-ink ring-1 ring-line">
                                <Icon size={10} style={{ color: meta.color }} /> {meta.label}
                            </span>
                        </div>
                        <p className="mt-1.5 truncate text-[12px] text-muted">
                            {person.account_name || person.username || 'Linked page'}
                        </p>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <TextField
                        label="Phone"
                        value={phone}
                        onChange={(e) => mark(setPhone)(e.target.value)}
                        placeholder="05XX XX XX XX"
                        inputMode="tel"
                    />
                    <TextField
                        label="Email"
                        type="email"
                        value={email}
                        onChange={(e) => mark(setEmail)(e.target.value)}
                        placeholder="name@example.com"
                    />
                </div>

                <div>
                    <p className="mb-2 flex items-center gap-1.5 text-[12px] font-semibold text-muted">
                        <MapPin size={13} /> Location
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {wilayas.length > 0 ? (
                            <SelectField
                                label="Wilaya"
                                value={wilaya}
                                onChange={(value) => mark(setWilaya)(String(value || ''))}
                                options={wilayaOptions}
                            />
                        ) : (
                            <TextField
                                label="Wilaya"
                                value={wilaya}
                                onChange={(e) => mark(setWilaya)(e.target.value)}
                                placeholder="Oran, Alger…"
                            />
                        )}
                        <TextField
                            label="Commune"
                            value={commune}
                            onChange={(e) => mark(setCommune)(e.target.value)}
                            placeholder="City / commune"
                        />
                    </div>
                </div>

                {(person.meta_ad_id || person.meta_ad_title) && (
                    <div className="rounded-xl border border-line bg-white px-3 py-2.5">
                        <p className="text-[10px] font-semibold uppercase tracking-wide text-muted">Meta Ad ID</p>
                        <p className="mt-0.5 break-all font-mono text-[12px] font-medium text-ink">
                            {person.meta_ad_title ? `${person.meta_ad_id} · ${person.meta_ad_title}` : person.meta_ad_id}
                        </p>
                    </div>
                )}

                {preview && (
                    <p className="rounded-xl bg-bubble px-3 py-2.5 text-[13px] leading-snug text-ink">{preview}</p>
                )}

                {notes && notes.length > 0 && (
                    <ul className="space-y-1.5">
                        {notes.map((note) => (
                            <li key={note.label} className="rounded-xl bg-bubble px-3 py-2">
                                <div className="text-[10px] font-semibold uppercase tracking-wide text-muted">{note.label}</div>
                                <div className="mt-0.5 text-[12px] text-ink">{note.value}</div>
                            </li>
                        ))}
                    </ul>
                )}

                <div className="flex flex-col gap-2 border-t border-line pt-4 sm:flex-row">
                    <button
                        type="submit"
                        disabled={busy || !dirty}
                        className="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl bg-ink text-sm font-semibold text-white disabled:opacity-45"
                    >
                        {busy ? <LoaderCircle size={15} className="animate-spin" /> : <Save size={15} />}
                        Save details
                    </button>
                    <button
                        type="button"
                        onClick={onOpenChat}
                        disabled={!person.conversation_id}
                        className="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl bg-coral text-sm font-semibold text-white disabled:opacity-50"
                    >
                        <MessageCircle size={16} /> View conversation
                    </button>
                </div>
            </form>
        </Modal>
    );
}
