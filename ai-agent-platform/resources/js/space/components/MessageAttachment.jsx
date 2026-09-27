import { useState } from 'react';
import { Download, ExternalLink, FileText, Film, ImageOff, Music } from 'lucide-react';

/**
 * Renders DM attachments (image / video / audio / file / share).
 * Media is served via same-origin `/api/messages/{id}/media` proxy when available.
 */
export default function MessageAttachment({ message, outgoing = false }) {
    const [broken, setBroken] = useState(false);
    const [lightbox, setLightbox] = useState(false);
    const url = message?.media_url;
    const kind = (message?.media_type || message?.type || '').toLowerCase();

    if (!url) return null;

    const shell = outgoing ? 'bg-black/10' : 'bg-white';

    if ((kind === 'image' || (!kind && looksLikeImage(url))) && !broken) {
        return (
            <>
                <button
                    type="button"
                    onClick={() => setLightbox(true)}
                    className={`mb-1.5 block max-w-full overflow-hidden rounded-xl ${shell}`}
                >
                    <img
                        src={url}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        onError={() => setBroken(true)}
                        className="max-h-72 w-auto max-w-full object-contain"
                    />
                </button>
                {lightbox && (
                    <button
                        type="button"
                        className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
                        onClick={() => setLightbox(false)}
                        aria-label="Close image"
                    >
                        <img
                            src={url}
                            alt=""
                            className="max-h-full max-w-full rounded-lg object-contain"
                        />
                    </button>
                )}
            </>
        );
    }

    if (kind === 'video') {
        return (
            <div className={`mb-1.5 overflow-hidden rounded-xl ${shell}`}>
                <video
                    src={url}
                    controls
                    playsInline
                    preload="metadata"
                    className="max-h-72 max-w-full bg-black"
                />
            </div>
        );
    }

    if (kind === 'audio') {
        return (
            <div className={`mb-1.5 flex min-w-[220px] items-center gap-2 rounded-xl px-2 py-2 ${shell}`}>
                <Music size={16} className={outgoing ? 'text-white/90' : 'text-muted'} />
                <audio src={url} controls preload="metadata" className="h-8 w-full max-w-[240px]" />
            </div>
        );
    }

    if (kind === 'share') {
        let host = 'Shared link';
        try {
            host = new URL(url).hostname.replace(/^www\./, '');
        } catch {
            /* keep label */
        }
        return (
            <a
                href={url}
                target="_blank"
                rel="noreferrer"
                className={`mb-1.5 flex items-start gap-2 rounded-xl border px-3 py-2 text-left transition ${
                    outgoing
                        ? 'border-white/25 bg-black/10 text-white hover:bg-black/20'
                        : 'border-line bg-white text-ink hover:bg-bubble'
                }`}
            >
                <ExternalLink size={14} className="mt-0.5 shrink-0 opacity-80" />
                <span className="min-w-0">
                    <span className="block truncate text-xs font-semibold">{host}</span>
                    <span className={`block truncate text-[11px] ${outgoing ? 'text-white/75' : 'text-muted'}`}>{url}</span>
                </span>
            </a>
        );
    }

    const label = kind === 'image' || broken ? 'Photo unavailable' : fileLabel(url, kind);
    const Icon = broken || kind === 'image' ? ImageOff : kind === 'video' ? Film : FileText;

    return (
        <a
            href={url}
            target="_blank"
            rel="noreferrer"
            className={`mb-1.5 inline-flex max-w-full items-center gap-2 rounded-xl border px-3 py-2 text-xs font-semibold transition ${
                outgoing
                    ? 'border-white/25 bg-black/10 text-white hover:bg-black/20'
                    : 'border-line bg-white text-ink hover:bg-bubble'
            }`}
        >
            <Icon size={14} className="shrink-0 opacity-80" />
            <span className="min-w-0 truncate">{label}</span>
            <Download size={12} className="shrink-0 opacity-70" />
        </a>
    );
}

function looksLikeImage(url) {
    return /\/media\/messages\/|\.(jpe?g|png|gif|webp|bmp)(\?|$)/i.test(String(url || ''));
}

function fileLabel(url, kind) {
    try {
        const path = decodeURIComponent(new URL(url, window.location.origin).pathname);
        const name = path.split('/').filter(Boolean).pop();
        if (name && name.includes('.') && name.length < 80) return name;
    } catch {
        /* fall through */
    }
    if (kind === 'file') return 'Attachment';
    return 'Open attachment';
}
