import { useDropzone } from 'react-dropzone';
import { Upload } from 'lucide-react';

export default function FileDrop({
    onFiles,
    multiple = true,
    disabled = false,
    label = 'Drop photos or click to add',
    hint = 'JPG, PNG or WEBP',
    compact = false,
    preview = null,
    className = '',
}) {
    const { getRootProps, getInputProps, isDragActive } = useDropzone({
        onDrop: (files) => {
            if (files?.length) onFiles(files);
        },
        multiple,
        disabled,
        accept: {
            'image/jpeg': ['.jpg', '.jpeg'],
            'image/png': ['.png'],
            'image/webp': ['.webp'],
            'image/gif': ['.gif'],
        },
    });

    if (compact) {
        return (
            <button
                type="button"
                disabled={disabled}
                {...getRootProps({
                    className: `flex h-full w-full flex-col items-center justify-center gap-1 overflow-hidden rounded-lg border border-dotted border-line bg-cream-deep hover:bg-white disabled:opacity-50 ${className}`,
                })}
            >
                <input {...getInputProps()} />
                {preview ? (
                    <img src={preview} alt="" className="h-full w-full object-cover" />
                ) : (
                    <>
                        <Upload size={16} className="text-muted" />
                        <span className="px-1 text-center text-[10px] font-medium text-ink">
                            {isDragActive ? 'Drop' : 'Add'}
                        </span>
                    </>
                )}
            </button>
        );
    }

    return (
        <div
            {...getRootProps({
                className: `cursor-pointer rounded-xl border border-dotted px-4 py-6 text-center transition ${
                    isDragActive ? 'border-ink bg-bubble' : 'border-line bg-cream-deep hover:bg-white'
                } ${disabled ? 'pointer-events-none opacity-50' : ''} ${className}`,
            })}
        >
            <input {...getInputProps()} />
            <span className="mx-auto flex h-10 w-10 items-center justify-center rounded-lg border border-dotted border-line bg-white">
                <Upload size={18} className="text-muted" />
            </span>
            <p className="mt-3 text-[13px] font-medium text-ink">{isDragActive ? 'Drop to add' : label}</p>
            {hint && <p className="mt-1 text-[12px] font-normal text-muted">{hint}</p>}
        </div>
    );
}
