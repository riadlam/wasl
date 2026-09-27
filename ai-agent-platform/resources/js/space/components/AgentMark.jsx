import { LottieLight } from 'lottie-react';
import aiAgents from '../lottie/ai-agents.json';

export default function AgentMark({ size = 22, active = false, className = '' }) {
    return (
        <div
            className={`flex shrink-0 items-center justify-center overflow-hidden ${className}`}
            style={{ width: size, height: size, opacity: active ? 1 : 0.7 }}
            aria-hidden
        >
            <LottieLight src={aiAgents} loop autoplay style={{ width: size, height: size }} />
        </div>
    );
}
