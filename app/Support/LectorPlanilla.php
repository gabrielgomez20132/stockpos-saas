<?php

namespace App\Support;

use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/** Lee la primera hoja de un Excel (.xlsx), LibreOffice (.ods) o CSV como filas de texto. */
class LectorPlanilla
{
    public static function leer(string $ruta, string $ext, int $max = 10000): array
    {
        $ext = strtolower($ext);
        if ($ext === 'csv' || $ext === 'txt') {
            $primera = (string) fgets(fopen($ruta, 'r'));
            $sep = substr_count($primera, ';') >= substr_count($primera, ',') ? ';' : ',';
            if (substr_count($primera, "\t") > max(substr_count($primera, ';'), substr_count($primera, ','))) {
                $sep = "\t";
            }
            $reader = new CsvReader(new CsvOptions(FIELD_DELIMITER: $sep));
        } elseif ($ext === 'ods') {
            $reader = new OdsReader();
        } else {
            $reader = new XlsxReader();
        }

        $filas = [];
        try {
            $reader->open($ruta);
            foreach ($reader->getSheetIterator() as $hoja) {
                foreach ($hoja->getRowIterator() as $row) {
                    $filas[] = array_map(function ($c) {
                        if ($c instanceof \DateTimeInterface) {
                            return $c->format('Y-m-d');
                        }
                        if (is_float($c) && floor($c) == $c && abs($c) < 1e15) {
                            return (string) (int) $c;
                        }
                        if (is_float($c)) {
                            return (string) round($c, 4);
                        }

                        return is_string($c) ? trim(preg_replace('/^\xEF\xBB\xBF/', '', $c)) : (string) $c;
                    }, $row->toArray());
                    if (count($filas) >= $max) {
                        break 2;
                    }
                }
                break;
            }
        } catch (\Throwable) {
            throw new \DomainException('No se pudo leer el archivo. Guardalo como Excel (.xlsx), LibreOffice (.ods) o CSV y probá de nuevo.');
        } finally {
            $reader->close();
        }

        return $filas;
    }
}
