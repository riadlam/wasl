import { createContext, useContext } from 'react';

export const WaitlistContext = createContext({
    openWaitlist: () => {},
    loginHref: '/login',
    registerHref: '/register',
});

export function useWaitlist() {
    return useContext(WaitlistContext);
}
