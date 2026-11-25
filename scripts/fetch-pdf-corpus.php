<?php

$root = dirname(__DIR__);
$destination = $root.'/.runtime/pdf-corpus';
if (! is_dir($destination)) {
    mkdir($destination, 0700, true);
}
$manifest = json_decode(file_get_contents($root.'/tests/Fixtures/pdf/corpus.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest as $file) {
    if (! preg_match('/\A[a-z0-9-]+\z/D', $file['name']) || (! str_starts_with($file['url'], 'https://raw.githubusercontent.com/smalot/pdfparser/') && ! str_starts_with($file['url'], 'https://github.com/smalot/pdfparser/files/'))) {
        throw new RuntimeException('Invalid corpus manifest.');
    }
    $data = '';
    $curl = curl_init($file['url']);
    curl_setopt_array($curl, [CURLOPT_TIMEOUT => 15, CURLOPT_FAILONERROR => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$data): int {
        if (strlen($data) + strlen($chunk) > 1048576) {
            return 0;
        }
        $data .= $chunk;

        return strlen($chunk);
    }]);
    if (! curl_exec($curl)) {
        throw new RuntimeException('Corpus download or integrity check failed.');
    }
    curl_close($curl);
    $valid = isset($file['upstream_blob']) ? hash('sha1', 'blob '.strlen($data)."\0".$data) === $file['upstream_blob'] : hash('sha256', $data) === $file['sha256'];
    if (! $valid) {
        throw new RuntimeException('Corpus integrity check failed.');
    }
    file_put_contents($destination.'/'.$file['name'].'.pdf', $data);
}
echo "Fetched verified upstream corpus; content stays outside Git.\n";
