<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Person;
use App\Models\Tag;
use App\Models\TagAssignmentLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of the tag pivots (person_tag, client_tag, asset_tag).
 *
 * Every link that changes writes exactly one tag_assignment_logs row in the same
 * transaction as the pivot write. A call that changes nothing writes nothing.
 * Role checks belong to the callers; this service enforces only the data rules.
 */
class TagService
{
    public const SOURCES = ['web', 'mcp', 'merge', 'system'];

    /** Model class => entity_type stored in the log. */
    private const ENTITY_TYPES = [
        Person::class => 'person',
        Client::class => 'client',
        Asset::class => 'asset',
    ];

    /** Link a tag to an entity. Returns true when a link was created. */
    public function attach(Model $entity, Tag $tag, ?User $actor, string $source): bool
    {
        $type = $this->entityType($entity);
        $this->assertSource($source);
        $this->assertActive($tag);

        return DB::transaction(function () use ($entity, $tag, $actor, $source, $type) {
            if ($this->relation($entity)->whereKey($tag->getKey())->exists()) {
                return false;
            }

            $this->relation($entity)->attach($tag->getKey());
            $this->log($entity, $type, $tag, 'attached', $actor, $source);

            return true;
        });
    }

    /** Unlink a tag from an entity. Returns true when a link was removed. */
    public function detach(Model $entity, Tag $tag, ?User $actor, string $source): bool
    {
        $type = $this->entityType($entity);
        $this->assertSource($source);

        return DB::transaction(function () use ($entity, $tag, $actor, $source, $type) {
            if ($this->relation($entity)->detach($tag->getKey()) === 0) {
                return false;
            }

            $this->log($entity, $type, $tag, 'detached', $actor, $source);

            return true;
        });
    }

    /**
     * Make the entity's tags exactly $tagIds. Only the difference is written and
     * logged. Every id must name an existing tag; a newly linked tag must be active
     * (an already-linked inactive tag may stay).
     *
     * @param  array<int|string>  $tagIds
     * @return array{attached: list<int>, detached: list<int>}
     */
    public function sync(Model $entity, array $tagIds, ?User $actor, string $source): array
    {
        $type = $this->entityType($entity);
        $this->assertSource($source);

        $wanted = array_values(array_unique(array_map('intval', $tagIds)));

        return DB::transaction(function () use ($entity, $wanted, $actor, $source) {
            $tags = Tag::whereKey($wanted)->get()->keyBy('id');
            $missing = array_diff($wanted, $tags->keys()->all());
            if ($missing !== []) {
                throw new InvalidArgumentException('Unknown tag id(s): '.implode(', ', $missing).'.');
            }

            $current = $this->relation($entity)->pluck('tags.id')->map(fn ($id) => (int) $id)->all();
            $toAttach = array_values(array_diff($wanted, $current));
            $toDetach = array_values(array_diff($current, $wanted));

            foreach ($toAttach as $id) {
                $this->assertActive($tags[$id]);
            }

            foreach ($toDetach as $id) {
                $this->detach($entity, Tag::findOrFail($id), $actor, $source);
            }
            foreach ($toAttach as $id) {
                $this->attach($entity, $tags[$id], $actor, $source);
            }

            return ['attached' => $toAttach, 'detached' => $toDetach];
        });
    }

    /**
     * Merge carry-over: link every tag of $from to $to (skipping ones $to already
     * has), then unlink all of them from $from. Both halves are logged with the
     * given source. Inactive tags carry too: a merge preserves, it does not assign.
     */
    public function moveAll(Model $from, Model $to, ?User $actor, string $source = 'merge'): int
    {
        $type = $this->entityType($from);
        if ($this->entityType($to) !== $type) {
            throw new InvalidArgumentException('Tags can only move between entities of one type.');
        }
        $this->assertSource($source);

        return DB::transaction(function () use ($from, $to, $actor, $source, $type) {
            $survivorIds = $this->relation($to)->pluck('tags.id')->map(fn ($id) => (int) $id)->all();
            $loserTags = $this->relation($from)->get();
            $moved = 0;
            foreach ($loserTags as $tag) {
                if (! in_array((int) $tag->id, $survivorIds, true)) {
                    $this->relation($to)->attach($tag->id);
                    $this->log($to, $type, $tag, 'attached', $actor, $source);
                    $moved++;
                }
            }
            foreach ($loserTags as $tag) {
                $this->detach($from, $tag, $actor, $source);
            }

            return $moved;
        });
    }

    private function relation(Model $entity): BelongsToMany
    {
        return $entity->tags();
    }

    private function entityType(Model $entity): string
    {
        $type = self::ENTITY_TYPES[$entity::class] ?? null;
        if ($type === null) {
            throw new InvalidArgumentException('Tags apply only to people, clients and assets.');
        }
        if (! $entity->exists) {
            throw new InvalidArgumentException('Cannot tag an unsaved record.');
        }

        return $type;
    }

    private function assertSource(string $source): void
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Unknown tag change source.');
        }
    }

    private function assertActive(Tag $tag): void
    {
        if (! $tag->is_active) {
            throw new InvalidArgumentException('A retired tag cannot be assigned.');
        }
    }

    private function log(Model $entity, string $type, Tag $tag, string $action, ?User $actor, string $source): void
    {
        TagAssignmentLog::create([
            'tag_id' => $tag->getKey(),
            'tag_name' => $tag->name,
            'entity_type' => $type,
            'entity_id' => $entity->getKey(),
            'client_id' => $type === 'client' ? $entity->getKey() : $entity->client_id,
            'action' => $action,
            'source' => $source,
            'changed_by' => $actor?->getKey(),
        ]);
    }
}
