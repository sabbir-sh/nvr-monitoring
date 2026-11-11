<?php

namespace App\Services;

class TcpPortProbe
{
    /**
     * @return array{online: bool, latency_ms: int|null, message: string|null}
     */
    public function check(string $ip, int $port, float $timeout = 1.5): array
    {
        $startedAt = microtime(true);
        $address = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? "tcp://[{$ip}]:{$port}"
            : "tcp://{$ip}:{$port}";
        $socket = @stream_socket_client(
            $address,
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            return [
                'online' => false,
                'latency_ms' => null,
                'message' => trim($errorMessage) ?: "Connection failed ({$errorCode}).",
            ];
        }

        fclose($socket);

        return [
            'online' => true,
            'latency_ms' => max(1, (int) round((microtime(true) - $startedAt) * 1000)),
            'message' => null,
        ];
    }
}
