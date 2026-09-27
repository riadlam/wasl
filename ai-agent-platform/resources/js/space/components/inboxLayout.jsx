import { useCallback, useEffect, useRef, useState } from 'react';

const STORAGE_KEY = 'wasl.inbox.layout';

const DEFAULTS = {
    platformWidth: 176,
    listWidth: 280,
    leadWidth: 220,
    platformCollapsed: false,
    leadCollapsed: false,
};

export function loadInboxLayout() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return { ...DEFAULTS };
        return { ...DEFAULTS, ...JSON.parse(raw) };
    } catch {
        return { ...DEFAULTS };
    }
}

export function useInboxLayout() {
    const [layout, setLayout] = useState(loadInboxLayout);

    useEffect(() => {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(layout));
        } catch {
            /* ignore */
        }
    }, [layout]);

    const set = useCallback((patch) => {
        setLayout((prev) => ({ ...prev, ...(typeof patch === 'function' ? patch(prev) : patch) }));
    }, []);

    return [layout, set];
}

export function ColumnResize({ onResize, onCollapse, label = 'Resize column', className = '' }) {
    const dragging = useRef(false);

    useEffect(() => {
        const onMove = (e) => {
            if (!dragging.current) return;
            onResize(e.clientX);
        };
        const onUp = () => {
            if (!dragging.current) return;
            dragging.current = false;
            document.body.style.cursor = '';
            document.body.style.userSelect = '';
        };
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        return () => {
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
        };
    }, [onResize]);

    return (
        <div
            role="separator"
            aria-orientation="vertical"
            aria-label={label}
            title="Drag to resize · double-click to collapse"
            onPointerDown={(e) => {
                e.preventDefault();
                dragging.current = true;
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';
            }}
            onDoubleClick={() => onCollapse?.()}
            className={`group relative z-20 hidden w-1.5 shrink-0 cursor-col-resize bg-transparent lg:block ${className}`}
        >
            <span className="pointer-events-none absolute inset-y-0 start-1/2 w-px -translate-x-1/2 bg-line transition group-hover:w-0.5 group-hover:bg-accent group-active:bg-accent" />
            <span className="pointer-events-none absolute start-1/2 top-1/2 h-9 w-[3px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-line/80 opacity-70 transition group-hover:bg-accent group-hover:opacity-100 group-active:bg-accent group-active:opacity-100" />
        </div>
    );
}

export function clamp(n, min, max) {
    return Math.min(max, Math.max(min, n));
}
