import { useCallback, useMemo, useState } from 'react';
import Navbar from './components/Navbar';
import Hero from './components/Hero';
import LogoMarquee from './components/LogoMarquee';
import JourneyTabs from './components/JourneyTabs';
import InboxPreview from './components/InboxPreview';
import AgentSection from './components/AgentSection';
import Stats from './components/Stats';
import Stories from './components/Stories';
import WhyWasl from './components/WhyWasl';
import FinalCta from './components/FinalCta';
import Footer from './components/Footer';
import WaitlistModal from './components/WaitlistModal';
import { WaitlistContext } from './waitlist-context';

export default function App({ root }) {
    const [waitlistOpen, setWaitlistOpen] = useState(false);

    const loginHref = root?.dataset?.login || '/login';
    const registerHref = root?.dataset?.register || '/register';

    const openWaitlist = useCallback(() => setWaitlistOpen(true), []);

    const value = useMemo(
        () => ({ openWaitlist, loginHref, registerHref }),
        [openWaitlist, loginHref, registerHref],
    );

    return (
        <WaitlistContext.Provider value={value}>
            <Navbar />
            <main>
                <Hero />
                <LogoMarquee />
                <JourneyTabs />
                <InboxPreview />
                <AgentSection />
                <Stats />
                <Stories />
                <WhyWasl />
                <FinalCta />
            </main>
            <Footer />
            <WaitlistModal open={waitlistOpen} onClose={() => setWaitlistOpen(false)} />
        </WaitlistContext.Provider>
    );
}
