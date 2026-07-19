<?php
/**
 * Central catalog for separated Google OAuth grants.
 */

namespace CRM\Services;

class GoogleOAuthScopeCatalog
{
    public const GRANT_CALENDAR_IMPORT = 'calendar_import';
    public const GRANT_CALENDAR_WRITE = 'calendar_write';
    public const GRANT_GMAIL_SEND = 'gmail_send';
    public const GRANT_GMAIL_INBOX = 'gmail_inbox';
    public const GRANT_GMAIL_MODIFY = 'gmail_modify';
    public const GRANT_ASSISTANT_GMAIL = 'assistant_gmail';
    public const GRANT_LEGACY_COMBINED = 'legacy_combined';
    public const GRANT_LEGACY_CALENDAR = 'legacy_calendar';

    public const SCOPE_USERINFO_EMAIL = 'https://www.googleapis.com/auth/userinfo.email';
    public const SCOPE_CALENDAR_READONLY = 'https://www.googleapis.com/auth/calendar.events.readonly';
    public const SCOPE_CALENDAR_WRITE = 'https://www.googleapis.com/auth/calendar.events';
    public const SCOPE_CALENDAR_FULL = 'https://www.googleapis.com/auth/calendar';
    public const SCOPE_GMAIL_SEND = 'https://www.googleapis.com/auth/gmail.send';
    public const SCOPE_GMAIL_READONLY = 'https://www.googleapis.com/auth/gmail.readonly';
    public const SCOPE_GMAIL_MODIFY = 'https://www.googleapis.com/auth/gmail.modify';

    /**
     * @return array{grant_type:string,client_key:string,label:string,surface:string,capability:string,scopes:array<int,string>}
     */
    public static function forGrant(string $grantType): array
    {
        $grantType = self::normalizeGrantType($grantType);

        return match ($grantType) {
            self::GRANT_CALENDAR_IMPORT => [
                'grant_type' => self::GRANT_CALENDAR_IMPORT,
                'client_key' => 'GOOGLE_CALENDAR',
                'label' => 'Google Calendar import',
                'surface' => 'calendar',
                'capability' => 'calendar_import',
                'scopes' => [self::SCOPE_USERINFO_EMAIL, self::SCOPE_CALENDAR_READONLY],
            ],
            self::GRANT_CALENDAR_WRITE => [
                'grant_type' => self::GRANT_CALENDAR_WRITE,
                'client_key' => 'GOOGLE_CALENDAR',
                'label' => 'Google Calendar two-way sync',
                'surface' => 'calendar',
                'capability' => 'calendar_write',
                'scopes' => [self::SCOPE_USERINFO_EMAIL, self::SCOPE_CALENDAR_WRITE],
            ],
            self::GRANT_GMAIL_INBOX => [
                'grant_type' => self::GRANT_GMAIL_INBOX,
                'client_key' => 'GOOGLE_GMAIL_INBOX',
                'label' => 'Gmail inbox sync',
                'surface' => 'email',
                'capability' => 'gmail_inbox',
                'scopes' => [self::SCOPE_USERINFO_EMAIL, self::SCOPE_GMAIL_READONLY],
            ],
            self::GRANT_GMAIL_MODIFY => [
                'grant_type' => self::GRANT_GMAIL_MODIFY,
                'client_key' => 'GOOGLE_GMAIL_INBOX',
                'label' => 'Gmail mailbox actions',
                'surface' => 'email',
                'capability' => 'gmail_modify',
                'scopes' => [self::SCOPE_USERINFO_EMAIL, self::SCOPE_GMAIL_MODIFY],
            ],
            self::GRANT_ASSISTANT_GMAIL => [
                'grant_type' => self::GRANT_ASSISTANT_GMAIL,
                'client_key' => 'GOOGLE_ASSISTANT_GMAIL',
                'label' => 'Assistant Gmail sending',
                'surface' => 'email_assistant',
                'capability' => 'gmail_send',
                'scopes' => [self::SCOPE_USERINFO_EMAIL, self::SCOPE_GMAIL_SEND],
            ],
            default => [
                'grant_type' => self::GRANT_GMAIL_SEND,
                'client_key' => 'GOOGLE_GMAIL_SEND',
                'label' => 'Gmail sending',
                'surface' => 'email',
                'capability' => 'gmail_send',
                'scopes' => [self::SCOPE_USERINFO_EMAIL, self::SCOPE_GMAIL_SEND],
            ],
        };
    }

    public static function normalizeGrantType(string $grantType): string
    {
        $grantType = strtolower(trim($grantType));

        return match ($grantType) {
            'calendar', 'calendar_read', 'calendar_readonly', self::GRANT_CALENDAR_IMPORT => self::GRANT_CALENDAR_IMPORT,
            'calendar_outbound', 'calendar_two_way', 'calendar_write', self::GRANT_CALENDAR_WRITE => self::GRANT_CALENDAR_WRITE,
            'send', 'gmail', 'gmail_send', 'mail_send', self::GRANT_GMAIL_SEND => self::GRANT_GMAIL_SEND,
            'inbox', 'gmail_inbox', 'gmail_read', 'gmail_readonly', self::GRANT_GMAIL_INBOX => self::GRANT_GMAIL_INBOX,
            'modify', 'gmail_modify', self::GRANT_GMAIL_MODIFY => self::GRANT_GMAIL_MODIFY,
            'assistant', 'assistant_gmail', self::GRANT_ASSISTANT_GMAIL => self::GRANT_ASSISTANT_GMAIL,
            self::GRANT_LEGACY_COMBINED => self::GRANT_LEGACY_COMBINED,
            self::GRANT_LEGACY_CALENDAR => self::GRANT_LEGACY_CALENDAR,
            default => self::GRANT_GMAIL_SEND,
        };
    }

    /**
     * @return array<int,string>
     */
    public static function requiredScopes(string $grantType): array
    {
        if (in_array($grantType, [self::GRANT_LEGACY_COMBINED, self::GRANT_LEGACY_CALENDAR], true)) {
            return [];
        }

        return self::forGrant($grantType)['scopes'];
    }

    /**
     * @return array<int,string>
     */
    public static function parseScopes(mixed $scopes): array
    {
        if (is_string($scopes)) {
            $parts = preg_split('/\s+/', trim($scopes)) ?: [];
            return array_values(array_unique(array_filter(array_map('trim', $parts))));
        }

        if (is_array($scopes)) {
            $result = [];
            foreach ($scopes as $scope) {
                $scope = trim((string) $scope);
                if ($scope !== '') {
                    $result[] = $scope;
                }
            }

            return array_values(array_unique($result));
        }

        return [];
    }

    /**
     * @param array<int,string> $grantedScopes
     * @param array<int,string> $requiredScopes
     */
    public static function hasRequiredScopes(array $grantedScopes, array $requiredScopes): bool
    {
        if ($requiredScopes === []) {
            return true;
        }

        $granted = array_fill_keys($grantedScopes, true);
        foreach ($requiredScopes as $required) {
            if (!isset($granted[$required])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,string> $grantedScopes
     * @param array<int,string> $requiredScopes
     */
    public static function scopeStatus(array $grantedScopes, array $requiredScopes, bool $legacy = false): string
    {
        if ($legacy) {
            return 'legacy';
        }

        if ($grantedScopes === []) {
            return 'unknown';
        }

        return self::hasRequiredScopes($grantedScopes, $requiredScopes) ? 'verified' : 'missing_required';
    }

    public static function grantSupportsMailSend(string $grantType): bool
    {
        return in_array(self::normalizeGrantType($grantType), [
            self::GRANT_GMAIL_SEND,
            self::GRANT_ASSISTANT_GMAIL,
            self::GRANT_LEGACY_COMBINED,
        ], true);
    }

    public static function grantSupportsMailInbox(string $grantType): bool
    {
        return in_array(self::normalizeGrantType($grantType), [
            self::GRANT_GMAIL_INBOX,
            self::GRANT_GMAIL_MODIFY,
            self::GRANT_LEGACY_COMBINED,
        ], true);
    }

    public static function grantSupportsCalendarImport(string $grantType): bool
    {
        return in_array(self::normalizeGrantType($grantType), [
            self::GRANT_CALENDAR_IMPORT,
            self::GRANT_CALENDAR_WRITE,
            self::GRANT_LEGACY_CALENDAR,
        ], true);
    }

    public static function grantSupportsCalendarWrite(string $grantType): bool
    {
        return in_array(self::normalizeGrantType($grantType), [
            self::GRANT_CALENDAR_WRITE,
            self::GRANT_LEGACY_CALENDAR,
        ], true);
    }
}
