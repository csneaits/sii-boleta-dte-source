<?php
declare(strict_types=1);

namespace Sii\BoletaDte\Infrastructure;

use Sii\BoletaDte\Infrastructure\WordPress\Settings as WPSettings;

/**
 * Backwards-compatible adapter: existing code/tests may type-hint \Sii\BoletaDte\Infrastructure\Settings
 * while the canonical implementation lives under Infrastructure\WordPress\Settings.
 */
class Settings extends WPSettings {
    // no-op adapter
}
