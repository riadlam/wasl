import { useEffect, useState } from 'react';
import { Flame, Users } from 'lucide-react';
import { useSpace } from '../context';
import ContactsPanel from './contacts/ContactsPanel';
import LeadsPanel from './contacts/LeadsPanel';

const LEAD_FILTERS = [
    { id: 'all', label: 'All' },
    { id: 'new', label: 'New' },
    { id: 'hot', label: 'Hot' },
];

export default function ContactsView({ initialSection = 'contacts' }) {
    const { setView } = useSpace();
    const [section, setSection] = useState(initialSection === 'leads' ? 'leads' : 'contacts');
    const [leadStatus, setLeadStatus] = useState('all');

    useEffect(() => {
        setSection(initialSection === 'leads' ? 'leads' : 'contacts');
    }, [initialSection]);

    const openContacts = () => setView('contacts');

    const openLeads = (status = 'all') => {
        setLeadStatus(status);
        setView('leads');
    };

    return (
        <div className="relative flex h-full min-h-0 overflow-hidden bg-cream">
            <aside className="hidden h-full w-[220px] shrink-0 flex-col border-e border-line bg-white/80 lg:flex">
                <div className="flex h-11 items-center px-3">
                    <span className="text-[14px] font-semibold text-ink">Contacts</span>
                </div>

                <p className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wide text-muted">People</p>
                <div className="px-2">
                    <button
                        type="button"
                        onClick={openContacts}
                        className={`flex h-9 w-full items-center gap-2 rounded-lg px-2 text-start text-[13px] ${
                            section === 'contacts' ? 'bg-selected font-semibold text-ink' : 'text-muted hover:bg-bubble'
                        }`}
                    >
                        <Users size={15} strokeWidth={1.75} className="shrink-0" />
                        All contacts
                    </button>
                </div>

                <p className="mt-4 px-3 pb-1 text-[10px] font-semibold uppercase tracking-wide text-muted">Leads</p>
                <div className="flex flex-col gap-0.5 px-2">
                    {LEAD_FILTERS.map((item) => {
                        const active = section === 'leads' && leadStatus === item.id;
                        return (
                            <button
                                key={item.id}
                                type="button"
                                onClick={() => openLeads(item.id)}
                                className={`flex h-9 items-center gap-2 rounded-lg px-2 text-start text-[13px] ${
                                    active ? 'bg-selected font-semibold text-ink' : 'text-muted hover:bg-bubble'
                                }`}
                            >
                                {item.id === 'all' ? (
                                    <Flame size={15} strokeWidth={1.75} className="shrink-0" />
                                ) : (
                                    <span className="w-[15px]" />
                                )}
                                {item.label}
                            </button>
                        );
                    })}
                </div>
            </aside>

            <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                <div className="flex gap-1 border-b border-line bg-white px-3 py-2 lg:hidden">
                    <button
                        type="button"
                        onClick={openContacts}
                        className={`h-8 flex-1 rounded-lg text-[12px] font-semibold ${
                            section === 'contacts' ? 'bg-selected text-ink' : 'text-muted'
                        }`}
                    >
                        Contacts
                    </button>
                    <button
                        type="button"
                        onClick={() => openLeads(leadStatus)}
                        className={`h-8 flex-1 rounded-lg text-[12px] font-semibold ${
                            section === 'leads' ? 'bg-selected text-ink' : 'text-muted'
                        }`}
                    >
                        Leads
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto bg-white">
                    {section === 'leads' ? (
                        <LeadsPanel status={leadStatus} onStatusChange={setLeadStatus} />
                    ) : (
                        <ContactsPanel />
                    )}
                </div>
            </div>
        </div>
    );
}
