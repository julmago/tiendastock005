<?php
declare(strict_types=1);

function xlsx_escape(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsx_column_letters(int $index): string {
  $letters = '';
  while ($index > 0) {
    $remainder = ($index - 1) % 26;
    $letters = chr(65 + $remainder).$letters;
    $index = intdiv($index - 1, 26);
  }
  return $letters;
}

function xlsx_build_sheet_xml(array $rows): string {
  $xml = [];
  $xml[] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
  $xml[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
  $xml[] = '<sheetData>';
  $rowIndex = 1;
  foreach ($rows as $row) {
    $xml[] = '<row r="'.$rowIndex.'">';
    $colIndex = 1;
    foreach ($row as $value) {
      $cellRef = xlsx_column_letters($colIndex).$rowIndex;
      $escaped = xlsx_escape((string)$value);
      $xml[] = '<c r="'.$cellRef.'" t="inlineStr"><is><t>'.$escaped.'</t></is></c>';
      $colIndex++;
    }
    $xml[] = '</row>';
    $rowIndex++;
  }
  $xml[] = '</sheetData>';
  $xml[] = '</worksheet>';
  return implode('', $xml);
}

function xlsx_generate(array $rows): string {
  if (!class_exists('ZipArchive')) {
    throw new RuntimeException('ZipArchive no disponible');
  }

  $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx_');
  if ($tmpFile === false) {
    throw new RuntimeException('No se pudo crear archivo temporal');
  }

  $zip = new ZipArchive();
  if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('No se pudo crear XLSX');
  }

  $sheetXml = xlsx_build_sheet_xml($rows);

  $zip->addFromString('[Content_Types].xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
      '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
      '<Default Extension="xml" ContentType="application/xml"/>'.
      '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.
      '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
      '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.
    '</Types>'
  );

  $zip->addFromString('_rels/.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'.
    '</Relationships>'
  );

  $zip->addFromString('xl/workbook.xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
    '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '.
      'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
      '<sheets>'.
        '<sheet name="Listado" sheetId="1" r:id="rId1"/>'.
      '</sheets>'.
    '</workbook>'
  );

  $zip->addFromString('xl/_rels/workbook.xml.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'.
      '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'.
    '</Relationships>'
  );

  $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

  $zip->addFromString('xl/styles.xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
    '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
      '<fonts count="1"><font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/><scheme val="minor"/></font></fonts>'.
      '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'.
      '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'.
      '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'.
      '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'.
      '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'.
    '</styleSheet>'
  );

  $zip->close();

  $contents = file_get_contents($tmpFile);
  unlink($tmpFile);
  if ($contents === false) {
    throw new RuntimeException('No se pudo leer XLSX');
  }
  return $contents;
}
