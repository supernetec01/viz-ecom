<?php

namespace App\Services;

use App\Contracts\PreviewConverter;
use App\Contracts\VirusScanner;
use App\Exceptions\FileProcessingFailed;
use App\Exceptions\PdfInspectionFailed;
use Illuminate\Support\Str;
use Throwable;

class DocumentSandbox extends PdfSandbox implements PreviewConverter, VirusScanner
{
    public function scan(string $path): void
    {
        $this->runIsolated($path, true);
    }

    public function convert(string $path): string
    {
        return $this->runIsolated($path, false);
    }

    private function runIsolated(string $input, bool $scan): string
    {
        $deadline = microtime(true) + (float) config('file-processing.timeout');
        $uuid = (string) Str::uuid();
        $scratch = config('file-processing.staging_root').'/'.$uuid;
        $name = 'viz-file-'.$uuid;
        $lease = null;
        $created = false;
        $source = $scratch.'/input'.($scan ? '.bin' : '.docx');
        try {
            $size = is_file($input) ? filesize($input) : false;
            if ($size === false || $size < 1 || $size > InspectCv::MAX_BYTES) {
                throw new FileProcessingFailed('invalid_file');
            }
            $lease = CandidateFileLease::acquire('processor-staging:'.$uuid);
            if (! mkdir($scratch, 0700, true) || ! copy($input, $source)) {
                throw new FileProcessingFailed('staging_unavailable', true);
            }
            chmod($source, 0444);
            $info = json_decode($this->command(['info', '--format', '{"os":{{json .OSType}},"security":{{json .SecurityOptions}}}'], $deadline), true, 16, JSON_THROW_ON_ERROR);
            $image = (string) config('file-processing.image');
            if (($info['os'] ?? '') !== 'linux' || (app()->isProduction() && (! in_array('name=rootless', $info['security'] ?? [], true) || ! preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image)))) {
                throw new FileProcessingFailed('isolation_unavailable', true);
            }
            $image = trim($this->command(['image', 'inspect', '--format', '{{.Id}}', $image], $deadline));
            if (! preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image)) {
                throw new FileProcessingFailed('image_unavailable', true);
            }
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : 65534;
            $gid = function_exists('posix_getegid') ? posix_getegid() : 65534;
            $memory = (int) config('file-processing.memory_bytes');
            $tmp = (int) config('file-processing.tmp_bytes');
            $arguments = ['create', '--name', $name, '--label', 'io.viz-ecom.file-processing=true', '--network', 'none',
                '--memory', (string) $memory, '--memory-swap', (string) $memory, '--cpus', '1', '--pids-limit', '64',
                '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true', '--ulimit', 'core=0',
                '--user', ($uid ?: 65534).':'.($gid ?: 65534), '--log-driver', 'none',
                '--tmpfs', '/tmp:rw,noexec,nosuid,size='.$tmp.',mode=1777',
                '--mount', 'type=bind,source='.$source.',target='.($scan ? '/input.bin' : '/input.docx').',readonly',
                '--env', 'HOME=/tmp/home', '--env', 'SAL_USE_VCLPLUGIN=svp', '--workdir', '/'];
            if ($scan) {
                $definitions = $this->definitions();
                $arguments = [...$arguments, '--mount', 'type=bind,source='.$definitions.',target=/database,readonly'];
            }
            $created = true;
            $this->command([...$arguments, $image], $deadline);
            $this->command(['start', $name], $deadline);
            $settings = json_decode($this->command(['inspect', '--format', '{{json .HostConfig}}', $name], $deadline), true, 32, JSON_THROW_ON_ERROR);
            if (($settings['NetworkMode'] ?? '') !== 'none' || ($settings['Memory'] ?? 0) !== $memory || ($settings['MemorySwap'] ?? 0) !== $memory
                || ($settings['PidsLimit'] ?? 0) !== 64 || ($settings['NanoCpus'] ?? 0) !== 1000000000 || ! ($settings['ReadonlyRootfs'] ?? false)
                || ! in_array('ALL', $settings['CapDrop'] ?? [], true) || ! in_array('no-new-privileges:true', $settings['SecurityOpt'] ?? [], true)
                || ! str_contains($settings['Tmpfs']['/tmp'] ?? '', 'noexec')) {
                throw new FileProcessingFailed('isolation_unavailable', true);
            }
            if (trim($this->exec($name, ['/usr/local/bin/processor-probe', 'network'], $deadline)) !== 'network-unreachable'
                || trim($this->exec($name, ['/bin/ls', '/sys/class/net'], $deadline)) !== 'lo'
                || trim($this->exec($name, ['/bin/cat', '/sys/fs/cgroup/memory.max'], $deadline)) !== (string) $memory
                || trim($this->exec($name, ['/bin/cat', '/sys/fs/cgroup/pids.max'], $deadline)) !== '64') {
                throw new FileProcessingFailed('isolation_unavailable', true);
            }
            $filesystem = explode(':', trim($this->exec($name, ['/usr/bin/stat', '-f', '--format=%S:%b', '/tmp'], $deadline)));
            if (count($filesystem) !== 2 || ! ctype_digit($filesystem[0]) || ! ctype_digit($filesystem[1]) || (int) $filesystem[0] * (int) $filesystem[1] > $tmp) {
                throw new FileProcessingFailed('output_limit', true);
            }
            if ($scan) {
                $code = 0;
                $this->exec($name, ['/usr/bin/clamscan', '--database=/database', '--no-summary', '--stdout', '--max-filesize=10485760',
                    '--max-scansize=52428800', '--max-files=1024', '--max-recursion=16', '--alert-exceeds-max=yes', '--max-scantime=25000', '--tempdir=/tmp', '/input.bin'], $deadline, [0, 1, 2], $code);
                if ($code !== 0) {
                    throw new FileProcessingFailed($code === 1 ? 'infected' : 'scanner_unavailable', $code !== 1);
                }

                return '';
            }
            $this->exec($name, ['/bin/mkdir', '-p', '/tmp/out', '/tmp/home'], $deadline);
            $options = json_encode($this->exportOptions(), JSON_THROW_ON_ERROR);
            $this->exec($name, ['/usr/bin/libreoffice', '--headless', '--nologo', '--nodefault', '--nolockcheck', '--nofirststartwizard',
                '-env:UserInstallation=file:///tmp/profile-'.$uuid, '--convert-to', 'pdf:writer_pdf_Export:'.$options, '--outdir', '/tmp/out', '/input.docx'], $deadline);
            if (trim($this->exec($name, ['/bin/ls', '-1', '/tmp/out'], $deadline)) !== 'input.pdf') {
                throw new FileProcessingFailed('conversion_failed', true);
            }
            $outputSize = trim($this->exec($name, ['/usr/bin/stat', '--format=%s', '/tmp/out/input.pdf'], $deadline));
            if (! ctype_digit($outputSize) || (int) $outputSize < 1 || (int) $outputSize > InspectCv::MAX_BYTES) {
                throw new FileProcessingFailed('preview_output_limit');
            }

            return $this->exec($name, ['/bin/cat', '/tmp/out/input.pdf'], $deadline);
        } catch (FileProcessingFailed $exception) {
            throw $exception;
        } catch (PdfInspectionFailed $exception) {
            throw new FileProcessingFailed($exception->reason === 'timeout' ? 'processor_timeout' : 'processor_unavailable', true);
        } catch (Throwable) {
            throw new FileProcessingFailed('processor_unavailable', true);
        } finally {
            $failed = false;
            if ($created) {
                try {
                    $this->command(['rm', '--force', $name], microtime(true) + 3);
                } catch (Throwable) {
                    $failed = true;
                }
            }
            try {
                if (is_file($source)) {
                    chmod($source, 0600);
                    unlink($source);
                }
                if (is_dir($scratch)) {
                    rmdir($scratch);
                }
            } catch (Throwable) {
                $failed = true;
            }
            $lease?->release();
            if ($failed) {
                throw new FileProcessingFailed('processor_cleanup_failed', true);
            }
        }
    }

    private function definitions(): string
    {
        $root = config('file-processing.definitions_root');
        if (! is_dir($root) || is_link($root)) {
            throw new FileProcessingFailed('scanner_unavailable', true);
        }
        foreach (['main', 'daily'] as $name) {
            $paths = array_filter([$root.'/'.$name.'.cvd', $root.'/'.$name.'.cld'], fn ($path) => is_file($path) && ! is_link($path));
            if ($paths === [] || ($name === 'daily' && max(array_map('filemtime', $paths)) < now()->subHours((int) config('file-processing.definitions_max_age_hours'))->timestamp)) {
                throw new FileProcessingFailed('scanner_unavailable', true);
            }
        }

        return realpath($root);
    }

    protected function exportOptions(): array
    {
        // InitialPage=0 omits LibreOffice's passive catalog OpenAction. Do not rewrite uploads.
        return ['InitialPage' => ['type' => 'long', 'value' => '0'], 'ExportFormFields' => ['type' => 'boolean', 'value' => 'false'],
            'ExportBookmarks' => ['type' => 'boolean', 'value' => 'false'], 'ExportNotes' => ['type' => 'boolean', 'value' => 'false'],
            'PDFViewSelection' => ['type' => 'long', 'value' => '3']];
    }
}
