<?php
/**
 * One-time script to create admin/ExportTemplate.xls.
 * Run from project root: php admin/create_export_template.php
 * Then use Assign Groups → "Export Students (Excel)" which uses this template.
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

$headers = ['Crew', 'First Name', 'Last Name', 'Age', 'Grade in Fall', 'Date of Birth', 'Allergy', 'T-shirt Size', 'Do you go to church', 'Home Church'];
$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Students');
$col = 'A';
foreach ($headers as $h) {
  $sheet->setCellValue($col . '1', $h);
  $sheet->getStyle($col . '1')->getFont()->setBold(true);
  $sheet->getColumnDimension($col)->setWidth(18);
  $col++;
}
$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xls($spreadsheet);
$path = __DIR__ . '/ExportTemplate.xls';
$writer->save($path);
echo "Created: $path\n";
