<?php

namespace App\Support;

use DateTimeInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * MASTER-HISAB-EXCEL-1 — chhota sa asli .xlsx banane wala (kai sheets, number number rahein, tareekh tareekh).
 *
 * Project me koi Excel package nahi, aur sirf aik file ke liye naya package (aur us ke saath har deploy par composer ka
 * khatra) zaroori nahi: .xlsx asal me chand XML files ka zip hai, aur PHP ka ZipArchive prod par mojood hai.
 * CSV yahan kaafi nahi tha — wo aik hi sheet hoti hai aur Excel us me raqam ko kabhi text, kabhi number samajhta hai.
 *
 * Cell: saada qeemat (string / int / float / DateTimeInterface / null), ya ['v' => qeemat, 's' => style].
 */
final class SimpleXlsx
{
    public const PLAIN = 0;

    public const HEADER = 1;   // mota, halka rang, neeche lakeer

    public const MONEY = 2;    // #,##0.00

    public const DATE = 3;     // 01 Oct 2026

    public const TITLE = 4;    // bara mota

    public const MONEY_BOLD = 5;

    public const PERCENT = 6;  // 41.46%

    public const BOLD = 7;

    public const NOTE = 8;     // halka, tircha — samjhane wali line

    /** @var list<array{name: string, rows: list<list<mixed>>, widths: list<float>, freeze: int}> */
    private array $sheets = [];

    /** Nayi sheet; wapas us ka number. `freeze` = itni upar ki rows scroll me ruki rahein. */
    public function sheet(string $name, array $widths = [], int $freeze = 0): int
    {
        $this->sheets[] = ['name' => $this->sheetName($name), 'rows' => [], 'widths' => $widths, 'freeze' => $freeze];

        return count($this->sheets) - 1;
    }

    /** @param  list<mixed>  $cells */
    public function row(int $sheet, array $cells = []): void
    {
        $this->sheets[$sheet]['rows'][] = $cells;
    }

    public function rowCount(int $sheet): int
    {
        return count($this->sheets[$sheet]['rows']);
    }

    public function download(string $filename): BinaryFileResponse
    {
        $path = $this->write();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** File temp folder me likh kar us ka raasta deta hai. */
    public function write(): string
    {
        if ($this->sheets === []) {
            throw new RuntimeException('An .xlsx needs at least one sheet.');
        }
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the .xlsx file.');
        }

        $n = count($this->sheets);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .implode('', array_map(fn ($i) => '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, $n)))
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.implode('', array_map(fn ($i) => '<sheet name="'.$this->esc($this->sheets[$i - 1]['name']).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>', range(1, $n))).'</sheets>'
            .'</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', array_map(fn ($i) => '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>', range(1, $n)))
            .'<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $this->sheetXml($sheet));
        }
        $zip->close();

        return $path;
    }

    private function sheetXml(array $sheet): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($sheet['freeze'] > 0) {
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$sheet['freeze'].'" topLeftCell="A'.($sheet['freeze'] + 1)
                .'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if ($sheet['widths']) {
            $xml .= '<cols>';
            foreach (array_values($sheet['widths']) as $i => $w) {
                $xml .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.(float) $w.'" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ($sheet['rows'] as $r => $cells) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach (array_values($cells) as $c => $cell) {
                $xml .= $this->cellXml($this->column($c).($r + 1), $cell);
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private function cellXml(string $ref, mixed $cell): string
    {
        $style = self::PLAIN;
        if (is_array($cell)) {
            $style = (int) ($cell['s'] ?? self::PLAIN);
            $cell = $cell['v'] ?? null;
        }
        $s = $style ? ' s="'.$style.'"' : '';
        if ($cell === null || $cell === '') {
            return $style ? '<c r="'.$ref.'"'.$s.'/>' : '';
        }
        if ($cell instanceof DateTimeInterface) {
            // Excel ki tareekh = 1899-12-30 se din.
            $days = (int) floor(($cell->getTimestamp() + $cell->getOffset()) / 86400) + 25569;

            return '<c r="'.$ref.'" s="'.($style ?: self::DATE).'"><v>'.$days.'</v></c>';
        }
        if (is_int($cell) || is_float($cell)) {
            return '<c r="'.$ref.'"'.$s.'><v>'.(is_float($cell) ? round($cell, 6) : $cell).'</v></c>';
        }

        return '<c r="'.$ref.'"'.$s.' t="inlineStr"><is><t xml:space="preserve">'.$this->esc((string) $cell).'</t></is></c>';
    }

    private function styles(): string
    {
        // Font 0 aam, 1 mota, 2 bara mota, 3 halka tircha. Fill 2 = header ka halka neela. Border 1 = neeche lakeer.
        $xf = fn (int $numFmt, int $font, int $fill = 0, int $border = 0) => '<xf numFmtId="'.$numFmt.'" fontId="'.$font.'" fillId="'.$fill
            .'" borderId="'.$border.'" xfId="0"'.($numFmt ? ' applyNumberFormat="1"' : '').($font ? ' applyFont="1"' : '')
            .($fill ? ' applyFill="1"' : '').($border ? ' applyBorder="1"' : '').'/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="dd mmm yyyy"/>'
            .'<numFmt numFmtId="166" formatCode="0.00%"/></numFmts>'
            .'<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFDCE8F8"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left/><right/><top/><bottom style="thin"><color rgb="FF8DA2C0"/></bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="9">'
            .$xf(0, 0)          // 0 PLAIN
            .$xf(0, 1, 2, 1)    // 1 HEADER
            .$xf(164, 0)        // 2 MONEY
            .$xf(165, 0)        // 3 DATE
            .$xf(0, 2)          // 4 TITLE
            .$xf(164, 1)        // 5 MONEY_BOLD
            .$xf(166, 0)        // 6 PERCENT
            .$xf(0, 1)          // 7 BOLD
            .$xf(0, 3)          // 8 NOTE
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function column(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    /** Excel sheet ka naam: 31 harf, in ke baghair : \ / ? * [ ], aur do sheets ka aik naam nahi. */
    private function sheetName(string $name): string
    {
        $base = mb_substr(trim(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $name)) ?: 'Sheet', 0, 31);
        $taken = array_map(fn ($s) => mb_strtolower($s['name']), $this->sheets);
        $candidate = $base;
        for ($k = 2; in_array(mb_strtolower($candidate), $taken, true); $k++) {
            $candidate = mb_substr($base, 0, 31 - strlen(" ($k)"))." ($k)";
        }

        return $candidate;
    }

    private function esc(string $s): string
    {
        // XML 1.0 me control characters mana hain (tab / newline theek hain).
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
