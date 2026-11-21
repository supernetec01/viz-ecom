<?php

namespace App\Services;

use App\Exceptions\PdfInspectionFailed;
use DOMDocument;
use DOMXPath;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class InspectCv
{
    public const MAX_BYTES = 10485760;

    public function __construct(private InspectPdf $pdf) {}

    /** @return array{extension: string, mime_type: string, size: int, sha256: string} */
    public function inspect(UploadedFile $file): array
    {
        if (! $file->isValid() || ! is_file($file->getPathname())) {
            $this->reject('upload_failed');
        }
        $size = filesize($file->getPathname());
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            $this->reject('file_size');
        }
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        if ($extension === 'doc' || in_array($mime, ['application/msword', 'application/x-ole-storage', 'application/CDFV2'], true)) {
            $this->reject('legacy_doc');
        }
        if ($extension === 'docm') {
            $this->reject('unsafe_document');
        }
        if ($extension === 'pdf' && $mime === 'application/pdf') {
            try {
                $this->pdf->inspect($file->getPathname());
            } catch (PdfInspectionFailed $exception) {
                $this->reject($exception->reason === 'encrypted' ? 'pdf_encrypted' : 'pdf_rejected');
            }
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                $this->reject('upload_failed');
            }

            return ['extension' => 'pdf', 'mime_type' => 'application/pdf', 'size' => $size, 'sha256' => $hash];
        }
        if ($extension !== 'docx' || ! in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
            $this->reject('file_type');
        }
        $this->inspectDocx($file->getPathname());
        $hash = hash_file('sha256', $file->getPathname());
        if ($hash === false) {
            $this->reject('upload_failed');
        }

        return ['extension' => 'docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => $size, 'sha256' => $hash];
    }

    private function inspectDocx(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            $this->reject('unsafe_document');
        }
        try {
            if ($zip->numFiles < 3 || $zip->numFiles > 512) {
                $this->reject('unsafe_document');
            }
            $expanded = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! $stat) {
                    $this->reject('unsafe_document');
                }
                $name = strtolower(rawurldecode($stat['name']));
                if (isset($names[$name]) || str_contains($name, '\\') || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('~(^|/)\.\.?(/|$)|^[a-z]:~', $name)
                    || preg_match('~vba|macro|activex|(^|/)embeddings/|\.bin$~', $name)) {
                    $this->reject('unsafe_document');
                }
                $names[$name] = true;
                $expanded += $stat['size'];
                if ($expanded > 33554432 || $stat['size'] > 8388608 || ($stat['size'] > 65536 && $stat['size'] > max(1, $stat['comp_size']) * 100) || $stat['encryption_method'] !== 0) {
                    $this->reject('unsafe_document');
                }
                $stream = $zip->getStreamIndex($i);
                if ($stream === false) {
                    $this->reject('unsafe_document');
                }
                try {
                    $actual = 0;
                    while (! feof($stream)) {
                        $chunk = fread($stream, 8192);
                        if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                            $this->reject('unsafe_document');
                        }
                        $actual += strlen($chunk);
                        if ($actual > $stat['size']) {
                            $this->reject('unsafe_document');
                        }
                    }
                    if ($actual !== $stat['size']) {
                        $this->reject('unsafe_document');
                    }
                } finally {
                    fclose($stream);
                }
                if (str_ends_with($name, '.rels')) {
                    $xml = $this->xml($zip, $stat['name'], 262144);
                    $xpath = new DOMXPath($xml);
                    foreach ($xpath->query('//*[local-name()="Relationship"]') as $relation) {
                        if (! $relation instanceof \DOMElement) {
                            $this->reject('unsafe_document');
                        }
                        $target = rawurldecode($relation->getAttribute('Target'));
                        $type = strtolower($relation->getAttribute('Type'));
                        if (strtolower($relation->getAttribute('TargetMode')) === 'external' || str_starts_with($target, '//') || str_contains($target, '\\') || preg_match('/[\x00-\x20]|^[a-z][a-z0-9+.-]*:/i', $target) || preg_match('/oleobject|activex|attachedtemplate|vbaproject/', $type)) {
                            $this->reject('unsafe_document');
                        }
                    }
                }
            }
            $types = $this->xml($zip, '[Content_Types].xml', 262144);
            if ($types->documentElement?->localName !== 'Types' || $types->documentElement->namespaceURI !== 'http://schemas.openxmlformats.org/package/2006/content-types') {
                $this->reject('unsafe_document');
            }
            $validMainType = false;
            foreach ($types->documentElement->childNodes as $node) {
                if (! $node instanceof \DOMElement) {
                    continue;
                }
                $type = strtolower($node->getAttribute('ContentType'));
                if (preg_match('/macro|vba|oleobject|activex/', $type)) {
                    $this->reject('unsafe_document');
                }
                if ($node->getAttribute('PartName') === '/word/document.xml' && $type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml') {
                    $validMainType = true;
                }
            }
            $relationships = new DOMXPath($this->xml($zip, '_rels/.rels', 262144));
            $mainRelationship = false;
            foreach ($relationships->query('//*[local-name()="Relationship"]') as $relation) {
                if (! $relation instanceof \DOMElement) {
                    $this->reject('unsafe_document');
                }
                if (in_array($relation->getAttribute('Type'), ['http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', 'http://purl.oclc.org/ooxml/officeDocument/relationships/officeDocument'], true)
                    && ltrim($relation->getAttribute('Target'), '/') === 'word/document.xml') {
                    $mainRelationship = true;
                }
            }
            $document = $this->xml($zip, 'word/document.xml', 4194304);
            if (! $validMainType || ! $mainRelationship || $document->documentElement?->localName !== 'document'
                || ! in_array($document->documentElement->namespaceURI, ['http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'http://purl.oclc.org/ooxml/wordprocessingml/main'], true)) {
                $this->reject('unsafe_document');
            }
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $name, int $max): DOMDocument
    {
        $stat = $zip->statName($name);
        if (! $stat || $stat['size'] > $max) {
            $this->reject('unsafe_document');
        }
        $content = $zip->getFromName($name, $max + 1);
        if (! is_string($content) || $content === '' || strlen($content) > $max || stripos($content, '<!DOCTYPE') !== false) {
            $this->reject('unsafe_document');
        }
        $xml = new DOMDocument;
        $xml->resolveExternals = false;
        $xml->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $xml->loadXML($content, LIBXML_NONET) || $xml->doctype !== null) {
                $this->reject('unsafe_document');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml;
    }

    private function reject(string $key): never
    {
        throw ValidationException::withMessages(['cv' => __('applications.'.$key)]);
    }
}
