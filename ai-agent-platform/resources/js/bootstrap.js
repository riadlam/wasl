import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (csrf) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrf;
}
