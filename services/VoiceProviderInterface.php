<?php

namespace CRM\Services;

interface VoiceProviderInterface
{
    /** @return array{remote_end:bool,transfer:bool,queue_status:bool,outbound_prebridge_consent:bool} */
    public function capabilities(): array;

    /** @return array{ok:bool,message:string,metadata?:array} */
    public function verifyConfiguration(array $config): array;

    /** @return array<string,mixed> */
    public function initiateAgentFirstOutboundBridge(array $config, string $agentEndpoint, string $callbackUrl): array;

    public function renderInboundInstructions(array $route): string;

    public function renderOutboundBridgeInstructions(array $route): string;

    public function renderEnqueueInstructions(string $queueName, string $holdMusicUrl = ''): string;

    public function renderDequeueInstructions(string $virtualNumber, string $queueName): string;

    /** @return array<string,mixed> */
    public function transfer(array $config, string $sessionId, string $destination, string $callbackUrl = ''): array;

    /** @return array<string,mixed> */
    public function queueStatus(array $config, array $phoneNumbers): array;

    /** @return array<string,mixed> */
    public function endCall(array $config, string $sessionId): array;

    /** @return array<string,mixed> */
    public function requestRecordingMetadata(array $config, string $sessionId): array;

    /** @return array<string,mixed> */
    public function normalizeEvent(array $payload): array;

    /** @return array{currency:string,amount:float,billable_minutes:int} */
    public function estimateUsage(string $direction, int $durationSeconds, bool $sipAgent = false): array;

    /** @return array{accepted:bool,score:int,reasons:array<int,string>} */
    public function scoreCallbackAuthenticity(array $config, array $payload, string $sourceIp, array $headers = []): array;
}
