import { brand, footer } from '../data';
import { Logo } from './ui';

export default function Footer() {
    return (
        <footer className="border-t border-ink/8 bg-cream-deep">
            <div className="mx-auto grid max-w-[1320px] gap-10 px-5 py-14 md:grid-cols-4 md:px-[60px]">
                <div>
                    <Logo />
                    <p className="mt-4 max-w-xs text-sm font-medium leading-relaxed text-ink/60">{footer.blurb}</p>
                </div>
                {footer.columns.map((col) => (
                    <div key={col.title}>
                        <div className="text-sm font-bold">{col.title}</div>
                        <ul className="mt-3 space-y-2">
                            {col.links.map((link) => (
                                <li key={link.label}>
                                    <a href={link.href} className="text-sm font-medium text-ink/60 hover:text-ink">
                                        {link.label}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
            <div className="border-t border-ink/8 py-5 text-center text-xs font-medium text-ink/45">
                © {new Date().getFullYear()} {brand.name} ({brand.nameAr}) · Made for Algerian shops
            </div>
        </footer>
    );
}
