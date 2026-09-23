<?php

declare(strict_types=1);

namespace Hfiuc\Xlsx;

use Hfiuc\Http\HttpException;
use ZipArchive;

final class SimpleXlsx
{
    /** @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public static function build(array $headers, array $rows): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new HttpException(500, 'Unable to create export.');
        }
        $sheetRows = array_merge([$headers], $rows);
        $sheet = self::sheet($sheetRows);
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="Reservations" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '</Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
        $path = tempnam(sys_get_temp_dir(), 'hfiuc');
        if ($path === false) {
            throw new HttpException(500, 'Unable to create export.');
        }
        @unlink($path);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            @unlink($path);
            throw new HttpException(500, 'Unable to create export.');
        }
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $bytes = file_get_contents($path);
        @unlink($path);
        if ($bytes === false) {
            throw new HttpException(500, 'Unable to create export.');
        }

        return $bytes;
    }

    /** @param list<list<string>> $rows */
    private static function sheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $index => $row) {
            $line = $index + 1;
            $xml .= '<row r="' . $line . '">';
            foreach ($row as $column => $value) {
                $cell = self::column($column) . $line;
                $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t>' . self::escape($value) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';

        return $xml;
    }

    private static function column(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
