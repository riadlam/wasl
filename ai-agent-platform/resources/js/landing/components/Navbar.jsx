import { useEffect, useRef, useState } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { ChevronDown, Menu, X } from 'lucide-react';
import { nav } from '../data';
import { useWaitlist } from '../waitlist-context';
import { CtaButton, Logo } from './ui';

export default function Navbar() {
    const { openWaitlist, loginHref } = useWaitlist();
    const [scrolled, setScrolled] = useState(false);
    const [openMenu, setOpenMenu] = useState(null);
    const [mobileOpen, setMobileOpen] = useState(false);
    const closeTimer = useRef(null);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 12);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    const open = (id) => {
        clearTimeout(closeTimer.current);
        setOpenMenu(id);
    };

    const delayedClose = () => {
        closeTimer.current = setTimeout(() => setOpenMenu(null), 140);
    };

    return (
        <header
            id="top"
            className={`sticky top-0 z-50 transition-all duration-300 ${
                scrolled || mobileOpen
                    ? 'border-b border-ink/8 bg-cream/80 shadow-sm backdrop-blur-xl'
                    : 'bg-transparent'
            }`}
        >
            <div className="mx-auto flex h-[72px] max-w-[1320px] items-center justify-between px-5 md:px-[60px]">
                <Logo />

                <nav className="hidden items-center gap-1 lg:flex">
                    <Mega
                        id="product"
                        label="Product"
                        openMenu={openMenu}
                        onEnter={open}
                        onLeave={delayedClose}
                    >
                        <div className="grid w-[560px] grid-cols-2 gap-2 p-3">
                            {nav.product.map((item) => (
                                <a
                                    key={item.title}
                                    href={item.href}
                                    onClick={() => setOpenMenu(null)}
                                    className="rounded-xl p-3 transition hover:bg-cream-deep"
                                >
                                    <div className="font-semibold">{item.title}</div>
                                    <p className="mt-1 text-sm text-ink/60">{item.desc}</p>
                                </a>
                            ))}
                        </div>
                    </Mega>

                    <a href="#journey" className="rounded-lg px-3 py-2 text-sm font-medium text-ink/80 hover:bg-ink/5">
                        Features
                    </a>

                    <Mega
                        id="industries"
                        label="Industries"
                        openMenu={openMenu}
                        onEnter={open}
                        onLeave={delayedClose}
                    >
                        <div className="flex w-56 flex-col p-2">
                            {nav.industries.map((item) => (
                                <a
                                    key={item.title}
                                    href={item.href}
                                    onClick={() => setOpenMenu(null)}
                                    className="rounded-lg px-3 py-2 text-sm font-medium hover:bg-cream-deep"
                                >
                                    {item.title}
                                </a>
                            ))}
                        </div>
                    </Mega>

                    <a href="#cta" className="rounded-lg px-3 py-2 text-sm font-medium text-ink/80 hover:bg-ink/5">
                        Pricing
                    </a>
                </nav>

                <div className="hidden items-center gap-2 lg:flex">
                    <CtaButton variant="ghost" href={loginHref} onClick={(e) => { e.preventDefault(); openWaitlist(); }}>
                        Log in
                    </CtaButton>
                    <CtaButton onClick={openWaitlist}>Start free</CtaButton>
                </div>

                <button
                    type="button"
                    className="ml-auto flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-ink/10 bg-white text-ink hover:bg-ink/5 lg:hidden"
                    onClick={() => setMobileOpen((v) => !v)}
                    aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
                >
                    {mobileOpen ? <X size={22} /> : <Menu size={22} />}
                </button>
            </div>

            <AnimatePresence>
                {mobileOpen && (
                    <motion.div
                        initial={{ height: 0, opacity: 0 }}
                        animate={{ height: 'auto', opacity: 1 }}
                        exit={{ height: 0, opacity: 0 }}
                        className="overflow-hidden border-t border-ink/8 bg-cream lg:hidden"
                    >
                        <div className="flex flex-col gap-1 px-5 py-4">
                            {nav.product.map((item) => (
                                <a
                                    key={item.title}
                                    href={item.href}
                                    onClick={() => setMobileOpen(false)}
                                    className="rounded-lg px-3 py-2 text-sm font-medium"
                                >
                                    {item.title}
                                </a>
                            ))}
                            <a href="#cta" onClick={() => setMobileOpen(false)} className="rounded-lg px-3 py-2 text-sm font-medium">
                                Pricing
                            </a>
                            <div className="mt-2 flex gap-2">
                                <CtaButton variant="outline" className="flex-1" onClick={openWaitlist}>
                                    Log in
                                </CtaButton>
                                <CtaButton className="flex-1" onClick={openWaitlist}>
                                    Start free
                                </CtaButton>
                            </div>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>
        </header>
    );
}

function Mega({ id, label, openMenu, onEnter, onLeave, children }) {
    const open = openMenu === id;

    return (
        <div className="relative" onMouseEnter={() => onEnter(id)} onMouseLeave={onLeave}>
            <button
                type="button"
                className="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-ink/80 hover:bg-ink/5"
            >
                {label}
                <ChevronDown size={14} className={`transition ${open ? 'rotate-180' : ''}`} />
            </button>
            <AnimatePresence>
                {open && (
                    <motion.div
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: 8 }}
                        transition={{ duration: 0.18 }}
                        className="absolute left-0 top-full z-50 pt-2"
                    >
                        <div className="overflow-hidden rounded-2xl border border-ink/8 bg-white shadow-[0_24px_60px_-24px_rgb(31_42_55_/_0.18)]">
                            {children}
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}
