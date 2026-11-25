<?php

namespace App\Services;

use App\Exceptions\PdfInspectionFailed;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class InspectPortfolio
{
    public function __construct(private InspectPdf $pdf) {}

    /** @return array{extension: string, mime_type: string, size: int, sha256: string} */
    public function inspect(UploadedFile $upload): array
    {
        $size = is_file($upload->getPathname()) ? filesize($upload->getPathname()) : false;
        if ($size === false || $size < 1 || $size > InspectCv::MAX_BYTES) {
            $this->reject('portfolio_size');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getPathname());
        $extension = strtolower($upload->getClientOriginalExtension());
        if ($mime === 'application/pdf' && $extension === 'pdf') {
            try {
                $this->pdf->inspect($upload->getPathname());
            } catch (PdfInspectionFailed) {
                $this->reject('portfolio_pdf_rejected');
            }
        } elseif (($mime === 'image/png' && $extension === 'png') || ($mime === 'image/jpeg' && in_array($extension, ['jpg', 'jpeg'], true))) {
            $bytes = file_get_contents($upload->getPathname());
            $dimensions = $bytes === false ? false : @getimagesizefromstring($bytes);
            if (! $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 16384 || $dimensions[1] > 16384
                || $dimensions[0] * $dimensions[1] > 40000000) {
                $this->reject('portfolio_type');
            }
            $valid = $mime === 'image/png' ? $this->png($bytes) : $this->jpeg($bytes);
            if (! $valid) {
                $this->reject('portfolio_type');
            }
            $extension = $mime === 'image/png' ? 'png' : 'jpg';
        } else {
            $this->reject('portfolio_type');
        }
        $hash = hash_file('sha256', $upload->getPathname());
        if ($hash === false) {
            $this->reject('portfolio_type');
        }

        return ['extension' => $extension, 'mime_type' => $mime, 'size' => $size, 'sha256' => $hash];
    }

    private function png(string $bytes): bool
    {
        if (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return false;
        }
        $offset = 8;
        $data = false;
        for ($index = 0; $index < 2048 && $offset + 12 <= strlen($bytes); $index++) {
            $length = unpack('N', substr($bytes, $offset, 4))[1];
            if ($length > strlen($bytes) - $offset - 12) {
                return false;
            }
            $type = substr($bytes, $offset + 4, 4);
            if (! preg_match('/^[A-Za-z]{4}$/D', $type) || ($index === 0 && ($type !== 'IHDR' || $length !== 13))
                || ($index > 0 && $type === 'IHDR')) {
                return false;
            }
            if (hash('crc32b', substr($bytes, $offset + 4, $length + 4), true) !== substr($bytes, $offset + 8 + $length, 4)) {
                return false;
            }
            $offset += $length + 12;
            $data = $data || $type === 'IDAT';
            if ($type === 'IEND') {
                return $length === 0 && $data && $offset === strlen($bytes);
            }
        }

        return false;
    }

    private function jpeg(string $bytes): bool
    {
        if (! str_starts_with($bytes, "\xff\xd8")) {
            return false;
        }
        $offset = 2;
        $scan = false;
        for ($markers = 0; $markers < 100000 && $offset + 2 <= strlen($bytes); $markers++) {
            if ($bytes[$offset] !== "\xff") {
                return false;
            }
            while ($offset + 1 < strlen($bytes) && $bytes[$offset + 1] === "\xff") {
                $offset++;
            }
            if ($offset + 1 >= strlen($bytes)) {
                return false;
            }
            $marker = ord($bytes[$offset + 1]);
            $offset += 2;
            if ($marker === 0xD9) {
                return $scan && $offset === strlen($bytes);
            }
            if ($marker === 0xD8 || $marker === 0 || $offset + 2 > strlen($bytes)) {
                return false;
            }
            $length = unpack('n', substr($bytes, $offset, 2))[1];
            if ($length < 2 || $offset + $length > strlen($bytes)) {
                return false;
            }
            $offset += $length;
            if ($marker === 0xDA) {
                $scan = true;
                // Skip entropy bytes and stuffed/restart markers without decoding image pixels.
                while (($next = strpos($bytes, "\xff", $offset)) !== false) {
                    if ($next + 1 >= strlen($bytes)) {
                        return false;
                    }
                    $value = ord($bytes[$next + 1]);
                    if ($value !== 0 && ($value < 0xD0 || $value > 0xD7)) {
                        $offset = $next;
                        break;
                    }
                    $offset = $next + 2;
                }
                if ($next === false) {
                    return false;
                }
            }
        }

        return false;
    }

    private function reject(string $reason): never
    {
        throw ValidationException::withMessages(['portfolio_file' => __('applications.'.$reason)]);
    }
}
