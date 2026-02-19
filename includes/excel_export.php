<?php
/**
 * Excel export helper for the Assign Groups page.
 *
 * Call export_students_excel() to stream an .xls file to the browser and exit.
 * All PhpSpreadsheet dependencies are loaded here.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// ─── Private helpers ─────────────────────────────────────────────────────────

function _excel_format_dob(?string $v): string
{
    if ($v === null || $v === '') return '';
    $ts = strtotime($v);
    return $ts !== false ? date('m/d/Y', $ts) : $v;
}

function _excel_church_yes_no(array $k): string
{
    return (isset($k['home_church']) && trim($k['home_church'] ?? '') !== '') ? 'Yes' : 'No';
}

function _excel_home_church(array $k): string
{
    return trim($k['home_church'] ?? '');
}

/**
 * Write one kid row at the given $row index.
 */
function _excel_write_kid_row(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    int $row,
    string $crewName,
    array $k
): void {
    $sheet->setCellValue('A' . $row, $crewName);
    $sheet->setCellValue('B' . $row, $k['first_name'] ?? '');
    $sheet->setCellValue('C' . $row, $k['last_name'] ?? '');
    $sheet->setCellValue('D' . $row, ($k['age'] !== null && $k['age'] !== '') ? (int) $k['age'] : '');
    $sheet->setCellValue('E' . $row, $k['last_grade_completed'] ?? '');
    $sheet->setCellValue('F' . $row, _excel_format_dob($k['date_of_birth'] ?? null));
    $sheet->setCellValue('G' . $row, $k['medical_allergy_info'] ?? '');
    $sheet->setCellValue('H' . $row, $k['t_shirt_size'] ?? '');
    $sheet->setCellValue('I' . $row, _excel_church_yes_no($k));
    $sheet->setCellValue('J' . $row, _excel_home_church($k));
}

/**
 * Merge column A over a crew block, top-align, and enable text wrap
 * so the volunteer line displays below the group name.
 */
function _excel_merge_crew_column(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
    int $start,
    int $end
): void {
    if ($end < $start) return;
    $range = "A{$start}:A{$end}";
    $sheet->mergeCells($range);
    $sheet->getStyle($range)->getAlignment()
        ->setVertical(Alignment::VERTICAL_TOP)
        ->setWrapText(true);
}

/**
 * Build the crew cell label: group name on the first line, then volunteer names
 * (Crew Leaders first, then Assistants) in parentheses on the second line.
 * Returns just the group name when there are no volunteers.
 */
function _excel_crew_label(string $groupName, array $volunteers): string
{
    $names = [];
    foreach (['Crew Leader', 'Assistant', 'Crew Member'] as $role) {
        foreach ($volunteers as $v) {
            if ($v['role'] === $role) {
                $names[] = ($role === 'Crew Leader' ? '*' : '') . $v['name'];
            }
        }
    }
    if (empty($names)) return $groupName;
    return $groupName . "\n(" . implode(', ', $names) . ')';
}

// ─── Spreadsheet builder ──────────────────────────────────────────────────────

function _excel_build_spreadsheet(array $groups, array $by_group, array $unassigned, array $volunteers_by_group = []): Spreadsheet
{
    $templatePath = dirname(__DIR__) . '/admin/ExportTemplate.xls';

    if (file_exists($templatePath)) {
        $spreadsheet = IOFactory::load($templatePath);
    } else {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $headers = [
            'A' => 'Crew',
            'B' => 'First Name',
            'C' => 'Last Name',
            'D' => 'Age',
            'E' => 'Grade in Fall',
            'F' => 'Date of Birth',
            'G' => 'Allergy',
            'H' => 'T-shirt Size',
            'I' => 'Do you go to church',
            'J' => 'Home Church',
        ];
        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col . '1', $label);
            $sheet->getColumnDimension($col)->setWidth(18);
        }
    }

    $sheet = $spreadsheet->getActiveSheet();

    // ── Header row formatting ──────────────────────────────────────────────
    $sheet->getRowDimension(1)->setRowHeight(45);
    $sheet->getColumnDimension('D')->setWidth(37 / 7);
    $sheet->getColumnDimension('E')->setWidth(71 / 7);
    $sheet->getColumnDimension('F')->setWidth(82 / 7);
    $sheet->getColumnDimension('H')->setWidth(68 / 7);

    $headerStyle = $sheet->getStyle('A1:J1');
    $headerStyle->getFont()->setBold(true);
    $headerStyle->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()->setRGB('D3D3D3');

    // ── Data rows ─────────────────────────────────────────────────────────
    $row        = 2;
    $blockStart = null;
    $blockEnd   = null;

    foreach ($groups as $g) {
        $crewKids  = $by_group[(int) $g['id']] ?? [];
        $groupName = ($g['name'] !== '') ? $g['name'] : 'Group ' . (int) $g['id'];
        $crewName  = _excel_crew_label($groupName, $volunteers_by_group[(int) $g['id']] ?? []);

        // Seal previous crew block before starting a new one
        if ($blockStart !== null && count($crewKids) > 0) {
            _excel_merge_crew_column($sheet, $blockStart, $blockEnd);
            $blockStart = null;
        }

        if (count($crewKids) > 0) {
            $blockStart = $row;
            $blockEnd   = $row - 1;
        }

        foreach ($crewKids as $k) {
            _excel_write_kid_row($sheet, $row, $crewName, $k);
            $blockEnd = $row;
            $row++;
        }

        // Blank separator row between groups
        if (count($crewKids) > 0) {
            $row++;
        }
    }

    // Seal last assigned block before unassigned section
    if ($blockStart !== null && count($unassigned) > 0) {
        _excel_merge_crew_column($sheet, $blockStart, $blockEnd);
        $blockStart = null;
    }

    if (count($unassigned) > 0) {
        $blockStart = $row;
        $blockEnd   = $row - 1;
        foreach ($unassigned as $k) {
            _excel_write_kid_row($sheet, $row, 'Unassigned', $k);
            $blockEnd = $row;
            $row++;
        }
    }

    if ($blockStart !== null) {
        _excel_merge_crew_column($sheet, $blockStart, $blockEnd);
    }

    // ── Final formatting ──────────────────────────────────────────────────
    $lastRow = $row - 1;
    if ($lastRow >= 1) {
        $dataRange = "A1:J{$lastRow}";
        $sheet->getStyle($dataRange)->getFont()->setSize(12);
        $sheet->getStyle($dataRange)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("D1:D{$lastRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    return $spreadsheet;
}

// ─── Public entry point ───────────────────────────────────────────────────────

/**
 * Build and stream the students Excel file, then exit.
 *
 * @param array $groups              Rows from the groups table (id, name, sort_order)
 * @param array $by_group            Kids keyed by group_id
 * @param array $unassigned          Kids with no group
 * @param array $volunteers_by_group Volunteer rows keyed by group_id
 */
function export_students_excel(array $groups, array $by_group, array $unassigned, array $volunteers_by_group = []): void
{
    $spreadsheet = _excel_build_spreadsheet($groups, $by_group, $unassigned, $volunteers_by_group);
    $filename    = 'students-' . date('Y-m-d') . '.xls';

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    (new XlsWriter($spreadsheet))->save('php://output');
    exit;
}
