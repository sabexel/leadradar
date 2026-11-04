<?php

declare(strict_types=1);

namespace App\Services\Crawler;

/**
 * Parsed robots.txt rules for our user agent (RFC 9309: longest matching rule wins, Allow wins ties).
 */
final readonly class RobotsRules
{
    /**
     * @param  list<array{allow: bool, pattern: string}>  $rules
     */
    public function __construct(
        public array $rules = [],
        public ?int $crawlDelay = null,
    ) {}

    public static function allowAll(): self
    {
        return new self;
    }

    public static function disallowAll(): self
    {
        return new self([['allow' => false, 'pattern' => '/']]);
    }

    public function allows(string $path): bool
    {
        $path = $path === '' ? '/' : $path;
        $best = null;

        foreach ($this->rules as $rule) {
            if (! $this->matches($rule['pattern'], $path)) {
                continue;
            }

            $length = strlen($rule['pattern']);

            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $best === null || $best['allow'];
    }

    /**
     * @return array{rules: list<array{allow: bool, pattern: string}>, crawl_delay: int|null}
     */
    public function toArray(): array
    {
        return ['rules' => $this->rules, 'crawl_delay' => $this->crawlDelay];
    }

    /**
     * @param  array{rules?: list<array{allow: bool, pattern: string}>, crawl_delay?: int|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['rules'] ?? [], $data['crawl_delay'] ?? null);
    }

    /**
     * Supports the `*` wildcard and the `$` end anchor.
     */
    private function matches(string $pattern, string $path): bool
    {
        if ($pattern === '') {
            return false;
        }

        $anchored = str_ends_with($pattern, '$');
        $regex = str_replace('\*', '.*', preg_quote($anchored ? substr($pattern, 0, -1) : $pattern, '#'));

        return preg_match('#^'.$regex.($anchored ? '$' : '').'#', $path) === 1;
    }
}
