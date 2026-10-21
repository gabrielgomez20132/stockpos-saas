// Arma el PDF de etiquetas en el navegador (medidas en mm, barras vectoriales: se imprimen nitidas).
import { plata } from '@/lib/formato';

const HOJAS = { A4: [210, 297], Carta: [215.9, 279.4], Oficio: [215.9, 355.6] };
const PT = 2.8346; // 1 mm en puntos tipograficos

/** mismas proporciones que la vista previa (Components/Etiqueta.vue) */
function medidas(c) {
    const H = Number(c.alto), W = Number(c.ancho);
    const base = Math.min(H, W * 0.9);
    const precio = ({ chico: 0.15, mediano: 0.21, grande: 0.28 }[c.tam_precio] ?? 0.24) * base;
    const nombre = Math.max(2, Math.min(4.2, base * 0.115));
    const codigo = Math.max(1.8, Math.min(3, base * 0.075));
    const libre = H - 3 - (c.nombre ? nombre * 2.3 : 0) - (c.precio ? precio * 1.15 : 0) - (c.codigo ? codigo * 1.3 : 0) - ((c.sucursal || c.fecha) ? 2.6 : 0);
    return { W, H, precio, nombre, codigo, barras: Math.max(4, Math.min(libre, H * 0.45)) };
}

function etiqueta(pdf, x, y, e, c, m, barras, sucursal, fecha) {
    const padX = 1.5, padY = 1.2;
    const ancho = m.W - 2 * padX;
    const cx = x + m.W / 2;
    // bloques de arriba hacia abajo, repartidos como en la pantalla (space-between)
    const bloques = [];
    if (c.nombre) {
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(m.nombre * PT);
        let lineas = pdf.splitTextToSize(e.nombre, ancho);
        if (lineas.length > 2) { lineas = lineas.slice(0, 2); lineas[1] = lineas[1].replace(/.{0,2}$/, '…'); }
        bloques.push({ alto: lineas.length * m.nombre * 1.12, dibujar: (yy) => { pdf.setFont('helvetica', 'bold'); pdf.setFontSize(m.nombre * PT); lineas.forEach((l, i) => pdf.text(l, cx, yy + m.nombre * (0.85 + i * 1.12), { align: 'center' })); } });
    }
    if (c.precio) {
        bloques.push({ alto: m.precio, dibujar: (yy) => { pdf.setFont('helvetica', 'bold'); pdf.setFontSize(m.precio * PT); pdf.text(plata(e.monto), cx, yy + m.precio * 0.82, { align: 'center' }); } });
    }
    if (c.barras) {
        const b = barras[e.codigo];
        bloques.push({ alto: m.barras, dibujar: (yy) => {
            if (!b) { pdf.setFont('helvetica', 'normal'); pdf.setFontSize(m.codigo * PT); pdf.setTextColor(150); pdf.text('sin código de barras', cx, yy + m.barras / 2, { align: 'center' }); pdf.setTextColor(0); return; }
            // margen en blanco de 10 modulos a cada lado: sin eso muchos lectores no leen el codigo
            const modulo = ancho / (b.total + 20);
            const x0 = x + padX + 10 * modulo;
            pdf.setFillColor(0, 0, 0);
            b.barras.forEach(([bx, bw]) => pdf.rect(x0 + bx * modulo, yy, bw * modulo, m.barras, 'F'));
        } });
    }
    if (c.codigo) {
        bloques.push({ alto: m.codigo, dibujar: (yy) => { pdf.setFont('helvetica', 'normal'); pdf.setFontSize(m.codigo * PT); pdf.setCharSpace(m.codigo * 0.08); pdf.text(e.codigo, cx, yy + m.codigo * 0.8, { align: 'center' }); pdf.setCharSpace(0); } });
    }
    if (c.sucursal || c.fecha) {
        const t = Math.max(1.7, m.codigo * 0.85);
        bloques.push({ alto: t, dibujar: (yy) => {
            pdf.setFont('helvetica', 'normal'); pdf.setFontSize(t * PT); pdf.setTextColor(60);
            if (c.sucursal && sucursal) pdf.text(sucursal, x + padX, yy + t * 0.8);
            if (c.fecha) pdf.text(fecha, x + m.W - padX, yy + t * 0.8, { align: 'right' });
            pdf.setTextColor(0);
        } });
    }
    const usado = bloques.reduce((a, b) => a + b.alto, 0);
    const hueco = bloques.length > 1 ? Math.max(0, (m.H - 2 * padY - usado) / (bloques.length - 1)) : 0;
    let yy = y + padY + (bloques.length === 1 ? (m.H - 2 * padY - usado) / 2 : 0);
    bloques.forEach((b) => { b.dibujar(yy); yy += b.alto + hueco; });
}

export async function generarPdf({ config: c, etiquetas, barras, sucursal }, guias = false) {
    const { jsPDF } = await import('jspdf');
    const m = medidas(c);
    const fecha = new Date().toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit' });
    let pdf;
    if (c.formato === 'rollo') {
        pdf = new jsPDF({ unit: 'mm', format: [m.W, m.H], orientation: m.W > m.H ? 'landscape' : 'portrait' });
        etiquetas.forEach((e, i) => {
            if (i > 0) pdf.addPage([m.W, m.H], m.W > m.H ? 'landscape' : 'portrait');
            etiqueta(pdf, 0, 0, e, c, m, barras, sucursal, fecha);
        });
    } else {
        const [hw, hh] = HOJAS[c.hoja] ?? HOJAS.A4;
        pdf = new jsPDF({ unit: 'mm', format: [hw, hh], orientation: 'portrait' });
        const mg = Number(c.margen), s = Number(c.separacion), cols = Number(c.columnas);
        const filas = Math.max(1, Math.floor((hh - 2 * mg + s) / (m.H + s)));
        etiquetas.forEach((e, i) => {
            const enHoja = i % (cols * filas);
            if (i > 0 && enHoja === 0) pdf.addPage([hw, hh], 'portrait');
            const x = mg + (enHoja % cols) * (m.W + s);
            const y = mg + Math.floor(enHoja / cols) * (m.H + s);
            if (guias) { pdf.setDrawColor(190); pdf.setLineWidth(0.1); pdf.setLineDashPattern([0.8, 0.8], 0); pdf.rect(x, y, m.W, m.H); pdf.setLineDashPattern([], 0); }
            etiqueta(pdf, x, y, e, c, m, barras, sucursal, fecha);
        });
    }
    pdf.setProperties({ title: `Etiquetas ${c.ancho}x${c.alto} mm`, creator: 'StokPOS' });
    return pdf;
}
