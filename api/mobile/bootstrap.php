<?php

require_once __DIR__ . '/_bootstrap.php';

mobileJson([
    'success' => true,
    'data' => mobileService()->bootstrapPayload(),
]);
