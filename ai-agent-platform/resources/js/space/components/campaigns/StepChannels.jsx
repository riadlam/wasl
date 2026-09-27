import { Check, Share2 } from 'lucide-react';
import { platformMeta } from '../channels/platforms';
import { channelLabel } from './campaignDefaults';

function ChannelMark({ platform, size = 15 }) {
    const meta = platformMeta(platform);
    const Icon = meta.Icon;
    return (
        <div
            className="flex shrink-0 items-center justify-center rounded-md"
            style={{ width: size + 14, height: size + 14, backgroundColor: `${meta.color}14`, color: meta.color }}
        >
            <Icon size={size} />
        </div>
    );
}

function CampaignNameField({ value, onChange }) {
    return (
        <label className="mb-5 block">
            <span className="text-[11px] font-medium text-ink/45">Campaign name</span>
            <input
                type="text"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder="e.g. Ramadan week push"
                className="mt-1 w-full rounded-lg border border-ink/10 bg-white px-3 py-2 text-[13px] font-medium text-ink placeholder:text-ink/30 focus:border-ink/25 focus:outline-none focus:ring-1 focus:ring-ink/10"
            />
        </label>
    );
}

export default function StepChannels({
    accounts,
    selectedIds,
    onToggle,
    error,
    onGoChannels,
    campaignName = '',
    onNameChange,
}) {
    if (!accounts.length) {
        return (
            <div>
                {onNameChange && (
                    <CampaignNameField value={campaignName} onChange={onNameChange} />
                )}
                <div className="rounded-xl border border-dashed border-ink/15 px-5 py-10 text-center">
                    <Share2 className="mx-auto text-ink/35" size={22} strokeWidth={1.6} />
                    <h2 className="cw-display mt-3 text-[15px] font-semibold text-ink">Connect a page first</h2>
                    <p className="mx-auto mt-1.5 max-w-sm text-[13px] leading-relaxed text-ink/50">
                        Link Instagram or Facebook, then return to build a campaign.
                    </p>
                    {onGoChannels && (
                        <button
                            type="button"
                            onClick={onGoChannels}
                            className="mt-4 inline-flex h-9 items-center rounded-lg bg-ink px-3.5 text-[13px] font-semibold text-white"
                        >
                            Open Channels
                        </button>
                    )}
                </div>
            </div>
        );
    }

    return (
        <div>
            {onNameChange && (
                <CampaignNameField value={campaignName} onChange={onNameChange} />
            )}

            <h2 className="cw-display text-[15px] font-semibold text-ink">Pages</h2>
            <p className="mt-1 text-[13px] leading-relaxed text-ink/50">
                Select channels that receive this campaign’s posts and stories.
            </p>

            <div className="mt-4 grid gap-2 sm:grid-cols-2">
                {accounts.map((account) => {
                    const id = Number(account.id);
                    const checked = selectedIds.includes(id);
                    return (
                        <button
                            key={id}
                            type="button"
                            onClick={() => onToggle(id)}
                            className={`flex items-center gap-2.5 rounded-lg px-3 py-2.5 text-left transition ${
                                checked
                                    ? 'bg-ink text-white'
                                    : 'bg-white text-ink ring-1 ring-ink/10 hover:ring-ink/20'
                            }`}
                        >
                            <ChannelMark platform={account.platform} />
                            <span className="min-w-0 flex-1">
                                <span className={`block truncate text-[13px] font-medium ${checked ? 'text-white' : 'text-ink'}`}>
                                    {channelLabel(account)}
                                </span>
                                <span className={`block truncate text-[10px] font-medium uppercase tracking-wide ${checked ? 'text-white/50' : 'text-ink/40'}`}>
                                    {account.platform || 'channel'}
                                </span>
                            </span>
                            <span
                                className={`flex h-[18px] w-[18px] shrink-0 items-center justify-center rounded-full ${
                                    checked ? 'bg-coral text-white' : 'bg-ink/5 text-transparent'
                                }`}
                            >
                                <Check size={11} strokeWidth={3} />
                            </span>
                        </button>
                    );
                })}
            </div>
            {error && (
                <p className="mt-3 text-[12px] font-medium text-coral" role="alert">{error}</p>
            )}
            {selectedIds.length > 0 && (
                <p className="mt-3 text-[12px] font-medium text-ink/40">{selectedIds.length} selected</p>
            )}
        </div>
    );
}
