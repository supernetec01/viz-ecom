<?php

namespace App\Services;

use App\Exceptions\PdfInspectionFailed;

class InspectPdf
{
    private const BLOCKED = ['js', 'javascript', 'openaction', 'aa', 'launch', 'gotor', 'gotoe', 'submitform', 'importdata', 'xfa', 'richmedia', '3d', 'embeddedfile', 'embeddedfiles', 'filespec', 'ef'];

    public function __construct(private PdfSandbox $sandbox) {}

    public function inspect(string $path): array
    {
        if (! config('pdf-inspection.enabled') || (app()->isProduction() && (! config('pdf-inspection.production_enabled') || ! preg_match('/\Asha256:[a-f0-9]{64}\z/D', config('pdf-inspection.image'))))) {
            throw new PdfInspectionFailed;
        }
        $result = $this->sandbox->inspect($path);
        $this->checkJson($result['json']);
        unset($result['json']);

        return $result;
    }

    public function checkJson(string $json): void
    {
        if (strlen($json) > config('pdf-inspection.output_bytes')) {
            throw new PdfInspectionFailed('output_limit');
        }
        // Bound allocations before json_decode as well as the recursive walk.
        $tokens = substr_count($json, ',') + substr_count($json, ':') + substr_count($json, '[') + substr_count($json, '{');
        if ($tokens > 100000) {
            throw new PdfInspectionFailed('object_limit');
        }
        try {
            $data = json_decode($json, true, (int) config('pdf-inspection.max_depth') + 6, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PdfInspectionFailed('invalid');
        }
        if (($data['encrypt']['encrypted'] ?? null) !== false) {
            throw new PdfInspectionFailed('encrypted');
        }
        if (($data['qpdf'][0]['jsonversion'] ?? null) !== 2 || ! is_array($data['qpdf'][1] ?? null)) {
            throw new PdfInspectionFailed('invalid');
        }
        $objects = $data['qpdf'][1];
        if (count($objects) > config('pdf-inspection.max_objects')) {
            throw new PdfInspectionFailed('object_limit');
        }
        if (! isset($objects['trailer']['value']['/Root'])) {
            throw new PdfInspectionFailed('invalid');
        }
        $remaining = 100000;
        $this->walk($objects, 0, $remaining);
    }

    private function walk(mixed $value, int $depth, int &$remaining): void
    {
        if (--$remaining < 0) {
            throw new PdfInspectionFailed('object_limit');
        }
        if ($depth > config('pdf-inspection.max_depth')) {
            throw new PdfInspectionFailed('depth_limit');
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->name($key);
                $this->walk($child, $depth + 1, $remaining);
            }
        } elseif (is_string($value)) {
            $this->name($value);
        }
    }

    private function name(string|int $value): void
    {
        if (! is_string($value)) {
            return;
        }
        if (str_starts_with($value, 'n:')) {
            throw new PdfInspectionFailed('active_content');
        }
        if (str_starts_with($value, '/')) {
            $name = strtolower(substr($value, 1));
            if (in_array($name, self::BLOCKED, true) || str_starts_with($name, 'richmedia') || str_starts_with($name, '3d') || $name === 'encrypt') {
                throw new PdfInspectionFailed('active_content');
            }
        }
    }
}
