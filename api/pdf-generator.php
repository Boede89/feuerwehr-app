<?php
// Einfache PDF-Generierung für PA-Träger-Liste
class SimplePDF {
    private $content = '';
    private $pageWidth = 210; // A4 width in mm
    private $pageHeight = 297; // A4 height in mm
    private $margin = 20;
    private $currentY = 0;
    private $lineHeight = 6;
    
    public function __construct() {
        $this->currentY = $this->pageHeight - $this->margin;
    }
    
    public function addHeader($title) {
        $this->content .= "BT\n";
        $this->content .= "/F1 16 Tf\n";
        $this->content .= ($this->margin) . " " . $this->currentY . " Td\n";
        $this->content .= "(" . $this->escape($title) . ") Tj\n";
        $this->currentY -= 20;
        $this->content .= "ET\n";
    }
    
    public function addText($text, $fontSize = 10) {
        $this->content .= "BT\n";
        $this->content .= "/F1 " . $fontSize . " Tf\n";
        $this->content .= ($this->margin) . " " . $this->currentY . " Td\n";
        $this->content .= "(" . $this->escape($text) . ") Tj\n";
        $this->currentY -= $this->lineHeight;
        $this->content .= "ET\n";
    }
    
    public function addTable($headers, $rows) {
        $colWidths = [20, 60, 30, 30, 30, 30]; // Column widths
        $startX = $this->margin;
        
        // Header
        $this->content .= "BT\n";
        $this->content .= "/F1 10 Tf\n";
        $x = $startX;
        foreach ($headers as $i => $header) {
            $this->content .= $x . " " . $this->currentY . " Td\n";
            $this->content .= "(" . $this->escape($header) . ") Tj\n";
            $x += $colWidths[$i];
        }
        $this->currentY -= 15;
        $this->content .= "ET\n";
        
        // Rows
        foreach ($rows as $row) {
            $x = $startX;
            foreach ($row as $i => $cell) {
                $this->content .= "BT\n";
                $this->content .= "/F1 9 Tf\n";
                $this->content .= $x . " " . $this->currentY . " Td\n";
                $this->content .= "(" . $this->escape($cell) . ") Tj\n";
                $this->content .= "ET\n";
                $x += $colWidths[$i];
            }
            $this->currentY -= 12;
        }
    }
    
    private function escape($text) {
        return str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $text);
    }
    
    public function generate() {
        $pdf = "%PDF-1.4\n";
        
        // Catalog
        $pdf .= "1 0 obj\n";
        $pdf .= "<<\n";
        $pdf .= "/Type /Catalog\n";
        $pdf .= "/Pages 2 0 R\n";
        $pdf .= ">>\n";
        $pdf .= "endobj\n";
        
        // Pages
        $pdf .= "2 0 obj\n";
        $pdf .= "<<\n";
        $pdf .= "/Type /Pages\n";
        $pdf .= "/Kids [3 0 R]\n";
        $pdf .= "/Count 1\n";
        $pdf .= ">>\n";
        $pdf .= "endobj\n";
        
        // Page
        $pdf .= "3 0 obj\n";
        $pdf .= "<<\n";
        $pdf .= "/Type /Page\n";
        $pdf .= "/Parent 2 0 R\n";
        $pdf .= "/MediaBox [0 0 " . ($this->pageWidth * 2.834) . " " . ($this->pageHeight * 2.834) . "]\n";
        $pdf .= "/Contents 4 0 R\n";
        $pdf .= "/Resources 5 0 R\n";
        $pdf .= ">>\n";
        $pdf .= "endobj\n";
        
        // Content
        $pdf .= "4 0 obj\n";
        $pdf .= "<<\n";
        $pdf .= "/Length " . strlen($this->content) . "\n";
        $pdf .= ">>\n";
        $pdf .= "stream\n";
        $pdf .= $this->content;
        $pdf .= "endstream\n";
        $pdf .= "endobj\n";
        
        // Resources
        $pdf .= "5 0 obj\n";
        $pdf .= "<<\n";
        $pdf .= "/Font <<\n";
        $pdf .= "/F1 6 0 R\n";
        $pdf .= ">>\n";
        $pdf .= ">>\n";
        $pdf .= "endobj\n";
        
        // Font
        $pdf .= "6 0 obj\n";
        $pdf .= "<<\n";
        $pdf .= "/Type /Font\n";
        $pdf .= "/Subtype /Type1\n";
        $pdf .= "/BaseFont /Helvetica\n";
        $pdf .= ">>\n";
        $pdf .= "endobj\n";
        
        // XRef
        $xref = "xref\n";
        $xref .= "0 7\n";
        $xref .= "0000000000 65535 f \n";
        $xref .= "0000000009 00000 n \n";
        $xref .= "0000000058 00000 n \n";
        $xref .= "0000000115 00000 n \n";
        $xref .= "0000000204 00000 n \n";
        $xref .= "0000000271 00000 n \n";
        $xref .= "0000000368 00000 n \n";
        
        // Trailer
        $trailer = "trailer\n";
        $trailer .= "<<\n";
        $trailer .= "/Size 7\n";
        $trailer .= "/Root 1 0 R\n";
        $trailer .= ">>\n";
        $trailer .= "startxref\n";
        $trailer .= "0000000000\n";
        $trailer .= "%%EOF\n";
        
        return $pdf . $xref . $trailer;
    }
}

function generatePDFForDownload($results, $params) {
    $pdf = new SimplePDF();
    
    // Header
    $pdf->addHeader("Feuerwehr App - PA-Träger Liste");
    $pdf->addText("Übungsdatum: " . date('d.m.Y', strtotime($params['uebungsDatum'] ?? '')));
    $pdf->addText("Anzahl: " . ($params['anzahlPaTraeger'] === 'alle' ? 'Alle verfügbaren' : $params['anzahlPaTraeger'] . ' PA-Träger'));
    $pdf->addText("Status-Filter: " . implode(', ', $params['statusFilter'] ?? []));
    $pdf->addText("Gefunden: " . count($results) . " PA-Träger");
    $pdf->addText(""); // Leerzeile
    
    // Tabelle
    $headers = ['Nr.', 'Name', 'Status', 'Strecke', 'G26.3', 'Übung/Einsatz'];
    $rows = [];
    
    foreach ($results as $index => $traeger) {
        $name = ($traeger['first_name'] ?? '') . ' ' . ($traeger['last_name'] ?? '');
        $rows[] = [
            $index + 1,
            substr($name, 0, 20),
            substr($traeger['status'], 0, 15),
            date('d.m.Y', strtotime($traeger['strecke_am'])),
            date('d.m.Y', strtotime($traeger['g263_am'])),
            date('d.m.Y', strtotime($traeger['uebung_am']))
        ];
    }
    
    $pdf->addTable($headers, $rows);
    
    return $pdf->generate();
}
?>
