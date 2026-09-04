<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use Throwable;

class ProcessClassifierArchiveCommandRunner implements ClassifierArchiveCommandRunner
{
    /** @param list<string> $command */
    public function run(array $command, int $maxOutputBytes, int $timeoutSeconds): ClassifierArchiveCommandResult
    {
        if ($command === [] || $maxOutputBytes < 1 || $timeoutSeconds < 1) {
            throw ClassifierParserException::fatal(
                'invalid_rar_command_configuration',
                'The trusted RAR command configuration is invalid.',
            );
        }

        $pipes = [];

        try {
            $process = @proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                null,
                null,
                ['bypass_shell' => true],
            );
        } catch (Throwable $exception) {
            throw ClassifierParserException::fatal(
                'rar_decoder_unavailable',
                'The trusted RAR decoder could not be started.',
                previous: $exception,
            );
        }

        if (! is_resource($process)) {
            throw ClassifierParserException::fatal(
                'rar_decoder_unavailable',
                'The trusted RAR decoder could not be started.',
            );
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);

        try {
            while (true) {
                $read = [];

                foreach ([1, 2] as $pipeIndex) {
                    if (is_resource($pipes[$pipeIndex]) && ! feof($pipes[$pipeIndex])) {
                        $read[] = $pipes[$pipeIndex];
                    }
                }

                $status = proc_get_status($process);

                if ((microtime(true) - $startedAt) > $timeoutSeconds) {
                    proc_terminate($process);

                    throw ClassifierParserException::fatal(
                        'rar_command_timeout',
                        'The trusted RAR decoder exceeded its configured time limit.',
                    );
                }

                if ($read !== []) {
                    $write = null;
                    $except = null;
                    @stream_select($read, $write, $except, 0, 200_000);

                    foreach ($read as $stream) {
                        $chunk = fread($stream, 65_536);

                        if (! is_string($chunk) || $chunk === '') {
                            continue;
                        }

                        if ($stream === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }

                        if (strlen($stdout) + strlen($stderr) > $maxOutputBytes) {
                            proc_terminate($process);

                            throw ClassifierParserException::fatal(
                                'rar_command_output_limit',
                                'The trusted RAR decoder output exceeded its configured limit.',
                            );
                        }
                    }
                }

                if (! ($status['running'] ?? false) && $read === []) {
                    break;
                }
            }

            $exitCode = proc_close($process);

            return new ClassifierArchiveCommandResult($exitCode, $stdout, $stderr);
        } catch (ClassifierParserException $exception) {
            proc_close($process);

            throw $exception;
        } catch (Throwable $exception) {
            proc_terminate($process);
            proc_close($process);

            throw ClassifierParserException::fatal(
                'rar_decoder_failure',
                'The trusted RAR decoder failed while processing the archive.',
                previous: $exception,
            );
        } finally {
            foreach ([1, 2] as $pipeIndex) {
                if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex])) {
                    fclose($pipes[$pipeIndex]);
                }
            }
        }
    }
}
