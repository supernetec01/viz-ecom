<?php

use Illuminate\Http\UploadedFile;

function docxUpload(array $extra = [], ?string $relationships = null, string $name = 'cv.docx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'viz-cv-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $entries = [
        '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>CV thử nghiệm, không có dữ liệu cá nhân thật.</w:t></w:r></w:p></w:body></w:document>',
    ];
    if ($relationships !== null) {
        $entries['word/_rels/document.xml.rels'] = $relationships;
    }
    foreach (array_replace($entries, $extra) as $entry => $content) {
        $zip->addFromString($entry, $content);
    }
    $zip->close();
    $contents = file_get_contents($path);
    unlink($path);

    return UploadedFile::fake()->createWithContent($name, $contents);
}

function cleanPdfBytes(): string
{
    $pdf = "%PDF-1.4\n";
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 300] /Contents 4 0 R >>', "<< /Length 0 >>\nstream\n\nendstream"];
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 5\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf."trailer\n<< /Root 1 0 R /Size 5 >>\nstartxref\n".$xref."\n%%EOF\n";
}
