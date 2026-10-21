const nf0 = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 0 });
const nf2 = new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const nf3 = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 3 });

/** $ 1.234 (con centavos solo si los hay) */
export function plata(v, { signo = true, centavos = 'auto' } = {}) {
    const n = Number(v) || 0;
    const usar = centavos === true || (centavos === 'auto' && Math.abs(n - Math.round(n)) >= 0.005);
    const s = usar ? nf2.format(n) : nf0.format(Math.round(n));
    return signo ? `$ ${s}` : s;
}

export function cantidad(v) {
    return nf3.format(Number(v) || 0);
}

/** unidad en singular cuando va 1 (Uds -> Ud) */
export function unidad(u, cant) {
    if (!u) return '';
    return Number(cant) === 1 && u.endsWith('s') ? u.slice(0, -1) : u;
}

export function fecha(v, conHora = false) {
    if (!v) return '';
    const d = new Date(String(v).replace(' ', 'T'));
    if (isNaN(d)) return v;
    const f = d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit' });
    return conHora ? `${f} ${hora(v)}` : f;
}

export function hora(v) {
    const d = new Date(String(v).replace(' ', 'T'));
    return isNaN(d) ? '' : d.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit', hour12: false });
}

/** fecha "AAAA-MM-DD" (solo dia) sin corrimiento de zona horaria */
export function dia(v) {
    if (!v) return '';
    const [y, m, d] = String(v).slice(0, 10).split('-');
    return `${d}/${m}/${y.slice(2)}`;
}

export function hoyISO(offset = 0) {
    const d = new Date();
    d.setDate(d.getDate() + offset);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** acepta "1.234,5" o "1234.5" */
export function numero(v) {
    if (v === null || v === undefined || v === '') return null;
    if (typeof v === 'number') return v;
    let s = String(v).trim();
    if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
    const n = Number(s);
    return isNaN(n) ? null : n;
}
