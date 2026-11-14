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
            ['PYTHONUTF8' => '1']
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