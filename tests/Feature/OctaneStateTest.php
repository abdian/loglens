<?php

namespace LogLens\Tests\Feature;

use LogLens\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Octane / long-process safety. The package holds no
 * mutable static state and uses scoped bindings; repeated requests must not
 * leak state across "workers".
 */
#[Group('octane')]
class OctaneStateTest extends TestCase
{
    public function test_repeated_requests_have_no_state_leak(): void
    {
        $this->fixtures->laravel('a.log', 20);
        $this->fixtures->laravel('b.log', 40);
        $idA = $this->fileId('a.log');
        $idB = $this->fileId('b.log');

        // Simulate many requests against alternating files; results must stay
        // correct (no carried-over file identity / store handle).
        for ($i = 0; $i < 25; $i++) {
            $a = $this->getJson("loglens/api/files/{$idA}/open");
            $a->assertOk();
            $b = $this->getJson("loglens/api/files/{$idB}/open");
            $b->assertOk();
            $this->assertSame($idA, $a->json('file.id'));
            $this->assertSame($idB, $b->json('file.id'));
        }
    }

    public function test_no_mutable_static_state_in_services(): void
    {
        // The only intentional static is the hash-algorithm cache + parser
        // detection cache, both immutable after first resolution. Assert key
        // services carry no public static mutable properties.
        $services = [
            \LogLens\Indexing\IndexManager::class,
            \LogLens\Indexing\Indexer::class,
            \LogLens\Search\SearchEngine::class,
            \LogLens\Tail\TailEngine::class,
            \LogLens\Security\Redactor::class,
        ];
        foreach ($services as $class) {
            $ref = new \ReflectionClass($class);
            foreach ($ref->getProperties(\ReflectionProperty::IS_STATIC) as $prop) {
                $this->fail("{$class} has static property \${$prop->getName()} (Octane risk).");
            }
            $this->assertTrue(true);
        }
    }
}
