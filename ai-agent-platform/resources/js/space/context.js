import { createContext, useContext } from 'react';

export const SpaceContext = createContext(null);

export function useSpace() {
    const ctx = useContext(SpaceContext);
    if (!ctx) {
        throw new Error('useSpace must be used inside the workspace');
    }
    return ctx;
}
