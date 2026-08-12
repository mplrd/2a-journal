<?php

return [
    'env' => getenv('APP_ENV') ?: 'local',
    // No 'debug' key on purpose. It only ever drove one thing: handing the
    // exception message, file and line to the *client* on a 500. Unhandled
    // throwables are now written to the server log (App\Core\ErrorLogger), so
    // nothing has to be leaked to diagnose an incident — and a switch that can
    // be left on in production is a switch worth deleting rather than
    // documenting.
    'url' => getenv('APP_URL') ?: 'http://localhost',
    'jwt_secret' => getenv('JWT_SECRET') ?: '',
];
