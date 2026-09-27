import TemplateWell from './TemplateWell';

export default function TemplateCard({ template, onOpen }) {
    return (
        <article
            className="relative flex min-h-[300px] cursor-pointer flex-col rounded-lg border border-line bg-white p-2 transition hover:shadow-[0_8px_24px_-16px_rgba(31,42,55,0.35)]"
            onClick={() => onOpen(template)}
        >
            <TemplateWell tone={template.tone} />
            <div className="flex flex-1 flex-col px-2 pb-12 pt-3">
                <h2 className="text-sm font-semibold text-ink">{template.title}</h2>
                <p className="mt-1 line-clamp-3 text-[13px] text-ink">{template.body}</p>
            </div>
            <button
                type="button"
                onClick={(event) => {
                    event.stopPropagation();
                    onOpen(template);
                }}
                className="absolute bottom-3 start-3 h-7 rounded-lg border border-line bg-white px-3.5 text-xs font-semibold text-ink hover:bg-bubble"
            >
                Use Template
            </button>
        </article>
    );
}
