<?php
if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

/**
 * Minimal Redis client (RESP2 over a TCP socket): enough for AUTH and XADD.
 * Pure PHP, so the plugin does not depend on the phpredis extension being installed in the CRM image.
 * Every error is thrown as RuntimeException; the caller decides how to handle it.
 */
final class WpsRedisClient
{
    /** @var resource */
    private $socket;

    public function __construct(string $host, int $port, string $password = '', float $timeout = 1.0)
    {
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new RuntimeException("Redis connect to {$host}:{$port} failed: {$errstr} ({$errno})");
        }
        $seconds = (int)floor($timeout);
        stream_set_timeout($socket, $seconds, (int)(($timeout - $seconds) * 1000000));
        $this->socket = $socket;
        if ($password !== '') {
            $this->command(['AUTH', $password]);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /**
     * @param array<int, string|int|float> $args
     * @return mixed
     */
    public function command(array $args)
    {
        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $arg = (string)$arg;
            $payload .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        $written = 0;
        $length = strlen($payload);
        while ($written < $length) {
            $n = @fwrite($this->socket, substr($payload, $written));
            if ($n === false || $n === 0) {
                $this->assertNotTimedOut();
                throw new RuntimeException('Redis write failed');
            }
            $written += $n;
        }
        return $this->readReply();
    }

    /**
     * @return mixed
     */
    private function readReply()
    {
        $line = fgets($this->socket);
        if ($line === false) {
            $this->assertNotTimedOut();
            throw new RuntimeException('Redis connection closed');
        }
        $type = $line[0];
        $data = substr($line, 1, -2);
        switch ($type) {
            case '+':
                return $data;
            case '-':
                throw new RuntimeException('Redis error: ' . $data);
            case ':':
                return (int)$data;
            case '$':
                $size = (int)$data;
                if ($size < 0) {
                    return null;
                }
                $buffer = '';
                while (strlen($buffer) < $size + 2) {
                    $chunk = fread($this->socket, $size + 2 - strlen($buffer));
                    if ($chunk === false || $chunk === '') {
                        $this->assertNotTimedOut();
                        throw new RuntimeException('Redis connection closed');
                    }
                    $buffer .= $chunk;
                }
                return substr($buffer, 0, $size);
            case '*':
                $count = (int)$data;
                if ($count < 0) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; $i++) {
                    $items[] = $this->readReply();
                }
                return $items;
            default:
                throw new RuntimeException('Unexpected Redis reply: ' . substr($line, 0, 100));
        }
    }

    private function assertNotTimedOut(): void
    {
        $meta = stream_get_meta_data($this->socket);
        if (!empty($meta['timed_out'])) {
            throw new RuntimeException('Redis read timeout');
        }
    }
}
