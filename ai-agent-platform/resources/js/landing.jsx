import { createRoot } from 'react-dom/client';
import App from './landing/App';

const el = document.getElementById('landing-root');

if (el) {
    createRoot(el).render(<App root={el} />);
}
