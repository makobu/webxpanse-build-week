<?php

namespace CRM\Services;

interface VoiceTranscriptionProviderInterface
{
    public function supportsModel(string $model): bool;

    /** @return array{text:string,language:string,segments:array,confidence:?float,model:string,provider:string} */
    public function transcribe(int $workspaceId, string $audioPath, string $model): array;
}
