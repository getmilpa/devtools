<?php

/**
 * This file is part of Milpa DevTools — the generate-verify-inspect developer loop of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/devtools
 */

declare(strict_types=1);

namespace Milpa\DevTools\Validators;

use Milpa\Attributes\PluginMetadata;
use Milpa\Plugin\PluginManifest;

/**
 * D5 parity watchdog: with the attribute as the single metadata authority,
 * a milpa.json that drifts from it on a GRAPH field (name, version, type,
 * author, provides, requires, suggests) is flagged before it can mislead
 * anyone. The `site` field is deliberately excluded: the canonical generator
 * never emits a homepage, so every manifest reads '' there by construction.
 */
final class MetadataParityValidator
{
    private const GRAPH_FIELDS = ['name', 'version', 'type', 'author', 'provides', 'requires', 'suggests'];

    /** Compare one manifest against its plugin class attribute. */
    public function validate(string $manifestPath, string $pluginClass): MetadataParityResult
    {
        try {
            $manifest = PluginManifest::fromPath($manifestPath)->toMetadataArray();
        } catch (\Exception $e) {
            return new MetadataParityResult($manifestPath, ['manifest: ' . $e->getMessage()]);
        }

        $attributes = (new \ReflectionClass($pluginClass))->getAttributes(PluginMetadata::class);
        if ($attributes === []) {
            return new MetadataParityResult($manifestPath, ['attribute: missing #[PluginMetadata]']);
        }
        $meta = $attributes[0]->newInstance();

        $fromAttribute = [
            'name' => $meta->name,
            'version' => $meta->version,
            'type' => $meta->type,
            'author' => $meta->author,
            'provides' => $meta->provides,
            'requires' => $meta->requires,
            'suggests' => $meta->suggests,
        ];

        $divergent = [];
        foreach (self::GRAPH_FIELDS as $field) {
            if ($this->normalize($manifest[$field]) !== $this->normalize($fromAttribute[$field])) {
                $divergent[] = $field;
            }
        }

        return new MetadataParityResult($manifestPath, $divergent);
    }

    /** Canonical key order, recursive — a record's key order is not divergence. */
    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(fn (mixed $v): mixed => $this->normalize($v), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
