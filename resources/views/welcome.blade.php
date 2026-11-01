
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>FlowerTrack IMS</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>

    <div class="container py-5">
        <div class="text-center">
            <h1 class="display-4 fw-bold">FlowerTrack IMS</h1>

            <p class="lead text-muted">
                Flower Inventory Management System
            </p>

            <div class="mt-4">
                <span class="badge text-bg-success">
                    Bootstrap 5.3.8 Ready
                </span>
            </div>
        </div>
    </div>

</body>
</html>
