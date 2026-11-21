<?php

function pdfFixtureBytes(bool $javascript = false, int $extraObjects = 0, bool $image = false): string
{
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R'.($javascript ? ' /OpenAction 5 0 R' : '').' >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Contents 4 0 R'.($image ? ' /Resources << /XObject << /Im1 5 0 R >> >>' : '').' >>',
    ];
    $content = $image ? 'q 600 0 0 800 0 0 cm /Im1 Do Q' : '';
    $objects[] = '<< /Length '.strlen($content).' >>'."\nstream\n".$content."\nendstream";
    if ($javascript) {
        // Only a harmless dialog; never open this fixture in a JS-capable viewer.
        $objects[] = '<< /S /JavaScript /JS (app.alert\("T23 harmless test"\);) >>';
    }
    if ($image) {
        $pixels = str_repeat("\x40\x80\xC0", 2048 * 1706);
        $objects[] = '<< /Type /XObject /Subtype /Image /Width 2048 /Height 1706 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Length '.strlen($pixels)." >>\nstream\n".$pixels."\nendstream";
    }
    for ($i = 0; $i < $extraObjects; $i++) {
        $objects[] = 'null';
    }
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
    }
    $tail = function (int $xref) use ($offsets): string {
        $count = count($offsets);
        $value = "xref\n0 ".$count."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $value .= sprintf("%010d 00000 n \n", $offset);
        }

        return $value."trailer\n<< /Root 1 0 R /Size ".$count." >>\nstartxref\n".$xref."\n%%EOF\n";
    };
    if ($image) {
        // A genuine uncompressed RGB page plus a short comment gives exactly 10 MiB.
        $padding = 10485760 - strlen($pdf) - strlen($tail(10485760));
        $pdf .= '%'.str_repeat('P', $padding - 2)."\n";
    }

    return $pdf.$tail(strlen($pdf));
}
