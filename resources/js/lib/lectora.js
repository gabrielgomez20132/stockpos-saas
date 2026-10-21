// Pistola lectora de codigos de barras: "tipea" el codigo muy rapido. Casi todas mandan
// Enter al final (eso ya agrega el producto). Para las que vienen sin Enter, esto detecta
// la rafaga y avisa sola cuando termina. Una persona no escribe 6 teclas seguidas a <35 ms.
export function crearLectora(alTerminar, { minimo = 6, intervalo = 35, espera = 90 } = {}) {
    let ultimo = 0;
    let rapidas = 0;
    let largo = 0;
    let timer = null;
    return {
        /** llamar en cada evento "input" del campo */
        entrada(ev) {
            const ahora = performance.now();
            const valor = ev?.target?.value ?? '';
            const unaTecla = valor.length === largo + 1; // pegar un texto no cuenta como lectora
            rapidas = unaTecla && ahora - ultimo < intervalo ? rapidas + 1 : 1;
            ultimo = ahora;
            largo = valor.length;
            clearTimeout(timer);
            if (rapidas >= minimo) timer = setTimeout(() => { if ((ev.target.value ?? '').trim()) alTerminar(); rapidas = 0; }, espera);
        },
        /** llamar cuando llega el Enter (ya se agrego por ese lado) */
        cancelar() { clearTimeout(timer); rapidas = 0; largo = 0; },
    };
}
