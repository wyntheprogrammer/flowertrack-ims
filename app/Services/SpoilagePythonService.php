<?php

namespace App\Services;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

class SpoilagePythonService
{
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

    /**
     * Send one batch's prepared data to the forecasting script.
     */
    public function predict(array $payload): array
    {
        $script = base_path('scripts/spoilage/predict.py');

        if (! is_file($script)) {
            throw new RuntimeException('The forecasting script was not found.');
        }

        // Settings come from Laravel, not browser input.
        $payload['settings'] = [
            'horizon_days' => config('spoilage.horizon_days'),
            'minimum_history_days' => config('spoilage.minimum_history_days'),
            'arima_order' => config('spoilage.arima_order'),
            'risk_thresholds' => config('spoilage.risk_thresholds'),
        ];

        $result = $this->execute(
            [$script],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        if (! in_array(
            $result['status'] ?? null,
            ['Predicted', 'InsufficientData'],
            true
        )) {
            throw new RuntimeException(
                'Python returned an unexpected prediction status.'
            );
        }

        if ($result['status'] === 'Predicted') {
            $this->validatePrediction($result, $payload);
        }

        return $result;
    }

    // One process loads Python libraries once for all prepared batches.
    public function predictMany(array $items): array
    {
        if ($items === []) {
            return [];
        }
        $script = base_path('scripts/spoilage/predict_many.py');
        if (! is_file($script)) {
            throw new RuntimeException('The batched forecasting script was not found.');
        }
        foreach ($items as &$item) {
            $item['payload']['settings'] = [
                'horizon_days' => config('spoilage.horizon_days'),
                'minimum_history_days' => config('spoilage.minimum_history_days'),
                'arima_order' => config('spoilage.arima_order'),
                'risk_thresholds' => config('spoilage.risk_thresholds'),
            ];
        }
        unset($item);
        $response = $this->execute([$script], json_encode(['items' => $items], JSON_THROW_ON_ERROR));
        $results = $response['results'] ?? null;
        if (($response['status'] ?? null) !== 'ok' || ! is_array($results)
            || count($results) !== count($items)) {
            throw new RuntimeException('Python returned an invalid batch response.');
        }
        foreach ($items as $item) {
            $key = (string) $item['key'];
            $result = $results[$key] ?? null;
            if (! is_array($result)
                || ! in_array($result['status'] ?? null, ['Predicted', 'InsufficientData', 'Error'], true)) {
                throw new RuntimeException('Python returned an invalid batch result.');
            }
            if ($result['status'] === 'Predicted') {
                $this->validatePrediction($result, $item['payload']);
                $end = \Carbon\CarbonImmutable::parse($item['payload']['as_of_date'])
                    ->addDays(config('spoilage.horizon_days') - 1)->toDateString();
                if (($result['as_of_date'] ?? null) !== $item['payload']['as_of_date']
                    || ($result['forecast_end_date'] ?? null) !== $end
                    || ($result['opening_quantity'] ?? null) !== $item['payload']['opening_quantity']
                    || ($result['history_days'] ?? null) !== count($item['payload']['history'])
                    || ! in_array($result['method'] ?? null, [
                        'ARIMA own-batch history', 'Flower/storage/age fallback', 'Global profile fallback',
                    ], true)) {
                    throw new RuntimeException('The batch forecast does not match its inputs.');
                }
            }
            if ($result['status'] === 'InsufficientData'
                && (($result['risk_level'] ?? null) !== null
                    || ($result['predicted_quantity'] ?? null) !== null
                    || ($result['predicted_fraction'] ?? null) !== null)) {
                throw new RuntimeException('Unavailable forecasts must not contain invented results.');
            }
        }
        return $results;
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
    private function execute(array $arguments, ?string $input = null): array
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
            (float) config('spoilage.process_timeout_seconds', 120)
        );

        if ($input !== null) {
            $process->setInput($input);
        }

        $process->mustRun();

        try {
            $result = json_decode(
                trim($process->getOutput()),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
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

        return $result;
    }

    /**
     * Reject invalid numerical results before any future database save.
     */
    private function validatePrediction(array $result, array $payload): void
    {
        foreach (['predicted_fraction', 'predicted_quantity'] as $field) {
            $value = $result[$field] ?? null;

            if (
                (! is_int($value) && ! is_float($value))
                || ! is_finite((float) $value)
            ) {
                throw new RuntimeException(
                    "Python returned an invalid {$field}."
                );
            }
        }

        $fraction = (float) $result['predicted_fraction'];
        $quantity = (float) $result['predicted_quantity'];
        $opening = $payload['opening_quantity'];

        if (
            $fraction < 0
            || $fraction > 1
            || $quantity < 0
            || $quantity > $opening
            || abs($quantity - ($opening * $fraction)) > 0.000001
        ) {
            throw new RuntimeException(
                'The prediction quantities do not reconcile.'
            );
        }

        $thresholds = config('spoilage.risk_thresholds');

        $expectedRisk = match (true) {
            $fraction < $thresholds['medium'] => 'Low',
            $fraction < $thresholds['high'] => 'Medium',
            $fraction < $thresholds['critical'] => 'High',
            default => 'Critical',
        };

        if (($result['risk_level'] ?? null) !== $expectedRisk) {
            throw new RuntimeException(
                'The predicted risk does not match its fraction.'
            );
        }
    }
}
