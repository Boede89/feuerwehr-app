<?php
/**
 * Hängt gespeicherte Anhänge an ein Haupt-PDF an (ab nächster Seite: Bilder, danach importierte PDFs).
 * Benötigt auf dem Server: composer install inkl. setasign/fpdi-tcpdf (verknüpft FPDI mit TCPDF), setasign/fpdi, tecnickcom/tcpdf
 */

use setasign\Fpdi\PdfParser\StreamReader;

require_once __DIR__ . '/bericht-anhaenge-helper.php';

/**
 * @param array<int, array{filename_original?:string,storage_path:string,mime_type:string}> $rows
 * @param string $attachments_heading_plain Erste Bildseite: Überschrift (Zeilen mit \n), UTF-8
 */
function bericht_anhaenge_merge_with_rows(string $mainPdfBinary, array $rows, string $attachments_heading_plain = ''): string {
    if (strlen($mainPdfBinary) < 10 || substr($mainPdfBinary, 0, 5) !== '%PDF-' || empty($rows)) {
        return $mainPdfBinary;
    }
    $images = [];
    $pdfs = [];
    foreach ($rows as $r) {
        $mime = $r['mime_type'] ?? '';
        $abs = bericht_anhaenge_abs_path($r['storage_path']);
        if (!is_file($abs)) {
            continue;
        }
        if (strpos($mime, 'image/') === 0) {
            $images[] = $abs;
        } elseif ($mime === 'application/pdf') {
            $pdfs[] = $abs;
        }
    }
    if (empty($images) && empty($pdfs)) {
        return $mainPdfBinary;
    }
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('bericht_anhaenge_merge: vendor/autoload.php fehlt — bitte composer install (setasign/fpdi, tecnickcom/tcpdf)');
        return $mainPdfBinary;
    }
    require_once $autoload;
    if (!class_exists(\setasign\Fpdi\Tcpdf\Fpdi::class)) {
        error_log('bericht_anhaenge_merge: Klasse setasign\\Fpdi\\Tcpdf\\Fpdi nicht gefunden — bitte „composer require setasign/fpdi-tcpdf“ im Projekt ausführen (Docker: im Web-Container).');
        return $mainPdfBinary;
    }
    try {
        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Feuerwehr App');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pageCount = $pdf->setSourceFile(StreamReader::createByString($mainPdfBinary));
        for ($p = 1; $p <= $pageCount; $p++) {
            $tplId = $pdf->importPage($p);
            $size = $pdf->getTemplateSize($tplId);
            $orientation = (!empty($size['width']) && !empty($size['height']) && $size['width'] > $size['height']) ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height']);
        }
        $headingPending = trim($attachments_heading_plain) !== '';
        foreach ($images as $imgPath) {
            try {
                $pdf->AddPage('P', 'A4');
                $pdf->SetMargins(10, 10, 10);
                $pdf->SetAutoPageBreak(false);
                if ($headingPending) {
                    try {
                        $pdf->SetFont('dejavusans', 'B', 11);
                    } catch (Throwable $fontEx) {
                        $pdf->SetFont('helvetica', 'B', 11);
                    }
                    foreach (explode("\n", str_replace("\r\n", "\n", $attachments_heading_plain)) as $ln) {
                        if ($ln === '') {
                            continue;
                        }
                        $pdf->MultiCell(0, 6, $ln, 0, 'L', false, 1);
                    }
                    $pdf->Ln(2);
                    $headingPending = false;
                }
                $imgY = max(12.0, (float)$pdf->GetY() + 2);
                $pdf->Image($imgPath, 10, $imgY, 190, 0, '', '', '', false, 300, '', false, false, 0, false, false, true);
            } catch (Throwable $imgEx) {
                error_log('bericht_anhaenge_merge: Bild übersprungen ' . $imgPath . ' — ' . $imgEx->getMessage());
            }
        }
        foreach ($pdfs as $pdfPath) {
            $c = $pdf->setSourceFile($pdfPath);
            for ($i = 1; $i <= $c; $i++) {
                $tplId = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tplId);
                if ($headingPending) {
                    $pdf->AddPage('P', 'A4');
                    $pdf->SetMargins(10, 10, 10);
                    $pdf->SetAutoPageBreak(false);
                    try {
                        $pdf->SetFont('dejavusans', 'B', 11);
                    } catch (Throwable $fontEx) {
                        $pdf->SetFont('helvetica', 'B', 11);
                    }
                    foreach (explode("\n", str_replace("\r\n", "\n", $attachments_heading_plain)) as $ln) {
                        if ($ln === '') {
                            continue;
                        }
                        $pdf->MultiCell(0, 6, $ln, 0, 'L', false, 1);
                    }
                    $pdf->Ln(2);
                    $yTop = (float)$pdf->GetY() + 2;
                    $availH = 297 - $yTop - 10;
                    $availW = 190.0;
                    $tw = (float)($size['width'] ?? 0);
                    $th = (float)($size['height'] ?? 0);
                    if ($tw > 0 && $th > 0) {
                        $scale = min($availW / $tw, $availH / $th);
                        $wUse = $tw * $scale;
                        $hUse = $th * $scale;
                        $pdf->useTemplate($tplId, 10, $yTop, $wUse, $hUse);
                    }
                    $headingPending = false;
                    continue;
                }
                $orientation = (!empty($size['width']) && !empty($size['height']) && $size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height']);
            }
        }
        return $pdf->Output('', 'S');
    } catch (Throwable $e) {
        error_log('bericht_anhaenge_merge: ' . $e->getMessage());
        return $mainPdfBinary;
    }
}

function bericht_anhaenge_merge_attachments_into_pdf(string $mainPdfBinary, PDO $db, string $entity_type, int $entity_id, string $attachments_heading_plain = ''): string {
    if ($entity_id <= 0) {
        return $mainPdfBinary;
    }
    $rows = bericht_anhaenge_fetch_for_entity($db, $entity_type, $entity_id);
    return bericht_anhaenge_merge_with_rows($mainPdfBinary, $rows, $attachments_heading_plain);
}

/**
 * Sammelt alle Anhänge mehrerer Berichte (Reihenfolge: nach entity_id, dann sort_order).
 *
 * @param int[] $entity_ids
 */
function bericht_anhaenge_merge_attachments_multi_into_pdf(string $mainPdfBinary, PDO $db, string $entity_type, array $entity_ids): string {
    $entity_ids = array_values(array_unique(array_filter(array_map('intval', $entity_ids), function ($x) { return $x > 0; })));
    if (empty($entity_ids)) {
        return $mainPdfBinary;
    }
    $all = [];
    foreach ($entity_ids as $eid) {
        foreach (bericht_anhaenge_fetch_for_entity($db, $entity_type, $eid) as $r) {
            $all[] = $r;
        }
    }
    return bericht_anhaenge_merge_with_rows($mainPdfBinary, $all);
}
