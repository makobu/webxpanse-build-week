<?php
/**
 * Event Bus
 * Event-driven architecture foundation
 */

namespace CRM;

class EventBus
{
    private static array $listeners = [];
    
    /**
     * Subscribe to event
     */
    public static function subscribe(string $event, callable $handler): void
    {
        if (!isset(self::$listeners[$event])) {
            self::$listeners[$event] = [];
        }
        
        self::$listeners[$event][] = $handler;
    }
    
    /**
     * Publish event
     */
    public static function publish(string $event, array $data = []): void
    {
        if (!isset(self::$listeners[$event])) {
            return;
        }
        
        foreach (self::$listeners[$event] as $handler) {
            try {
                $handler($data);
            } catch (\Exception $e) {
                error_log("Event handler error for $event: " . $e->getMessage());
            }
        }
    }

    public static function reset(): void
    {
        self::$listeners = [];
    }
}
