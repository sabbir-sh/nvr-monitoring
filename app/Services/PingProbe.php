<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class PingProbe
{
    /**
     * @return array{online: bool, latency_ms: int|null, message: string|null}
     */
    public function check(string $ip, float $timeout = 1.5): array
    {
        $timeoutMilliseconds = max(1, (int) round($timeout * 1000));
        $command = PHP_OS_FAMILY === 'Windows'
            ? ['ping', '-n', '3', '-w', (string) $timeoutMilliseconds, $ip]
            : ['ping', '-c', '3', '-W', (string) max(1, (int) ceil($timeout)), $ip];
        $process = new Process($command);
        $process->setTimeout(($timeout * 3) + 1);
        $process->run();
        return $this->parseOutput($process->getOutput(), $process->isSuccessful(), $ip);
    }

    /**
     * @return array{online: bool, latency_ms: int|null, message: string|null}
     */
    public function parseOutput(string $output, bool $commandSucceeded, string $targetIp): array
    {
        $sent = 0;
        $targetReplies = 0;

        if (preg_match('/Sent\s*=\s*(\d+)\s*,\s*Received\s*=\s*(\d+)/i', $output, $matches)) {
            $sent = (int) $matches[1];
        } elseif (preg_match('/(\d+)\s+packets transmitted,\s*(\d+)\s+(?:packets )?received/i', $output, $matches)) {
            $sent = (int) $matches[1];
        } else {
            $sent = 3;
        }

        preg_match_all('/^Reply from (.+?):\s*(.+)$/im', $output, $windowsReplies, PREG_SET_ORDER);

        foreach ($windowsReplies as $reply) {
            if (! preg_match('/Destination host unreachable/i', $reply[2]) && $this->sameIp($reply[1], $targetIp)) {
                $targetReplies++;
            }
        }

        if ($windowsReplies === []) {
            preg_match_all('/^\d+ bytes from (.+?)(?::\s*icmp_seq=|\s+icmp_seq=|$)/im', $output, $unixReplies);

            foreach ($unixReplies[1] as $replyIp) {
                if ($this->sameIp($replyIp, $targetIp)) {
                    $targetReplies++;
                }
            }
        }

        if (! $commandSucceeded || $sent !== 3 || $targetReplies < 2) {
            $message = $targetReplies > 0
                ? "Incomplete ping response ({$targetReplies} of {$sent} replies from target)."
                : "No ping response from {$targetIp}.";

            return ['online' => false, 'latency_ms' => null, 'message' => $message];
        }

        $latency = null;

        if (preg_match('/Average\s*=\s*([\d.,]+)\s*ms/i', $output, $matches)) {
            $latency = max(1, (int) round((float) str_replace(',', '.', $matches[1])));
        } elseif (preg_match('/rtt min\/avg\/max(?:\/mdev)? = [\d.]+\/([\d.]+) ms/i', $output, $matches)) {
            $latency = max(1, (int) round((float) $matches[1]));
        } elseif (preg_match('/\btime\s*[=<]\s*([\d.,]+)\s*ms/i', $output, $matches)) {
            $latency = max(1, (int) round((float) str_replace(',', '.', $matches[1])));
        }

        return [
            'online' => true,
            'latency_ms' => $latency,
            'message' => null,
        ];
    }

    private function sameIp(string $replyIp, string $targetIp): bool
    {
        $replyIp = trim(trim($replyIp), '[]');
        $targetIp = trim(trim($targetIp), '[]');
        $replyIp = preg_replace('/%[0-9A-Za-z_.-]+$/', '', $replyIp);
        $targetIp = preg_replace('/%[0-9A-Za-z_.-]+$/', '', $targetIp);
        $replyAddress = @inet_pton($replyIp);
        $targetAddress = @inet_pton($targetIp);

        return $replyAddress !== false && $targetAddress !== false
            ? hash_equals($targetAddress, $replyAddress)
            : strcasecmp($replyIp, $targetIp) === 0;
    }
}
