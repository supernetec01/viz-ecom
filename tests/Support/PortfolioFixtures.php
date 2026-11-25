<?php

use Illuminate\Http\UploadedFile;

function portfolioPngBytes(): string
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.hash('crc32b', $type.$data, true);

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0))
        .$chunk('IDAT', gzcompress("\0\xff\0\0")).$chunk('IEND', '');
}

function portfolioJpegBytes(): string
{
    // A real 1x1 JPEG, no customer metadata.
    return base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAb/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAB//2Q==', true);
}

function portfolioImage(string $type = 'png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent('portfolio.'.$type, $type === 'png' ? portfolioPngBytes() : portfolioJpegBytes());
}
