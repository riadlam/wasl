import { useCallback, useEffect, useMemo, useState } from 'react';
import {
    Background,
    Controls,
    Handle,
    MiniMap,
    Position,
    ReactFlow,
    useEdgesState,
    useNodesState,
} from '@xyflow/react';
import { MessageSquarePlus, Send, Settings2, UserRound, Zap } from 'lucide-react';
import '@xyflow/react/dist/style.css';
import NodeEditor from './NodeEditor';

const nodeTypes = {
    trigger: CardNode,
    action: CardNode,
    message: CardNode,
    chip: ChipNode,
};

export default function FlowCanvas({
    steps = [],
    editable = false,
    options = [],
    field = 'phone',
    hint = '',
    onField,
    onHint,
    requireClearMatch = true,
    onRequireClearMatch,
    engagement = null,
    dm = null,
}) {
    const [selectedId, setSelectedId] = useState(null);
    const graph = useMemo(
        () => stepsToGraph(steps, selectedId, editable),
        [steps, selectedId, editable],
    );
    const [nodes, setNodes, onNodesChange] = useNodesState(graph.nodes);
    const [edges, setEdges, onEdgesChange] = useEdgesState(graph.edges);
    const [wide, setWide] = useState(() => typeof window !== 'undefined' && window.matchMedia('(min-width: 1024px)').matches);
    const count = steps.filter((step) => step.kind && step.kind !== 'chip').length;
    const selectedIndex = selectedId != null ? Number(String(selectedId).replace('step-', '')) : -1;
    const selectedStep = selectedIndex >= 0 ? steps[selectedIndex] || null : null;

    useEffect(() => {
        setNodes(graph.nodes);
        setEdges(graph.edges);
    }, [graph, setEdges, setNodes]);

    useEffect(() => {
        const query = window.matchMedia('(min-width: 1024px)');
        const onChange = () => setWide(query.matches);
        onChange();
        query.addEventListener('change', onChange);
        return () => query.removeEventListener('change', onChange);
    }, []);

    const onNodeClick = useCallback((_event, node) => {
        if (!editable) {
            return;
        }
        setSelectedId(node.id);
    }, [editable]);

    const onPaneClick = useCallback(() => {
        setSelectedId(null);
    }, []);

    return (
        <div className="relative h-full min-h-0 w-full bg-bubble">
            <ReactFlow
                nodes={nodes}
                edges={edges}
                onNodesChange={onNodesChange}
                onEdgesChange={onEdgesChange}
                onNodeClick={onNodeClick}
                onPaneClick={onPaneClick}
                nodeTypes={nodeTypes}
                fitView
                fitViewOptions={{ padding: 0.28 }}
                minZoom={0.4}
                maxZoom={1.6}
                panOnDrag
                zoomOnScroll
                zoomOnPinch
                panOnScroll={false}
                nodesConnectable={false}
                edgesReconnectable={false}
                elementsSelectable={editable}
                deleteKeyCode={null}
                proOptions={{ hideAttribution: false }}
                defaultEdgeOptions={{
                    type: 'smoothstep',
                    style: { stroke: '#C5CCD0', strokeWidth: 2 },
                }}
            >
                <Background color="#c5ccd0" gap={18} size={1} />
                <Controls showInteractive={false} position="bottom-right" />
                {wide && <MiniMap pannable zoomable position="top-right" />}
            </ReactFlow>

            {editable && !selectedStep && (
                <div className="pointer-events-none absolute top-4 start-1/2 z-10 -translate-x-1/2 rounded-full border border-line bg-white/95 px-3 py-1.5 text-[12px] font-medium text-muted shadow-sm">
                    Click a step to edit its settings
                </div>
            )}

            {editable && selectedStep && (
                <NodeEditor
                    step={selectedStep}
                    options={options}
                    field={field}
                    hint={hint}
                    onField={onField}
                    onHint={onHint}
                    requireClearMatch={requireClearMatch}
                    onRequireClearMatch={onRequireClearMatch}
                    engagement={engagement}
                    dm={dm}
                    onClose={() => setSelectedId(null)}
                />
            )}

            <div className="pointer-events-none absolute bottom-4 start-4 z-10 rounded-lg border border-line bg-white/95 px-2.5 py-1 text-[12px] shadow-sm">
                <span className="text-muted">Steps</span>{' '}
                <span className="font-semibold text-ink">{count}</span>
            </div>
        </div>
    );
}

function stepsToGraph(steps, selectedId, editable) {
    const nodes = steps.map((step, index) => {
        const id = `step-${index}`;
        return {
            id,
            type: step.kind,
            position: { x: 0, y: index * 148 },
            data: {
                ...step,
                selected: selectedId === id,
                editable,
            },
            draggable: true,
            selected: selectedId === id,
        };
    });

    const edges = steps.slice(1).map((_, index) => ({
        id: `edge-${index}`,
        source: `step-${index}`,
        target: `step-${index + 1}`,
    }));

    return { nodes, edges };
}

function CardNode({ data }) {
    const Icon = iconFor(data);
    const selected = !!data.selected;
    const editable = !!data.editable;

    return (
        <article
            className={`w-[200px] overflow-hidden rounded-lg border bg-white text-ink shadow-sm transition ${
                selected
                    ? 'border-coral shadow-[0_0_0_2px_rgba(255,90,60,0.25)]'
                    : 'border-line'
            } ${editable ? 'cursor-pointer hover:border-coral/70' : ''}`}
        >
            <Handle type="target" position={Position.Top} className="!h-2 !w-2 !border-0 !bg-[#C5CCD0]" />
            <div className="flex items-center gap-2 px-2 py-2">
                <Icon size={16} style={{ color: data.color }} className="shrink-0" />
                <h3 className="min-w-0 flex-1 truncate text-xs font-semibold">{data.title}</h3>
                {editable && (
                    <Settings2 size={12} className={selected ? 'text-coral' : 'text-muted'} />
                )}
            </div>
            {(data.body || data.message) && (
                <>
                    <div className="h-px bg-line" />
                    <div className="px-3 py-2.5 text-xs text-ink">
                        {data.message ? (
                            <p className="line-clamp-3 italic">
                                <span className="not-italic">Message: </span>
                                {renderMessage(data.message)}
                            </p>
                        ) : (
                            <p>{data.body}</p>
                        )}
                    </div>
                </>
            )}
            <Handle type="source" position={Position.Bottom} className="!h-2 !w-2 !border-0 !bg-[#C5CCD0]" />
        </article>
    );
}

function ChipNode({ data }) {
    const selected = !!data.selected;
    const editable = !!data.editable;

    return (
        <div className={`relative px-1 py-1 ${editable ? 'cursor-pointer' : ''}`}>
            <Handle type="target" position={Position.Top} className="!h-2 !w-2 !border-0 !bg-[#C5CCD0]" />
            <span
                className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold transition ${
                    selected
                        ? 'bg-coral text-white shadow-[0_0_0_2px_rgba(255,90,60,0.25)]'
                        : 'bg-info text-accent'
                }`}
            >
                {data.label}
            </span>
            <Handle type="source" position={Position.Bottom} className="!h-2 !w-2 !border-0 !bg-[#C5CCD0]" />
        </div>
    );
}

function renderMessage(text) {
    const parts = text.split(/(\$contact\.firstname)/g);
    return parts.map((part, index) =>
        part === '$contact.firstname' ? (
            <span key={index} className="not-italic font-semibold">$contact.firstname</span>
        ) : (
            <span key={index}>{part}</span>
        ),
    );
}

function iconFor(step) {
    if (step.kind === 'message') return Send;
    if (step.kind === 'trigger') return Zap;
    const title = (step.title || '').toLowerCase();
    if (title.includes('assign') || title.includes('mark') || title.includes('tag')) return UserRound;
    return MessageSquarePlus;
}
