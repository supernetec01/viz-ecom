<?php

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../tests/Support/PdfFixtures.php';

use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$directory = $root.'/tests/Fixtures/pdf/generated';
if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}
file_put_contents($directory.'/clean.pdf', pdfFixtureBytes());
file_put_contents($directory.'/javascript.pdf', pdfFixtureBytes(javascript: true));
file_put_contents($directory.'/many-objects.pdf', pdfFixtureBytes(extraObjects: 10010));
$uid = function_exists('posix_geteuid') ? posix_geteuid() : 65534;
$gid = function_exists('posix_getegid') ? posix_getegid() : 65534;
foreach ([
    ['--object-streams=generate', '--stream-data=compress', '/fixtures/clean.pdf', '/fixtures/clean-object-stream.pdf'],
    ['--object-streams=generate', '--stream-data=compress', '/fixtures/javascript.pdf', '/fixtures/javascript-object-stream.pdf'],
    ['--encrypt', 'demo-user-password', 'demo-owner-password', '256', '--', '/fixtures/clean.pdf', '/fixtures/encrypted.pdf'],
    ['--encrypt', '', 'demo-owner-password', '256', '--', '/fixtures/clean.pdf', '/fixtures/encrypted-empty-password.pdf'],
] as $arguments) {
    (new Process([
        'docker', 'run', '--rm', '--network', 'none', '--memory', '256m', '--memory-swap', '256m', '--pids-limit', '32', '--read-only',
        '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true', '--user', $uid.':'.$gid,
        '--mount', 'type=bind,source='.$directory.',target=/fixtures', 'viz-ecom-qpdf:local', ...$arguments,
    ], timeout: 15))->mustRun();
}
echo "Generated synthetic PDFs with real qpdf, including compressed JavaScript and encrypted variants.\n";
