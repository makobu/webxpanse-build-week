<?php

namespace CRM\Services;

class OpenAIVoiceTranscriptionProvider implements VoiceTranscriptionProviderInterface
{
    /** @var callable|null */
    private $transport;
    private WorkspaceAIProviderConfigService $configs;

    public function __construct(?WorkspaceAIProviderConfigService $configs = null, ?callable $transport = null)
    {
        $this->configs = $configs ?? new WorkspaceAIProviderConfigService();
        $this->transport = $transport;
    }

    public function supportsModel(string $model): bool
    {
        return (bool) preg_match(
            '/^(?:whisper-1|gpt-4o-transcribe(?:-diarize)?(?:-[0-9]{4}-[0-9]{2}-[0-9]{2})?|gpt-4o-mini-transcribe(?:-[0-9]{4}-[0-9]{2}-[0-9]{2})?)$/',
            trim($model)
        );
    }

    public function transcribe(int $workspaceId, string $audioPath, string $model): array
    {
        if (!is_file($audioPath) || filesize($audioPath) <= 0) {
            throw new \RuntimeException('The call recording is empty or unavailable.');
        }
        if ((int) filesize($audioPath) > 24 * 1024 * 1024) {
            throw new \RuntimeException('The call recording exceeds the transcription size limit.');
        }
        $config = $this->configs->get($workspaceId, true);
        if (empty($config['enabled']) || trim((string) ($config['api_key'] ?? '')) === '') {
            throw new \RuntimeException('Save and enable the workspace OpenAI key in Settings before transcription.');
        }
        if (strtolower((string) ($config['provider_key'] ?? 'openai')) !== 'openai') {
            throw new \RuntimeException('Production voice transcription requires the workspace OpenAI provider in Settings.');
        }
        if (!$this->supportsModel($model)) {
            throw new \InvalidArgumentException('The configured OpenAI transcription model is not supported by production voice v1.');
        }
        $endpoint = 'https://api.openai.com/v1/audio/transcriptions';
        $isDiarized = str_contains(strtolower($model), 'diarize');
        $isWhisper = strtolower($model) === 'whisper-1';
        $fields = [
            'file' => new \CURLFile($audioPath, $this->mime($audioPath), basename($audioPath)),
            'model' => $model,
            'response_format' => $isDiarized ? 'diarized_json' : ($isWhisper ? 'verbose_json' : 'json'),
        ];
        if ($isDiarized) {
            $fields['chunking_strategy'] = 'auto';
        }
        if ($this->transport !== null) {
            $response = (array) call_user_func($this->transport, $endpoint, $fields, (string) $config['api_key']);
        } else {
            if (!function_exists('curl_init')) {
                throw new \RuntimeException('cURL is required for OpenAI transcription.');
            }
            $handle = curl_init($endpoint);
            curl_setopt_array($handle, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . (string) $config['api_key'], 'Accept: application/json'],
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 180, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);
            if ($body === false || $error !== '') {
                throw new \RuntimeException('OpenAI transcription connection failed.');
            }
            $response = json_decode((string) $body, true);
            if (!is_array($response) || $status < 200 || $status >= 300) {
                $message = is_array($response) ? (string) ($response['error']['message'] ?? '') : '';
                throw new \RuntimeException('OpenAI transcription failed' . ($message !== '' ? ': ' . substr($message, 0, 300) : '.'));
            }
        }
        $text = trim((string) ($response['text'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('OpenAI returned an empty transcript.');
        }
        return [
            'text' => $text, 'language' => (string) ($response['language'] ?? ''),
            'segments' => array_values((array) ($response['segments'] ?? [])),
            'confidence' => isset($response['confidence']) ? max(0.0, min(1.0, (float) $response['confidence'])) : null,
            'model' => $model, 'provider' => 'openai',
        ];
    }

    private function mime(string $path): string
    {
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($path) : '';
        return str_starts_with($mime, 'audio/') ? $mime : 'audio/mpeg';
    }
}
