<?php

namespace CRM\Services;

interface BillingHubClientInterface
{
    public function createCheckout(array $settings, array $payload): array;

    public function fetchStatus(array $settings): array;
}
