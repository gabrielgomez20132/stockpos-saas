import axios from 'axios';

// axios manda solo el header X-XSRF-TOKEN (cookie de Laravel) => protegido contra CSRF
const http = axios.create({ headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });

export function mensajeError(e) {
    const r = e?.response;
    if (!r) return 'No hay conexión con el servidor. Revisá la red e intentá de nuevo.';
    if (r.status === 419) return 'La sesión venció. Recargá la página.';
    if (r.data?.errors) return Object.values(r.data.errors).flat()[0];
    return r.data?.message || 'Ocurrió un error inesperado.';
}

export default http;
