import { useState } from 'react';
import CampaignDetail from './campaigns/CampaignDetail';
import CampaignsList from './campaigns/CampaignsList';
import CampaignWizard from './campaigns/CampaignWizard';

function readDeepLink() {
    try {
        const params = new URLSearchParams(window.location.search);
        const campaignId = Number(params.get('c') || 0);
        const editSlotId = Number(params.get('edit') || 0);
        return {
            campaignId: Number.isFinite(campaignId) && campaignId > 0 ? campaignId : null,
            editSlotId: Number.isFinite(editSlotId) && editSlotId > 0 ? editSlotId : null,
        };
    } catch {
        return { campaignId: null, editSlotId: null };
    }
}

function clearDeepLinkParams() {
    try {
        const url = new URL(window.location.href);
        if (!url.searchParams.has('c') && !url.searchParams.has('edit')) return;
        url.searchParams.delete('c');
        url.searchParams.delete('edit');
        window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
    } catch {
        /* ignore */
    }
}

function initialScreen() {
    const { campaignId, editSlotId } = readDeepLink();
    if (campaignId) {
        return { name: 'detail', id: campaignId, editSlotId };
    }
    return { name: 'list' };
}

export default function CampaignsView() {
    const [screen, setScreen] = useState(initialScreen);

    if (screen.name === 'new') {
        return (
            <CampaignWizard
                onBack={() => setScreen({ name: 'list' })}
                onLaunched={(campaign) => setScreen({ name: 'detail', id: campaign.id })}
            />
        );
    }

    if (screen.name === 'detail') {
        return (
            <CampaignDetail
                campaignId={screen.id}
                initialEditSlotId={screen.editSlotId || null}
                onDeepLinkConsumed={clearDeepLinkParams}
                onBack={() => setScreen({ name: 'list' })}
            />
        );
    }

    return (
        <CampaignsList
            onNew={() => setScreen({ name: 'new' })}
            onOpen={(id) => setScreen({ name: 'detail', id })}
        />
    );
}
