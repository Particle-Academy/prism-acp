<?php

declare(strict_types=1);

namespace Prism\Acp\Models;

use Prism\Acp\Meta;
use Prism\Acp\Support\Js;
use Prism\Acp\Text;

/**
 * THE MODELS A PROVIDER ACTUALLY OFFERS, for the account that is signed in. Ported from prism-acp-ts src/models.ts:
 *
 * - Claude: the control `initialize` request's `models` (`value`, `resolvedModel`, `displayName`, `description`).
 * - Codex: the app-server's paged `model/list`.
 *
 * A model is ['modelId', 'name', 'description'?, 'resolvedModel'?, 'isDefault'?]; the session state is
 * ['availableModels' => list<model>, 'currentModelId' => ?string], ACP's (unstable) session model state.
 *
 * One known difference: provider text is cut at 500 UTF-16 units, as there. A cut through a surrogate pair leaves the
 * reference a lone surrogate, which a PHP string cannot hold; here the cut falls before the pair.
 *
 * @phpstan-type Model array{modelId: string, name: string, description?: string, resolvedModel?: string, isDefault?: true}
 * @phpstan-type ModelState array{availableModels: list<Model>, currentModelId: string|null}
 */
final class Models
{
    private const MAX_MODELS = 500;

    private const MAX_TEXT = 500;

    /**
     * The models in Claude's `initialize` response. Entries without a usable id are left out, not guessed at.
     *
     * @return list<Model>
     */
    public static function claudeModelsOf(mixed $initializeResponse): array
    {
        $models = Js::get($initializeResponse, 'models');
        if (! is_array($models)) {
            return [];
        }
        $out = [];
        foreach (array_slice($models, 0, self::MAX_MODELS) as $entry) {
            $id = self::modelId(Js::get($entry, 'value'));
            if ($id === null) {
                continue;
            }
            $model = ['modelId' => $id, 'name' => self::text(Js::get($entry, 'displayName')) ?? $id];
            $description = self::text(Js::get($entry, 'description'));
            if ($description !== null) {
                $model['description'] = $description;
            }
            $resolved = self::modelId(Js::get($entry, 'resolvedModel'));
            if ($resolved !== null) {
                $model['resolvedModel'] = $resolved;
            }
            if ($id === 'default') {
                $model['isDefault'] = true;
            }
            $out[] = $model;
        }

        return $out;
    }

    /**
     * The models in one page of Codex's `model/list` (`data`). Hidden ones are left out unless asked for.
     *
     * @return list<Model>
     */
    public static function codexModelsOf(mixed $page, bool $includeHidden = false): array
    {
        $data = Js::get($page, 'data');
        if (! is_array($data)) {
            return [];
        }
        $out = [];
        foreach (array_slice($data, 0, self::MAX_MODELS) as $entry) {
            if (Js::get($entry, 'hidden') === true && ! $includeHidden) {
                continue;
            }
            $id = self::modelId(Js::get($entry, 'id')) ?? self::modelId(Js::get($entry, 'model'));
            if ($id === null) {
                continue;
            }
            $model = ['modelId' => $id, 'name' => self::text(Js::get($entry, 'displayName')) ?? $id];
            $description = self::text(Js::get($entry, 'description'));
            if ($description !== null) {
                $model['description'] = $description;
            }
            $resolved = self::modelId(Js::get($entry, 'model'));
            if ($resolved !== null && $resolved !== $id) {
                $model['resolvedModel'] = $resolved;
            }
            if (Js::get($entry, 'isDefault') === true) {
                $model['isDefault'] = true;
            }
            $out[] = $model;
        }

        return $out;
    }

    /**
     * A model state, checked: what a driver's Deferred delivered is only trusted once it has this shape. Throws
     * ModelListError otherwise, so a malformed list is reported as no list rather than passed on.
     *
     * @return ModelState
     */
    public static function state(mixed $state): array
    {
        $available = is_array($state) ? ($state['availableModels'] ?? null) : null;
        $current = is_array($state) ? ($state['currentModelId'] ?? null) : null;
        if (! is_array($available) || ! array_is_list($available) || ($current !== null && ! is_string($current))) {
            throw new ModelListError('the agent reported a model list of the wrong shape');
        }
        $models = [];
        foreach ($available as $model) {
            if (! is_array($model) || ! is_string($model['modelId'] ?? null) || ! is_string($model['name'] ?? null)) {
                throw new ModelListError('the agent reported a model list of the wrong shape');
            }
            $checked = ['modelId' => $model['modelId'], 'name' => $model['name']];
            foreach (['description', 'resolvedModel'] as $field) {
                if (isset($model[$field])) {
                    if (! is_string($model[$field])) {
                        throw new ModelListError('the agent reported a model list of the wrong shape');
                    }
                    $checked[$field] = $model[$field];
                }
            }
            if (($model['isDefault'] ?? null) === true) {
                $checked['isDefault'] = true;
            }
            $models[] = $checked;
        }

        return ['availableModels' => $models, 'currentModelId' => $current];
    }

    /**
     * The driver's model state in ACP's shape: what ACP's model info has no field for rides in `_meta`.
     *
     * @param  ModelState  $state
     * @return array<string, mixed>
     */
    public static function acp(array $state): array
    {
        $models = [];
        foreach ($state['availableModels'] as $model) {
            $entry = ['modelId' => $model['modelId'], 'name' => $model['name']];
            if (isset($model['description'])) {
                $entry['description'] = $model['description'];
            }
            $extra = [];
            if (isset($model['resolvedModel'])) {
                $extra['resolvedModel'] = $model['resolvedModel'];
            }
            if (isset($model['isDefault'])) {
                $extra['isDefault'] = $model['isDefault'];
            }
            if ($extra !== []) {
                $entry['_meta'] = [Meta::MODEL_INFO => $extra];
            }
            $models[] = $entry;
        }

        return ['availableModels' => $models, 'currentModelId' => $state['currentModelId']];
    }

    /** Provider text is shown to a person: control sequences removed, trimmed, and bounded. */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $clean = Js::trim(Text::stripAnsiControlSequences($value)['text']);

        return $clean === '' ? null : Js::slice($clean, self::MAX_TEXT);
    }

    /** A model id is also an argument the provider is handed back: it must pass the check a `model` option does. */
    private static function modelId(mixed $value): ?string
    {
        if (! is_string($value) || Js::length($value) > 200) {
            return null;
        }
        try {
            return Meta::assertModel($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
