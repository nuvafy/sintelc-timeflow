<?php

namespace App\Exports;

use App\Models\AttendanceLog;
use App\Models\BiometricUserSync;
use Carbon\Carbon;

/**
 * Generates a styled .xlsx attendance report using ZipArchive + XML (no composer packages).
 * XLSX = ZIP of XML files per the Office Open XML spec.
 */
class AttendanceReportExport
{
    private array $sharedStrings = [];
    private array $rows = [];

    public function __construct(
        private int     $clientId,
        private string  $clientName,
        private ?string $dateFrom,
        private ?string $dateTo,
        private ?string $search,
        private ?string $statusFilter,
        private ?string $checkTypeFilter,
    ) {}

    public function download(string $filename): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response
    {
        $path = $this->buildFile();
        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function buildFile(): string
    {
        $this->buildRows();

        $path = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip  = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);

        // sheet() must run first — it populates $this->sharedStrings via si()
        $sheetXml         = $this->sheet();
        $sharedStringsXml = $this->sharedStringsXml();

        $zip->addFromString('[Content_Types].xml',           $this->contentTypes());
        $zip->addFromString('_rels/.rels',                   $this->rels());
        $zip->addFromString('xl/workbook.xml',               $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels',    $this->workbookRels());
        $zip->addFromString('xl/styles.xml',                 $this->styles());
        $zip->addFromString('xl/sharedStrings.xml',          $sharedStringsXml);
        $zip->addFromString('xl/worksheets/sheet1.xml',      $sheetXml);

        $zip->close();
        return $path;
    }

    // ── Data building ─────────────────────────────────────────────────────────

    private function buildRows(): void
    {
        $query = AttendanceLog::with(['biometricSource', 'factorialEmployee'])
            ->where('client_id', $this->clientId)
            ->when($this->dateFrom, fn($q) => $q->whereDate('occurred_at', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn($q) => $q->whereDate('occurred_at', '<=', $this->dateTo))
            ->when($this->search, fn($q) => $q->where(function ($q2) {
                $q2->where('employee_code', 'like', "%{$this->search}%")
                   ->orWhereHas('factorialEmployee', fn($e) => $e->where('full_name', 'like', "%{$this->search}%"));
            }))
            ->when($this->statusFilter,    fn($q) => $q->where('sync_status', $this->statusFilter))
            ->when($this->checkTypeFilter, fn($q) => $q->where('check_type', $this->checkTypeFilter))
            ->orderBy('occurred_at')
            ->get();

        $localNames = BiometricUserSync::where('client_id', $this->clientId)
            ->whereNotNull('local_name')
            ->pluck('local_name', 'external_employee_code');

        // Group by employee → date
        $byEmployee = [];
        foreach ($query as $log) {
            if ($log->factorialEmployee) {
                $key  = 'f_' . $log->factorialEmployee->id;
                $name = $log->factorialEmployee->full_name;
                $local = false;
            } elseif (isset($localNames[$log->employee_code])) {
                $key  = 'l_' . $log->employee_code;
                $name = $localNames[$log->employee_code];
                $local = true;
            } else {
                $key  = 'u_' . $log->employee_code;
                $name = 'PIN ' . $log->employee_code;
                $local = false;
            }

            $date = $log->occurred_at->format('Y-m-d');
            if (!isset($byEmployee[$key])) {
                $byEmployee[$key] = ['name' => $name, 'local' => $local, 'days' => []];
            }
            $byEmployee[$key]['days'][$date][] = $log;
        }

        $rows = [];

        // Title rows
        $rows[] = ['type' => 'title',   'values' => ['Reporte de asistencia — ' . $this->clientName]];
        $rows[] = ['type' => 'subtitle','values' => [trim(($this->dateFrom ?? '') . ' — ' . ($this->dateTo ?? ''))]];
        $rows[] = ['type' => 'blank',   'values' => []];

        // Header
        $rows[] = ['type' => 'header',  'values' => ['Fecha', 'Entrada', 'Salida', 'Descanso', 'Área / Biométrico', 'Estado']];

        foreach ($byEmployee as $empData) {
            $rows[] = [
                'type'   => $empData['local'] ? 'emp_local' : 'emp_header',
                'values' => [$empData['name'] . ($empData['local'] ? ' [Local]' : ''), '', '', '', '', ''],
            ];

            $days      = $empData['days'];
            $totalMins = 0;
            $daysOk    = 0;
            $daysNA    = 0;

            ksort($days);
            $dayKeys = array_keys($days);

            foreach ($dayKeys as $date) {
                // Collect events for this date + next day (night shifts within 24h)
                $allEvents = $days[$date];
                $nextDate  = Carbon::parse($date)->addDay()->format('Y-m-d');
                if (isset($days[$nextDate])) {
                    $firstIn = collect($allEvents)->where('check_type', 'check_in')->sortBy('occurred_at')->first();
                    if ($firstIn) {
                        foreach ($days[$nextDate] as $e) {
                            if ($firstIn->occurred_at->diffInMinutes($e->occurred_at) <= 1440) {
                                $allEvents[] = $e;
                            }
                        }
                    }
                }

                // Sort chronologically
                usort($allEvents, fn($a, $b) => $a->occurred_at <=> $b->occurred_at);

                $col        = collect($allEvents);
                $checkIn    = $col->where('check_type', 'check_in')->first();
                $checkOut   = $col->where('check_type', 'check_out')->last();
                $inTime     = $checkIn  ? $checkIn->occurred_at->format('H:i')  : null;
                $outTime    = $checkOut ? $checkOut->occurred_at->format('H:i') : null;
                $area       = $checkIn?->biometricSource?->name ?? $checkOut?->biometricSource?->name ?? '—';

                // Calculate break minutes: break_in → break_out pairs
                // Falls back to intermediate check_out → check_in pairs if no break labels exist
                $breakMins    = 0;
                $breakStart   = null;
                $hasBreakLabels = collect($allEvents)->whereIn('check_type', ['break_in', 'break_out'])->isNotEmpty();

                if ($hasBreakLabels) {
                    foreach ($allEvents as $e) {
                        if ($e->check_type === 'break_in')  $breakStart = $e->occurred_at;
                        if ($e->check_type === 'break_out' && $breakStart) {
                            $breakMins += $breakStart->diffInMinutes($e->occurred_at);
                            $breakStart = null;
                        }
                    }
                } else {
                    // No break labels: intermediate check_out→check_in pairs are breaks
                    $prevOut = null;
                    foreach ($allEvents as $e) {
                        if ($e->check_type === 'check_out') $prevOut = $e->occurred_at;
                        if ($e->check_type === 'check_in' && $prevOut) {
                            $breakMins += $prevOut->diffInMinutes($e->occurred_at);
                            $prevOut = null;
                        }
                    }
                }

                $breakStr = $breakMins > 0
                    ? intdiv($breakMins, 60) . 'h ' . ($breakMins % 60) . 'min'
                    : '—';

                // Worked = total span − breaks
                $hasComplete = $checkIn && $checkOut;
                if ($hasComplete) {
                    $spanMins   = $checkIn->occurred_at->diffInMinutes($checkOut->occurred_at);
                    $workedMins = max(0, $spanMins - $breakMins);
                    $totalMins += $workedMins;
                    $estado     = 'Completo';
                    $daysOk++;
                } else {
                    $breakStr = '—';
                    $estado   = $checkIn ? 'Sin salida' : ($checkOut ? 'Sin entrada' : 'N/A');
                    $daysNA++;
                }

                $dateLabel = Carbon::parse($date)->locale('es')->isoFormat('ddd D MMM YYYY');

                $rows[] = [
                    'type'   => 'day',
                    'estado' => $estado,
                    'values' => [$dateLabel, $inTime ?? '—', $outTime ?? '—', $breakStr, $area, $estado],
                ];
            }

            // Total row
            $h = intdiv($totalMins, 60);
            $m = $totalMins % 60;
            $totalStr = $daysOk > 0 ? "{$h}h {$m}min" : 'N/A';
            $naNote   = $daysNA > 0 ? "({$daysOk} días completos · {$daysNA} N/A)" : "({$daysOk} días completos)";

            $rows[] = [
                'type'   => 'total',
                'values' => ['', '', '', '', 'Total horas laboradas', "{$totalStr}  {$naNote}"],
            ];
            $rows[] = ['type' => 'blank', 'values' => []];
        }

        $this->rows = $rows;
    }

    // ── Sheet XML ─────────────────────────────────────────────────────────────

    private function sheet(): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetViews><sheetView workbookViewId="0"><selection activeCell="A1"/></sheetView></sheetViews>';
        $xml .= '<sheetFormatPr defaultRowHeight="16"/>';
        $xml .= '<cols>';
        $xml .= '<col min="1" max="1" width="18" customWidth="1"/>';
        $xml .= '<col min="2" max="2" width="10" customWidth="1"/>';
        $xml .= '<col min="3" max="3" width="10" customWidth="1"/>';
        $xml .= '<col min="4" max="4" width="12" customWidth="1"/>';
        $xml .= '<col min="5" max="5" width="28" customWidth="1"/>';
        $xml .= '<col min="6" max="6" width="34" customWidth="1"/>';
        $xml .= '</cols>';
        $xml .= '<sheetData>';

        $rowNum = 1;
        foreach ($this->rows as $row) {
            $xml .= $this->buildRow($rowNum, $row);
            $rowNum++;
        }

        $xml .= '</sheetData>';

        // Merges for title/subtitle/emp headers (col A–F = 1–6)
        $merges = [];
        $r = 1;
        foreach ($this->rows as $row) {
            if (in_array($row['type'], ['title', 'subtitle', 'emp_header', 'emp_local'])) {
                $merges[] = "A{$r}:F{$r}";
            }
            $r++;
        }
        if ($merges) {
            $xml .= '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $m) $xml .= "<mergeCell ref=\"{$m}\"/>";
            $xml .= '</mergeCells>';
        }

        $xml .= '</worksheet>';
        return $xml;
    }

    private function buildRow(int $rowNum, array $row): string
    {
        $type   = $row['type'];
        $values = $row['values'];

        // style indices (defined in styles()) — 6 columns A-F
        $styleMap = [
            'title'      => [0 => 1],
            'subtitle'   => [0 => 2],
            'header'     => [0=>3,1=>3,2=>3,3=>3,4=>3,5=>3],
            'emp_header' => [0 => 4],
            'emp_local'  => [0 => 5],
            'day'        => [0=>6,1=>7,2=>7,3=>7,4=>6,5=>6],
            'total'      => [0=>8,1=>8,2=>8,3=>8,4=>9,5=>10],
            'blank'      => [],
        ];

        $styles = $styleMap[$type] ?? [];

        if ($type === 'blank' || empty($values)) {
            return "<row r=\"{$rowNum}\"><c r=\"A{$rowNum}\" t=\"s\"><v></v></c></row>";
        }

        $ht = match($type) {
            'title'      => ' ht="22" customHeight="1"',
            'header'     => ' ht="18" customHeight="1"',
            'emp_header','emp_local' => ' ht="20" customHeight="1"',
            'total'      => ' ht="18" customHeight="1"',
            default      => '',
        };

        $xml = "<row r=\"{$rowNum}\"{$ht}>";
        $cols = ['A','B','C','D','E','F'];

        foreach ($values as $i => $val) {
            $col   = $cols[$i] ?? chr(65 + $i);
            $ref   = $col . $rowNum;
            $style = $styles[$i] ?? 0;

            if ($val === '' || $val === null) {
                $xml .= "<c r=\"{$ref}\" s=\"{$style}\"/>";
            } else {
                $si  = $this->si($val);
                $xml .= "<c r=\"{$ref}\" t=\"s\" s=\"{$style}\"><v>{$si}</v></c>";
            }
        }

        $xml .= '</row>';
        return $xml;
    }

    // ── Shared strings ────────────────────────────────────────────────────────

    private function si(string $val): int
    {
        if (!isset($this->sharedStrings[$val])) {
            $this->sharedStrings[$val] = count($this->sharedStrings);
        }
        return $this->sharedStrings[$val];
    }

    private function sharedStringsXml(): string
    {
        $count = count($this->sharedStrings);
        $xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml  .= "<sst xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\" count=\"{$count}\" uniqueCount=\"{$count}\">";
        foreach (array_keys($this->sharedStrings) as $str) {
            $xml .= '<si><t xml:space="preserve">' . htmlspecialchars($str, ENT_XML1) . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }

    // ── Styles ───────────────────────────────────────────────────────────────
    // Style indices used in buildRow():
    // 0 = default
    // 1 = title (bold, large, indigo bg)
    // 2 = subtitle (small, gray, indigo bg)
    // 3 = header (bold white on indigo)
    // 4 = emp_header (bold indigo on light indigo)
    // 5 = emp_local  (bold amber on light amber)
    // 6 = day normal cell
    // 7 = day center (H:i columns)
    // 8 = total row base
    // 9 = total label (right-align, bold indigo)
    // 10 = total value (bold dark)

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="9">
    <font><sz val="12"/><color rgb="FF000000"/><name val="Calibri"/></font>
    <font><b/><sz val="14"/><color rgb="FF1E1B4B"/><name val="Calibri"/></font>
    <font><sz val="12"/><color rgb="FF6B7280"/><name val="Calibri"/></font>
    <font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    <font><b/><sz val="12"/><color rgb="FF3730A3"/><name val="Calibri"/></font>
    <font><b/><sz val="12"/><color rgb="FF92400E"/><name val="Calibri"/></font>
    <font><sz val="12"/><color rgb="FF111827"/><name val="Calibri"/></font>
    <font><b/><sz val="12"/><color rgb="FF4338CA"/><name val="Calibri"/></font>
    <font><b/><sz val="12"/><color rgb="FF1E1B4B"/><name val="Calibri"/></font>
  </fonts>
  <fills count="7">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEEF2FF"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF4F46E5"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF1F0FF"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFF3CD"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEEF2FF"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border><left/><right/><top><color rgb="FFD1D5DB"/></top><bottom><color rgb="FFD1D5DB"/></bottom><diagonal/></border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="11">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center" horizontal="left" indent="1"/></xf>
    <xf numFmtId="0" fontId="4" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="5" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" indent="1"/></xf>
    <xf numFmtId="0" fontId="6" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment vertical="center" indent="2"/></xf>
    <xf numFmtId="0" fontId="6" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment vertical="center" horizontal="center"/></xf>
    <xf numFmtId="0" fontId="6" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" indent="2"/></xf>
    <xf numFmtId="0" fontId="7" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" horizontal="right"/></xf>
    <xf numFmtId="0" fontId="8" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" indent="1"/></xf>
  </cellXfs>
</styleSheet>';
    }

    // ── OOXML boilerplate ─────────────────────────────────────────────────────

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml"   ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/sharedStrings.xml"       ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
  <Override PartName="/xl/styles.xml"              ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';
    }

    private function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Asistencia" sheetId="1" r:id="rId1"/></sheets>
</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"     Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"        Target="styles.xml"/>
</Relationships>';
    }
}
