<?php

return [
    // Python executable inside this project's virtual environment.
    'python_binary' => env(
        'SPOILAGE_PYTHON_BINARY',
        PHP_OS_FAMILY === 'Windows'
            ? base_path('.venv/Scripts/python.exe')
            : base_path('.venv/bin/python')
    ),

    // Predict today through six days later.
    'horizon_days' => 7,

    // Completed daily records required before trying own-batch ARIMA.
    'minimum_history_days' => 6,

    // Settings selected during Colab validation.
    'arima_order' => [0, 1, 0],

    // Boundaries expressed as fractions, not whole percentages.
    'risk_thresholds' => [
        'medium' => 0.10,
        'high' => 0.25,
        'critical' => 0.50,
    ],

    // Calendar timezone for daily inventory observations.
    'observation_timezone' => 'Asia/Manila',

    // Limit Python execution time per process.
    'process_timeout_seconds' => 120,

    // Identify this forecasting implementation.
    'model_version' => 'arima-batch-v1',
];