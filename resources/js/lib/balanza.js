// Etiqueta de balanza (EAN-13): igual que App\Support\Panaderia::leer, para la venta sin conexion.
export function prefijos(cfg) {
    return String(cfg?.prefijos ?? '').split(',').map((p) => p.trim()).filter((p) => /^\d{1,3}$/.test(p));
}

export function digitoOk(ean) {
    let s = 0;
    for (let i = 0; i < 12; i++) s += Number(ean[i]) * (i % 2 ? 3 : 1);
    return (10 - (s % 10)) % 10 === Number(ean[12]);
}

/** null si no es una etiqueta; lanza Error si la etiqueta se leyo mal */
export function leerEtiqueta(codigo, cfg) {
    const c = String(codigo ?? '').trim();
    if (!cfg || !/^\d{13}$/.test(c)) return null;
    const pref = prefijos(cfg).find((p) => c.startsWith(p));
    if (pref === undefined) return null;
    if (cfg.verificar && !digitoOk(c)) throw new Error('La etiqueta de la balanza no se leyó bien (dígito verificador). Volvé a escanearla.');
    const dig = Number(cfg.digitos_codigo);
    const largo = 12 - pref.length - dig;
    if (dig < 1 || largo < 3) throw new Error('La configuración de la balanza no cierra: revisá Configuración → Panadería.');
    const plu = c.substr(pref.length, dig);
    const valor = Math.round((Number(c.substr(pref.length + dig, largo)) / 10 ** Number(cfg.decimales)) * 1000) / 1000;
    return { plu, tipo: cfg.tipo, valor };
}

export function codigosPlu(plu) {
    const sin = plu.replace(/^0+/, '') || '0';
    return [...new Set([plu, sin])];
}

/** "1.500" o "$1.500" -> 1500 ; "1500,50" -> 1500.5 */
export function importe(v) {
    let s = String(v ?? '').trim().replace(/^\$\s*/, '');
    if (/^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
    else if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
    const n = Number(s);
    return s === '' || isNaN(n) ? null : n;
}
