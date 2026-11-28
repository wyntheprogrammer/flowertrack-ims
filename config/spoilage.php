<?php

return [
    // Python executable inside this project's virtual environment.
    'python_binary' => env(
        'SPOILAGE_PYTHON_BINARY',
        PHP_OS_FAMILY === 'Windows'
            ? base_path('.venv/Scripts/python.exe')
            : base_path('.venv/bin/python')
    ),

    // Existing batch advisories and risk thresholds retain a seven-day reference.
    'horizon_days' => 7,

    // One rolling-year daily forecast supports all four presentation periods.
    'forecast_format_version' => 'daily-periods-v1',

    // Boundaries expressed as fractions, not whole percentages.
    'risk_thresholds' => [
        'medium' => 0.10,
        'high' => 0.25,
        'critical' => 0.50,
    ],

    // Calendar timezone for daily inventory observations.
    'observation_timezone' => 'Asia/Manila',

    // Eligible login/logout activity verifies completed ledger days; no timer.
    'activity_collection' => env('SPOILAGE_ACTIVITY_COLLECTION', true),
    'closing_catchup_days' => 7,

    // Limit Python execution time per process.
    'process_timeout_seconds' => 120,
    'training_timeout_seconds' => 600,

    // Identify this forecasting implementation.
    'model_version' => 'arima-flower-v1',
];
