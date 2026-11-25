<?php

use App\Exceptions\PdfInspectionFailed;
use App\Services\InspectPdf;

function safeQpdfJson(array $extra = []): string
{
    return json_encode(['encrypt' => ['encrypted' => false], 'qpdf' => [['jsonversion' => 2], array_replace([
        'obj:1 0 R' => ['value' => ['/Type' => '/Catalog', '/Pages' => '2 0 R']],
        'trailer' => ['value' => ['/Root' => '1 0 R']],
    ], $extra)]], JSON_THROW_ON_ERROR);
}

it('rejects every approved active content marker anywhere in expanded PDF objects', function (string $marker) {
    app(InspectPdf::class)->checkJson(safeQpdfJson(['obj:9 0 R' => ['value' => [$marker => '10 0 R']]]));
})->with(['/JS', '/JavaScript', '/OpenAction', '/AA', '/Launch', '/GoToR', '/GoToE', '/SubmitForm', '/ImportData', '/XFA', '/RichMedia', '/RichMediaSettings', '/3D', '/3DD', '/EmbeddedFile', '/EmbeddedFiles', '/Filespec', '/EF'])->throws(PdfInspectionFailed::class);

it('rejects an indirect action name object instead of checking only direct dictionary keys', function () {
    app(InspectPdf::class)->checkJson(safeQpdfJson(['obj:9 0 R' => ['value' => '/JavaScript']]));
})->throws(PdfInspectionFailed::class);

it('accepts passive modern PDF dictionaries after qpdf expansion', function () {
    app(InspectPdf::class)->checkJson(safeQpdfJson(['obj:9 0 R' => ['stream' => ['dict' => ['/Type' => '/ObjStm', '/Filter' => '/FlateDecode']]]]));
    expect(true)->toBeTrue();
});

it('rejects encryption malformed JSON excessive objects and excessive nesting', function (string $case) {
    $json = match ($case) {
        'encrypted' => str_replace('"encrypted":false', '"encrypted":true', safeQpdfJson()),
        'invalid' => '{not-json',
        'objects' => safeQpdfJson(array_fill_keys(array_map(fn ($n) => 'obj:'.$n.' 0 R', range(10, 10011)), ['value' => 1])),
        'depth' => safeQpdfJson(['obj:9 0 R' => ['value' => json_decode(str_repeat('[', 40).'0'.str_repeat(']', 40), true)]]),
    };
    app(InspectPdf::class)->checkJson($json);
})->with(['encrypted', 'invalid', 'objects', 'depth'])->throws(PdfInspectionFailed::class);

it('keeps production PDF reception disabled by default even if local inspection is enabled', function () {
    app()->instance('env', 'production');
    config(['pdf-inspection.enabled' => true, 'pdf-inspection.production_enabled' => false]);
    app(InspectPdf::class)->inspect('not-read.pdf');
})->throws(PdfInspectionFailed::class);

it('requires a pinned inspector image before production can enable PDFs', function () {
    app()->instance('env', 'production');
    config(['pdf-inspection.enabled' => true, 'pdf-inspection.production_enabled' => true, 'pdf-inspection.image' => 'an-unpinned-tag']);
    app(InspectPdf::class)->inspect('not-read.pdf');
})->throws(PdfInspectionFailed::class);
