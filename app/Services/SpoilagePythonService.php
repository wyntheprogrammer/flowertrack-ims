<?php

namespace App\Services;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class SpoilagePythonService
{
    public const ARIMA = 'Flower ARIMA';

    public const FALLBACK = 'Flower historical fallback';

    /**
     * Check Python and installed libraries.
     */
    public function health(): array
    {
        $code = <<<'PYTHON'
import json
import sys
import numpy
import pandas
import statsmodels
import asyncio
import socket
from statsmodels.tsa.arima.model import ARIMA

with socket.socket():
    pass

print(json.dumps({
    "status": "ok",
    "python": sys.version.split()[0],
    "numpy": numpy.__version__,
    "pandas": pandas.__version__,
    "statsmodels": statsmodels.__version__
}))
PYTHON;

        return $this->execute(['-c', $code]);
    }

    public function train(array $history): array
    {
        $result = $this->execute(
            [base_path('scripts/spoilage/predict.py'), '--train'],
            json_encode(['history' => $history], JSON_THROW_ON_ERROR),
            (float) config('spoilage.training_timeout_seconds', 600)
        );
        if (($result['status'] ?? null) === 'InsufficientData' && is_string($result['message'] ?? null)) {
            return $result;
        }

        $order = $result['order'] ?? null;
        if (($result['status'] ?? null) !== 'Trained' || ! is_array($order) || array_keys($order) !== [0, 1, 2]) {
            throw new RuntimeException('Python returned an invalid training response.');
        }
        [$p, $d, $q] = $order;
        if (! is_int($p) || ! is_int($d) || ! is_int($q)
            || $p < 0 || $p > 4 || $d < 0 || $d > 1 || $q < 0 || $q > 4 || $p + $q === 0) {
            throw new RuntimeException('Python returned an invalid ARIMA order.');
        }

        $days = count($history);
        $train = intdiv($days * 70, 100);
        $validationEnd = intdiv($days * 80, 100);
        if ($train < 30 || ($result['split_days'] ?? null) !== [
            'train' => $train, 'validation' => $validationEnd - $train, 'test' => $days - $validationEnd,
        ]) {
            throw new RuntimeException('Python returned an invalid historical split.');
        }

        foreach ([
            $result['adf_p_value'] ?? null,
            $result['validation']['mae'] ?? null,
            $result['validation']['mse'] ?? null,
            $result['validation']['rmse'] ?? null,
            $result['test']['MAE_rate'] ?? null,
            $result['test']['MSE_rate'] ?? null,
            $result['test']['RMSE_rate'] ?? null,
        ] as $value) {
            if (! $this->isFiniteNumber($value) || $value < 0 || $value > 1) {
                throw new RuntimeException('Python returned an invalid training metric.');
            }
        }
        foreach (['aic', 'bic'] as $metric) {
            if (! $this->isFiniteNumber($result['validation'][$metric] ?? null)) {
                throw new RuntimeException('Python returned an incomplete validation report.');
            }
        }

        return $result;
    }

    public function predictMany(array $items): array
    {
        $response = $this->execute(
            [base_path('scripts/spoilage/predict_many.py')],
            json_encode(['items' => $items], JSON_THROW_ON_ERROR)
        );
        $results = $response['results'] ?? null;
        if (($response['status'] ?? null) !== 'ok' || ! is_array($results) || count($results) !== count($items)) {
            throw new RuntimeException('Python returned an invalid flower forecast response.');
        }

        foreach ($items as $item) {
            $result = $results[$item['key']] ?? null;
            $status = $result['status'] ?? null;
            if (! in_array($status, ['Predicted', 'InsufficientData', 'Error'], true)
                || (isset($result['message']) && ! is_string($result['message']))) {
                throw new RuntimeException('Python returned an invalid flower result.');
            }
            $fraction = $result['predicted_fraction'] ?? null;
            $method = $result['method'] ?? null;
            if ($status === 'Predicted') {
                if (! $this->isFiniteNumber($fraction) || $fraction < 0 || $fraction > 1
                    || ! in_array($method, [self::ARIMA, self::FALLBACK], true)
                    || ($method === self::ARIMA && $item['payload']['order'] === null)
                    || $item['payload']['history'] === []) {
                    throw new RuntimeException('The flower forecast does not match its inputs.');
                }
            } elseif ($fraction !== null || $method !== null) {
                throw new RuntimeException('Unavailable forecasts must not contain invented results.');
            }
        }

        return $results;
    }

    private function isFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    // Preserve the Windows fix verified in the browser.
    private function processEnvironment(): array
    {
        $environment = ['PYTHONUTF8' => '1', 'PYTHONIOENCODING' => 'utf-8'];
        if (PHP_OS_FAMILY !== 'Windows') {
            return $environment;
        }
        foreach ([getenv('SystemRoot'), getenv('SYSTEMROOT'),
            $_SERVER['SystemRoot'] ?? null, $_SERVER['SYSTEMROOT'] ?? null,
            getenv('WINDIR'), $_SERVER['WINDIR'] ?? null, 'C:\\Windows'] as $directory) {
            if (is_string($directory) && $directory !== ''
                && is_dir($directory.DIRECTORY_SEPARATOR.'System32')) {
                return [...$environment, 'SystemRoot' => $directory, 'WINDIR' => $directory];
            }
        }
        throw new RuntimeException('The Windows directory could not be located for Python.');
    }

    /**
     * Run Python directly and read its JSON response.
     */
    private function execute(array $arguments, ?string $input = null, ?float $timeout = null): array
    {
        $python = config('spoilage.python_binary');

        if (! is_string($python) || ! is_file($python)) {
            throw new RuntimeException(
                'The configured Python executable was not found.'
            );
        }

        $process = new Process(
            [$python, ...$arguments],
            base_path(),
            $this->processEnvironment()
        );

        $process->setTimeout(
            $timeout ?? (float) config('spoilage.process_timeout_seconds', 120)
        );

        if ($input !== null) {
            $process->setInput($input);
        }

        $process->run();

        try {
            $result = json_decode(
                trim($process->getOutput()),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            throw new RuntimeException(
                'Python returned invalid JSON.',
                previous: $exception
            );
        }

        if (! is_array($result)) {
            throw new RuntimeException(
                'Python must return a JSON object.'
            );
        }

        if (! $process->isSuccessful()) {
            $message = $result['message'] ?? null;
            throw new RuntimeException(is_string($message) ? $message : 'The Python forecasting process failed.');
        }

        return $result;
    }
}
