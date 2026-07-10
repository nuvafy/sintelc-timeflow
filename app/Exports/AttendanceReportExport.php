<?php

namespace App\Exports;

use App\Models\AttendanceLog;
use App\Models\BiometricUserSync;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Color;

class AttendanceReportExport implements WithEvents, WithTitle
{
    public function __construct(
        private int     $clientId,
        private string  $clientName,
        private ?string $dateFrom,
        private ?string $dateTo,
        private ?string $search,
        private ?string $statusFilter,
        private ?string $checkTypeFilter,
    ) {}

    public function title(): string
    {
        return 'Asistencia';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // ── Fetch & group data ─────────────────────────────────────
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

                // Local employee names (local_name on BiometricUserSync)
                $localNames = BiometricUserSync::where('client_id', $this->clientId)
                    ->whereNotNull('local_name')
                    ->pluck('local_name', 'external_employee_code');

                // Group logs by employee key → date → events
                $byEmployee = [];
                foreach ($query as $log) {
                    if ($log->factorialEmployee) {
                        $empKey  = 'f_' . $log->factorialEmployee->id;
                        $empName = $log->factorialEmployee->full_name;
                        $isLocal = false;
                    } elseif (isset($localNames[$log->employee_code])) {
                        $empKey  = 'l_' . $log->employee_code;
                        $empName = $localNames[$log->employee_code];
                        $isLocal = true;
                    } else {
                        $empKey  = 'u_' . $log->employee_code;
                        $empName = 'PIN ' . $log->employee_code;
                        $isLocal = false;
                    }

                    $date = $log->occurred_at->format('Y-m-d');

                    if (!isset($byEmployee[$empKey])) {
                        $byEmployee[$empKey] = ['name' => $empName, 'local' => $isLocal, 'days' => []];
                    }
                    $byEmployee[$empKey]['days'][$date][] = $log;
                }

                // ── Styles ────────────────────────────────────────────────
                $headerBg   = 'FF4F46E5'; // indigo
                $groupBg    = 'FFF1F0FF'; // light indigo
                $totalBg    = 'FFEEF2FF';
                $localBg    = 'FFFFF3CD'; // amber tint
                $borderColor= 'FFD1D5DB';
                $white      = 'FFFFFFFF';

                $sheet->getColumnDimension('A')->setWidth(16);
                $sheet->getColumnDimension('B')->setWidth(30);
                $sheet->getColumnDimension('C')->setWidth(12);
                $sheet->getColumnDimension('D')->setWidth(12);
                $sheet->getColumnDimension('E')->setWidth(28);
                $sheet->getColumnDimension('F')->setWidth(14);

                // ── Title row ────────────────────────────────────────────
                $sheet->setCellValue('A1', 'Reporte de asistencia — ' . $this->clientName);
                $sheet->mergeCells('A1:F1');
                $sheet->getStyle('A1')->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FF1E1B4B']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(22);

                $dateLabel = trim(($this->dateFrom ?? '') . ' — ' . ($this->dateTo ?? ''));
                $sheet->setCellValue('A2', $dateLabel);
                $sheet->mergeCells('A2:F2');
                $sheet->getStyle('A2')->applyFromArray([
                    'font'      => ['size' => 10, 'color' => ['argb' => 'FF6B7280']],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFEEF2FF']],
                ]);
                $sheet->getRowDimension(2)->setRowHeight(16);

                // ── Column headers ────────────────────────────────────────
                $row = 4;
                $headers = ['Fecha', 'Empleado', 'Entrada', 'Salida', 'Área / Biométrico', 'Estado'];
                foreach ($headers as $col => $header) {
                    $cell = chr(65 + $col) . $row;
                    $sheet->setCellValue($cell, $header);
                }
                $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => $white]],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $headerBg]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF6366F1']]],
                ]);
                $sheet->getRowDimension($row)->setRowHeight(18);
                $row++;

                // ── Employee groups ───────────────────────────────────────
                foreach ($byEmployee as $empData) {
                    $empName   = $empData['name'];
                    $isLocal   = $empData['local'];
                    $days      = $empData['days'];
                    $totalMins = 0;
                    $daysOk    = 0;
                    $daysNA    = 0;
                    $empStartRow = $row;

                    // Employee header row
                    $label = $empName . ($isLocal ? '  [Local]' : '');
                    $sheet->setCellValue("A{$row}", $label);
                    $sheet->mergeCells("A{$row}:F{$row}");
                    $bg = $isLocal ? $localBg : $groupBg;
                    $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
                        'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => $isLocal ? 'FF92400E' : 'FF3730A3']],
                        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
                        'borders'   => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => $borderColor]]],
                    ]);
                    $sheet->getRowDimension($row)->setRowHeight(20);
                    $row++;

                    // Day rows
                    ksort($days);
                    foreach ($days as $date => $events) {
                        // Find check_in and check_out anchored to check_in date
                        $checkIn  = null;
                        $checkOut = null;
                        foreach ($events as $e) {
                            if ($e->check_type === 'check_in' && !$checkIn)   $checkIn  = $e;
                            if ($e->check_type === 'check_out' && !$checkOut) $checkOut = $e;
                        }

                        // Also look for check_out in next-day events (night shifts: within 24h)
                        if ($checkIn && !$checkOut) {
                            $nextDate = \Carbon\Carbon::parse($date)->addDay()->format('Y-m-d');
                            if (isset($days[$nextDate])) {
                                foreach ($days[$nextDate] as $e) {
                                    if ($e->check_type === 'check_out' && !$checkOut) {
                                        $diff = $e->occurred_at->diffInMinutes($checkIn->occurred_at);
                                        if ($diff <= 1440) $checkOut = $e; // within 24h
                                    }
                                }
                            }
                        }

                        $inTime   = $checkIn  ? $checkIn->occurred_at->format('H:i')  : null;
                        $outTime  = $checkOut ? $checkOut->occurred_at->format('H:i') : null;
                        $area     = $checkIn?->biometricSource?->name ?? $checkOut?->biometricSource?->name ?? '—';

                        if ($checkIn && $checkOut) {
                            $mins       = $checkOut->occurred_at->diffInMinutes($checkIn->occurred_at);
                            $totalMins += $mins;
                            $estado     = 'Completo';
                            $daysOk++;
                        } else {
                            $mins   = null;
                            $estado = $checkIn ? 'Sin salida' : ($checkOut ? 'Sin entrada' : 'N/A');
                            $daysNA++;
                        }

                        $dateLabel = \Carbon\Carbon::parse($date)->locale('es')->isoFormat('ddd D MMM');

                        $sheet->setCellValue("A{$row}", $dateLabel);
                        $sheet->setCellValue("B{$row}", '');
                        $sheet->setCellValue("C{$row}", $inTime  ?? '—');
                        $sheet->setCellValue("D{$row}", $outTime ?? '—');
                        $sheet->setCellValue("E{$row}", $area);
                        $sheet->setCellValue("F{$row}", $estado);

                        $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
                            'font'      => ['size' => 10],
                            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $white]],
                            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                        ]);
                        $sheet->getStyle("A{$row}")->getAlignment()->setIndent(2);
                        $sheet->getStyle("C{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                        // Color estado cell
                        if ($estado === 'Completo') {
                            $sheet->getStyle("F{$row}")->getFont()->getColor()->setARGB('FF15803D');
                        } elseif (in_array($estado, ['Sin salida', 'Sin entrada'])) {
                            $sheet->getStyle("F{$row}")->getFont()->getColor()->setARGB('FFB45309');
                        }

                        $sheet->getStyle("A{$row}:F{$row}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setARGB($borderColor);
                        $sheet->getRowDimension($row)->setRowHeight(16);
                        $row++;
                    }

                    // Total row
                    $totalH   = intdiv($totalMins, 60);
                    $totalM   = $totalMins % 60;
                    $totalStr = $daysOk > 0 ? "{$totalH}h {$totalM}min" : 'N/A';
                    $naStr    = $daysNA > 0 ? "  ({$daysOk} días completos · {$daysNA} N/A)" : "  ({$daysOk} días completos)";

                    $sheet->setCellValue("E{$row}", 'Total horas laboradas');
                    $sheet->setCellValue("F{$row}", $totalStr . $naStr);
                    $sheet->mergeCells("F{$row}:F{$row}");
                    $sheet->getStyle("A{$row}:F{$row}")->applyFromArray([
                        'font'      => ['bold' => true, 'size' => 10],
                        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $totalBg]],
                        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                        'borders'   => [
                            'top'    => ['borderStyle' => Border::BORDER_THIN,   'color' => ['argb' => $borderColor]],
                            'bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => $borderColor]],
                        ],
                    ]);
                    $sheet->getStyle("E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    $sheet->getStyle("E{$row}")->getFont()->getColor()->setARGB('FF4338CA');
                    $sheet->getStyle("F{$row}")->getFont()->setBold(true)->getColor()->setARGB('FF1E1B4B');
                    $sheet->getRowDimension($row)->setRowHeight(18);
                    $row++;

                    $row++; // blank row between employees
                }

                // Freeze header rows
                $sheet->freezePane('A5');
            },
        ];
    }
}
