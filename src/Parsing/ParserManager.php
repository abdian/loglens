<?php

namespace LogLens\Parsing;

use LogLens\Contracts\Parser;
use LogLens\Parsing\Parsers\RegexParser;

/**
 * Registry + auto-detection for log parsers.
 *
 * Detection samples the first lines of a file, ranks every registered parser
 * by confidence, and caches the winning parser id per file in index meta so the
 * decision is made once.
 */
class ParserManager
{
    /** @var array<string,Parser> */
    private array $parsers = [];

    /** @var string[] preferred detection order */
    private array $order;

    public function __construct(private array $config = [])
    {
        $vendorMarkers = $config['vendor_markers'] ?? ['/vendor/', '\\vendor\\'];

        $builtins = BuiltInFormats::all($vendorMarkers);
        $this->order = $config['formats'] ?? array_keys($builtins);

        foreach ($builtins as $id => $parser) {
            $this->parsers[$id] = $parser;
        }

        $this->registerCustom($config['custom'] ?? []);
        $this->registerClasses($config['parsers'] ?? []);
        $this->registerOpcodes($config['opcodes_parsers'] ?? []);
    }

    public function register(Parser $parser, bool $prepend = false): void
    {
        $this->parsers[$parser->id()] = $parser;
        if (! in_array($parser->id(), $this->order, true)) {
            $prepend
                ? array_unshift($this->order, $parser->id())
                : $this->order[] = $parser->id();
        }
    }

    public function get(?string $id): ?Parser
    {
        return $id !== null ? ($this->parsers[$id] ?? null) : null;
    }

    public function getOrDetect(?string $id, array $sampleLines): Parser
    {
        if ($id !== null && isset($this->parsers[$id])) {
            return $this->parsers[$id];
        }

        return $this->detect($sampleLines);
    }

    /**
     * Rank registered parsers by confidence over a sample. Ties break toward
     * the configured order (laravel before json before the rest).
     */
    public function detect(array $sampleLines): Parser
    {
        $best = null;
        $bestScore = -1.0;
        $bestRank = PHP_INT_MAX;

        foreach ($this->order as $rank => $id) {
            $parser = $this->parsers[$id] ?? null;
            if (! $parser) {
                continue;
            }
            $score = $parser->detect($sampleLines);
            if ($score > $bestScore || ($score === $bestScore && $rank < $bestRank)) {
                $best = $parser;
                $bestScore = $score;
                $bestRank = $rank;
            }
        }

        // Default to Laravel when nothing matches (most files are Laravel logs).
        return ($bestScore > 0.0 && $best) ? $best : ($this->parsers['laravel'] ?? array_values($this->parsers)[0]);
    }

    /**
     * @return array<string,Parser>
     */
    public function all(): array
    {
        return $this->parsers;
    }

    private function registerCustom(array $custom): void
    {
        foreach ($custom as $name => $def) {
            if (empty($def['pattern'])) {
                continue;
            }
            $this->register(new RegexParser(
                is_string($name) ? $name : ($def['name'] ?? 'custom'),
                $def['pattern'],
                $def['levels'] ?? [],
                $def['default_level'] ?? 'info'
            ), true);
        }
    }

    private function registerClasses(array $classes): void
    {
        foreach ($classes as $class) {
            if (is_string($class) && class_exists($class) && is_subclass_of($class, Parser::class)) {
                $this->register(new $class(), true);
            } elseif ($class instanceof Parser) {
                $this->register($class, true);
            }
        }
    }

    private function registerOpcodes(array $classes): void
    {
        foreach ($classes as $class) {
            $parser = OpcodesAdapter::adapt($class);
            if ($parser) {
                $this->register($parser, true);
            }
        }
    }
}
