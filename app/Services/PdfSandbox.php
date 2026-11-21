<?php

namespace App\Services;

use App\Exceptions\PdfInspectionFailed;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class PdfSandbox
{
    /** @return array{json: string, elapsed_ms: float, peak_memory_bytes: int} */
    public function inspect(string $input): array
    {
        $start = microtime(true);
        $deadline = $start + (float) config('pdf-inspection.timeout');
        $scratch = storage_path('app/pdf-inspection/'.Str::uuid());
        $name = 'viz-pdf-'.Str::uuid();
        $created = false;
        try {
            if (! mkdir($scratch, 0700, true) || ! copy($input, $scratch.'/input.pdf')) {
                throw new PdfInspectionFailed;
            }
            // The directory remains 0700. A read-only single-file bind allows a non-root,
            // rootless-mapped container UID to read it without exposing its host parent.
            chmod($scratch.'/input.pdf', 0444);
            $info = json_decode($this->command(['info', '--format', '{"os":{{json .OSType}},"security":{{json .SecurityOptions}}}'], $deadline), true, 16, JSON_THROW_ON_ERROR);
            if (($info['os'] ?? '') !== 'linux' || (app()->isProduction() && ! in_array('name=rootless', $info['security'] ?? [], true))) {
                throw new PdfInspectionFailed('isolation');
            }
            $image = trim($this->command(['image', 'inspect', '--format', '{{.Id}}', config('pdf-inspection.image')], $deadline));
            if (! preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image)) {
                throw new PdfInspectionFailed;
            }
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : 65534;
            $gid = function_exists('posix_getegid') ? posix_getegid() : 65534;
            // Never run as container root, including on root-based CI hosts.
            $uid = $uid === 0 ? 65534 : $uid;
            $gid = $gid === 0 ? 65534 : $gid;
            $created = true;
            $this->command([
                'create', '--name', $name, '--label', 'io.viz-ecom.pdf-inspection=true',
                '--network', 'none', '--memory', (string) config('pdf-inspection.memory_bytes'), '--memory-swap', (string) config('pdf-inspection.memory_bytes'),
                '--cpus', '1', '--pids-limit', '32', '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
                '--ulimit', 'core=0', '--ulimit', 'nofile=64:64', '--user', $uid.':'.$gid, '--log-driver', 'none',
                '--mount', 'type=bind,source='.$scratch.'/input.pdf,target=/input.pdf,readonly', '--workdir', '/',
                '--entrypoint', '/usr/local/bin/sandbox-probe', $image, 'hold',
            ], $deadline);
            $this->command(['start', $name], $deadline);
            $settings = json_decode($this->command(['inspect', '--format', '{{json .HostConfig}}', $name], $deadline), true, 32, JSON_THROW_ON_ERROR);
            if (($settings['NetworkMode'] ?? '') !== 'none' || ($settings['Memory'] ?? 0) !== config('pdf-inspection.memory_bytes')
                || ($settings['MemorySwap'] ?? 0) !== config('pdf-inspection.memory_bytes') || ($settings['PidsLimit'] ?? 0) !== 32
                || ($settings['NanoCpus'] ?? 0) !== 1000000000 || ! in_array('ALL', $settings['CapDrop'] ?? [], true)
                || ! in_array('no-new-privileges:true', $settings['SecurityOpt'] ?? [], true) || ! ($settings['ReadonlyRootfs'] ?? false)) {
                throw new PdfInspectionFailed('isolation');
            }
            // Verify actual namespace and enforced cgroup memory, not just requested flags.
            $interfaces = trim($this->exec($name, ['/bin/ls', '/sys/class/net'], $deadline));
            $memory = trim($this->exec($name, ['/bin/cat', '/sys/fs/cgroup/memory.max'], $deadline));
            if ($interfaces !== 'lo' || $memory !== (string) config('pdf-inspection.memory_bytes')) {
                throw new PdfInspectionFailed('isolation');
            }
            if (trim($this->exec($name, ['/usr/local/bin/sandbox-probe', 'network'], $deadline)) !== 'network-unreachable') {
                throw new PdfInspectionFailed('isolation');
            }
            $code = 0;
            $this->exec($name, ['/usr/bin/qpdf', '--is-encrypted', '/input.pdf'], $deadline, [0, 2], $code);
            if ($code === 0) {
                throw new PdfInspectionFailed('encrypted');
            }
            $this->exec($name, ['/usr/bin/qpdf', '--check', '/input.pdf'], $deadline);
            $json = $this->exec($name, ['/usr/bin/qpdf', '--json=2', '--json-key=qpdf', '--json-key=encrypt', '--json-stream-data=none', '/input.pdf'], $deadline);
            $peak = trim($this->exec($name, ['/bin/cat', '/sys/fs/cgroup/memory.peak'], $deadline));

            if (! ctype_digit($peak) || (int) $peak < 1) {
                throw new PdfInspectionFailed('isolation');
            }
            $result = ['json' => $json, 'elapsed_ms' => 0.0, 'peak_memory_bytes' => (int) $peak];
        } catch (PdfInspectionFailed $exception) {
            throw $exception;
        } catch (Throwable) {
            // Do not include process output/previous exceptions: JSON may contain CV text.
            throw new PdfInspectionFailed;
        } finally {
            $cleanupFailed = false;
            if ($created) {
                try {
                    $this->command(['rm', '--force', $name], microtime(true) + 3);
                } catch (Throwable) {
                    $cleanupFailed = true;
                }
            }
            try {
                if (is_file($scratch.'/input.pdf')) {
                    // Windows maps chmod(0444) to its read-only attribute.
                    chmod($scratch.'/input.pdf', 0600);
                    unlink($scratch.'/input.pdf');
                }
                if (is_dir($scratch)) {
                    rmdir($scratch);
                }
            } catch (Throwable) {
                $cleanupFailed = true;
            }
            if ($cleanupFailed) {
                throw new PdfInspectionFailed('cleanup');
            }
        }
        $result['elapsed_ms'] = (microtime(true) - $start) * 1000;

        return $result;
    }

    protected function exec(string $container, array $arguments, float $deadline, array $allowed = [0], int &$exit = 0): string
    {
        return $this->command(['exec', $container, ...$arguments], $deadline, $allowed, $exit);
    }

    private function command(array $arguments, float $deadline, array $allowed = [0], int &$exit = 0): string
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new PdfInspectionFailed('timeout');
        }
        $environment = array_fill_keys(array_unique([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)]), false);
        foreach (['PATH', 'HOME', 'USERPROFILE', 'SystemRoot', 'TEMP', 'TMP', 'DOCKER_CONFIG', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH'] as $key) {
            $environment[$key] = getenv($key);
        }
        $process = new Process([config('pdf-inspection.docker'), ...$arguments], base_path(), $environment, timeout: $remaining);
        $output = '';
        $bytes = 0;
        try {
            $exit = $process->run(function (string $type, string $data) use (&$output, &$bytes): void {
                $bytes += strlen($data);
                if ($bytes > config('pdf-inspection.output_bytes')) {
                    throw new PdfInspectionFailed('output_limit');
                }
                if ($type === Process::OUT) {
                    $output .= $data;
                }
            });
        } catch (ProcessTimedOutException) {
            throw new PdfInspectionFailed('timeout');
        }
        if (! in_array($exit, $allowed, true)) {
            throw new PdfInspectionFailed('invalid');
        }

        return $output;
    }
}
