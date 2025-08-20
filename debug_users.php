<?php

require_once 'vendor/autoload.php';
use Dompdf\Dompdf;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

// Test PDF
if (isset($_GET['type']) && $_GET['type'] === 'pdf') {
    $dompdf = new Dompdf();
    $html = '<h1>Test PDF</h1><p>This is a test PDF.</p>';
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="test.pdf"');
    $dompdf->stream('test.pdf', ['Attachment' => true]);
    exit();
}

// Test Word
if (isset($_GET['type']) && $_GET['type'] === 'word') {
    $phpWord = new PhpWord();
    $section = $phpWord->addSection();
    $section->addText('Test Word Document', ['bold' => true, 'size' => 16]);
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="test.docx"');
    $writer = IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save('php://output');
    exit();
}
?>
<a href="test_export.php?type=pdf">Test PDF</a><br>
<a href="test_export.php?type=word">Test Word</a>