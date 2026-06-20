<?php

namespace LogLens\Search;

/**
 * Saved searches persisted in LogLens's OWN storage (a JSON file under the
 * index directory) — never the host application's database. Keyed per user so searches don't leak across accounts.
 */
class SavedSearchStore
{
    public function __construct(private string $directory)
    {
    }

    public function all($userId): array
    {
        $data = $this->load();

        return array_values($data[$this->key($userId)] ?? []);
    }

    public function save($userId, array $search): array
    {
        $data = $this->load();
        $key = $this->key($userId);
        $id = $search['id'] ?? bin2hex(random_bytes(8));
        $search['id'] = $id;
        $search['updated_at'] = time();
        $data[$key][$id] = $search;
        $this->persist($data);

        return $search;
    }

    public function delete($userId, string $id): bool
    {
        $data = $this->load();
        $key = $this->key($userId);
        if (isset($data[$key][$id])) {
            unset($data[$key][$id]);
            $this->persist($data);

            return true;
        }

        return false;
    }

    private function key($userId): string
    {
        return 'u' . ($userId ?? 'guest');
    }

    private function path(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'saved-searches.json';
    }

    private function load(): array
    {
        $path = $this->path();

        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    private function persist(array $data): void
    {
        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        @file_put_contents($this->path(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
