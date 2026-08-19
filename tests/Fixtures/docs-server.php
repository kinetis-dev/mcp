<?php

declare(strict_types=1);

// Stands in for raw.githubusercontent.com in KinetisDocsResourceTest — a
// real HTTP server, just local, mirroring the exact "no external service
// needed" pattern AmpHttpClientFactoryTest's own echo-server.php fixture
// already established.

if ($_SERVER['REQUEST_URI'] === '/known-remote-page.md') {
    echo "# Remote Fixture\n\nThis came from the remote fallback.\n";

    return;
}

http_response_code(404);
echo "404: Not Found\n";
