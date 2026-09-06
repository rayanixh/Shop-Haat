<?php
/**
 * AI Auto Work — provider contract.
 *
 * Adding a provider means implementing this interface and registering it in
 * AIManager::sh_ai_provider(). No other file needs to change.
 *
 * Implementations must NEVER throw: return a structured failure instead, so a
 * provider outage can never break the admin panel.
 */
interface ShAIProvider
{
    /** Machine key, e.g. "openai". */
    public function key(): string;

    /** Human label for the settings screen. */
    public function label(): string;

    /** Text models this provider offers. */
    public function textModels(): array;

    /** Image models this provider offers (empty if unsupported). */
    public function imageModels(): array;

    /**
     * Generate text.
     *
     * @return array{ok:bool,text?:string,tokens?:int,error?:string}
     */
    public function generateText(string $system, string $user, array $options = []): array;

    /**
     * Generate an image and return raw binary data.
     *
     * @return array{ok:bool,binary?:string,mime?:string,error?:string}
     */
    public function generateImage(string $prompt, array $options = []): array;

    /**
     * Cheap credential check.
     *
     * @return array{ok:bool,error?:string,detail?:string}
     */
    public function testConnection(): array;
}
