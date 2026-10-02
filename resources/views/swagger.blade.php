<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} API · Swagger</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
    <style>body { margin: 0; }</style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        SwaggerUIBundle({
            url: '{{ route('docs.spec') }}',
            dom_id: '#swagger-ui',
            persistAuthorization: true,
            deepLinking: true,
            tryItOutEnabled: true,
            displayRequestDuration: true,
            requestInterceptor: (request) => {
                request.headers['Accept'] = 'application/json';
                return request;
            },
        });
    </script>
</body>
</html>
