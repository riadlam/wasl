import {
    FaBluesky,
    FaFacebook,
    FaGoogle,
    FaInstagram,
    FaLinkedin,
    FaPinterest,
    FaTelegram,
    FaTiktok,
    FaWhatsapp,
    FaXTwitter,
    FaYoutube,
} from 'react-icons/fa6';
import { Bot } from 'lucide-react';
import { SiThreads } from 'react-icons/si';

/**
 * Channel platforms available in Wasl.
 * oauth = connect returns auth_url to redirect.
 * invite = SocialAPI-hosted connect link (WhatsApp — no Wasl domain allowlist).
 * dashboard = guided connect (credentials / SocialAPI dashboard).
 */
export const SOCIALAPI_PLATFORMS = [
    {
        id: 'instagram',
        label: 'Instagram',
        blurb: 'DMs, comments, and mentions.',
        group: 'inbox',
        mode: 'oauth',
        Icon: FaInstagram,
        color: '#E4405F',
    },
    {
        id: 'facebook',
        label: 'Facebook',
        blurb: 'Page inbox — pick Pages after login.',
        group: 'inbox',
        mode: 'oauth',
        Icon: FaFacebook,
        color: '#1877F2',
    },
    {
        id: 'whatsapp',
        label: 'WhatsApp',
        blurb: 'Business DMs via SocialAPI invite signup.',
        group: 'inbox',
        mode: 'invite',
        Icon: FaWhatsapp,
        color: '#25D366',
    },
    {
        id: 'threads',
        label: 'Threads',
        blurb: 'Comments and replies on Threads.',
        group: 'inbox',
        mode: 'oauth',
        Icon: SiThreads,
        color: '#000000',
    },
    {
        id: 'tiktok',
        label: 'TikTok',
        blurb: 'Publishing and account connect.',
        group: 'more',
        mode: 'oauth',
        Icon: FaTiktok,
        color: '#010101',
    },
    {
        id: 'youtube',
        label: 'YouTube',
        blurb: 'Comments on your channel.',
        group: 'more',
        mode: 'oauth',
        Icon: FaYoutube,
        color: '#FF0000',
    },
    {
        id: 'linkedin',
        label: 'LinkedIn',
        blurb: 'Personal or organization page.',
        group: 'more',
        mode: 'oauth',
        Icon: FaLinkedin,
        color: '#0A66C2',
    },
    {
        id: 'twitter',
        label: 'X',
        blurb: 'Posts, replies, and DMs.',
        group: 'more',
        mode: 'oauth',
        Icon: FaXTwitter,
        color: '#000000',
    },
    {
        id: 'google',
        label: 'Google Business',
        blurb: 'Reviews and profile locations.',
        group: 'more',
        mode: 'oauth',
        Icon: FaGoogle,
        color: '#4285F4',
    },
    {
        id: 'pinterest',
        label: 'Pinterest',
        blurb: 'Pins and boards.',
        group: 'more',
        mode: 'oauth',
        Icon: FaPinterest,
        color: '#E60023',
    },
    {
        id: 'telegram',
        label: 'Telegram',
        blurb: 'Bot DMs via dashboard connect.',
        group: 'more',
        mode: 'dashboard',
        Icon: FaTelegram,
        color: '#26A5E4',
    },
    {
        id: 'bluesky',
        label: 'Bluesky',
        blurb: 'Posts, comments, and DMs.',
        group: 'more',
        mode: 'dashboard',
        Icon: FaBluesky,
        color: '#1185FE',
    },
];

export function platformMeta(id) {
    if (id === 'simulator') {
        return { id: 'simulator', label: 'Simulator', Icon: Bot, color: '#5c6570', mode: 'local' };
    }

    return SOCIALAPI_PLATFORMS.find((p) => p.id === id) || {
        id,
        label: id ? id.charAt(0).toUpperCase() + id.slice(1) : 'Unknown',
        Icon: FaInstagram,
        color: '#5c6570',
        mode: 'oauth',
    };
}

export function conversationPlatform(conversation) {
    return String(conversation?.platform || conversation?.channel || '').toLowerCase();
}
