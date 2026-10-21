<?php

namespace App\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/** Exporta una tabla a CSV (separado por ;) o a Excel real (.xlsx). */
final class Exportador
{
    public static function descargar(string $formato, string $nombre, array $cabecera, array $filas)
    {
        $nombre = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombre).'.'.$formato;
        $tmp = tempnam(sys_get_temp_dir(), 'exp');

        $w = $formato === 'xlsx'
            ? new XlsxWriter()
            : new CsvWriter(new CsvOptions(FIELD_DELIMITER: ';'));
        $w->openToFile($tmp);
        $w->addRow(Row::fromValuesWithStyle($cabecera, (new Style())->withFontBold(true)));
        foreach ($filas as $f) {
            // evita inyeccion de formulas al abrir el archivo en Excel
            $f = array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $f);
            $w->addRow(Row::fromValues($f));
        }
        $w->close();

        return response()->download($tmp, $nombre, [
            'Content-Type' => $formato === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv; charset=UTF-8',
        ])->deleteFileAfterSend();
    }
}
